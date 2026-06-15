<?php

// =============================================================================
// ---{ Constants }-------------------------------------------------------------

def([
    'MB_FLASH_MSG_INFO'    => 0x01,
    'MB_FLASH_MSG_SUCCESS' => 0x02,
    'MB_FLASH_MSG_WARNING' => 0x03,
    'MB_FLASH_MSG_ERROR'   => 0x04,
]);

def('MB_FLASH_MSG_DEFAULT', MB_FLASH_MSG_INFO);

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Start the session if it was not already done.
 * @return boolean True if the session was correctly started. Else, false.
 */
function session_setup()
{
    if (cfg('~@session.enabled') === false) return false;
    if (cfg('~' . ($k = '@core.session.started'))) return true;
    cfg($k, true);

    if (session_status() !== PHP_SESSION_NONE) return true;

    session_name('MBSID');
    if (!($dir = cfg('~@sessions.path'))) $dir = defined('MB_SESSION_DIR') ? MB_SESSION_DIR : get_sessions_dir();
    if (!is_dir($dir)) mkdir($dir, get_mkdir_chmod(), true);
    if (fileowner($dir) === posix_geteuid()) session_save_path($dir);
    session_start();
    return true;
}

/**
 * <USER>
 * Get a value from the global $_SESSION array.
 * @param  string $name Name of the session var.
 * @return mixed        The value if exists, null else.
 */
function get_session_var(string $name): mixed
{
    if (!session_setup()) return null;
    return $_SESSION[$name] ?? null;
}

/**
 * <USER>
 * Set some value for a specific session variable (aka global $_SESSION array).
 * @param string     $name  Name of the variable.
 * @param mixed|null $value Value to set.
 */
function set_session_var(string $name, mixed $value = null): void
{
    if (!session_setup() || !isset($_SESSION)) return;
    $_SESSION[$name] = $value;
}

/**
 * <USER>
 * Delete a session variable (aka an entry of the global $_SESSION array).
 * @param  string $name Name of the variable.
 */
function delete_session_var(string $name): void
{
    if (!session_setup()) return;
    if (array_key_exists($name, $_SESSION ?? [])) unset($_SESSION[$name]);
}

/**
 * Returns all the session flash variables.
 * @return array Key/Value array containing the flash variables.
 */
function get_flash_vars(): array
{
    return get_session_var('_flash') ?: [];
}

/**
 * <USER>
 * Define some flash variables.
 * @param array|null $vars    Array representing the flash variables.
 * @param boolean    $replace Replace all the flash variables.
 *                            If false (default), the given $vars will be
 *                            merged into the current variables.
 */
function set_flash_vars(?array $vars = null, bool $replace = false): void
{
    if ($replace === false) {
        foreach ($vars as $k => $v) set_flash_var($k, $v);
        return;
    }

    if (!is_array($vars) || empty($vars)) {
        delete_session_var('_flash');
        return;
    }

    set_session_var('_flash', $vars);
}

/**
 * <USER>
 * Define a flash variable. This value, represented by a name, will be stored
 * in the session until a standard call to <get_flash_var>.
 * @param string     $name  Name of the flash variable.
 * @param mixed|null $value Value of the variable.
 */
function set_flash_var(string $name, mixed $value = null): void
{
    $all = get_flash_vars();
    $all[$name] = $value;
    set_flash_vars($all, true);
}

/**
 * <USER>
 * Delete a flash variable.
 * @param  string $name Name of the flash variable.
 */
function delete_flash_var(string $name): void
{
    $all = get_flash_vars();
    if (array_key_exists($name, $all)) unset($all[$name]);
    set_flash_vars($all, true);
}

/**
 * <USER>
 * Get a flash variable. If the second parameter stays at true, the variable
 * will be deleted. The standard use case of a flash variable should be to
 * be set on a request, then get and cleaned on the next request
 * (e.g. form data/errors, global error message, etc.).
 * @param  string  $name   Name of the flash variable.
 * @param  boolean $delete Get the value, then delete the variable before
 *                         the return. Default is true.
 * @return mixed           Value of the flash variable, or null if undefined.
 */
function get_flash_var(string $name, bool $delete = true): mixed
{
    $value = get_flash_vars()[$name] ?? null;
    if ($delete) delete_flash_var($name);
    return $value;
}

/**
 * <USER>
 * Push a flash message. Basically, it will get, push then
 * set a flash variable. If the value is empty or null, the message will be
 * @param  string  $msg   Value to push.
 * @param  string  $name  Name of the messages context.
 * @param  integer $type  Type of the message.
 *                        Should be one of the MB_FLASH_MSG_* constants.
 */
function push_flash_message(?string $msg = null, string $name = '*', int $type = MB_FLASH_MSG_DEFAULT): void
{
    $name = '_messages.' . $name;
    if (!$msg) return;
    if (!($items = get_flash_var($name, false))) $items = [];
    else if (!is_array($items)) $items = [];
    $items[] = [
        'type'    => $type,
        'message' => $msg,
    ];
    set_flash_var($name, $items);
}

/**
 * <USER>
 * Returns the flash messages of the given context name.
 * The result will be an array containing zero or more objects with two
 * properties: the type (corresponding to one of the MB_FLASH_MSG_* constants)
 * and the message as a string.
 * If the second parameter is left as true, the flash messages will be
 * deleted after the getting.
 * @param  string  $name   Name of the messages context.
 * @param  boolean $delete Get the value, then delete the messages before
 *                         the return. Default is true.
 * @return array           Array containing the flash messages as objects.
 */
function get_flash_messages(string $name, bool $delete = true): array
{
    $name = '_messages.' . $name;
    $messages = get_flash_var($name, $delete);
    if (!$messages || !is_array($messages)) return [];
    return array_values(array_filter(array_map(function(mixed $m): ?object
    {
        if (!$m || !is_array($m)) return null;
        $m = (object) array_merge([
            'type'     => null,
            'type_str' => null,
            'message'  => null,
        ], $m);
        if (!$m->message || !is_string($m->message)) return null;
        if (!$m->type) $m->type = MB_FLASH_MSG_DEFAULT;
        $m->type_str = match ($m->type) {
            MB_FLASH_MSG_INFO    => 'info',
            MB_FLASH_MSG_SUCCESS => 'success',
            MB_FLASH_MSG_WARNING => 'warning',
            MB_FLASH_MSG_ERROR   => 'error',
            default              => null,
        };
        return $m;
    }, $messages)));
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('before_first_render', function(): void
{
    set_template_vars([
        'global_flash_messages' => get_flash_messages('*'),
    ]);
});

// =============================================================================
