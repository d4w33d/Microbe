<?php

// =============================================================================
// ---{ Constants }-------------------------------------------------------------

define('ACL_DEFAULT',    'default');
define('ACL_ALLOWED',    'allowed');
define('ACL_DISALLOWED', 'disallowed');

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Check if the permission status is one of the valid ACL_* constants.
 * @param  mixed   $status Status to check.
 * @return bool            Is it valid or not?
 */
function is_valid_permission_status(mixed $status): bool
{
    if (!is_string($status)) return false;
    return in_array($status, [
        ACL_DEFAULT,
        ACL_ALLOWED,
        ACL_DISALLOWED,
    ]);
}

/**
 * <USER>
 * Register an ACL permission, which will be used to edit permissions
 * of a user or a role.
 * @param  string      $name        Internal name of the permission.
 * @param  string|null $title       Pretty name/title of the permission.
 * @param  string|null $description Detailled description of what the
 *                                  permission allows to do.
 * @param  string|null $icon        Icon name representing the permission.
 * @param  bool        $default     Default value of the permission:
 *                                  ACL_ALLOWED or ACL_DISALLOWED.
 *
 */
function register_acl_permission(
    string  $name,
    ?string $title       = null,
    ?string $description = null,
    ?string $icon        = null,
    string  $default     = ACL_DISALLOWED,
): void
{
    register_thing('acl_permissions', (object) [
        'name'        => $name,
        'title'       => $title ?: $name,
        'description' => $description,
        'icon'        => $icon,
        'default'     => $default,
    ], key: $name);
}

/**
 * <USER>
 * Returns the registered ACL permissions.
 * @return array List of permissions registered.
 */
function get_registered_acl_permissions(): array
{
    return get_registered_things('acl_permissions');
}

/**
 * <USER>
 * Returns a specific registered ACL permission.
 * @param  string      $name Internal name of the permission.
 * @return object|null       Object representing the permission, if exists.
 */
function get_registered_acl_permission(string $name): ?object
{
    return get_registered_thing('acl_permissions', key: $name);
}

/**
 * <USER>
 * Register a ACL validator function, used to check if the current visitor
 * is allowed to perform an action.
 * @param  string  $name Name of the validator source (e.g. user_role).
 * @param  Closure $func Function called to validate the action. This function
 *                       should accept one argument: the permission name.
 *                       Then, the function should return a boolean meaning if
 *                       the current visitor was allowed to perform the
 *                       action requiring the permission, or not.
 */
function register_acl_validator(string $name, Closure $func): void
{
    register_thing('acl_validators', (object) [
        'name' => $name,
        'func' => $func,
    ]);
}

/**
 * Returns registered ALC validators.
 * @return array The collection of ACL validators.
 */
function get_registered_acl_validators(): array
{
    return get_registered_things('acl_validators');
}

/**
 * <USER>
 * Execute registered ACL validators to ask them if the current visitor is
 * allowed to perform the action requiring the permission(s).
 * @param  string|array $permission Permission(s) name(s).
 * @return bool                     Is the user allowed or not?
 */
function is_allowed(string | array $permission): bool
{
    if (!is_array($permission)) $permission = [ $permission ];
    if (!($validators = get_registered_acl_validators())) return true;
    foreach ($permission as $perm) {
        $allowed = false;
        foreach ($validators as $validator) {
            if (!call_user_func($validator->func, $perm)) continue;
            $allowed = true;
            break;
        }
        if (!$allowed) return false;
    }
    return true;
}

/**
 * <USER>
 * Do the exact opposite of <is_allowed()>.
 * @param  string|array $permission Permission(s) name(s).
 * @return bool                     Is the user disallowed or not?
 */
function is_disallowed(string | array $permission): bool
{
    return !is_allowed($permission);
}

/**
 * <USER>
 * Check if the user is allowed to perform the action requiring
 * the permission(s).
 * If it's OK, the function returns nothing.
 * If it's not, the user will be either:
 *   - Redirected to $redirect if provided;
 *   - Shown a 403 error.
 * @param  string|array $permission      Permission(s) name(s).
 * @param  string|null  $redirect        Redirect URL.
 * @param  bool         $login           If true, user will be redirected
 *                                       to login page.
 * @param  bool         $throwIfLoggedIn If true, and if user is logged in, the
 *                                       user will be shown a 403 error even if
 *                                       $redirect is not not null or $login
 *                                       is true.
 * @return mixed                         If assertion is passed, returns
 *                                       the currently logged in user if
 *                                       available.
 */
function assert_allowed(
    string | array $permission,
    ?string        $redirect        = null,
    bool           $login           = false,
    bool           $throwIfLoggedIn = true,
): mixed
{
    if (is_allowed($permission)) return get_logged_in_user();
    if (!is_logged_in() || !$throwIfLoggedIn) {
        if ($redirect) redirect($redirect);
        if ($login) redirect(cfg('~@acl.urls.login') ?: '/login');
    }
    throw new Microbe_Unauthorized_Exception("ACL Validation Failed for permission(s) '" . (is_array($permission) ? implode(', ', $permission) : $permission) . "'");
}

// =============================================================================
