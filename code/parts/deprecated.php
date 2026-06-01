<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Display a deprecation message.
 * @param  string $funcName Function name.
 * @param  string $type     Type of error: warning or fatal.
 * @param  string $reason   String representing the error and/or the migration.
 */
function deprecated(string $funcName, ?string $type = null, string $reason = ''): void
{
    if ($type !== 'fatal') $type = 'warning';
    echo "\n[Microbe " . ucfirst($type) . ']' . ($reason ? ' ' . $reason : '') . "\n";
    if ($type === 'fatal') exit;
}

/**
 * <USER>
 * Display a warning deprecation message.
 * @param  string $funcName Function name.
 * @param  string $reason   String representing the error and/or the migration.
 */
function warning_deprecated(string $funcName, string $reason = ''): void
{
    deprecated($funcName, 'warning', $reason);
}

/**
 * <USER>
 * Display a fatal deprecation message.
 * @param  string $funcName Function name.
 * @param  string $reason   String representing the error and/or the migration.
 */
function fatal_deprecated(string $funcName, string $reason = ''): void
{
    deprecated($funcName, 'fatal', $reason);
}

/**
 * <DEPRECATED>
 */

// =============================================================================
