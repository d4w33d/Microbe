<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Returns an array of valid environments names.
 * @return array Array of environments names.
 */
function get_valid_env(): array
{
    return [ 'local', 'dev', 'test', 'staging', 'prod' ];
}

/**
 * Check if the given environment name is a valid one.
 * @param  string  $env Environment name.
 * @return boolean      Returns true if the name is in the allowed list,
 *                      given by <get_valid_env>.
 */
function is_valid_env(string $env): bool
{
    return in_array($env, get_valid_env());
}

/**
 * <USER>
 * Get and returns the current environment, based on the '/ENV' file.
 * If this file is not found, or empty, 'prod' will be returned.
 * @return string Current environment name.
 */
function get_env(): string
{
    if ($env = cfg('~@core.env')) return $env;
    $default = 'prod';
    $f = join_path(get_root_dir(), 'ENV');
    if (!is_file($f)) return $default;

    $env = strtolower(trim(file_get_contents($f)));
    if (!is_valid_env($env)) return $default;

    cfg('@core.env', $env);
    return $env;
}

/**
 * <USER>
 * Set current environment via /ENV file.
 * @param string | false $env Environment name (one of get_valid_env()).
 */
function set_env(string | false $env): void
{
    $f = join_path(get_root_dir(), 'ENV');
    cfg('@core.env', null);
    if ($env === false) {
        if (is_file($f)) unlink($f);
        return;
    }
    if (!is_valid_env($env)) throw new Microbe_Exception("Trying to set current env to invalid environment: {$env}");
    file_put_contents($f, $env);
}

/**
 * <USER>
 * Check if the current environment is one of those given in arguments.
 * @param  string...  $envs Environment name (or several, as different args).
 * @return boolean          Returns true if one of those is the current env.
 */
function is_env(string ...$envs): bool
{
    $current = get_env();
    foreach ($envs as $env) {
        if ($current === $env) {
            return true;
        }
    }
    return false;
}

/**
 * <USER>
 * Returns the directory where the framework's file is located, which is
 * assumed to be the root directory of the project.
 * @return string Root path.
 */
function get_root_dir(): string
{
    return __DIR__;
}

/**
 * <USER>
 * Join paths parts together, using the system's directory separator (/ or \),
 * and returns the result.
 * @param  string... $paths Paths parts as separated arguments.
 * @return string           Path joint.
 */
function join_path(string... $paths): string
{
    $path = '';
    foreach ($paths as $part) {
        if ($path !== '') $path .= DIRECTORY_SEPARATOR;
        $path .= rtrim($part, '/\\');
    }
    $path = preg_replace('/[\/\\\]{2,}/', DIRECTORY_SEPARATOR, $path);
    return $path;
}

/**
 * <USER>
 * Join paths parts together exactly like <join_path>, but prefixing the root
 * directory got through <get_root_dir>.
 * @param  string... $paths Paths parts as separated arguments.
 * @return string           Path joint.
 */
function get_path(string... $dirs): string
{
    array_unshift($dirs, get_root_dir());
    return call_user_func_array('join_path', $dirs);
}

/**
 * <USER>
 * Returns the vendor directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_vendor_dir(string... $dirs): string
{
    array_unshift($dirs, 'vendor');
    return call_user_func_array('get_path', $dirs);
}

/**
 * <USER>
 * Returns the vendor-static directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_vendor_static_dir(string... $dirs): string
{
    array_unshift($dirs, 'vendor-static');
    return call_user_func_array('get_path', $dirs);
}

/**
 * <USER>
 * Returns the backend directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_src_dir(string... $dirs): string
{
    array_unshift($dirs, 'src');
    return call_user_func_array('get_path', $dirs);
}

/**
 * <USER>
 * Returns the controllers directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_ctrl_dir(string... $dirs): string
{
    array_unshift($dirs, 'ctrl');
    return call_user_func_array('get_src_dir', $dirs);
}

/**
 * <USER>
 * Returns the data directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_data_dir(string... $dirs): string
{
    array_unshift($dirs, 'data');
    return call_user_func_array('get_path', $dirs);
}

/**
 * <USER>
 * Returns the sessions directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory. */
function get_sessions_dir(string... $dirs): string
{
    array_unshift($dirs, 'sessions');
    return call_user_func_array('get_data_dir', $dirs);
}

/**
 * <USER>
 * Returns the uploads directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_uploads_dir(string... $dirs): string
{
    array_unshift($dirs, 'uploads');
    return call_user_func_array('get_data_dir', $dirs);
}

/**
 * <USER>
 * Returns the cache directory.
 * @param  string... $paths Optional sub-paths parts as separated arguments.
 * @return string           Path to the directory.
 */
function get_cache_dir(string... $dirs): string
{
    array_unshift($dirs, 'cache');
    return call_user_func_array('get_data_dir', $dirs);
}

/**
 * <USER>
 * Returns a cache file path.
 * @param  string... $parts File name with extension, with optionaly some
 *                          directories before.
 * @return string           Path to temporary file.
 */
function get_cache_file(string... $parts): string
{
    return call_user_func_array('get_cache_dir', $parts);
}

/**
 * <USER>
 * Returns the directory path for the temporary files, optionaly with a
 * subdirectory (which will be created if it doesn't exists).
 * @param  string|null $sub Subdirectory name.
 * @return string           Path to the directory.
 */
function get_tmp_dir(?string $sub = null): string
{
    $dir = get_data_dir('tmp');
    if ($sub) $dir = join_path($dir, $sub);
    if (!is_dir($dir)) mkdir($dir, get_mkdir_chmod(), true);
    return $dir;
}

/**
 * <USER>
 * Returns a temporary file path.
 * @param  string|null $name File name. Random string if empty.
 * @param  string|null $sub  Subdirectory name. Temp directory root if empty.
 * @param  string|null $ext  File extension.
 * @return string            Path to temporary file.
 */
function get_tmp_file(?string $name = null, ?string $sub = null, ?string $ext = null): string
{
    if ($name === null) $name = uid(16);
    if ($ext !== null) $name .= '.' . $ext;
    return join_path(get_tmp_dir($sub), $name);
}

/**
 * <USER>
 * Include all PHP files of a directory.
 * @param  string $dir  Directory path.
 * @param  bool   $once Default true. If true, the file will be required using
 *                      'require_once'. In other case, it will be 'require'.
 */
function include_dir(string $dir, bool $once = true): void
{
    if (!is_dir($dir)) return;
    foreach (glob(join_path($dir, '*.php')) as $f) {
        if ($once) require_once $f;
        else require $f;
    }
}

/**
 * Includes specific file type (helpers, ctrl, etc.) located at root.
 * @param  string $type Type of file (helpers, ctrl, etc.).
 * @param  bool   $once Require once instead of simply require.
 */
function include_root_files(string $type, bool $once = true): void
{
    if (is_file($path = join_path(get_root_dir(), $type . '.php'))) {
        if ($once) require_once $path;
        else require $path;
    }

    include_dir(join_path(get_root_dir(), $type));
}

/**
 * Includes specific files type (helpers, ctrl, etc.), from root and bundles.
 * @param  string $type Type of file (helpers, ctrl, etc.).
 * @param  bool   $once Require once instead of simply require.
 */
function include_files(string $type, bool $once = true): void
{
    include_root_files($type, $once);
    include_bundles_files($type, $once);
}

/**
 * <USER>
 * Returns the current domain name, aka HTTP hostname.
 * @return string Domain name.
 */
function get_domain_name(): string
{
    if (!array_key_exists('HTTP_HOST', $_SERVER)
        && function_exists('cfg')
        && ($host = cfg('~@app.hosting.hosts.fallback'))) return strtolower($host);
    return strtolower($_SERVER['HTTP_HOST'] ?? '');
}

/**
 * <USER>
 * Verify is the result of <get_domain_name> match with the string or the
 * regex pattern given.
 * @param  string  $pattern String or regex pattern (beginning and ending with
 *                          a slash, with optionnaly some letters at the end).
 * @return boolean          If we got a match, true. Else, false.
 */
function is_domain_name(string $pattern): bool
{
    if (!seems_regex($pattern)) $pattern = '/^' . preg_quote($pattern, '/') . '$/';
    return (bool) preg_match($pattern, get_domain_name());
}

/**
 * <USER>
 * Returns the top level domain name (e.g. when <get_domain_name> will be
 * 'foo.bar.tld' or 'john.doe.bar.tld', this function will return 'bar.tld').
 * This function can be useful with the cookies policies.
 * @return string Top level domain name.
 */
function get_top_level_domain_name(): string
{
    return extract_top_level_domain_name(get_domain_name());
}

/**
 * <USER>
 * Returns the current HTTP scheme: https:// or http://.
 * @return string HTTP scheme.
 */
function get_http_scheme(): string
{
    if (!array_key_exists('SERVER_PORT', $_SERVER)
        && function_exists('cfg')
        && ($scheme = cfg('~@app.hosting.scheme.fallback'))) return strtolower($scheme);
    return ((int) ($_SERVER['SERVER_PORT'] ?? 80)) === 443 ? 'https://' : 'http://';
}

/**
 * <USER>
 * Returns true if the current HTTP scheme is HTTPS. Else, false.
 * @return boolean True if using HTTPS. Else, false.
 */
function is_ssl(): bool
{
    return get_http_scheme() === 'https://';
}

/**
 * <USER>
 * Returns the base URL, based on the $_SERVER's SCRIPT_NAME.
 * Generally, the server configuration should redirect every request
 * (except for existing files) to the 'index.php'.
 * This function retrieve the directory URL to the called file, so 'index.php'
 * and returns it.
 * If the app is working at the root of the server (at the domain name level),
 * an empty string will be returned. Else, the subdirectories will be returned.
 * @return string Base URL.
 */
function get_base_url(): string
{
    if (php_sapi_name() === 'cli'
        && function_exists('cfg')
        && ($baseUrl = cfg('~@app.hosting.base_url.fallback'))) return rtrim($baseUrl, '/');
    return rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
}

/**
 * <USER>
 * Convert an absolute path into a URL.
 * @param  string $path Absolute path to a file or a directory.
 * @param  bool   $host Include host in URL.
 * @return string       URL targeting this file or directory.
 */
function path_to_url(string $path, bool $host = false): ?string
{
    if (!$path) return null;
    $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
    if (strpos($path, get_root_dir()) !== 0) return null;
    return url(substr($path, strlen(get_root_dir())), host: $host);
}

/**
 * <USER>
 * Convert a URL to a local path.
 * @param  string      $url          URL to process.
 * @param  string|bool $validateHost Validate the host if the URL contains one.
 *                                   If true, the current host will be used.
 *                                   If false, every host will be allowed.
 *                                   If a string, it should be a regexp.
 * @return string|null               Path, if the host matched.
 */
function url_to_path(string $url, string | bool $validateHost = true): ?string
{
    if ($hostRegex === true) $hostRegex = preg_quote(get_domain_name(), '/');
    if ($hostRegex
        && ($host = extract_domain_name($url))
        && !preg_match($hostRegex, $host)) return null;

    $url = preg_replace('/^[^:]*:\/\/[^\/]+\//', '/', $url);
    $url = trim($url, '/');
    $url = str_replace('/', DIRECTORY_SEPARATOR, $url);
    return get_path($url);
}

/**
 * <USER>
 * Returns the requested URL, as given by the server.
 * @param  bool   $full Returns the URL with scheme and domain, or only path.
 * @return string       Request URL.
 */
function get_request_url(bool $full = false): string
{
    $url = $_SERVER['REQUEST_URI'] ?? '/';
    if (!$full) return $url;
    return get_http_scheme() . get_domain_name() . $url;
}

/**
 * <USER>
 * Returns the referer URL if available.
 * @return string|null Referer URL, or null if unavailable.
 */
function get_referer_url(): ?string
{
    return $_SERVER['HTTP_REFERER'] ?? null;
}

/**
 * <USER>
 * Returns the current URL, without query strings.
 * @param  bool   $trimmed If true, trim the slashes.
 * @return string          Current path.
 */
function get_request_path(bool $trimmed = false): string
{
    $path = preg_replace('/\?.*$/', '', get_request_url());
    if ($trimmed) $path = trim($path, '/');
    return $path;
}

/**
 * <USER>
 * Returns the requested path, relatively to the project root.
 * The given path should be located insidide the project folder.
 * @param  string $path Absolute path to relativize.
 * @return string       Relative path.
 */
function get_relative_path(string $path): string
{
    if (!$path || $path[0] !== DIRECTORY_SEPARATOR) return $path;
    if (!str_starts_with($path, $root = get_root_dir())) return $path;
    return substr($path, strlen($root) + 1);
}

/**
 * <USER>
 * Returns the requested URL, based on <get_request_url>, relatively
 * to the base URL of the app got using <get_base_url>.
 * @param  bool   $keepQueryString Keep or strip the query strings from the URL.
 * @return string                  Request URL relatively to the app root.
 */
function get_relative_url(bool $keepQueryString = true): string
{
    $url = substr(get_request_url(), strlen(get_base_url()));
    if ($keepQueryString) return $url;
    return preg_replace('/^([^?]+)(\?.*)?$/', '$1', $url);
}

/**
 * <USER>
 * Returns the HTTP method (GET, POST, PUT or DELETE).
 * @return string HTTP method (uppercase).
 */
function get_http_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/**
 * <USER>
 * Returns true if the current HTTP method is the one given.
 * @param  string  $method Method to compare.
 * @return boolean         True if this is the current HTTP method. Else, false.
 */
function is_http_method(string $method): bool
{
    return get_http_method() === strtoupper($method);
}

/**
 * <USER>
 * Returns true if the current HTTP method is POST.
 * @return boolean True if the current HTTP method is POST.
 */
function is_post(): bool
{
    return is_http_method('POST');
}

/**
 * <USER>
 * Returns true if the browser sent the headers of a XMLHTTPRequest,
 * aka ajax request.
 * @return boolean True if it's an ajax request. Else, false.
 */
function is_xhr(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/**
 * <USER>
 * Returns the client IP address, as given by the server.
 * @return string|null The IP address. If the server doesn't got or
 *                     passed it, null.
 */
function get_remote_ip(): ?string
{
    return strtoupper($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
}

/**
 * <USER>
 * Returns the user agent of the visitor.
 * @return string|null The user agent.
 */
function get_user_agent(): ?string
{
    return ($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null;
}

/**
 * <USER>
 * Get the variable given in argument from the POST or GET data.
 * If the argument is an array of variable names, an associative array
 * will be returned with all the corresponding values from POST or GET.
 * @param  string|array|null $k      Variable(s) name(s).
 * @param  string            $method Variables passing methods
 *                                   ('both', 'get' or 'post')
 * @param  boolean           $trim   Trim string values.
 * @return mixed                     POST/GET value(s).
 */
function get(string | array | null $k = null, string $method = 'both', bool $trim = false): mixed
{
    if ($k === null) return array_merge($_GET, $_POST);

    if (is_array($k)) {
        $all = [];
        foreach ($k as $kk) $all[$kk] = get($kk, method: $method, trim: $trim);
        return $all;
    }

    $v = null;
    if ($method === 'get') $v = $_GET[$k] ?? null;
    else if ($method === 'post') $v = $_POST[$k] ?? null;
    else if ($method === 'both') $v = $_POST[$k] ?? $_GET[$k] ?? null;
    else throw new Microbe_Exception("Unhandled method {$method} while getting request var");

    if ($trim && is_string($v)) $v = trim($v);
    return $v;
}

/**
 * <USER>
 * Getting the variable given in argument from the GET data.
 * Does exactly the same as <get()>, but only get data from GET, not from POST.
 * @param  string|array $k    Variable(s) name(s).
 * @param  boolean      $trim Trim string values.
 * @return mixed              GET value(s).
 */
function get_queried(string | array $k, bool $trim = false): mixed
{
    return get($k, method: 'get', trim: $trim);
}

/**
 * <USER>
 * Getting the variable given in argument from the POST data.
 * Does exactly the same as <get()>, but only get data from POST, not from GET.
 * @param  string|array $k    Variable(s) name(s).
 * @param  boolean      $trim Trim string values.
 * @return mixed              POST value(s).
 */
function get_posted(string | array $k, bool $trim = false): mixed
{
    return get($k, method: 'post', trim: $trim);
}

/**
 * <USER>
 * @param  string|array $k          Key or array of keys to get with <get()>.
 * @param  Closure      $modifier   Modifier function. Takes two params: the
 *                                  value and the key.
 * @param  bool         $nullable   Returns null if string is empty.
 * @param  array        $nullValues Nullable values.
 * @param  string       $method     Method used by <get>.
 * @return mixed                    A single casted value if $k is a single
 *                                  param, or an object with casted values
 *                                  for each param key's value.
 */
function get_func_casted(
    string | array $k,
    Closure $modifier,
    bool    $nullable   = false,
    array   $nullValues = [],
    string  $method     = 'both',
): mixed
{
    $multiple = true;
    if (!is_array($k)) {
        $multiple = false;
        $k = [ $k ];
    }
    $values = [];
    foreach ($k as $kk) {
        $v = get($kk, method: $method);
        $v = $modifier($v, $kk);
        if ($nullable) {
            foreach ($nullValues as $nullValue) {
                if ($v === $nullValue) $v = null;
                break;
            }
        }
        $values[$kk] = $v;
    }
    return $multiple ? (object) $values : array_pop($values);
}

/**
 * <USER>
 * Get the variable(s), cast it to trimed string.
 * @param  string|array       $k        Key or array of keys to get with
 *                                      <get()>.
 * @param  bool               $trim     Trim the value.
 * @param  bool               $nullable Returns null if string is empty.
 * @param  string             $method   Method used by <get>.
 * @return null|string|object           A string if $k is a single param, or an
 *                                      object with string for each param
 *                                      key's value.
 */
function get_str(string | array $k, bool $trim = true, bool $nullable = false, string $method = 'both'): null | string | object
{
    return get_func_casted(
        k:          $k,
        nullable:   $nullable,
        method:     $method,
        nullValues: [ '' ],
        modifier:   function(mixed $value, string $key) use ($trim): string
        {
            if (!is_scalar($value)) $value = '';
            $value = (string) $value;
            if ($trim) $value = trim($value);
            return $value;
        },
    );
}

/**
 * <USER>
 * Get the variable(s) with <get_str()>, but returns null as value if the
 * string is empty.
 * @param  string|array       $k    Key or array of keys to get with <get()>.
 * @param  bool               $trim Trim the value.
 * @return null|string|object       A string if $k is a single param, or an
 *                                  object with string for each param
 *                                  key's value.
 */
function get_nullable_str(string | array $k, bool $trim = true): null | string | object
{
    return get_str($k, $trim, true);
}

/**
 * <USER>
 * Get the variable(s), cast it to a number.
 * @param  string|array          $k          Key or array of keys to get with
 *                                           <get()>.
 * @param  bool                  $int        Don't allow floating values.
 * @param  int|float|null        $min        Minimum value.
 * @param  int|float|null        $max        Maximum value.
 * @param  bool                  $nullable   Returns null if string is empty.
 * @param  array                 $nullValues Nullable values.
 * @param  string                $method     Method used by <get>.
 * @return null|int|float|object             A number if $k is a single param,
 *                                           or an object with numbers for each
 *                                           param key's value.
 */
function get_number(
    string | array     $k,
    bool               $int       = false,
    int | float | null $min       = null,
    int | float | null $max       = null,
    bool               $nullable  = false,
    string             $method    = 'both',
): null | int | float | object
{
    return get_func_casted(
        k:         $k,
        nullable:  $nullable,
        method:    $method,
        modifier:  function(mixed $value, string $key) use ($int, $min, $max, $nullable): int | float | null
        {
            if (!is_scalar($value)) return $nullable ? null : 0;
            if (ctype_digit($value)) $value = (int) $value;
            else if (is_numeric($value)) $value = (float) $value;
            else return $nullable ? null : 0;
            if ($min !== null && $value < $min) $value = $min;
            if ($max !== null && $value > $max) $value = $max;
            return $value;
        },
    );
}

/**
 * <USER>
 * Get the variable(s), cast it to an integer.
 * @param  string|array          $k          Key or array of keys to get with
 *                                           <get()>.
 * @param  int|float|null        $min        Minimum value.
 * @param  int|float|null        $max        Maximum value.
 * @param  bool                  $nullable   Returns null if string is empty.
 * @param  array                 $nullValues Nullable values.
 * @param  string                $method     Method used by <get>.
 * @return null|int|float|object             An integer if $k is a single param,
 *                                           or an object with integers for each
 *                                           param key's value.
 */
function get_int(
    string | array $k,
    ?int           $min       = null,
    ?int           $max       = null,
    bool           $nullable  = false,
    string         $method    = 'both',
): null | int | object
{
    return get_number(
        k:         $k,
        int:       true,
        min:       $min,
        max:       $max,
        nullable:  $nullable,
        method:    $method,
    );
}

/**
 * <USER>
 * Get the variable(s), cast it to an integer, in nullable mode.
 * @param  string|array          $k          Key or array of keys to get with
 *                                           <get()>.
 * @param  int|float|null        $min        Minimum value.
 * @param  int|float|null        $max        Maximum value.
 * @param  array                 $nullValues Nullable values.
 * @param  string                $method     Method used by <get>.
 * @return null|int|float|object             An integer if $k is a single param,
 *                                           or an object with integers for each
 *                                           param key's value.
 */
function get_nullable_int(
    string | array $k,
    ?int           $min       = null,
    ?int           $max       = null,
    string         $method    = 'both',
): null | int | object
{
    return get_int(
        k:        $k,
        min:      $min,
        max:      $max,
        nullable: true,
        method:   $method,
    );
}

/**
 * <USER>
 * Check if the given POST/GET param seems to be equivalent to a true,
 * aka 1, (y)es or (t)rue.
 * @param  string  $k      Variable name.
 * @param  string  $method Variables passing methods ('both', 'get' or 'post').
 * @return boolean         Is the variable a true or not?
 */
function get_is(string $k, string $method = 'both'): bool
{
    return value_seems_true(get($k, method: $method));
}

/**
 * <USER>
 * Get the given POST/GET param and check if its value is one of those
 * specified with $values. If not, $default will be returned.
 * @param  string  $k       Variable name.
 * @param  array   $values  Allowed values.
 * @param  mixed   $default Value returned.
 * @param  string  $method  Variables passing methods ('both', 'get' or 'post').
 * @return mixed            The value.
 */
function get_in(string $k, array $values, mixed $default = null, string $method = 'both'): mixed
{
    $v = get($k, method: $method);
    return in_array($v, $values) ? $v : $default;
}

/**
 * <USER>
 * Get the POST/GET param $k and tries to build the DateTime object.
 * @param  string        $k Variable name.
 * @return DateTime|null    The DateTime object if the value is valid.
 */
function get_date(string $k): ?DateTime
{
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $v = get_str($k))) return null;
    return new DateTime($v . ' 00:00:00');
}

/**
 * <USER>
 * Get the POST/GET param $kd and tries to build the DateTime object.
 * If $kt is given, it should corresponds to the time variable name.
 * @param  string                   $kd       Date or datetime variable name.
 * @param  string|null              $kt       Time variable name.
 * @param  string|DateTimeZone|null $timezone Optional timezone.
 * @return DateTime|null                      The DateTime object if the value
 *                                            is valid.
 */
function get_datetime(string $kd, ?string $kt = null, string | DateTimeZone | null $timezone = null): ?DateTime
{
    $d = null;
    $t = null;
    if (!preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})/', $v = get_str($kd), $m)) return null;
    $d = $m[1];
    if ($kt && preg_match('/^[0-9]{2}:[0-9]{2}(:[0-9]{2})$/', $v = get_str($kt), $m)) $t = $v . ($m[1] ?? '' ? '' : ':00');
    else if (preg_match('/([0-9]{2}:[0-9]{2}:[0-9]{2})$/', $v = get_str($kd), $m)) $t = $m[1];
    if (is_string($timezone)) $timezone = new DateTimeZone($timezone);
    return new DateTime($d . ' ' . ($t ?: '00:00:00'), $timezone);
}

/**
 * <USER>
 * Get some query-string passed values, which use the same name.
 * E.g. foo.html?a=foo&a=bar&b=john => a=[foo,bar]
 * @param  string $k Variable name.
 * @return array     Array of values.
 */
function get_multiple(string $k): array
{
    $values = [];
    foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $param) {
      if (!str_contains($param, '=')) $param .= '=';
      list($key, $value) = explode('=', $param, 2);
      if (urldecode($key) !== $k) continue;
      $values[] = urldecode($value);
    }
    return $values;
}

/**
 * <USER>
 * Get the variable given in argument from the POST or GET data, then cast it
 * to an associative array.
 * If no variable match, or the value is not a valid associative array, an
 * empty array will be returned.
 * @param  string|array  $k      Variable name.
 * @param  string        $method Variables passing methods
 *                               ('both', 'get' or 'post')
 * @return array                 Value, casted as an associative array.
 */
function get_assoc_array(string | array $k, string $method = 'both'): array
{
    if (!($value = get($k, method: $method)) || !is_assoc_array($value)) return [];
    return $value;
}

/**
 * <USER>
 * Getting the variable given in argument from the GET data and cast
 * it as an array.
 * Does exactly the same as <get_assoc_array()>, but only get data
 * from GET, not from POST.
 * @param  string|array $k Variable(s) name(s).
 * @return mixed           GET value(s) casted as an array.
 */
function get_queried_assoc_array(string | array $k): array
{
    return get_assoc_array($k, method: 'get');
}

/**
 * <USER>
 * Getting the variable given in argument from the POST data and cast
 * it as an array.
 * Does exactly the same as <get_assoc_array()>, but only get data
 * from POST, not from GET.
 * @param  string|array $k Variable(s) name(s).
 * @return mixed           POST value(s) casted as an array.
 */
function get_posted_assoc_array(string | array $k): array
{
    return get_assoc_array($k, method: 'post');
}

/**
 * <USER>
 * Execute <cast_data> on $_POST.
 * @param  mixed   $data      Pattern of the data we are waiting for.
 * @param  bool    $trim      Trim string values or not (default true)?
 * @param  string  $separator Chained keys separator (when default one may be
 *                            found in one of the key names).
 * @param  string  $key       Current walking key. Internal use only.
 * @return mixed              Casted data.
 */
function cast_posted_data(mixed $data, bool $trim = true, string $separator = '§', string $key = ''): mixed
{
    return cast_data(
        input:     $_POST,
        data:      $data,
        trim:      $trim,
        separator: $separator,
        key:       $key,
    );
}

/**
 * <USER>
 * Execute <cast_data> on $_GET.
 * @param  mixed   $data      Pattern of the data we are waiting for.
 * @param  bool    $trim      Trim string values or not (default true)?
 * @param  string  $separator Chained keys separator (when default one may be
 *                            found in one of the key names).
 * @param  string  $key       Current walking key. Internal use only.
 * @return mixed              Casted data.
 */
function cast_queried_data(mixed $data, bool $trim = true, string $separator = '§', string $key = ''): mixed
{
    return cast_data(
        input:     $_GET,
        data:      $data,
        trim:      $trim,
        separator: $separator,
        key:       $key,
    );
}

/**
 * <USER>
 * Get the queried or posted page number, and cast the value to get an integer
 * between $min and $max, with a $default value.
 * @param  mixed    $page    Value to process. If null, the value will be got
 *                           from $_GET or $_POST with the key $var.
 * @param  string   $var     Var name corresponding to the page
 *                           number requested.
 * @param  int      $default Default page number.
 * @param  int|null $max     Maximum page number.
 * @param  int      $min     Minimum page number.
 * @return int               Casted page number.
 */
function get_page_number(mixed $page = null, string $var = 'page', int $default = 1, ?int $max = null, ?int $min = 1): int
{
    if (($page === null) && (($page = get($var)) === null)) return $default;
    if (!is_scalar($page)) return $default;
    $page = ((int) $page) ?: $default;
    if ($min !== null) $page = max($page, $min);
    if ($max !== null) $page = min($page, $max);
    return $page;
}

// =============================================================================
