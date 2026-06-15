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
    if (!($token = get_auth_token())) {
        return null;
    }

    if (!($getter = cfg('@core.user_getter'))) {
        throw new Microbe_Exception("User getter is not defined");
    }

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

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('before_first_render', function(): void
{
    set_template_vars([
        'logged_in_user' => ($user = get_logged_in_user()),
        'is_logged_in'   => (bool) $user,
    ]);
});

// =============================================================================
