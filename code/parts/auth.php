<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Returns the authentication cookie name.
 * This name can be set with the sibling function <set_auth_cookie_name>.
 * @return string The authentication cookie name
 */
function get_auth_cookie_name(): string
{
    return cfg('~@core.auth_cookie_name') ?: 'MBA';
}

/**
 * Set the authentication cookie name.
 * @param string $name New name of the cookie. If null, the default name
 *                     hardcoded in <get_auth_cookie_name> will be used.
 */
function set_auth_cookie_name(?string $name = null): void
{
    cfg('@core.auth_cookie_name', $name);
}

/**
 * <USER>
 * Set authentication cookie default lifetime. This behaviour can be bypassed
 * when setting authentication token (e.g. for unremembered logins).
 * @param int|null $lifetime TTL in seconds
 */
function set_auth_cookie_lifetime(?int $lifetime = null): void
{
    cfg('@core.auth_cookie_lifetime', $lifetime);
}

/**
 * <USER>
 * Register user getter callback function.
 * This function will be called when calling <get_logged_in_user>, with
 * the authenticated token as parameter.
 * @param  Closure $callback Function to call from <get_logged_in_user>.
 */
function register_user_getter(Closure $callback): void
{
    cfg('@core.user_getter', $callback);
}

/**
 * <USER>
 * Set authentication token. The token will be stored in a cookie named
 * with <get_auth_cookie_name>, with the lifetime given in second parameter,
 * or the one set with <set_auth_cookie_lifetime> or finally two month
 * as hardcoded.
 * @param string|null $token    Token to store. If null, cookie will
 *                              be cleared (aka logout) - cf <clear_auth_token>.
 * @param int|null    $lifetime TTL in seconds
 */
function set_auth_token(?string $token = null, ?int $lifetime = null): void
{
    if ($lifetime === null) {
        $lifetime = cfg('~@core.auth_cookie_lifetime') ?: 61 * 24 * 60 * 60;
    }

    cookie(get_auth_cookie_name(), $token === null ? false : $token, $lifetime);
}

/**
 * <USER>
 * Clear authentication token cookie. Used for logout.
 */
function clear_auth_token(): void
{
    set_auth_token(null);
}

/**
 * Returns authentication token currently stored in cookie, or null.
 * @return string|null Authentication token
 */
function get_auth_token(): ?string
{
    return cookie(get_auth_cookie_name());
}

/**
 * <USER>
 * Registers the default url where the user will be redirected
 * when asserting login (<assert_logged_in>).
 * @param  string|null $redirect URL to redirect. If null, an exception
 *                               may be thrown if <assert_logged_in> needs it.
 */
function register_not_logged_in_redirect(?string $redirect = null): void
{
    cfg('@core.user_not_logged_in_redirect', $redirect);
}

/**
 * <USER>
 * Try to get the authentication token, then try to call the user getter
 * function defined with <register_user_getter> with the token as
 * unique parameter. If this function is not defined, an exception will be
 * thrown. If the user is not logged in, null will be returned. In other cases,
 * the function result will be returned.
 * @return mixed The result of the getter function. If not authenticated, null.
 */
function get_logged_in_user(): mixed
{
    if (!($token = get_auth_token())) return null;
    if (!($getter = cfg('~@core.user_getter'))) return null;

    try {
        return $getter($token);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * <USER>
 * Check if the user is logged in, based on <get_logged_in_user> and
 * returns the corresponding boolean.
 * @return boolean Is the user logged in or not?
 */
function is_logged_in(): bool
{
    return (bool) get_logged_in_user();
}

/**
 * <USER>
 * Check if the user is logged in. If yes, the user got from the function
 * defined by <register_user_getter> will be returned. If not, three cases:
 *   - $redirect is null: the user will get a HTTP 403 error.
 *   - $redirect = ':login': the user will be redirected to the defined URL
 *                           with <register_not_logged_in_redirect>.
 *   - $redirect = ':json': a JSON error with 'unauthorized' as message
 *                          will be echoed.
 *   - $redirect is different: the user will be redirected to this URL.
 * @param  string|null $redirect [description]
 * @return mixed                 [description]
 */
function assert_logged_in(?string $redirect = null): mixed
{
    if ($user = get_logged_in_user()) {
        return $user;
    }

    if ($redirect === null) {
        throw_403();
    } else if ($redirect === ':login') {
        redirect(cfg('~@core.user_not_logged_in_redirect') ?: '/login');
    } else if ($redirect === ':json') {
        json_error('unauthorized');
    }

    redirect($redirect);
    return null;
}

/**
 * Check if static authentication is enabled.
 * @return bool Is it enabled or not?
 */
function is_static_auth_enabled(): bool
{
    return (bool) cfg('~@auth.static.enabled');
}

/**
 * Returns the static authentication cookie name.
 * @return string Cookie name.
 */
function get_static_auth_cookie_name(): string
{
    return cfg('~@core.static_auth_cookie_name') ?: 'MBSA';
}

/**
 * Returns the static authentication cookie lifetime.
 * @return int Lifetime in seconds.
 */
function get_static_auth_cookie_lifetime(): int
{
    return cfg('~@core.static_auth_cookie_lifetime') ?: 180 * 24 * 60 * 60;
}

/**
 * Authenticate a static user.
 * @param  object $user Static user object (processed).
 */
function authenticate_static_user(?object $user): void
{
    if (!is_static_auth_enabled()) return;
    cookie(get_static_auth_cookie_name(), $user === null ? false : $user->token, get_static_auth_cookie_lifetime());
}

/**
 * <USER>
 * Get the logged in static user object.
 * @return object|null User's object if logged in. Else, null.
 */
function get_static_logged_in_user(): ?object
{
    if (!is_static_auth_enabled()) return null;
    if (!($token = cookie(get_static_auth_cookie_name()))) return null;
    foreach (get_static_users(processed: true) as $u) {
        if ($u->token === $token) return $u;
    }
    return null;
}

/**
 * <USER>
 * Check if a static user is logged in.
 * @return bool Is logged in or not?
 */
function is_static_user_logged_in(): bool
{
    return get_static_logged_in_user() !== null;
}

/**
 * Returns the path storing the static users.
 * @return string Path to the JSON file.
 */
function get_static_users_file_path(): string
{
    return get_data_dir('static_users.json');
}

/**
 * <USER>
 * Get some static user information, by username.
 * If password is provided, it will be validated.
 * @param  string      $username Username of the user to get.
 * @param  string|null $password Password to be validated.
 * @return object|null           User's object if found and with a
 *                               valid password, or null.
 */
function get_static_user(string $username, ?string $password = null): ?object
{
    if ($password !== null) $password = hash_static_user_password($password);
    foreach (get_static_users(processed: true) as $u) {
        if ($u->username !== $username) continue;
        if ($password !== null && $u->password !== $password) return null;
        return $u;
    }
    return null;
}

/**
 * <USER>
 * Returns the list of static users.
 * @param  bool $processed Process each user to add uid and token,
 *                         or returns the raw array.
 * @return array           Array of users arrays (or objects if processed).
 */
function get_static_users(bool $processed = false): array
{
    if (!is_file($path = get_static_users_file_path())) return [];
    if (!($users = json_decode(file_get_contents($path), true))) return [];
    if (!is_array($users)) return [];
    return $processed ? array_values(array_filter(array_map(function(mixed $u): ?object
    {
        if (!is_array($u)) return null;
        if (count($u) !== 2) return null;
        if (!isset($u['username']) || !is_string($u['username'])) return null;
        if (!isset($u['password']) || !is_string($u['username'])) return null;
        ksort($u);
        $u['uid'] = sha256($u['username'] . ':' . $u['password']);
        $u['token'] = sha256($u['uid'] . '::' . get_salt());
        return (object) $u;
    }, $users))) : $users;
}

/**
 * Store static users array.
 * @param  array|null $users Store given users. If null or empty,
 *                           the storage file is deleted.
 */
function store_static_users(?array $users = null): void
{
    $path = get_static_users_file_path();
    if (!$users) {
        if (is_file($path)) unlink($path);
        return;
    }
    file_put_contents($path, json_encode($users));
}

/**
 * Hash some static user password.
 * @param  string $password Password to be hashed.
 * @return string           Hashed password.
 */
function hash_static_user_password(string $password): string
{
    return hash_password($password);
}

/**
 * <USER>
 * Remove some static user.
 * @param  string $username Username of the user to be removed.
 */
function remove_static_user(string $username): void
{
    store_static_users(array_values(array_filter(get_static_users(),
        fn(array $u): bool => $u['username'] !== $username)));
}

/**
 * <USER>
 * Add some static user.
 * @param string $username Username.
 * @param string $oassword Password (plain, no hashed).
 */
function add_static_user(string $username, string $oassword): void
{
    remove_static_user($username);
    $users = get_static_users();
    $users[] = [ 'username' => $username, 'password' => hash_static_user_password($password) ];
    store_static_users($users);
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('before_first_render', function(): void
{
    set_template_vars([
        'logged_in_user'        => ($user = get_logged_in_user()),
        'is_logged_in'          => (bool) $user,
        'static_logged_in_user' => ($staticUser = get_static_logged_in_user()),
        'static_is_logged_in'   => (bool) $staticUser,
    ]);
});

// =============================================================================
