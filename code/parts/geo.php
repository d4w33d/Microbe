<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Convert a coordinate given as a string or a set of degrees, minutes,
 * seconds and direction, to a decimal coordinate.
 * @param  string|int  $deg Coordinate string (e.g. 1°2'3.456"W) or degrees
 *                          as an integer.
 * @param  int|null    $min If $deg is not a string: the minutes.
 * @param  float|null  $sec If $deg is not a string: the seconds.
 * @param  string|null $dir If $deg is not a string: the direction
 *                          (N, S, W/O, E). $deg can also be signed,
 *                          and $dir ignored.
 * @return float            Decimal coordinate.
 */
function dms_coordinate_to_decimal(string | int $deg, ?int $min = null, ?float $sec = null, ?string $dir = null): float
{
    if (is_string($deg) && ($min === null)) {
        if (preg_match('/^\s*(?<deg>-?\d+)\s*°\s*(?<min>\d+)\s*\'\s*(?<sec>(\d*\.)?\d+)\s*\"\s*(?<dir>[NSEWO])?\s*$/', strtoupper(trim($deg)), $m)) {
            $deg = (int) $m['deg'];
            $min = (int) $m['min'];
            $sec = (float) $m['sec'];
            if (!($dir = str_replace('O', 'W', $m['dir'] ?: ''))) $dir = $deg < 0 ? 'W' : 'E';
            $deg = abs($deg);
        } else return 0;
    }

    return (str_contains('NWO', strtoupper($dir)) ? -1 : 1) * ($deg + ((($min * 60) + ($sec)) / 3600));
}

/**
 * <USER>
 * Convert a decimal coordinate to a string like 1°2'3.456"W.
 * @param  string        $type     (lat)itude or (lon)gitude.
 * @param  float         $dec      Decimal coordinate.
 * @param  bool          $asString Returns a string (default) or an object
 *                                 containing direction/degrees/minutes/seconds.
 * @param  int           $round    Round the seconds.
 * @return string|object           Object or string representing the coordinate.
 */
function dec_coordinate_to_dms(string $type, float $dec, bool $asString = true, int $round = 6, bool $spaces = true): string | object
{
    $space = $spaces ? ' ' : '';
    if (($type = str_replace('longitude', 'lon', str_replace('latitude', 'lat', strtolower($type)))) !== 'lon') $type = 'lat';
    $parts = explode('.', $dec);
    $deg = (int) $parts[0];
    $tempma = ((float) ('0.' . ($parts[1] ?? '0'))) * 3600;
    $dms = (object) [
        'direction' => $deg < 0 ? ($type === 'lat' ? 'N' : 'W') : ($type === 'lat' ? 'S' : 'E'),
        'degrees'   => abs($deg),
        'minutes'   => $min = floor($tempma / 60),
        'seconds'   => $tempma - ($min * 60),
    ];
    return !$asString ? $dms : ($dms->degrees . '°' . $space
        . $dms->minutes . "'" . $space
        . round($dms->seconds, $round) . '"' . $space
        . $dms->direction);
}

/**
 * <USER>
 * Execute <dec_coordinate_to_dms()> with $type = 'lat'.
 * @param  float         $dec      Decimal coordinate.
 * @param  bool          $asString Returns a string (default) or an object
 *                                 containing direction/degrees/minutes/seconds.
 * @param  int           $round    Round the seconds.
 * @return string|object           Object or string representing the coordinate.
 */
function dec_lat_to_dms(float $dec, bool $asString = true, int $round = 6, bool $spaces = true): string | object
{
    return dec_coordinate_to_dms(type: 'lat', dec: $dec, asString: $asString, round: $round, spaces: $spaces);
}

/**
 * <USER>
 * Execute <dec_lon_to_dms()> with $type = 'lon'.
 * @param  float         $dec      Decimal coordinate.
 * @param  bool          $asString Returns a string (default) or an object
 *                                 containing direction/degrees/minutes/seconds.
 * @param  int           $round    Round the seconds.
 * @return string|object           Object or string representing the coordinate.
 */
function dec_lon_to_dms(float $dec, bool $asString = true, int $round = 6, bool $spaces = true): string | object
{
    return dec_coordinate_to_dms(type: 'lon', dec: $dec, asString: $asString, round: $round, spaces: $spaces);
}

/**
 * <USER>
 * Create a box of coordinates, based on a center latitude and longitude,
 * optionaly enlarged with a radius.
 * Based on: 1 latitude-deg  = 110574                 (meters)
 *           1 longitude-deg = 111320 * cos(latitude) (meters)
 * @param  float    $lat    Decimal latitude.
 * @param  float    $lon    Decimal longitude.
 * @param  int|null $radius Radius in meters.
 * @return object           Coordinates box.
 */
function get_coordinates_box(float $lat, float $lon, int $radius = null): object
{
    $box = (object) [
        'center' => (object) [ 'lat' => ($lat = (float) $lat), 'lon' => ($lon = (float) $lon) ],
        'top'    => $lat,
        'right'  => $lon,
        'bottom' => $lat,
        'left'   => $lon,
    ];

    if (!$radius) return $box;

    $latRadius = abs($radius / 110574);
    $lonRadius = abs($radius / (111320 * cos($lat)));

    $box->top -= $latRadius;
    $box->bottom += $latRadius;
    $box->left -= $lonRadius;
    $box->right += $lonRadius;

    return $box;
}

// =============================================================================
