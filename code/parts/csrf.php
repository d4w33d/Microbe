<?php

/**
 * Returns the CSRF session entry name.
 * @return string Key of the session entry.
 */
function get_csrf_session_name(): string
{
    return '_csrf';
}

/**
 * Returns the CSRF time-to-live.
 * @return int Time-to-live in seconds.
 */
function get_csrf_ttl(): int
{
    return 30 * 60;
}

/**
 * Returns the maximum CSRF tokens allowed at the same time, per session.
 * @return int Number of tokens allowed at the same time.
 */
function get_csrf_max_tokens(): int
{
    return 64;
}

/**
 * Returns the length of each CSRF token's string.
 * @return int Token length.
 */
function get_csrf_token_length(): int
{
    return 32;
}

/**
 * <USER>
 * Generate a CSRF token and returns it.
 * @param  string|null $ctx Optional context.
 * @return string           Token string.
 */
function csrf_token(?string $ctx = null): string
{
    csrf_gc();

    $tokens = get_session_var($sn = get_csrf_session_name());
    if (!$tokens || !is_array($tokens)) $tokens = [];

    $token = bin2hex(random_bytes(get_csrf_token_length()));
    $tokens[$token] = [
        'ctx'     => $ctx,
        'expires' => time() + get_csrf_ttl(),
    ];

    set_session_var($sn, $tokens);
    return $token;
}

/**
 * <USER>
 * Check if a CSRF token is valid.
 * @param  string      $token Token to be verified.
 * @param  string|null $ctx   Optional context in which this token applies.
 * @return bool               Is valid or not?
 */
function csrf_verify(string $token, ?string $ctx = null): bool
{
    $tokens = get_session_var($sn = get_csrf_session_name());

    if (!($t = ($tokens[$token] ?? null))) return false;
    unset($tokens[$token]);
    set_session_var($sn, $tokens);

    return $token['expires'] >= time();
}

/**
 * <USER>
 * Check if a CSRF token is valid through <csrf_verify()>, and, if invalid,
 * returns a JSON error or a 403 error.
 * @param  string      $token  Token to be verified.
 * @param  string|null $ctx    Optional context in which this token applies.
 * @param  string      $output Output: 'exception', json' or 'auto'.
 *                             The mode 'auto' checks if the request if a XHR.
 */
function csrf_assert(string $token, ?string $ctx = null, string $output = 'auto'): void
{
    if (csrf_verify($token, $ctx)) return;
    if ($output === 'auto') $output = is_xhr() ? 'json' : 'exception';
    if ($output === 'json') json_error('invalid_csrf_token');
    throw new Microbe_Unauthorized_Exception("Invalid CSRF Token");
}

/**
 * <USER>
 * Drop all CSRF tokens. To be used after every important action
 * (aka logins, logouts, etc.).
 */
function csrf_rotate(): void
{
    delete_session_var(get_csrf_session_name());
}

/**
 * <USER>
 * Remove expired CSRF tokens and limit tokens to the maximum number allowed.
 */
function csrf_gc(): void
{
    if (!($tokens = get_session_var($sn = get_csrf_session_name()))) return;
    $now = time();
    $tokens = array_filter($tokens, fn(array $token): bool => $token['expires'] >= $now);
    if (count($tokens) > ($max = get_csrf_max_tokens())) {
        uasort($tokens, fn(array $a, array $b): int => $a['expires'] <=> $b['expires']);
        $tokens = array_slice($tokens, -1 * $max, preserve_keys: true);
    }
    set_session_var($sn, $tokens);
}

/**
 * <USER>
 * Write a hidden HTML input with a new CSRF token.
 * @param  string|null $ctx  Optional context.
 * @param  string      $name Name of the input.
 */
function csrf_input(?string $ctx = null, string $name = '_csrf', array $attrs = []): void
{
    echo (string) dom('input')->attrs(array_merge($attrs, [
        'type'  => 'hidden',
        'name'  => $name,
        'value' => csrf_token($ctx),
    ]));
}
