<?php

/**
 * Convert a string into valid UTF-8. This function is quite slow.
 *
 * When invalid byte subsequences are encountered, they will be replaced with
 * U+FFFD, the Unicode replacement character.
 *
 * @param   string  String to convert to valid UTF-8.
 * @return  string  String with invalid UTF-8 byte subsequences replaced with
 *                  U+FFFD.
 * @group utf8
 */
function phutil_utf8ize($string) {
  if (phutil_is_utf8($string)) {
    return $string;
  }

  // There is no function to do this in iconv, mbstring or ICU to do this, so
  // do it (very very slowly) in pure PHP.

  // TODO: Provide an optional fast C implementation ala fb_utf8ize() if this
  // ever shows up in profiles?

  $result = array();

  $regex =
    "/([\x01-\x7F]".
    "|[\xC2-\xDF][\x80-\xBF]".
    "|[\xE0-\xEF][\x80-\xBF][\x80-\xBF]".
    "|[\xF0-\xF4][\x80-\xBF][\x80-\xBF][\x80-\xBF])".
    "|(.)/";

  $offset = 0;
  $matches = null;
  while (preg_match($regex, $string, $matches, 0, $offset)) {
    if (!isset($matches[2])) {
      $result[] = $matches[1];
    } else {
      // Unicode replacement character, U+FFFD.
      $result[] = "\xEF\xBF\xBD";
    }
    $offset += strlen($matches[0]);
  }

  return implode('', $result);
}


/**
 * Determine if a string is valid UTF-8, with only basic multilingual plane
 * characters. This is particularly important because MySQL's `utf8` column
 * types silently truncate strings which contain characters outside of this
 * set.
 *
 * @param string  String to test for being valid UTF-8 with only characters in
 *                the basic multilingual plane.
 * @return bool   True if the string is valid UTF-8 with only BMP characters.
 */
function phutil_is_utf8_with_only_bmp_characters($string) {

  // NOTE: By default, PCRE segfaults on patterns like the one we would need
  // to use here at very small input sizes, at least on some systems (like
  // OS X). This is apparently because the internal implementation is recursive
  // and it blows the stack. See <https://bugs.php.net/bug.php?id=45735> for
  // some discussion. Since the input limit is extremely low (less than 50KB on
  // my system), do this check very very slowly in PHP instead.

  $len = strlen($string);
  for ($ii = 0; $ii < $len; $ii++) {
    $chr = ord($string[$ii]);
    if ($chr >= 0x01 && $chr <= 0x7F) {
      continue;
    } else if ($chr >= 0xC2 && $chr <= 0xDF) {
      ++$ii;
      if ($ii >= $len) {
        return false;
      }
      $chr = ord($string[$ii]);
      if ($chr >= 0x80 && $chr <= 0xBF) {
        continue;
      }
      return false;
    } else if ($chr > 0xE0 && $chr <= 0xEF) {
      ++$ii;
      if ($ii >= $len) {
        return false;
      }
      $chr = ord($string[$ii]);
      if ($chr >= 0x80 && $chr <= 0xBF) {
        ++$ii;
        if ($ii >= $len) {
          return false;
        }
        $chr = ord($string[$ii]);
        if ($chr >= 0x80 && $chr <= 0xBF) {
          continue;
        }
      }
      return false;
    } else if ($chr == 0xE0) {
      ++$ii;
      if ($ii >= $len) {
        return false;
      }
      $chr = ord($string[$ii]);

      // NOTE: This range starts at 0xA0, not 0x80. The values 0x80-0xA0 are
      // "valid", but not minimal representations, and MySQL rejects them. We're
      // special casing this part of the range.

      if ($chr >= 0xA0 && $chr <= 0xBF) {
        ++$ii;
        if ($ii >= $len) {
          return false;
        }
        $chr = ord($string[$ii]);
        if ($chr >= 0x80 && $chr <= 0xBF) {
          continue;
        }
      }
      return false;
    }

    return false;
  }

  return true;
}


/**
 * Determine if a string is valid UTF-8.
 *
 * @param string  Some string which may or may not be valid UTF-8.
 * @return bool    True if the string is valid UTF-8.
 * @group utf8
 */
function phutil_is_utf8($string) {
  if (function_exists('mb_check_encoding')) {
    // If mbstring is available, this is significantly faster than using PHP
    // regexps.
    return mb_check_encoding($string, 'UTF-8');
  }

  // NOTE: This incorrectly accepts characters like \xE0\x80\x80, but should
  // not. The MB version works correctly.

  $regex =
    "/^(".
      "[\x01-\x7F]+".
    "|([\xC2-\xDF][\x80-\xBF])".
    "|([\xE0-\xEF][\x80-\xBF][\x80-\xBF])".
    "|([\xF0-\xF4][\x80-\xBF][\x80-\xBF][\x80-\xBF]))*\$/";

  return (bool)preg_match($regex, $string);
}


/**
 * Find the character length of a UTF-8 string.
 *
 * @param string A valid utf-8 string.
 * @return int   The character length of the string.
 * @group utf8
 */
function phutil_utf8_strlen($string) {
  return strlen(utf8_decode($string));
}


/**
 * Find the console display length of a UTF-8 string. This may differ from the
 * character length of the string if it contains double-width characters, like
 * many Chinese characters.
 *
 * This method is based on a C implementation here, which is based on the IEEE
 * standards. The source has more discussion and addresses more considerations
 * than this implementation does.
 *
 *   http://www.cl.cam.ac.uk/~mgk25/ucs/wcwidth.c
 *
 * NOTE: We currently assume width 1 for East-Asian ambiguous characters.
 *
 * NOTE: This function is VERY slow.
 *
 * @param   string  A valid UTF-8 string.
 * @return  int     The console display length of the string.
 * @group   utf8
 */
function phutil_utf8_console_strlen($string) {

  // Formatting and colors don't contribute any width in the console.
  $string = preg_replace("/\x1B\[\d*m/", '', $string);

  // In the common case of an ASCII string, just return the string length.
  if (preg_match('/^[\x01-\x7F]*\z/', $string)) {
    return strlen($string);
  }

  $len = 0;

  // NOTE: To deal with combining characters, we're splitting the string into
  // glyphs first (characters with combiners) and then counting just the width
  // of the first character in each glyph.

  $display_glyphs = phutil_utf8v_combined($string);
  foreach ($display_glyphs as $display_glyph) {
    $glyph_codepoints = phutil_utf8v_codepoints($display_glyph);
    foreach ($glyph_codepoints as $c) {
      if ($c == 0) {
        break;
      }

      $len += 1 +
        ($c >= 0x1100 &&
          ($c <= 0x115f ||                    /* Hangul Jamo init. consonants */
            $c == 0x2329 || $c == 0x232a ||
            ($c >= 0x2e80 && $c <= 0xa4cf &&
              $c != 0x303f) ||                  /* CJK ... Yi */
            ($c >= 0xac00 && $c <= 0xd7a3) || /* Hangul Syllables */
            ($c >= 0xf900 && $c <= 0xfaff) || /* CJK Compatibility Ideographs */
            ($c >= 0xfe10 && $c <= 0xfe19) || /* Vertical forms */
            ($c >= 0xfe30 && $c <= 0xfe6f) || /* CJK Compatibility Forms */
            ($c >= 0xff00 && $c <= 0xff60) || /* Fullwidth Forms */
            ($c >= 0xffe0 && $c <= 0xffe6) ||
            ($c >= 0x20000 && $c <= 0x2fffd) ||
            ($c >= 0x30000 && $c <= 0x3fffd)));

      break;
    }
  }

  return $len;
}


/**
 * Split a UTF-8 string into an array of characters. Combining characters are
 * also split.
 *
 * @param string A valid utf-8 string.
 * @return list  A list of characters in the string.
 * @group utf8
 */
function phutil_utf8v($string) {
  $res = array();
  $len = strlen($string);
  $ii = 0;
  while ($ii < $len) {
    $byte = $string[$ii];
    if ($byte <= "\x7F") {
      $res[] = $byte;
      $ii += 1;
      continue;
    } else if ($byte < "\xC0") {
      throw new Exception('Invalid UTF-8 string passed to phutil_utf8v().');
    } else if ($byte <= "\xDF") {
      $seq_len = 2;
    } else if ($byte <= "\xEF") {
      $seq_len = 3;
    } else if ($byte <= "\xF7") {
      $seq_len = 4;
    } else if ($byte <= "\xFB") {
      $seq_len = 5;
    } else if ($byte <= "\xFD") {
      $seq_len = 6;
    } else {
      throw new Exception('Invalid UTF-8 string passed to phutil_utf8v().');
    }

    if ($ii + $seq_len > $len) {
      throw new Exception('Invalid UTF-8 string passed to phutil_utf8v().');
    }
    for ($jj = 1; $jj < $seq_len; ++$jj) {
      if ($string[$ii + $jj] >= "\xC0") {
        throw new Exception('Invalid UTF-8 string passed to phutil_utf8v().');
      }
    }
    $res[] = substr($string, $ii, $seq_len);
    $ii += $seq_len;
  }
  return $res;
}


/**
 * Split a UTF-8 string into an array of codepoints (as integers).
 *
 * @param   string  A valid UTF-8 string.
 * @return  list    A list of codepoints, as integers.
 * @group   utf8
 */
function phutil_utf8v_codepoints($string) {
  $str_v = phutil_utf8v($string);

  foreach ($str_v as $key => $char) {
    $c = ord($char[0]);
    $v = 0;

    if (($c & 0x80) == 0) {
      $v = $c;
    } else if (($c & 0xE0) == 0xC0) {
      $v = (($c & 0x1F) << 6)
         + ((ord($char[1]) & 0x3F));
    } else if (($c & 0xF0) == 0xE0) {
      $v = (($c & 0x0F) << 12)
         + ((ord($char[1]) & 0x3f) << 6)
         + ((ord($char[2]) & 0x3f));
    } else if (($c & 0xF8) == 0xF0) {
      $v = (($c & 0x07) << 18)
         + ((ord($char[1]) & 0x3F) << 12)
         + ((ord($char[2]) & 0x3F) << 6)
         + ((ord($char[3]) & 0x3f));
    } else if (($c & 0xFC) == 0xF8) {
      $v = (($c & 0x03) << 24)
         + ((ord($char[1]) & 0x3F) << 18)
         + ((ord($char[2]) & 0x3F) << 12)
         + ((ord($char[3]) & 0x3f) << 6)
         + ((ord($char[4]) & 0x3f));
    } else if (($c & 0xFE) == 0xFC) {
      $v = (($c & 0x01) << 30)
         + ((ord($char[1]) & 0x3F) << 24)
         + ((ord($char[2]) & 0x3F) << 18)
         + ((ord($char[3]) & 0x3f) << 12)
         + ((ord($char[4]) & 0x3f) << 6)
         + ((ord($char[5]) & 0x3f));
    }

    $str_v[$key] = $v;
  }

  return $str_v;
}


/**
 * Shorten a string to provide a summary, respecting UTF-8 characters. This
 * function attempts to truncate strings at word boundaries.
 *
 * NOTE: This function makes a best effort to apply some reasonable rules but
 * will not work well for the full range of unicode languages.
 *
 * @param   string  UTF-8 string to shorten.
 * @param   int     Maximum length of the result.
 * @param   string  If the string is shortened, add this at the end. Defaults to
 *                  horizontal ellipsis.
 * @return  string  A string with no more than the specified character length.
 *
 * @group utf8
 */
function phutil_utf8_shorten($string, $length, $terminal = "\xE2\x80\xA6") {
  // If the string has fewer bytes than the minimum length, we can return
  // it unmodified without doing any heavy lifting.
  if (strlen($string) <= $length) {
    return $string;
  }

  $string_v = phutil_utf8v_combined($string);
  $string_len = count($string_v);

  if ($string_len <= $length) {
    // If the string is already shorter than the requested length, simply return
    // it unmodified.
    return $string;
  }

  // NOTE: This is not complete, and there are many other word boundary
  // characters and reasonable places to break words in the UTF-8 character
  // space. For now, this gives us reasonable behavior for latin langauges. We
  // don't necessarily have access to PCRE+Unicode so there isn't a great way
  // for us to look up character attributes.

  // If we encounter these, prefer to break on them instead of cutting the
  // string off in the middle of a word.
  static $break_characters = array(
    ' '   => true,
    "\n"  => true,
    ';'   => true,
    ':'   => true,
    '['   => true,
    '('   => true,
    ','   => true,
    '-'   => true,
  );

  // If we encounter these, shorten to this character exactly without appending
  // the terminal.
  static $stop_characters = array(
    '.'   => true,
    '!'   => true,
    '?'   => true,
  );

  // Search backward in the string, looking for reasonable places to break it.
  $word_boundary = null;
  $stop_boundary = null;

  $terminal_len = phutil_utf8_strlen($terminal);

  // If we do a word break with a terminal, we have to look beyond at least the
  // number of characters in the terminal. If the terminal is longer than the
  // required length, we'll skip this whole block and return it on its own
  $terminal_area = $length - min($length, $terminal_len);
  for ($ii = $length; $ii >= 0; $ii--) {
    $c = $string_v[$ii];

    if (isset($break_characters[$c]) && ($ii <= $terminal_area)) {
      $word_boundary = $ii;
    } else if (isset($stop_characters[$c]) && ($ii < $length)) {
      $stop_boundary = $ii + 1;
      break;
    } else {
      if ($word_boundary !== null) {
        break;
      }
    }
  }

  if ($stop_boundary !== null) {
    // We found a character like ".". Cut the string there, without appending
    // the terminal.
    $string_part = array_slice($string_v, 0, $stop_boundary);
    return implode('', $string_part);
  }

  // If we didn't find any boundary characters or we found ONLY boundary
  // characters, just break at the maximum character length.
  if ($word_boundary === null || $word_boundary === 0) {
    $word_boundary = $terminal_area;
  }

  $string_part = array_slice($string_v, 0, $word_boundary);
  $string_part = implode('', $string_part);
  return $string_part.$terminal;
}


/**
 * Hard-wrap a block of UTF-8 text with embedded HTML tags and entities.
 *
 * @param   string An HTML string with tags and entities.
 * @return  list   List of hard-wrapped lines.
 * @group utf8
 */
function phutil_utf8_hard_wrap_html($string, $width) {
  $break_here = array();

  // Convert the UTF-8 string into a list of UTF-8 characters.
  $vector = phutil_utf8v($string);
  $len = count($vector);
  $char_pos = 0;
  for ($ii = 0; $ii < $len; ++$ii) {
    // An ampersand indicates an HTML entity; consume the whole thing (until
    // ";") but treat it all as one character.
    if ($vector[$ii] == '&') {
      do {
        ++$ii;
      } while ($vector[$ii] != ';');
      ++$char_pos;
    // An "<" indicates an HTML tag, consume the whole thing but don't treat
    // it as a character.
    } else if ($vector[$ii] == '<') {
      do {
        ++$ii;
      } while ($vector[$ii] != '>');
    } else {
      ++$char_pos;
    }

    // Keep track of where we need to break the string later.
    if ($char_pos == $width) {
      $break_here[$ii] = true;
      $char_pos = 0;
    }
  }

  $result = array();
  $string = '';
  foreach ($vector as $ii => $char) {
    $string .= $char;
    if (isset($break_here[$ii])) {
      $result[] = $string;
      $string = '';
    }
  }

  if (strlen($string)) {
    $result[] = $string;
  }

  return $result;
}

/**
  * Hard-wrap a block of UTF-8 text with no embedded HTML tags and entitites
  *
  * @param string A non HTML string
  * @param int Width of the hard-wrapped lines
  * @return list List of hard-wrapped lines.
  * @group utf8
  */
function phutil_utf8_hard_wrap($string, $width) {
  $result = array();

  $lines = phutil_split_lines($string, $retain_endings = false);
  foreach ($lines as $line) {

    // Convert the UTF-8 string into a list of UTF-8 characters.
    $vector = phutil_utf8v($line);

    $len = count($vector);
    $buffer = '';

    for ($ii = 1; $ii <= $len; ++$ii) {
      $buffer .= $vector[$ii - 1];
      if (($ii % $width) === 0) {
        $result[] = $buffer;
        $buffer = '';
      }
    }

    if (strlen($buffer)) {
      $result[] = $buffer;
    }
  }

  return $result;
}

/**
 * Convert a string from one encoding (like ISO-8859-1) to another encoding
 * (like UTF-8).
 *
 * This is primarily a thin wrapper around `mb_convert_encoding()` which checks
 * you have the extension installed, since we try to require the extension
 * only if you actually need it (i.e., you want to work with encodings other
 * than UTF-8).
 *
 * NOTE: This function assumes that the input is in the given source encoding.
 * If it is not, it may not output in the specified target encoding. If you
 * need to perform a hard conversion to UTF-8, use this function in conjunction
 * with @{function:phutil_utf8ize}. We can detect failures caused by invalid
 * encoding names, but `mb_convert_encoding()` fails silently if the
 * encoding name identifies a real encoding but the string is not actually
 * encoded with that encoding.
 *
 * @param string String to re-encode.
 * @param string Target encoding name, like "UTF-8".
 * @param string Source endocing name, like "ISO-8859-1".
 * @return string Input string, with converted character encoding.
 *
 * @group utf8
 *
 * @phutil-external-symbol function mb_convert_encoding
 */
function phutil_utf8_convert($string, $to_encoding, $from_encoding) {
  if (!$from_encoding) {
    throw new InvalidArgumentException(
      'Attempting to convert a string encoding, but no source encoding '.
      'was provided. Explicitly provide the source encoding.');
  }
  if (!$to_encoding) {
    throw new InvalidArgumentException(
      'Attempting to convert a string encoding, but no target encoding '.
      'was provided. Explicitly provide the target encoding.');
  }

  // Normalize encoding names so we can no-op the very common case of UTF8
  // to UTF8 (or any other conversion where both encodings are identical).
  $to_upper = strtoupper(str_replace('-', '', $to_encoding));
  $from_upper = strtoupper(str_replace('-', '', $from_encoding));
  if ($from_upper == $to_upper) {
    return $string;
  }

  if (!function_exists('mb_convert_encoding')) {
    throw new Exception(
      "Attempting to convert a string encoding from '{$from_encoding}' ".
      "to '{$to_encoding}', but the 'mbstring' PHP extension is not ".
      "available. Install mbstring to work with encodings other than ".
      "UTF-8.");
  }

  $result = @mb_convert_encoding($string, $to_encoding, $from_encoding);

  if ($result === false) {
    $message = error_get_last();
    if ($message) {
      $message = idx($message, 'message', 'Unknown error.');
    }
    throw new Exception(
      "String conversion from encoding '{$from_encoding}' to encoding ".
      "'{$to_encoding}' failed: {$message}");
  }

  return $result;
}


/**
 * Convert a string to title case in a UTF8-aware way. This function doesn't
 * necessarily do a great job, but the builtin implementation of ucwords() can
 * completely destroy inputs, so it just has to be better than that. Similar to
 * @{function:ucwords}.
 *
 * @param   string  UTF-8 input string.
 * @return  string  Input, in some semblance of title case.
 *
 * @group utf8
 */
function phutil_utf8_ucwords($str) {
  // NOTE: mb_convert_case() discards uppercase letters in words when converting
  // to title case. For example, it will convert "AAA" into "Aaa", which is
  // undesirable.

  $v = phutil_utf8v($str);
  $result = '';
  $last = null;

  $ord_a = ord('a');
  $ord_z = ord('z');
  foreach ($v as $c) {
    $convert = false;
    if ($last === null || $last === ' ') {
      $o = ord($c[0]);
      if ($o >= $ord_a && $o <= $ord_z) {
        $convert = true;
      }
    }

    if ($convert) {
      $result .= phutil_utf8_strtoupper($c);
    } else {
      $result .= $c;
    }

    $last = $c;
  }

  return $result;
}


/**
 * Convert a string to lower case in a UTF8-aware way. Similar to
 * @{function:strtolower}.
 *
 * @param   string  UTF-8 input string.
 * @return  string  Input, in some semblance of lower case.
 *
 * @group utf8
 *
 * @phutil-external-symbol function mb_convert_case
 */
function phutil_utf8_strtolower($str) {
  if (function_exists('mb_convert_case')) {
    return mb_convert_case($str, MB_CASE_LOWER, 'UTF-8');
  }

  static $map;
  if ($map === null) {
    $map = array_combine(
      range('A', 'Z'),
      range('a', 'z'));
  }

  return phutil_utf8_strtr($str, $map);
}


/**
 * Convert a string to upper case in a UTF8-aware way. Similar to
 * @{function:strtoupper}.
 *
 * @param   string  UTF-8 input string.
 * @return  string  Input, in some semblance of upper case.
 *
 * @group utf8
 *
 * @phutil-external-symbol function mb_convert_case
 */
function phutil_utf8_strtoupper($str) {
  if (function_exists('mb_convert_case')) {
    return mb_convert_case($str, MB_CASE_UPPER, 'UTF-8');
  }

  static $map;
  if ($map === null) {
    $map = array_combine(
      range('a', 'z'),
      range('A', 'Z'));
  }

  return phutil_utf8_strtr($str, $map);
}


/**
 * Replace characters in a string in a UTF-aware way. Similar to
 * @{function:strtr}.
 *
 * @param   string              UTF-8 input string.
 * @param   map<string, string> Map of characters to replace.
 * @return  string              Input with translated characters.
 *
 * @group utf8
 */
function phutil_utf8_strtr($str, array $map) {
  $v = phutil_utf8v($str);
  $result = '';
  foreach ($v as $c) {
    if (isset($map[$c])) {
      $result .= $map[$c];
    } else {
      $result .= $c;
    }
  }

  return $result;
}

/**
 * Determine if a given unicode character is a combining character or not.
 *
 * @param   string              A single unicode character.
 * @return  boolean             True or false.
 *
 * @group utf8
 */

function phutil_utf8_is_combining_character($character) {
  $components = phutil_utf8v_codepoints($character);

  // Combining Diacritical Marks (0300 - 036F).
  // Combining Diacritical Marks Supplement (1DC0 - 1DFF).
  // Combining Diacritical Marks for Symbols (20D0 - 20FF).
  // Combining Half Marks (FE20 - FE2F).

  foreach ($components as $codepoint) {
    if ($codepoint >= 0x0300 && $codepoint <= 0x036F ||
         $codepoint >= 0x1DC0 && $codepoint <= 0x1DFF ||
         $codepoint >= 0x20D0 && $codepoint <= 0x20FF ||
         $codepoint >= 0xFE20 && $codepoint <= 0xFE2F) {
      return true;
    }
  }

  return false;
}

/**
 * Split a UTF-8 string into an array of characters. Combining characters
 * are not split.
 *
 * @param string A valid utf-8 string.
 * @return list  A list of characters in the string.
 *
 * @group utf8
 */

function phutil_utf8v_combined($string) {
  $components = phutil_utf8v($string);
  $array_length = count($components);

  // If the first character in the string is a combining character,
  // prepend a space to the string.
  if (
    $array_length > 0 &&
    phutil_utf8_is_combining_character($components[0])) {
    $string = ' '.$string;
    $components = phutil_utf8v($string);
    $array_length++;
  }

  for ($index = 1; $index < $array_length; $index++) {
    if (phutil_utf8_is_combining_character($components[$index])) {
      $components[$index - 1] =
        $components[$index - 1].$components[$index];

      unset($components[$index]);
      $components = array_values($components);

      $index --;
      $array_length = count($components);
    }
  }

  return $components;
}
