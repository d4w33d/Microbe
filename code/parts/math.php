<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Convert some floating number to a "human-readable" fraction/ratio.
 * @param  float       $n         Floating number.
 * @param  int|integer $tolerance Roundable tolerancy.
 * @return null|string            Fraction/Ratio.
 */
function float_to_ratio(float $n, float $tolerance = 1.e-6): ?string
{
    if ($n === 0.0) return null;
    $h1 = 1; $h2 = 0;
    $k1 = 0; $k2 = 1;
    $b = 1 / $n;
    do {
        $b = 1 / $b;
        $a = floor($b);
        $aux = $h1; $h1 = $a * $h1 + $h2; $h2 = $aux;
        $aux = $k1; $k1 = $a * $k1 + $k2; $k2 = $aux;
        $b = $b - $a;
    } while (abs($n - $h1 / $k1) > $n * $tolerance);
    return $h1 . '/' . $k1;
}

/**
 * <USER>
 * Process a string containing some fractional value (e.g. 50/100 returns 0.5).
 * @param  string $fraction Fractional string.
 * @return float            Calculated value.
 */
function process_fractional_number(string $fraction): float
{
    if (!str_contains($fraction, '/')) return (float) $fraction;
    if (!($nb = count($parts = explode('/', $fraction)))) return 0;
    if ($nb === 1) return (float) $parts[0];
    if (($by = (float) $parts[1]) === 0.0) return 0;
    return ((float) $parts[0]) / $by;
}

// =============================================================================
