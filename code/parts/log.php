<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Log something.
 * @param  string      $name    Name of the log.
 * @param  string      $msg     Message to log.
 * @param  int|null    $maxSize Max size of each log file. Null for unlimited.
 * @param  string|null $path    Path of the log file to write in (if using this
 *                              parameter, will ignore $maxSize).
 */
function slog(
    string  $name,
    string  $msg,
    ?int    $maxSize = 2000000,
    ?string $path    = null,
): void
{
    if ($path === null) {
        $pattern = get_data_dir('logs', $name, $name . '%s.log');
        $path = sprintf($pattern, '');
        if ($maxSize !== null && is_file($path) && filesize($path) > $maxSize) {
            for ($i = 0; is_file($p = sprintf($pattern, '-' . $i)); $i++);
            $path = $p;
        }
    }
    rmkdir(dirname($path));
    file_put_contents($path, get_remote_ip() . ' -- [' . (new DateTime())->format('c') . '] -- "' . trim($msg) . '"' . "\n", FILE_APPEND);
}
