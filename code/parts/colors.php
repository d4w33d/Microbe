<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Generate some random hexadecimal color.
 * @param  bool   $hash Include beginning hash character.
 * @return string       Random color.
 */
function get_random_color(bool $hash = true): string
{
    return ($hash ? '#' : '') . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
}

/**
 * <USER>
 * Returns the most contrasted color (white or black), based on a
 * specified color.
 * @param  string       $hex   Hexadecimal color. Hash is optional.
 * @param  bool         $asRgb Returns the result as an RGB triplet instead of
*                              an hexadecimal color.
 * @return string|array        The hexadecimal color (including leading hash),
 *                             or RGB triplet.
 */
function contrast_color(string $hex, bool $asRgb = false): string | array
{
    list($r, $g, $b) = sscanf(str_replace('#', '', $hex), "%02x%02x%02x");
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    $contrasted = ($yiq >= 128) ? '#000000' : '#ffffff';
    if (!$asRgb) return $contrasted;
    return sscanf($contrasted, "#%02x%02x%02x");
}
