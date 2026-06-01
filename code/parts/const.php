<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Define one or several constants, if they are not already defined.
 * @param  string|array $k     Key, or Key/Value array.
 * @param  mixed|null   $value Value, if $k is a single key.
 */
function def(string | array $k, mixed $value = null): void
{
    if (is_array($k)) {
        foreach ($k as $key => $value) def($key, $value);
        return;
    }

    if (defined($k)) return;
    define($k, $value);
}

// =============================================================================
