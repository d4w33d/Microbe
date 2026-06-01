<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Get the action parameter name.
 * @return string Parameter name.
 */
function get_action_param(): string
{
    return cfg('~@core.actions.param') ?: 'do';
}

/**
 * <USER>
 * Set the action parameter name (default is 'action').
 * @param string $param Parameter name.
 */
function set_action_param(string $param): void
{
    cfg('@core.actions.param', $param);
}

/**
 * <USER>
 * Get the currently requested action name.
 * @return string Action name.
 */
function get_current_action_name(): string
{
    return get(get_action_param()) ?: '';
}

/**
 * <USER>
 * Register an action, known as a controller $_GET/$_POST drived.
 * @param  array|string  $name     Name(s) of the action(s)
 *                                 (value of get(get_action_param())).
 * @param  Closure       $callback Function to execute if the action is ran.
 */
function action(array | string $name, Closure $callback): void
{
    if (is_array($name)) {
        foreach ($name as $n) action($n, $callback);
        return;
    }

    if (get_current_action_name() !== $name) return;

    $args = compute_route_args(get_relative_url());

    cfg('@core.routes.found', true);
    foreach (cfg('~@core.routes.before_any') ?: [] as $func) {
        call_user_func_array($func, (object) [ 'args' => $args ]);
    }

    call_user_func_array($callback, array_values($args));
    close();
}

/**
 * <USER>
 * Register the default action, an alias of default_action('', ...).
 * @param  Closure $callback Function to execute if the action is ran.
 */
function default_action(Closure $callback): void
{
    action('', $callback);
}

// =============================================================================
