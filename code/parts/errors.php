<?php

// =============================================================================
// ---{ Exceptions }------------------------------------------------------------

class Microbe_Unauthorized_Exception extends Exception {}
class Microbe_NotFound_Exception extends Exception {}
class Microbe_Exception extends Exception {}

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Register a custom error callback function, based on an error code.
 * @param int        $errorCode    Error code: basically 403, 404 or 500.
 * @param Closure    $func         Callback function, executed when the
 *                                 $errorCode is thrown somewhere.
 * @param array|null $environments Option environments array: when the current
 *                                 environment is not in this list, the
 *                                 callback will not be called.
 */
function register_custom_error_handler(int $errorCode, Closure $func, ?array $environments = null): void
{
    if ($environments !== null && !in_array(get_env(), $environments)) {
        return;
    }
    cfg('@core.error_handler_' . $errorCode, $func);
}

/**
 * <USER>
 * List error files.
 * @param  string      $type    Type of error (500, 404, 403).
 * @param  string|null $year    Filter by year.
 * @param  string|null $month   Filter by month.
 * @param  bool        $reverse Reverse files (default true: recent first).
 * @return array                Objects describing error files.
 */
function get_logged_errors_files(
    string  $type,
    ?string $year    = null,
    ?string $month   = null,
    bool    $reverse = true,
): array
{
    if (!($cfg = cfg('~@errors.log.' . $type))) return [];
    if (!($cfg['enabled'] ?? false)) return [];
    $dir = $cfg['path'] ?? get_data_dir('logs', 'errors');
    $files = [];
    if (!is_dir($dir)) return [];
    foreach (get_folders($dir) as $fYear) {
        $thisYear = $fYear->getName();
        if ($year && ($thisYear !== $year)) continue;
        foreach (get_files($fYear->getPath()) as $f) {
            if (!preg_match('/^(?<y>[0-9]{4})(?<m>[0-9]{2})(?<d>[0-9]{2})-(?<t>.+)\.log$/', $f->getName(), $m)) continue;
            if ($type !== $m['t']) continue;
            if ($thisYear !== $m['y']) continue;
            if ($month && ($month !== $m['m'])) continue;
            $files[] = (object) [
                'instance'       => $f,
                'year'           => $m['y'],
                'month'          => $m['m'],
                'day'            => $m['d'],
                'dt'             => $dt = new DateTime($m['y'] . '-' . $m['m'] . '-' . $m['d'] . ' 00:00:00'),
                'day_name'       => get_day_name($dt, locale: 'en_US'),
                'short_day_name' => get_day_name($dt, locale: 'en_US', short: true),
            ];
        }
    }
    return $reverse ? array_reverse($files) : $files;
}

/**
 * <USER>
 * Returns logged error for a specific error type and date.
 * @param  string   $type    Type of error (500, 404, 403).
 * @param  string   $year    File's year.
 * @param  string   $month   File's month.
 * @param  string   $day     File's day.
 * @param  int|null $limit   Limit results. Null for no limit.
 * @param  bool     $reverse Reverse results: recent first.
 * @return array             Array containing error objects.
 */
function get_logged_errors(
    string $type,
    string $year,
    string $month,
    string $day,
    ?int   $limit   = 100,
    bool   $reverse = true,
): array
{
    if (!($path = get_errors_file_path($type, $year, $month, $day))) return [];
    if (!is_file($path)) return [];
    return array_values(array_filter(array_map(function(string $line): ?object
    {
        if (!($line = trim($line))) return null;
        return parse_eror_line($line);
    }, $reverse ? tail_file($path, $limit) : head_file($path, $limit))));
}

/**
 * <USER>
 * Returns path of error log file.
 * @param  string      $type    Type of error (500, 404, 403).
 * @param  string      $year    File's year.
 * @param  string      $month   File's month.
 * @param  string      $day     File's day.
 * @return string|null          File path.
 */
function get_errors_file_path(string $type, string $year, string $month, string $day): ?string
{
    if (!($cfg = cfg('~@errors.log.' . $type))) return null;
    if (!($cfg['enabled'] ?? false)) return null;
    $dir = $cfg['path'] ?? get_data_dir('logs', 'errors');
    $path = join_path($dir, $year, $year . str_pad($month, 2, '0', STR_PAD_LEFT) . str_pad($day, 2, '0', STR_PAD_LEFT) . '-' . $type . '.log');
    return $path;
}

/**
 * <USER>
 * Log error in local file.
 * @param  string      $type    Error type: 404, 403 or 500.
 * @param  string|null $message Optional message.
 */
function log_error(string $type, ?string $message = null): void
{
    if (!($cfg = cfg('~@errors.log.' . $type))) return;
    if (!($cfg['enabled'] ?? false)) return;
    $at = new DateTime();
    $dir = $cfg['path'] ?? get_data_dir('logs', 'errors');
    $path = join_path($dir, $at->format('Y'), $at->format('Ymd') . '-' . $type . '.log');
    rmkdir(dirname($path));
    file_put_contents($path, implode(' | ', [
        $at->format('c'),
        $type,
        get_remote_ip() ?: '',
        get_http_method() ?: '',
        get_request_url(full: true) ?: '',
        get_referer_url() ?: '',
        get_user_agent() ?: '',
        $message ? base64_encode($message) : '',
    ]) . "\n", FILE_APPEND);
}

/**
 * <USER>
 * Parse an error line got from a log file.
 * @param  string $line Line as a string.
 * @return object       Object describing the error.
 */
function parse_eror_line(string $line): object
{
    $cols = explode(' | ', $line);
    $err = (object) [
        'at'          => $cols[0] ?? null,
        'type'        => $cols[1] ?? null,
        'ip'          => $cols[2] ?? null,
        'http_method' => $cols[3] ?? null,
        'url'         => $cols[4] ?? null,
        'referer'     => $cols[5] ?? null,
        'user_agent'  => $cols[6] ?? null,
        'message'     => $cols[7] ?? null ? base64_decode($cols[7]) : null,
    ];
    $err->pretty_url = $err->url ? preg_replace('/^https?:\/\/[^\/]+(\/.*)?/', '...$1', $err->url) : null;
    $err->pretty_referer = $err->referer ? preg_replace('/^https?:\/\/' . preg_quote(get_domain_name(), '/') . '/', '...', $err->referer) : null;
    $err->browser = $err->user_agent ? guess_browser_by_user_agent($err->user_agent) : null;
    return $err;
}

/**
 * Throw a 403 error, with the registered custom handler
 * or with a system message.
 * Generally reached after a catching of an 'Microbe_Unauthorized_Exception'.
 * @param  string|null $message Message to pass to the handler or to show
 *                              in the system message.
 */
function throw_403(?string $message = null): void
{
    log_error('403', $message);

    if (!MB_CLI && ($func = cfg('~@core.error_handler_403'))) {
        $func($message);
        close();
    }

    message(
        type:   'error',
        title:  "403 Unauthorized",
        before: '<!-- [[ERROR_403]]' . ($message ? ' ' . $message : '') . ' -->' . "\n",
        html:   "You are not allowed to access this resource." . ($message ? "<br><br>" . $message : ''),
    );
}

/**
 * Throw a 404 error, with the registered custom handler
 * or with a system message.
 * Generally reached after a catching of a 'Microbe_NotFound_Exception'.
 * @param  string|null $message Message to pass to the handler or to show
 *                              in the system message.
 */
function throw_404(?string $message = null): void
{
    log_error('404', $message);

    if (!MB_CLI && ($func = cfg('~@core.error_handler_404'))) {
        $func($message);
        close();
    }

    message(
        type:   'error',
        title:  "404 Not Found",
        before: '<!-- [[ERROR_404]]' . ($message ? ' ' . $message : '') . ' -->' . "\n",
        html:   "The resource you're trying to access was not found." . ($message ? "<br><br>" . $message : ''),
    );
}

/**
 * Throw a 500 error, with the registered custom handler
 * or with a system message.
 * Generally reached after a catching of any 'Exception' which is not an
 * 'Microbe_Unauthorized_Exception' or an 'Microbe_NotFound_Exception'.
 * In the case of an 'Microbe_Exception' which means an 'Exception' sent through
 * this framework or the friends of it, $isExternal will stay false.
 * When it's reached from an unmanaged Exception, $isExternal will be true.
 * @param  array       $err        Error details, generally given from an
 *                                 'Error' or an 'Exception' instance.
 * @param  bool        $isExternal Is it an error caught from a
 *                                 'Microbe_Exception' or not?
 * @param  string|null $message    Message to pass in the $err array.
 */
function throw_500(array $err = [], bool $isExternal = false, ?string $message = null): void
{
    if ($err instanceof Exception) {
        $err = [
            'message' => $err->getMessage(),
            'file'    => $err->getFile(),
            'line'    => $err->getLine(),
            'trace'   => null,
        ];
    }

    $err = (object) array_merge([
        'message' => $message,
        'file'    => null,
        'line'    => null,
        'trace'   => null,
    ], $err);

    log_error('500', $err->message);

    if (!MB_CLI && ($func = cfg('~@core.error_handler_500'))) {
        $func($err);
        close();
    }

    if (!headers_sent()) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
    }

    message(
        type:   'error',
        title:  "500 Internal Server Error",
        before: "<!--\n"
              . "[[ERROR_500]]\n"
              . $err->message . "\n"
              . ($err->file ?: '(unknown)') . ':' . ((int) ($err->line ?: 0)) . "\n"
              . "-->\n",
        html:   '<pre>' . trim($err->message) . '</pre>'
              . '<ul class="bz-e-msg-err-loc">'
              .   '<li><strong>File</strong><div>' . (html_backtrace_file_path($err->file, (int) ($err->line ?: 0)) ?: '(unknown)') . '</div></li>'
              .   '<li><strong>Line</strong><div>' . ($err->line ?: '(unknown)') . '</div></li>'
              .   '<li><strong>Backtrace</strong><div>' . ($err->trace ? $err->trace : '(empty)') . '</div></li>'
              . '</ul>',
    );
}

/**
 * Generate the HTML string for a file path entry, separating the relative URL
 * from root and the other part of the absolute path, then appending the
 * line number.
 * @param  string|null $path File path.
 * @param  int|null    $line Line number.
 * @return string|null       Formated file path and line number.
 */
function html_backtrace_file_path(?string $path = null, ?int $line = null): ?string
{
    if ($path === null || $path === '') return null;

    $parent = get_root_dir();
    if (strpos($path, $parent) !== 0) return $path;

    return substr($path, 0, strlen($parent) + 1)
        . '<i>'
        . substr($path, strlen($parent) + 1)
        . ($line ? '<u>:' . $line . '</u>' : '')
        . '</i>';
}

/**
 * Errors callback, used by <set_exception_handler>, <set_error_handler> and
 * <register_shutdown_function>.
 * @param  Exception|Error|int|null $exception Error, exception or exit code.
 */
function handle_error(Exception | Error | int | null $exception = null): void
{
    if (!($exception instanceof Exception)
        && !($exception instanceof Error)
        && !($err = error_get_last())) {
        return;
    }

    $trace = null;

    if ($exception && ($exceptionTrace = $exception->getTrace())) {
        $trace = '<ul class="bz-e-msg-backtrace">';
        foreach ($exceptionTrace as $entry) {
            $entry = array_merge([
                'file'     => '(unknown file)',
                'line'     => '(unknown line)',
                'function' => null,
                'class'    => null,
            ], $entry);

            $trace .= '<li>'
                . html_backtrace_file_path($entry['file'], (int) ($entry['line'] ?: 0)) . ':' . $entry['line']
                . ($entry['class'] || $entry['function'] ? ' >> ' : '')
                . ($entry['class'] ? $entry['class'] . '::' : '')
                . ($entry['function'] ?: '')
                . '</li>' . "\n";
        }
        $trace .= '</ul>' . "\n";
    }

    $error = [
        'message' => $exception ? $exception->getMessage() : $err['message'],
        'file'    => $exception ? $exception->getFile() : $err['file'],
        'line'    => $exception ? $exception->getLine() : $err['line'],
        'trace'   => $trace,
    ];

    if ($exception) {
        if ($exception instanceof Microbe_Unauthorized_Exception) {
            throw_403($error['message']);
            return;
        } else if ($exception instanceof Microbe_NotFound_Exception) {
            throw_404($error['message']);
            return;
        } else if ($exception instanceof Microbe_Exception) {
            throw_500($error);
            return;
        }
    }

    throw_500($error, true);
}

/**
 * <USER>
 * Returns a HTML string, representing a red <div/> with the $str message.
 * @param  string $str Error message.
 * @return string      HTML error.
 */
function html_error_block(string $str): string
{
    return '<div style="margin:1rem 0; padding:1.5rem; background:#e30000; border-radius:.5rem; color:white; font-size:1rem; font-weight:bold;">' . $str . '</div>';
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'errors' => [
            'log' => [
                '404' => [ 'enabled' => false, 'path' => null ],
                '403' => [ 'enabled' => false, 'path' => null ],
                '500' => [ 'enabled' => false, 'path' => null ],
            ],
        ]
    ];
});

// =============================================================================
// ---{ PHP Error Handlers }----------------------------------------------------

if (defined('MB_HANDLE_ERRORS') && MB_HANDLE_ERRORS) {
    set_exception_handler('handle_error');
    set_error_handler('handle_error');
    register_shutdown_function('handle_error');
}

// =============================================================================
