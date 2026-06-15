<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Encode HTML entities in a string.
 * @param  mixed  $s String to encode.
 * @return string    Encoded string.
 */
function esc(mixed $s): string
{
    if (!is_scalar($s)) return '';
    if ($s === 0) $s = '0';
    else $s = (string) $s;
    return htmlentities((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'utf-8');
}

/**
 * <USER>
 * Echo the encoded HTML using <esc()>.
 * @param  mixed  $s String to escape.
 */
function _esc(mixed $s): void
{
    echo esc($s);
}

/**
 * <USER>
 * Decode HTML entities from a string.
 * @param  mixed  $s String to decode.
 * @return string    Decoded string.
 */
function unesc(mixed $s): string
{
    if (!is_scalar($s)) return '';
    if ($s !== 0 && !($s = (string) $s)) return '';
    return trim(html_entity_decode((string) ($s ?: ''), ENT_QUOTES | ENT_SUBSTITUTE, 'utf-8'));
}

/**
 * <USER>
 * Echo the decoded HTML using <unesc()>.
 * @param  mixed  $s String to decode.
 */
function _unesc(mixed $s): void
{
    echo unesc($s);
}

/**
 * <USER>
 * Remove block tags from HTML string.
 * @param  string $str String to clean.
 * @return string      String cleaned.
 */
function strip_blocks(string $str): string
{
    $tags = [
        'address', 'article', 'aside', 'blockquote', 'canvas',
        'fieldset', 'figcaption', 'figure', 'footer', 'form',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dd', 'div', 'dl', 'dt',
        'header', 'hr', 'main', 'nav', 'noscript',
        'ol', 'ul', 'li', 'p', 'pre', 'section', 'video',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    foreach ($tags as $tag) $str = preg_replace('/<\/' . $tag . '>\s*<' . $tag . '>/imsU', '<br>', $str);
    foreach ($tags as $tag) {
        $str = preg_replace('/<' . $tag . '(\s[^>]*)?>/i', '', $str);
        $str = preg_replace('/<\/' . $tag . '>/i', '', $str);
    }

    $str = preg_replace('/<br\s*\/>/i', '<br>', $str);
    while(preg_match($re = '/<br>\s*<br>/i', $str)) $str = preg_replace($re, '<br>', $str);

    return $str;
}

/**
 * <USER>
 * Check if the string contains only simple characters, excluding special
 * quotes, emojis, etc.
 *   - Unicode letters with accents;
 *   - Numbers;
 *   - Everything else should be simple ASCII.
 * @param  mixed   $str Probably a string.
 * @return bool         Is string safe or not.
 */
function is_str_safe(mixed $str): bool
{
    if (!is_string($str)) return false;
    return (bool) preg_match('/^[\p{L}\p{N}\x20-\x7E]*$/u', $str);
}

/**
 * <USER>
 * Returns given string after replacement of key-value $params.
 * @param  string $str       String to process.
 * @param  array  $params    Key-Value array of params to replace.
 * @param  string $keyFormat String defining the format of the keys as
 *                           specified in input string.
 * @return string            String processed.
 */
function replace_params(string $str, array $params, string $keyFormat = '{%s}'): string
{
    foreach ($params as $k => $v) $str = str_replace(sprintf($keyFormat, $k), $v, $str);
    return $str;
}

/**
 * <USER>
 * Check if the string seems to be a valid Base64 encoded string.
 * @param  string $s String to be checked.
 * @return bool      Does it seems to be a Base64 encoded string or not?
 */
function seems_base64(string $s): bool
{
    return base64_encode(base64_decode($s, true)) === $s;
}

/**
 * <USER>
 * Truncate a text word by word.
 * @param  string           $str       Text to truncate.
 * @param  int|integer      $maxLength Maximum length of the returned text.
 * @param  string|bool|null $ellipsis  Add an ellipsis if the text was
 *                                     truncated. If null, nothing is appent.
 *                                     If true, the three-dots will be used.
 *                                     Else, the given string will be appent.
 * @return string                      The truncated text.
 */
function truncate_text(?string $str, int $maxLength = 200, null | bool | string $ellipsis = null): string
{
    $parts = preg_split('/([\s\n\r]+)/', $str ?: '', -1, PREG_SPLIT_DELIM_CAPTURE);
    $length = 0;
    for ($i = 0, $nbParts = count($parts); $i < $nbParts; ++$i) {
        $length += strlen($parts[$i]);
        if ($length > $maxLength) break;
    }
    $truncated = trim(implode(array_slice($parts, 0, $i)));
    if ($truncated !== $str && $ellipsis !== null) $truncated .= $ellipsis === true ? unesc('&hellip;') : $ellipsis;
    return $truncated;
}

/**
 * <USER>
 * Truncate a string without taking count of words.
 * @param  string           $str       Text to truncate.
 * @param  int|integer      $maxLength Maximum length of the returned text.
 * @param  string|bool|null $ellipsis  Add an ellipsis if the text was
 *                                     truncated. If null, nothing is appent.
 *                                     If true, the three-dots will be used.
 *                                     Else, the given string will be appent.
 * @return string                      The truncated text.
 */
function truncate_str(?string $str, int $maxLength = 200, null | bool | string $ellipsis = null): string
{
    $truncated = substr($str, 0, $maxLength);
    if ($truncated !== $str && $ellipsis !== null) $truncated .= $ellipsis === true ? unesc('&hellip;') : $ellipsis;
    return $truncated;
}

/**
 * <USER>
 * Sanitize a string, removing accents and special character, and trimming it.
 * @param  string      $str           String to sanitize.
 * @param  string      $separator     Words separator (replacing all non
 *                                    alpha-numerical character).
 * @param  bool        $keepExtension Keep a file's extension.
 * @param  int|integer $maxLength     Shorten the string to a maximum length.
 * @return string                     The sanitized string.
 */
function sanitize_string(string $str, string $separator = '-', bool $keepExtension = false, int $maxLength = 32): string
{
    $str = remove_accents($str);
    $str = strtolower($str);

    if (!$keepExtension) $str = str_replace('.', '-', $str);
    else while (substr_count($str, '.') > 1) $str = preg_replace('/\./', $separator, $str, 1);

    $str = preg_replace('/[^a-z0-9\.]+/', $separator, $str);
    $str = preg_replace('/' . preg_quote($separator) . '+\./', '.', $str);

    if ($keepExtension) {
        $part = preg_replace('/^(.*)\.[^.\/]+$/', '$1', $str);
        $ext = str_replace('jpeg', 'jpg', preg_match('/\.([^.\/]+)$/', $str, $m) ? '.' . $m[1] : '');
        $str = trim($str, $separator);
        $str = substr($part, 0, $maxLength);
        $str = trim($str, $separator);
        $str .= $ext;
    } else {
        $str = trim($str, $separator);
        $str = substr($str, 0, $maxLength);
        $str = trim($str, $separator);
    }

    return $str;
}

/**
 * <USER>
 * Convert some HTML to its pseudo equivalent Plain Text.
 * @param  string $html Probably some HTML code.
 * @return string       Something representing the HTML code in text.
 */
function html_to_text(string $html): string
{
    $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $html);
    $html = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])>/i', "\n", $html);
    $html = preg_replace('/<(p|div|li|tr|h[1-6])[^>]*>/i', "\n", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'utf-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/**
 * <USER>
 * Clean and cast an unknown value to a float number. Returns null if the value
 * is empty or totally invalid.
 * @param  mixed      $value Input value.
 * @return float|null        Floating value, or null.
 */
function cast_float(mixed $value): ?float
{
    if (!is_scalar($value)) return null;
    $value = (string) $value;
    if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace(',', '', $value);
    $value = str_replace(',', '.', $value);
    if (!($value = preg_replace('/[^0-9.-]/', '', $value))) return null;
    $value = ($value[0] === '-' ? '-' : '') . str_replace('-', '', $value);
    if ($value === '-') return null;
    return (float) implode('.', explode('.', $value, 2));
}

/**
 * <USER>
 * Cast some scalar value to a predefined format. Cast methods availables are:
 *   - number
 *   - int
 *   - percentage
 *   - hours
 * @param  mixed  $something Value to cast.
 * @param  string $cast      Cast method.
 * @return string            Casted value.
 */
function format(mixed $something, string $cast): string
{
    if ($cast === 'number' || $cast === 'int') {
        return number_format($something, $cast === 'int' ? 0 : 2, '.', ',');
    } else if ($cast === 'percentage') {
        return format($something, 'number') . '%';
    } else if ($cast === 'hours') {
        return format($something / 3600, 'number') . 'h';
    }

    return $something;
}

/**
 * <USER>
 * Format a number.
 * @param  mixed  $something Value to cast.
 * @return string            Casted value.
 */
function format_number(mixed $something): string
{
    return format($something, 'number');
}

/**
 * <USER>
 * Format a number.
 * @param  mixed  $something Value to cast.
 * @return string            Casted value.
 */
function format_int(mixed $something): string
{
    return format($something, 'int');
}

/**
 * <USER>
 * Replace a bucket of strings, based on a Key/Value array.
 * If the key seems to be a regex (aka starting and ending with a /,
 * with optional ending modifiers), a preg_replace will be used instead
 * of a str_replace.
 * @param  array  $replacements Pattern/Value replacements array.
 * @param  string $haystack     Input string.
 * @return string               Output string.
 */
function replace(array $replacements, string $haystack): string
{
    foreach ($replacements as $pattern => $replacement) {
        if (seems_regex($pattern)) {
            $haystack = preg_replace($pattern, $replacement, $haystack);
        } else {
            $haystack = str_replace($pattern, $replacement, $haystack);
        }
    }
    return $haystack;
}

/**
 * <USER>
 * Replace the last occurence of a substring.
 * @param  string $from Substring to replace.
 * @param  string $to   Substring replacement.
 * @param  string $str  Full string.
 * @return string       Transformed string.
 */
function replace_last_occurence(string $from, string $to, string $str): string
{
    if (($pos = strrpos($str, $from)) === false) return $str;
    return substr_replace($str, $to, $pos, strlen($from));
}

/**
 * <USER>
 * Check a string and returns true if it seems to contains standard regex
 * delimiters with optional modifieds.
 * @param  string $str String which could be a regex.
 * @return bool        True if this is a regex. Else, false.
 */
function seems_regex(string $str): bool
{
    return (bool) preg_match('/^\/.*\/[a-z]*$/i', $str);
}

/**
 * <USER>
 * Convert a string with optional joker stars, to a regex.
 * @param  string $str Input string.
 * @return string      Output regex string.
 */
function joker_to_regex(string $str): string
{
    return '/^' . str_replace('Oo__joker__oO', '.*', preg_quote(str_replace('*', 'Oo__joker__oO', $str), '/')) . '$/';
}

/**
 * <USER>
 * Enforce a string is in UTF8.
 * @param  string $str String to validate.
 * @return string      Converted string.
 */
function enforce_utf8_str(string $str, ?string $srcEncoding = null): string
{
    if (!$str) return '';
    if (mb_check_encoding($str, 'UTF-8')) return $str;
    return mb_convert_encoding($str, 'UTF-8', $srcEncoding ?: 'auto');
}

/**
 * <USER>
 * Check a string and returns true if it seems to contains some
 * UTF-8 character.
 * @param  string $str String to verify.
 * @return bool        True if it seems to be a UTF-8 string. Else, false.
 */
function seems_utf8(string $str): bool
{
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($str[$i]);
        if ($c < 0x80) $n = 0; // 0bbbbbbb
        elseif (($c & 0xE0) == 0xC0) $n = 1; // 110bbbbb
        elseif (($c & 0xF0) == 0xE0) $n = 2; // 1110bbbb
        elseif (($c & 0xF8) == 0xF0) $n = 3; // 11110bbb
        elseif (($c & 0xFC) == 0xF8) $n = 4; // 111110bb
        elseif (($c & 0xFE) == 0xFC) $n = 5; // 1111110b
        else return false; // Does not match any model
        for ($j = 0; $j < $n; $j++) { // n bytes matching 10bbbbbb follow ?
            if ((++$i == $len) || ((ord($str[$i]) & 0xC0) != 0x80)) return false;
        }
    }
    return true;
}

/**
 * <USER>
 * Remove accents from a UTF-8 or ISO-8859-1 string.
 * @param  string $str String to process.
 * @return string      String cleaned from accents.
 */
function remove_accents(string $str): string
{
    if (!preg_match('/[\x80-\xff]/', $str)) return $str;

    if (seems_utf8($str)) {
        $chars = [

            // Decompositions for Latin-1 Supplement
            chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
            chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
            chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
            chr(195) . chr(135) => 'C', chr(195) . chr(136) => 'E',
            chr(195) . chr(137) => 'E', chr(195) . chr(138) => 'E',
            chr(195) . chr(139) => 'E', chr(195) . chr(140) => 'I',
            chr(195) . chr(141) => 'I', chr(195) . chr(142) => 'I',
            chr(195) . chr(143) => 'I', chr(195) . chr(145) => 'N',
            chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
            chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
            chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
            chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
            chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
            chr(195) . chr(159) => 's', chr(195) . chr(160) => 'a',
            chr(195) . chr(161) => 'a', chr(195) . chr(162) => 'a',
            chr(195) . chr(163) => 'a', chr(195) . chr(164) => 'a',
            chr(195) . chr(165) => 'a', chr(195) . chr(167) => 'c',
            chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
            chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
            chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
            chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
            chr(195) . chr(177) => 'n', chr(195) . chr(178) => 'o',
            chr(195) . chr(179) => 'o', chr(195) . chr(180) => 'o',
            chr(195) . chr(181) => 'o', chr(195) . chr(182) => 'o',
            chr(195) . chr(182) => 'o', chr(195) . chr(185) => 'u',
            chr(195) . chr(186) => 'u', chr(195) . chr(187) => 'u',
            chr(195) . chr(188) => 'u', chr(195) . chr(189) => 'y',
            chr(195) . chr(191) => 'y',

            // Decompositions for Latin Extended-A
            chr(196) . chr(128) => 'A',  chr(196) . chr(129) => 'a',
            chr(196) . chr(130) => 'A',  chr(196) . chr(131) => 'a',
            chr(196) . chr(132) => 'A',  chr(196) . chr(133) => 'a',
            chr(196) . chr(134) => 'C',  chr(196) . chr(135) => 'c',
            chr(196) . chr(136) => 'C',  chr(196) . chr(137) => 'c',
            chr(196) . chr(138) => 'C',  chr(196) . chr(139) => 'c',
            chr(196) . chr(140) => 'C',  chr(196) . chr(141) => 'c',
            chr(196) . chr(142) => 'D',  chr(196) . chr(143) => 'd',
            chr(196) . chr(144) => 'D',  chr(196) . chr(145) => 'd',
            chr(196) . chr(146) => 'E',  chr(196) . chr(147) => 'e',
            chr(196) . chr(148) => 'E',  chr(196) . chr(149) => 'e',
            chr(196) . chr(150) => 'E',  chr(196) . chr(151) => 'e',
            chr(196) . chr(152) => 'E',  chr(196) . chr(153) => 'e',
            chr(196) . chr(154) => 'E',  chr(196) . chr(155) => 'e',
            chr(196) . chr(156) => 'G',  chr(196) . chr(157) => 'g',
            chr(196) . chr(158) => 'G',  chr(196) . chr(159) => 'g',
            chr(196) . chr(160) => 'G',  chr(196) . chr(161) => 'g',
            chr(196) . chr(162) => 'G',  chr(196) . chr(163) => 'g',
            chr(196) . chr(164) => 'H',  chr(196) . chr(165) => 'h',
            chr(196) . chr(166) => 'H',  chr(196) . chr(167) => 'h',
            chr(196) . chr(168) => 'I',  chr(196) . chr(169) => 'i',
            chr(196) . chr(170) => 'I',  chr(196) . chr(171) => 'i',
            chr(196) . chr(172) => 'I',  chr(196) . chr(173) => 'i',
            chr(196) . chr(174) => 'I',  chr(196) . chr(175) => 'i',
            chr(196) . chr(176) => 'I',  chr(196) . chr(177) => 'i',
            chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
            chr(196) . chr(180) => 'J',  chr(196) . chr(181) => 'j',
            chr(196) . chr(182) => 'K',  chr(196) . chr(183) => 'k',
            chr(196) . chr(184) => 'k',  chr(196) . chr(185) => 'L',
            chr(196) . chr(186) => 'l',  chr(196) . chr(187) => 'L',
            chr(196) . chr(188) => 'l',  chr(196) . chr(189) => 'L',
            chr(196) . chr(190) => 'l',  chr(196) . chr(191) => 'L',
            chr(197) . chr(128) => 'l',  chr(197) . chr(129) => 'L',
            chr(197) . chr(130) => 'l',  chr(197) . chr(131) => 'N',
            chr(197) . chr(132) => 'n',  chr(197) . chr(133) => 'N',
            chr(197) . chr(134) => 'n',  chr(197) . chr(135) => 'N',
            chr(197) . chr(136) => 'n',  chr(197) . chr(137) => 'N',
            chr(197) . chr(138) => 'n',  chr(197) . chr(139) => 'N',
            chr(197) . chr(140) => 'O',  chr(197) . chr(141) => 'o',
            chr(197) . chr(142) => 'O',  chr(197) . chr(143) => 'o',
            chr(197) . chr(144) => 'O',  chr(197) . chr(145) => 'o',
            chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
            chr(197) . chr(148) => 'R',  chr(197) . chr(149) => 'r',
            chr(197) . chr(150) => 'R',  chr(197) . chr(151) => 'r',
            chr(197) . chr(152) => 'R',  chr(197) . chr(153) => 'r',
            chr(197) . chr(154) => 'S',  chr(197) . chr(155) => 's',
            chr(197) . chr(156) => 'S',  chr(197) . chr(157) => 's',
            chr(197) . chr(158) => 'S',  chr(197) . chr(159) => 's',
            chr(197) . chr(160) => 'S',  chr(197) . chr(161) => 's',
            chr(197) . chr(162) => 'T',  chr(197) . chr(163) => 't',
            chr(197) . chr(164) => 'T',  chr(197) . chr(165) => 't',
            chr(197) . chr(166) => 'T',  chr(197) . chr(167) => 't',
            chr(197) . chr(168) => 'U',  chr(197) . chr(169) => 'u',
            chr(197) . chr(170) => 'U',  chr(197) . chr(171) => 'u',
            chr(197) . chr(172) => 'U',  chr(197) . chr(173) => 'u',
            chr(197) . chr(174) => 'U',  chr(197) . chr(175) => 'u',
            chr(197) . chr(176) => 'U',  chr(197) . chr(177) => 'u',
            chr(197) . chr(178) => 'U',  chr(197) . chr(179) => 'u',
            chr(197) . chr(180) => 'W',  chr(197) . chr(181) => 'w',
            chr(197) . chr(182) => 'Y',  chr(197) . chr(183) => 'y',
            chr(197) . chr(184) => 'Y',  chr(197) . chr(185) => 'Z',
            chr(197) . chr(186) => 'z',  chr(197) . chr(187) => 'Z',
            chr(197) . chr(188) => 'z',  chr(197) . chr(189) => 'Z',
            chr(197) . chr(190) => 'z',  chr(197) . chr(191) => 's',

            chr(226) . chr(130) . chr(172) => 'E', // Euro Sign
            chr(194) . chr(163) => '', // GBP (Pound) Sign

        ];

        $str = strtr($str, $chars);
    } else {
        // Assume ISO-8859-1 if not UTF-8
        $chars['in'] = chr(128) . chr(131) . chr(138) . chr(142) . chr(154)
                     . chr(158) . chr(159) . chr(162) . chr(165) . chr(181)
                     . chr(192) . chr(193) . chr(194) . chr(195) . chr(196)
                     . chr(197) . chr(199) . chr(200) . chr(201) . chr(202)
                     . chr(203) . chr(204) . chr(205) . chr(206) . chr(207)
                     . chr(209) . chr(210) . chr(211) . chr(212) . chr(213)
                     . chr(214) . chr(216) . chr(217) . chr(218) . chr(219)
                     . chr(220) . chr(221) . chr(224) . chr(225) . chr(226)
                     . chr(227) . chr(228) . chr(229) . chr(231) . chr(232)
                     . chr(233) . chr(234) . chr(235) . chr(236) . chr(237)
                     . chr(238) . chr(239) . chr(241) . chr(242) . chr(243)
                     . chr(244) . chr(245) . chr(246) . chr(248) . chr(249)
                     . chr(250) . chr(251) . chr(252) . chr(253) . chr(255);

        $chars['out'] = "EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy";
        $str = strtr($str, $chars['in'], $chars['out']);
        $dbl = [
            'in'  => [ chr(140), chr(156), chr(198), chr(208), chr(222),
                       chr(223), chr(230), chr(240), chr(254) ],
            'out' => [ 'OE', 'oe', 'AE', 'DH', 'TH',
                       'ss', 'ae', 'dh', 'th' ],
        ];

        $str = str_replace($dbl['in'], $dbl['out'], $str);
    }

    return $str;
}

/**
 * <USER>
 * Returns a bolded unicode string.
 * @param  string $str Input string.
 * @return string      The same string (if all character were available) bolded.
 */
function unicode_bold(string $str): string
{
    $chars = [
        'A' => 120276, 'a' => 120302, 'B' => 120277, 'b' => 120303, 'C' => 120278, 'c' => 120304, 'D' => 120279, 'd' => 120305,
        'E' => 120280, 'e' => 120306, 'F' => 120281, 'f' => 120307, 'G' => 120282, 'g' => 120308, 'H' => 120283, 'h' => 120309,
        'I' => 120284, 'i' => 120310, 'J' => 120285, 'j' => 120311, 'K' => 120286, 'k' => 120312, 'L' => 120287, 'l' => 120313,
        'M' => 120288, 'm' => 120314, 'N' => 120289, 'n' => 120315, 'O' => 120290, 'o' => 120316, 'P' => 120291, 'p' => 120317,
        'Q' => 120292, 'q' => 120318, 'R' => 120293, 'r' => 120319, 'S' => 120294, 's' => 120320, 'T' => 120295, 't' => 120321,
        'U' => 120296, 'u' => 120322, 'V' => 120297, 'v' => 120323, 'W' => 120298, 'w' => 120324, 'X' => 120299, 'x' => 120325,
        'Y' => 120300, 'y' => 120326, 'Z' => 120301, 'z' => 120327, '0' => 120812, '1' => 120813, '2' => 120814, '3' => 120815,
        '4' => 120816, '5' => 120817, '6' => 120818, '7' => 120819, '8' => 120820, '9' => 120821,
    ];

    $unconverted = [ ' ', '-', '_', '/' ];

    $expr = preg_quote(implode('', array_keys($chars)) . implode('', $unconverted), '/');
    $str = preg_replace('/[^' . $expr . ']/', '', $str);
    $bolded = '';
    for ($i = 0, $len = strlen($str); $i < $len; $i++) {
        $bolded .= in_array($str[$i], $unconverted) ? $str[$i] : mb_chr($chars[$str[$i]]);
    }
    return $bolded;
}

/**
 * <USER>
 * Create an almost prononciable word, switching between vowels and consonants.
 * @param  int    $minSyllables     Minimum number of syllables.
 * @param  int    $maxSyllables     Maximum number of syllables.
 * @param  bool   $excludeConfusing Exclude confusing letters or not.
 * @return string                   Generated word.
 */
function create_word(int $minSyllables = 7, int $maxSyllables = 9, bool $excludeConfusing = false): string
{
    $syllables = $minSyllables;
    if ($minSyllables !== $maxSyllables) $syllables = mt_rand($minSyllables, $maxSyllables);

    $w = [];
    $offset = mt_rand(0, 1);
    for ($i = $offset; $i < $syllables + $offset; $i++) {
        $s = null;
        while ($s === null || in_array($s, $w)) $s = get_letter($i % 2 === 0 ? 'vowel' : 'consonant', $excludeConfusing);
        $w[] = $s;
    }
    return implode('', $w);
}

/**
 * <USER>
 * Get a random letter, with optional choice between a vowel or a consonant.
 * @param  string|null $type             Can be 'vowel', 'consonant' or null
 *                                       for both.
 * @param  bool        $excludeConfusing Exclude confusing letters or not.
 * @return string                        The letter.
 */
function get_letter(?string $type = null, bool $excludeConfusing = false): string
{
    $vowels = $excludeConfusing ? 'aeuy' : 'aeiouy';
    $consonants = $excludeConfusing ? 'bcdfghjkmnpqrstvwxz' : 'bcdfghjklmnpqrstvwxz';
    $alphabet = $vowels . $consonants;
    if ($type === 'vowel') $alphabet = $vowels;
    else if ($type === 'consonant') $alphabet = $consonants;
    return $alphabet[mt_rand(0, strlen($alphabet) - 1)];
}

/**
 * <USER>
 * Create a fake sentence.
 * @param  int     $minWords     Minimum number of words in the sentence.
 * @param  int     $maxWords     Maximum number of words in the sentence.
 * @param  int     $minSyllables Minimum number of syllables.
 * @param  int     $maxSyllables Maximum number of syllables.
 * @param  bool    $endDot       Put a final dot or not?
 * @return string                Generated sentence.
 */
function create_sentence(int $minWords = 5, int $maxWords = 35, int $minSyllables = 7, int $maxSyllables = 9, bool $endDot = true): string
{
    $words = [];
    $len = mt_rand($minWords, $maxWords);
    for ($i = 0; $i < $len; $i++) {
        $comma = (($len > 4) && ($i > 0) && (mt_rand(1, 4) === 1)) ? ', ' : '';
        $words[] = $comma . create_word(minSyllables: $minSyllables, maxSyllables: $maxSyllables);
    }
    return ucfirst(str_replace(' ,', ',', implode(' ', $words))) . ($endDot ? '.' : '');
}

/**
 * <USER>
 * Create a paragraph containing some sentences.
 * @param  int     $minSentences Minimum number of sentences.
 * @param  int     $maxSentences Maximum number of sentences.
 * @return string                Generated paragraph.
 */
function create_paragraph(int $minSentences = 3, int $maxSentences = 11): string
{
    $sentences = [];
    $len = mt_rand($minSentences, $maxSentences);
    for ($i = 0; $i < $len; $i++) $sentences[] = create_sentence();
    return implode(' ', $sentences);
}

/**
 * <USER>
 * Create some paragraphes, returned as an array or a HTML string.
 * @param  int     $minParagraphes Minimum number of paragraphes.
 * @param  int     $maxParagraphes Maximum number of paragraphes.
 * @param  bool    $html           Return as an HTML string or not.
 * @return string                  Generated paragraphes.
 */
function create_paragraphes(int $minParagraphes = 1, int $maxParagraphes = 5, bool $html = false): array | string
{
    $paragraphes = [];
    $len = mt_rand($minParagraphes, $maxParagraphes);
    for ($i = 0; $i < $len; $i++) $paragraphes[] = create_paragraph();
    return $html ? '<p>' . implode('</p><p>', $paragraphes) . '</p>' : $paragraphes;
}

/**
 * <USER>
 * Get some random western's actor name.
 * @param  string|null $gender Gender (male, female). Default: null = random.
 * @return object              Object containing the gender, the full name and
 *                             the name parts.
 */
function get_random_name(?string $gender = null): object
{
    $female = [ "Olivia", "Emma", "Charlotte", "Amelia", "Sophia", "Mia", "Isabella", "Ava", "Evelyn", "Luna", "Harper", "Sofia", "Camila", "Eleanor", "Elizabeth", "Violet", "Scarlett", "Emily", "Hazel", "Lily", "Gianna", "Aurora", "Penelope", "Aria", "Nora", "Chloe", "Ellie", "Mila", "Avery", "Layla", "Abigail", "Ella", "Isla", "Eliana", "Nova", "Madison", "Zoe", "Ivy", "Grace", "Lucy", "Willow", "Emilia", "Riley", "Naomi", "Victoria", "Stella", "Elena", "Hannah", "Valentina", "Maya", "Zoey", "Delilah", "Leah", "Lainey", "Lillian", "Paisley", "Genesis", "Madelyn", "Sadie", "Sophie", "Leilani", "Addison", "Natalie", "Josephine", "Alice", "Ruby", "Claire", "Kinsley", "Everly", "Emery", "Adeline", "Kennedy", "Maeve", "Audrey", "Autumn", "Athena", "Eden", "Iris", "Anna", "Eloise", "Jade", "Maria", "Caroline", "Brooklyn", "Quinn", "Aaliyah", "Vivian", "Liliana", "Gabriella", "Hailey", "Sarah", "Savannah", "Cora", "Madeline", "Natalia", "Ariana", "Lydia", "Lyla", "Clara", "Allison" ];
    $male = [ "Liam", "Noah", "Oliver", "James", "Elijah", "Mateo", "Theodore", "Henry", "Lucas", "William", "Benjamin", "Levi", "Sebastian", "Jack", "Ezra", "Michael", "Daniel", "Leo", "Owen", "Samuel", "Hudson", "Alexander", "Asher", "Luca", "Ethan", "John", "David", "Jackson", "Joseph", "Mason", "Luke", "Matthew", "Julian", "Dylan", "Elias", "Jacob", "Maverick", "Gabriel", "Logan", "Aiden", "Thomas", "Isaac", "Miles", "Grayson", "Santiago", "Anthony", "Wyatt", "Carter", "Jayden", "Ezekiel", "Caleb", "Cooper", "Josiah", "Charles", "Christopher", "Isaiah", "Nolan", "Cameron", "Nathan", "Joshua", "Kai", "Waylon", "Angel", "Lincoln", "Andrew", "Roman", "Adrian", "Aaron", "Wesley", "Ian", "Thiago", "Axel", "Brooks", "Bennett", "Weston", "Rowan", "Christian", "Theo", "Beau", "Eli", "Silas", "Jonathan", "Ryan", "Leonardo", "Walker", "Jaxon", "Micah", "Everett", "Robert", "Enzo", "Parker", "Jeremiah", "Jose", "Colton", "Luka", "Easton", "Landon", "Jordan", "Amir", "Gael" ];
    $lastnames = [ "Smith", "Johnson", "Williams", "Brown", "Jones", "Garcia", "Miller", "Davis", "Rodriguez", "Martinez", "Hernandez", "Lopez", "Gonzales", "Wilson", "Anderson", "Thomas", "Taylor", "Moore", "Jackson", "Martin", "Lee", "Perez", "Thompson", "White", "Harris", "Sanchez", "Clark", "Ramirez", "Lewis", "Robinson", "Walker", "Young", "Allen", "King", "Wright", "Scott", "Torres", "Nguyen", "Hill", "Flores", "Green", "Adams", "Nelson", "Baker", "Hall", "Rivera", "Campbell", "Mitchell", "Carter", "Roberts", "Gomez", "Phillips", "Evans", "Turner", "Diaz", "Parker", "Cruz", "Edwards", "Collins", "Reyes", "Stewart", "Morris", "Morales", "Murphy", "Cook", "Rogers", "Gutierrez", "Ortiz", "Morgan", "Cooper", "Peterson", "Bailey", "Reed", "Kelly", "Howard", "Ramos", "Kim", "Cox", "Ward", "Richardson", "Watson", "Brooks", "Chavez", "Wood", "James", "Bennet", "Gray", "Mendoza", "Ruiz", "Hughes", "Price", "Alvarez", "Castillo", "Sanders", "Patel", "Myers", "Long", "Ross", "Foster", "Jimenez" ];

    if ($gender === null || !in_array($gender, [ 'male', 'female' ])) $gender = mt_rand(0, 1) ? 'female' : 'male';

    return (object) [
        'gender'    => $gender,
        'firstname' => $firstname = ${$gender[mt_rand(0, count($$gender) - 1)]},
        'lastname'  => $lastname = $lastnames[mt_rand(0, count($lastnames) - 1)],
        'name'      => $firstname . ' ' . $lastname,
    ];
}

/**
 * <USER>
 * Tries to sanitize and normalize a full name (John Doe).
 * @param  string $name Name.
 * @return string       Sanitized name.
 */
function sanitize_full_name(string $name): string
{
    $name = str_replace([ '_', '+', '.' ], ' ', $name);
    if (!($name = trim(trim(trim($name), ',;')))) return '';
    $name = str_replace([ "’", "`", "´" ], "'", $name);
    $name = mb_strtolower($name, 'UTF-8');

    $lowerParticles = [ 'de', 'du', 'des', 'd', 'la', 'le', 'les', 'van', 'von', 'der', 'den', 'di', 'da', 'del', 'della' ];
    $words = preg_split('/\s+/', $name);
    $result = [];

    foreach ($words as $index => $word) {
        $hyphenParts = explode('-', $word);
        $newHyphenParts = [];
        foreach ($hyphenParts as $partIndex => $part) {
            if (strpos($part, "'") !== false) { // Simple quotes (O'Connor, D'Artagnan)
                [$prefix, $suffix] = explode("'", $part, 2);
                $prefix = in_array($prefix, $lowerParticles) && $index !== 0
                    ? $prefix
                    : mb_convert_case($prefix, MB_CASE_TITLE, 'UTF-8');
                $suffix = mb_convert_case($suffix, MB_CASE_TITLE, 'UTF-8');
                $newHyphenParts[] = $prefix . "'" . $suffix;
                continue;
            }
            if (in_array($part, $lowerParticles) && $index !== 0) { // Simple particle
                $newHyphenParts[] = $part;
                continue;
            }
            if (preg_match('/^mc(.+)/', $part, $m)) { // McDonald
                $newHyphenParts[] = 'Mc' . mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
                continue;
            }
            if (preg_match('/^mac(.{2,})/', $part, $m)) { // MacArthur
                $newHyphenParts[] = 'Mac' . mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
                continue;
            }
            if (preg_match('/^(du|de|van|von)([a-z])/', $part, $m) && !str_starts_with($part, 'develop')) { // DuPont
                $newHyphenParts[] = ucfirst($m[1]) . mb_strtoupper($m[2], 'UTF-8') . mb_substr($part, 3);
                continue;
            }
            $newHyphenParts[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        }
        $result[] = implode('-', $newHyphenParts);
    }

    return implode(' ', $result);
}

/**
 * <USER>
 * Remove useless spaces and comments in some HTML code.
 * @param  string $html Unminified HTML code.
 * @return string       Minified HTML code.
 */
function minify_html(string $html): string
{
    return preg_replace([
        '/\>[^\S ]+/s',      // Strip whitespaces after tags, except space
        '/[^\S ]+\</s',      // Strip whitespaces before tags, except space
        '/(\s)+/s',          // Shorten multiple whitespace sequences
        '/<!--(.|\s)*?-->/', // Remove HTML comments
    ], [
        '>',
        '<',
        '\\1',
        '',
    ], $html);
}

/**
 * <USER>
 * Beautify an HTML string, using the third-party Beautify_Html class.
 * @param  string $html Ugly HTML code.
 * @return string       Beautiful HTML code.
 */
function beautify_html(string $html, array $opts = []): string
{
    $opts = (object) array_merge([
        'indent'            => 2,
        'char'              => ' ',
        'unformatted'       => [ 'code', 'pre' ],
        'preserve_newlines' => false,
    ], $opts);

    return (new Microbe_Beautify_Html([
        'indent_inner_html'     => true,
        'indent_size'           => $opts->indent,
        'indent_char'           => $opts->char,
        'indent_scripts'        => 'normal',
        'wrap_line_length'      => 32786,
        'unformatted'           => $opts->unformatted,
        'preserve_newlines'     => $opts->preserve_newlines,
        'max_preserve_newlines' => 32786,
    ]))->beautify($html);
}

/**
 * <USER>
 * Split a string between values, by default using commas and/or semicolons
 * as separator, then trim and remove empty values.
 * @param  string $str Some string with optional .
 * @param  string $sep Separator, as a regex or a simple substring.
 * @return array       Array of items found.
 */
function split_values(string $str, string $sep = '/[,;]/'): array
{
    $str = trim($str);
    return array_values(array_filter(array_map(function($s)
    {
        return trim($s) ?: null;
    }, seems_regex($sep) ? preg_split($sep, $str) : explode($sep, $str))));
}

/**
 * <USER>
 * Increment a string, using a validation function.
 * @param  string       $str      Base string.
 * @param  string       $prefix   Prefix where {idx} is replaced by increment.
 * @param  string       $suffix   Suffix where {idx} is replaced by increment.
 * @param  Closure|null $validate Validation function, taking two arguments:
 *                                the incremented string, and the index
 *                                as integer.
 * @return string                 Incremented string.
 */
function increment_str(string $str, string $prefix = '', string $suffix = '', ?Closure $validate = null): string
{
    if ((!$prefix && !$suffix) || (!str_contains($prefix, '{idx}') && !str_contains($suffix, '{idx}'))) $suffix = ' - {idx}';

    $regexPrefix = str_replace($k = 'Oo___IdX___oO', '[0-9]+', preg_quote(str_replace('{idx}', 'Oo___IdX___oO', $prefix), '/'));
    $regexSuffix = str_replace($k = 'Oo___IdX___oO', '[0-9]+', preg_quote(str_replace('{idx}', 'Oo___IdX___oO', $suffix), '/'));
    $str = preg_replace('/' . $regexSuffix . '$/', '', preg_replace('/^' . $regexPrefix . '/', '', $str));

    for ($idx = 1; $idx <= 99; $idx++) {
        $out = str_replace('{idx}', (string) $idx, $prefix . $str . $suffix);
        if (!($validate instanceof Closure)) return $out;
        if ($validate($out, $idx)) return $out;
    }
    return $str;
}

/**
 * Explode the strings part, based on conventional case separators:
 * camel-cased, dashes or underscores.
 * @param  string       $str   Input string.
 * @param  string|null  $joint If given, this string will be used for an
 *                             implode and a string will be returned instead
 *                             of an array.
 * @return array|string        An array with the string parts, or a string with
 *                             those parts joined with $joint.
 */
function explode_case(string $str, ?string $joint = null): array | string
{
    if (!preg_match('/[a-z]/', $str)) return [ $str ];
    $str = preg_replace('/([a-z])([A-Z])/', '$1 $2', $str);
    $str = preg_replace('/([a-zA-Z])([0-9]+)$/', '$1 $2', $str);
    $str = str_replace([ '-', '_' ], ' ', $str);
    $parts = array_values(array_filter(array_map(function($c): ?string
    {
        return trim($c) ?: null;
    }, explode(' ', $str))));
    if ($joint === null) return $parts;
    return implode($joint, $parts);
}

/**
 * <USER>
 * Returns a camelCasedString.
 * @param  string $str Input string.
 * @return string      Cased string.
 */
function camel_case(string $str): string
{
    $str = substr(ucwords('Z' . strtolower(explode_case($str, ' '))), 1);
    return str_replace(' ', '', $str);
}

/**
 * <USER>
 * Returns a PascalCasedString.
 * @param  string $str Input string.
 * @return string      Cased string.
 */
function pascal_case(string $str): string
{
    return ucfirst(camel_case($str));
}

/**
 * <USER>
 * Returns a snake_cased_string, a SNAKE_CASED_STRING or a Snake_Cased_String.
 * @param  string $str        Input string.
 * @param  bool   $upper      Uppercase all the string.
 * @param  bool   $upperWords Uppercase the first letter of each word.
 * @return string             Cased string.
 */
function snake_case(string $str, bool $upper = false, bool $upperWords = false): string
{
    $str = strtolower(explode_case($str, '_'));
    if ($upper) return strtoupper($str);
    if (!$upperWords) return $str;
    return str_replace(' ', '_', ucwords(str_replace('_', ' ', $str)));
}

/**
 * <USER>
 * Returns a kebab-cased-string, or a KEBAB-CASED-STRING.
 * @param  string $str   Input string.
 * @param  bool   $upper Uppercase all the string.
 * @return string        Cased string.
 */
function kebab_case(string $str, bool $upper = false): string
{
    $str = strtolower(explode_case($str, '-'));
    return $upper ? strtoupper($str) : $str;
}

/**
 * <USER>
 * Extract quoted substrings from a string, and returns an object with
 * the original string altered with unquoted $replacement, and the quotes as
 * an array of objects containing the initial quoted text.
 * @param  string $str         Input string
 * @param  string $replacement Replacement string, with a %d somewhere which
 *                             will be replaced by the quote index.
 * @param  string $quotes      Quotes taken in account (default "').
 * @return array               Array of two entries: the altered text, and an
 *                             array of objects describing the quoted entries.
 */
function extract_quoted_substr(string $str, string $replacement = '{{{QUOTED_%d}}}', string $quotes = '\'"'): array
{
    $indexes = [];

    $start = null;
    $lastQuoteChar = null;
    for ($i = 0, $len = strlen($str); $i < $len; $i++) {
        if (!str_contains($quotes, $str[$i])) continue;
        $lastQuoteChar = $str[$i];
        if ($start) {
            $indexes[] = [ $start, $i ];
            $start = null;
        } else {
            $start = $i;
        }
    }

    if ($start) {
        $str .= $lastQuoteChar;
        $indexes[] = [ $start, strlen($str) - 1 ];
    }

    foreach ($indexes as $idx => list($start, $end)) {
        $quotedSubstr = $substr = substr($str, $start, $end - $start + 1);
        if (strlen($substr) >= 2) $substr = substr(substr($substr, 1), 0, -1);
        $indexes[$idx][] = $quotedSubstr;
        $indexes[$idx][] = $substr;
    }

    $quotes = [];
    foreach ($indexes as $idx => list($start, $end, $quotedSubstr, $substr)) {
        $repl = sprintf($replacement, $idx);
        $str = preg_replace('/' . preg_quote($quotedSubstr, '/') . '/', $repl, $str, 1);
        $quotes[] = (object) [
            'index'         => $idx,
            'quoted_substr' => $quotedSubstr,
            'substr'        => $substr,
            'replacement'   => $repl,
        ];
    }

    return [ $str, $quotes ];
}

/**
 * <USER>
 * Parse a query which can have some words, and key/value pairs.
 * E.g.: foo bar id:123
 * @param  string $q Query terms.
 * @return object    Object containing the string query, the splitted terms of
 *                   the query (as an array) and the params as a key/value
 *                   array.
 */
function parse_search_query(string $q): object
{
    list($str, $quotes) = extract_quoted_substr($q);

    $str = preg_replace('/([a-z0-9])\s*:\s*([^\s])/i', '$1:$2', $str);
    $str = ' ' . preg_replace('/\s+/', '  ', $str) . ' ';

    $params = [];
    if (preg_match_all('/\s(?<k>\w*[a-z0-9]):(?<v>[^\s]+)\s/i', $str, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $str = str_replace($m[0], '', $str);
            $v = trim($m['v']);
            foreach ($quotes as $q) {
                if ($v === $q->replacement) {
                    $v = $q->substr;
                    break;
                }
            }
            $params[$m['k']] = $v;
        }
    }

    $str = trim(preg_replace('/\s+/', ' ', $str));

    return (object) [
        'query'  => $str,
        'terms'  => explode(' ', $str),
        'params' => $params,
    ];
}

// ==={ Fallback Functions }====================================================

if (!function_exists('mb_ucfirst') && function_exists('mb_strtoupper')) {
    function mb_ucfirst(string $str, ?string $encoding = null): string
    {
        if ($encoding === null) $encoding = mb_internal_encoding();
        return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding) . mb_substr($str, 1, null, $encoding);
    }
}

if (!function_exists('mb_str_pad') && function_exists('mb_strlen')) {
    function mb_str_pad(string $input, int $length, string $padding = ' ', int $padType = STR_PAD_RIGHT, string $encoding = 'utf-8'): string
    {
        $result = $input;
        if (($paddingRequired = $length - mb_strlen($input, $encoding)) <= 0) return $result;
        switch ($padType) {
            case STR_PAD_LEFT:
                $result = mb_substr(str_repeat($padding, $paddingRequired), 0, $paddingRequired, $encoding) . $input;
                break;
            case STR_PAD_RIGHT:
                $result = $input . mb_substr(str_repeat($padding, $paddingRequired), 0, $paddingRequired, $encoding);
                break;
            case STR_PAD_BOTH:
                $leftPaddingLength = floor($paddingRequired / 2);
                $rightPaddingLength = $paddingRequired - $leftPaddingLength;
                $result = mb_substr(str_repeat($padding, $leftPaddingLength), 0, $leftPaddingLength, $encoding)
                    . $input . mb_substr(str_repeat($padding, $rightPaddingLength), 0, $rightPaddingLength, $encoding);
                break;
        }
        return $result;
    }
}

if (!function_exists('mb_str_replace') && function_exists('mb_split')) {
    function mb_str_replace(string | array $search, string $replace, string $subject): string
    {
        if (!is_array($search)) return implode($replace, mb_split($search, $subject));
        foreach ($search as $s) $subject = mb_str_replace($s, $replace, $subject);
        return $subject;
    }
}

// =============================================================================
