<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Register a callback function which will be called after trying every route,
 * without success.
 * @param  Closure $func Function called just before throwing a 404.
 */
function register_fallback_route(Closure $func, bool $overwrite = false): void
{
    if (!$overwrite && cfg('~@core.routes.fallback')) return;
    cfg('@core.routes.fallback', $func);
}

/**
 * Call the optionnaly registered fallback function. If this function returns
 * a true, the 404 will not be thrown directly.
 * @return bool Was a fallback function executed or not?
 */
function call_fallback_route(): bool
{
    if (cfg('~@core.routes.found')) return false;
    if (!($func = cfg('~@core.routes.fallback'))) return false;
    $func(get_request_path(trimmed: true));
    cfg('@core.routes.found', true);
    return true;
}

/**
 * <USER>
 * Register a callback function which will be called before any route.
 * This can be usefull, for example, to setup, from a helper or directly
 * inside the index.php, a function which will be able to fill the
 * global template variables.
 * @param  Closure $func Function which will be called before executing a route.
 */
function register_before_any_route(Closure $func): void
{
    $callbacks = cfg('~@core.routes.before_any') ?: [];
    $callbacks[] = $func;
    cfg('@core.routes.before_any', $callbacks);
}

/**
 * <USER>
 * Register a global modifier for URLs building.
 * @param  Closure $modifier Closure called on each URL building. The unique
 *                           and first parameter is the URL which is currently
 *                           processed. The function must returns the URL,
 *                           modified or not.
 */
function register_url_modifier(Closure $modifier): void
{
    $modifiers = get_url_modifiers();
    $modifiers[] = $modifier;
    cfg('@core.routes.urls.modifiers', $modifiers);
}

/**
 * Returns the registered URLs modifiers.
 * @return array Route modifiers functions.
 */
function get_url_modifiers(): array
{
    return cfg('~@core.routes.urls.modifiers') ?: [];
}

/**
 * Apply registered URL modifiers on a given path.
 * @param  string $path Path to process.
 * @return string       Path processed.
 */
function apply_url_modifiers(string $path): string
{
    foreach (get_url_modifiers() as $modifier) $path = $modifier($path);
    return $path;
}

/**
 * <USER>
 * Register a route filter. When a route will match with the current URL,
 * the registered filters callbacks will be runned. If one of them
 * returns false, the route will not be reached and the next one will be tried.
 * The route filters are cleared automatically at the beginning of each
 * controller files.
 * The classic purpose of this behaviour is to apply all the routes of a
 * controller file only on specific domain name, or only if there is a cookie
 * registered, or only with some IP addresses.
 * @param  Closure|null $callback Filter callback. A function which will take
 *                                one argument: the Microbe class instance, in
 *                                which are available GET/POST parameters,
 *                                route arguments and the route path
 *                                (aka the path given as first argument
 *                                of <route>).
 */
function register_route_filter(?Closure $callback = null): void
{
    $filters = get_route_filters();
    $filters[] = $callback;
    cfg('@core.routes.filters', $filters);
}

/**
 * <USER>
 * Shortcut to <register_route_filter>, to register a route filter which
 * will check if the current domain name corresponds to the given pattern.
 * If the pattern seems to be a regex (begins and ends with slashes), a regex
 * comparisition will be done. Else, a strictly equal comparisition
 * will be used.
 * @param  string $pattern Domain name or regex to validate the current domain.
 */
function register_domain_route_filter(string $pattern): void
{
    if (($len = strlen($pattern)) < 2) return;
    if ($pattern[0] !== '/' || $pattern[$len - 1] !== '/') {
        $pattern = '/^' . preg_quote($pattern, '/') . '$/i';
    }
    register_route_filter(function() use ($pattern): bool
    {
        return (bool) preg_match($pattern, get_domain_name());
    });
}

/**
 * <USER>
 * Clear the registered route filters. This function is always called
 * immediately before requiring a controller file.
 */
function clear_route_filters(): void
{
    cfg('@core.routes.filters', null);
}

/**
 * Returns the registered route filters.
 * @return array Route filters.
 */
function get_route_filters(): array
{
    return cfg('~@core.routes.filters') ?: [];
}

/**
 * <USER>
 * Set route arguments, prepended to route functions.
 * @param array $args Array of objects with 'name' and 'func' properties.
 */
function set_route_args(array $args): void
{
    cfg('@core.route.args', $args);
}

/**
 * <USER>
 * Returns predefined route arguments.
 * @return array Array of arguments objects.
 */
function get_route_args(): array
{
    return cfg('~@core.route.args') ?: [];
}

/**
 * <USER>
 * Add a route argument. A route argument is defined by its name (for further
 * deletion purpose), and its callback function. The function should return
 * the value which will be given as the route's function argument.
 * @param string  $name     Name of predefined argument.
 * @param Closure $callback Function executed to get the proper value.
 */
function add_route_arg(string $name, Closure $callback): void
{
    $args = get_route_args();
    $args[] = (object) [ 'name' => $name, 'func' => $callback ];
    set_route_args($args);
}

/**
 * <USER>
 * Delete a specific route argument.
 * @param  string $name Name of the argument
 */
function delete_route_arg(string $name): void
{
    set_route_args(array_values(array_filter(get_route_args(), function(object $arg) use ($name): bool
    {
        return $arg->name !== $name;
    })));
}

/**
 * Compute predefined route args.
 * @param  string|null $url     Optional current URL, passed to args functions.
 * @param  array       $urlArgs Optional array containing URL arguments.
 * @return array                Array of named predefined route args.
 */
function compute_route_args(?string $url = null, array $urlArgs = []): array
{
    $args = [];
    foreach (get_route_args() as $arg) {
        $args[$arg->name] = call_user_func($arg->func, $url, $urlArgs);
    }
    return $args;
}

/**
 * <USER>
 * Add a route rule.
 * The first argument is the path who have to match.
 * The second argument is the callback which will be executed if the URL match.
 * Before executing a route, the route filters registered with
 * <register_route_filter> will be executed.
 * If the $path is an array of paths, the separated routes will be declared
 * for each item of the array.
 * The path parts between chevrons will be considered as jokers, and returned
 * in the Microbe instance.
 * Note the behaviour of the parts between chevrons:
 *   - '/foo/<var>' will match '/foo/abc' or '/foo/123', but not '/foo/abc/123';
 *   - '/foo/<var>/bar' will match '/foo/abc/bar' or '/foo/abc/123'
 *     but not '/foo/abc/def/bar';
 *   - '/foo/<*var>' will match '/foo/abc', 'foo/abc/def',
 *     'foo/bar/abc/def/123', etc.
 *   - '/foo/<var:bar>' will match '/foo/bar' only, and set the route argument
 *     'var' to 'bar'. It's useful when using a paths array, to differenciate
 *     the different paths.
 * @param  string|array $path     Path of the URL which should match.
 * @param  Closure      $callback Function to call when the route match.
 *                                This function will accept a Microbe instance
 *                                as unique parameter.
 */
function route(string | array $path, Closure $callback): void
{
    cfg('@core.initialized', true);

    if (is_array($path)) {
        foreach ($path as $p) route($p, $callback);
        return;
    }

    $path = apply_url_modifiers($path);

    $routePath = $path;

    $r = (object) [
        'open'               => '___o_O_@',
        'close'              => '@_O_o___',
        'jokerClose'         => '@@_j_O_o___',
        'substringSeparator' => '___O_o_s_o_O___',
        'substringClose'     => '@_s_O_o___',
    ];

    if (preg_match_all('/<(?<joker>\*?)(?<name>[a-z_]([a-z0-9_-]*[a-z0-9_%]+)?)(\:(?<substr>[^>]+))?>/i', $path, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $name = $m['name'];
            if (array_key_exists('substr', $m) && $m['substr']) {
                $replacement = $r->open . $name . $r->substringSeparator . $m['substr'] . $r->substringClose;
            } else {
                $replacement = $r->open . $name . ($m['joker'] === '*' ? $r->jokerClose : $r->close);
            }
            $path = str_replace($m[0], $replacement, $path);
        }
    }

    $path = str_replace('/', '\\/', preg_quote($path));
    $path = str_replace($r->open, '(?<', $path);
    $path = str_replace($r->close, '>[^\\/]+)', $path);
    $path = str_replace($r->jokerClose, '>.+)', $path);
    $path = str_replace($r->substringSeparator, '>', $path);
    $path = str_replace($r->substringClose, ')', $path);

    $path = '/^' . $path . '(?<_tailing_slash>\\/?)$/';

    $url = get_relative_url(false);

    if (!preg_match($path, $url, $matches)) return;

    if ($url !== '/' && $matches['_tailing_slash'] === '/') {
        // We got a tailing slash and we prefer without
        redirect(preg_replace('/^(.*)\/(\?.*)?$/', '$1$2', get_relative_url()));
    }

    foreach ($matches as $k => $v) {
        if (is_numeric($k) || $k === '_tailing_slash') unset($matches[$k]);
    }

    $matches = array_map('urldecode', $matches);

    foreach (get_route_filters() as $filter) {
        if (!$filter($url, $matches)) return;
    }

    $namedArgs = $matches;
    $indexedArgs = array_values($namedArgs);
    $predefinedArgs = compute_route_args($url, $namedArgs);
    $combinedArgs = $predefinedArgs + $namedArgs;

    cfg('@core.routes.found', true);
    foreach (cfg('~@core.routes.before_any') ?: [] as $func) {
        call_user_func_array($func, (object) [ 'args' => $combinedArgs ]);
    }

    call_user_func_array($callback, array_values($combinedArgs));
    close();
}

/**
 * <USER>
 * Generate a URL.
 * The $args argument can be skipped and replaced by the $host argument.
 * Some special paths which can be given as first argument:
 *   - '.': current path, with query strings;
 *   - './': current path, without query strings;
 *   - ':back': HTTP_REFERER if available, or $fallback, or '/';
 * @param  string       $path     Path of the URL.
 * @param  array        $args     Query String arguments.
 * @param  bool|boolean $host     Prepend the protocol and the hostname or not.
 * @param  string|null  $fallback Back-url fallback. Used with special
 *                                path ':back'.
 * @return string                 Computed URL.
 */
function url(string $path = '/', array | bool $args = [], bool $host = false, ?string $fallback = null): string
{
    if ($path === '.') { // Current path
        $path = get_relative_url();
    } else if (preg_match('/^\.\/(?<h>#.*)?$/', $path, $m)) { // Current path without query strings
        $path = preg_replace('/^([^\?]+)\?.*$/', '$1', get_relative_url()) . (array_key_exists('h', $m) ? $m['h'] ?: '' : '');
    } else if (str_starts_with($path, '@')) { // Action URL
        $hash = null;
        if (str_contains($path, '#')) {
            list($path, $hash) = explode('#', $path);
            $hash = trim($hash) ?: null;
        }
        $args['do'] = ltrim($path, '@');
        $path = '/' . ($hash ? '#' . $hash : '');
    } else if (str_starts_with($path, '?')) { // Use optional 'after' query-string as URL if provided
        if ($after = get('after')) {
            $path = $after;
            $args = [];
            $host = false;
        } else {
            $path = ltrim($path, '?');
        }
    } else if ($path === ':back') { // Referer
        if (array_key_exists('HTTP_REFERER', $_SERVER)) {
            $path = $_SERVER['HTTP_REFERER'];
        } else {
                 if (gettype($args) === 'string') $path = $args;
            else if (gettype($host) === 'string') $path = $host;
            else if ($fallback)                   $path = $fallback;
            else                                  $path = '/';
        }
    }

    $path = apply_url_modifiers($path);

    if ($args === true || $args === false) {
        $host = $args;
        $args = [];
    }

    $qs = [];
    $hash = null;

    if (str_contains($path, '#')) {
        list($path, $hash) = explode('#', $path);
        $hash = trim($hash) ?: null;
    }

    if (str_contains($path, '?')) {
        list($path, $query) = explode('?', $path);
        parse_str($query, $qs);
    }

    $qsp = (object) [
        'before' => '___v_V_',
        'after'  => '_V_v___',
        'vars'   => [],
    ];

    $qs = array_map(function(mixed $v) use (&$qsp): mixed
    {
        if (is_array($v) || !preg_match('/^\{(.+)\}$/U', (string) $v, $n)) return $v;
        $qsp->vars[$idx = count($qsp->vars)] = $n[1];
        $v = $qsp->before . $idx . $qsp->after;
        return $v;
    }, array_filter(array_merge($qs, $args), fn(mixed $v): bool => $v !== null));

    $qsSuffix = $qs ? '?' . http_build_query($qs) : '';

    $qsSuffix = str_replace($qsp->before, '{', $qsSuffix);
    $qsSuffix = str_replace($qsp->after, '}', $qsSuffix);
    foreach ($qsp->vars as $k => $v) $qsSuffix = str_replace('{' . $k . '}', '{' . $v . '}', $qsSuffix);

    $hashSuffix = $hash ? '#' . $hash : '';

    if (strpos($path, '://')) {
        return $path . $qsSuffix . $hashSuffix;
    }

    return ($host ? get_http_scheme() . get_domain_name() : '')
        . get_base_url() . '/'
        . ltrim($path, '/')
        . $qsSuffix
        . $hashSuffix;
}

/**
 * <USER>
 * Generate a URL using <url()>, then echo it.
 * @param  string       $path     Path of the URL.
 * @param  array        $args     Query String arguments.
 * @param  bool|boolean $host     Prepend the protocol and the hostname or not.
 * @param  string|null  $fallback Back-url fallback. Used with special
 *                                path ':back'.
 * @return string                 Computed URL.
 */
function _url(string $path = '/', array | bool $args = [], bool $host = false, ?string $fallback = null): string
{
    echo $url = url(path: $path, args: $args, host: $host, fallback: $fallback);
    return $url;
}

/**
 * <USER>
 * Generate a URL using <url()>, then echo it, escaped.
 * @param  string       $path     Path of the URL.
 * @param  array        $args     Query String arguments.
 * @param  bool|boolean $host     Prepend the protocol and the hostname or not.
 * @param  string|null  $fallback Back-url fallback. Used with special
 *                                path ':back'.
 * @return string                 Computed URL.
 */
function _h_url(string $path = '/', array | bool $args = [], bool $host = false, ?string $fallback = null): string
{
    echo $url = esc(url(path: $path, args: $args, host: $host, fallback: $fallback));
    return $url;
}

/**
 * <USER>
 * Tries to check if the given URL is NOT pointing to the current site.
 * @param  string  $url URL to checj.
 * @return bool         Is external or not?
 */
function is_external_url(string $url): bool
{
    if (!str_contains($url, '://')) return false;
    if (str_starts_with($url, '://')) return false;
    if (str_contains($url . '/', '://' . get_domain_name() . '/')) return false;
    return true;
}

// =============================================================================
