<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Guess the location fo the PHP binary, ideally including the same PHP version.
 * @param  string|null $fallback Fallback PHP binary path.
 * @return string|null           PHP binary path if found.
 */
function guess_php_binary(?string $fallback = null): ?string
{
    $binary = preg_replace('/\/php-fpm$/', '/php', PHP_BINARY);
    if (is_file($binary)) return $binary;
    $binary = null;
    $locations = array_unique([ dirname(PHP_BINARY), '/usr/sbin', '/usr/bin', '/usr/local/bin' ]);
    foreach ($locations as $loc) {
        $bin = $loc . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (!is_file($bin)) continue;
        return $bin;
    }
    foreach ($locations as $loc) {
        $bin = $loc . '/php';
        if (!is_file($bin)) continue;
        return $bin;
    }
    return $fallback;
}

/**
 * <USER>
 * Execute a PHP file using current PHP binary.
 * @param  string      $path   Path of PHP file to execute.
 * @param  array       $vars   Variables to pass as environment variables
 *                             got in child via ($_SERVER).
 * @param  bool        $return Return execution response instead of echoing it.
 * @param  string|null $binary Path of PHP binary. Default is guessed
 *                             from PHP_BINARY.
 * @return int|array           Code returned by execution, or array containing
 *                             the code and the response.
 */
function exec_php_file(
    string  $path,
    array   $vars   = [],
    bool    $return = false,
    ?string $binary = null,
): int | array
{
    if ($binary === null) $binary = guess_php_binary();
    $env = '';
    foreach ($vars as $k => $v) $env .= strtoupper($k) . '=' . escapeshellarg($v) . ' ';
    $cmd = $env . escapeshellcmd($binary) . ' ' . escapeshellcmd($path);
    if ($return) {
        exec($cmd, $output, $code);
        return [ $code, $output ];
    }
    passthru($cmd, $code);
    return $code;
}

/**
 * <USER>
 * Execute an interactive command through <proc_open>, and wait for some of
 * the entries of $expect, then answer it.
 * @param  string      $cmd        Command to execute.
 * @param  array       $expect     Simple array, containing associative arrays
 *                                 with the following enties:
 *                                 - <in>, as a regex tested on each text row
 *                                   returned by the command.
 *                                 - <out>, as the string to write in the
 *                                   <stdout>, as an input to the command.
 * @param  string|null $ending     An optional regex which indicates that we can
 *                                 consider the program is fully exectuted, so
 *                                 we don't have to wait the timeout of
 *                                 <stream_select>.
 * @param  bool        $showOutput Echo each result of the stream.
 *                                 Default false.
 * @return bool|string             False is something goes wrong. Else, the
 *                                 full output of the execution of the command.
 */
function exec_in_out(string $cmd, array $expect = [], string $ending = null, bool $showOutput = false): bool | string
{
    $log = [];

    $descriptorspec = [
       [ 'pipe', 'r' ], // stdin
       [ 'pipe', 'w' ], // stdout
       [ 'pipe', 'w' ], // stderr
    ];

    $process = proc_open($cmd, $descriptorspec, $pipes);

    $read = [ $pipes[1] ];
    $write = null;
    $except = null;
    $readTimeout = 10;

    if (!is_resource($process)) return false;
    sleep(2);
    stream_set_blocking($pipes[1], false);

    $i = 0;
    while (true) {
        $res = stream_select($read, $write, $except, $readTimeout);
        $rawOutput = fgets($pipes[1]);
        $output = trim(cli_strip_colors($rawOutput));
        if (!preg_match('/[A-Za-z0-9]/', $output)) continue;
        if ($showOutput) echo rtrim($rawOutput, "\r\n") . "\n";

        $log[] = $output;
        if (preg_match($ending, $output)) {
            sleep(1);
            break;
        }
        foreach ($expect as $idx => $exp) {
            if (!preg_match($exp['out'], $output)) continue;
            fwrite($pipes[0], $exp['in'] . "\n");
            fflush($pipes[0]);
            unset($expect[$idx]);
            $expect = array_values($expect);
            break;
        }
    }

    foreach ($pipes as $p) fclose($p);
    $ret = proc_close($process);
    return implode("\n", $log);
}

/**
 * <USER>
 * Returns the user or group name, from its system ID.
 * @param  int         $id System user/group ID
 * @return string|null     Name, if found and if the proper <posix_getpwuid()>
 *                         is available.
 */
function get_system_user_name_by_id(int $id): ?string
{
    if (!function_exists('posix_getpwuid')) return null;
    if (!($pw = posix_getpwuid($id))) return null;
    return $pw['name'] ?? null;
}

/**
 * <USER>
 * Returns the current's process user ID.
 * @return int|null User ID.
 */
function get_system_user_id(): ?int
{
    return function_exists('posix_geteuid') ? posix_geteuid() : null;
}

/**
 * <USER>
 * Returns the current's process user name.
 * @return string|null User name.
 */
function get_system_user_name(): ?string
{
    if (!function_exists('posix_getpwuid')) return null;
    if (($id = get_system_user_id()) === null) return null;
    return get_system_user_name_by_id($id);
}

// =============================================================================
