<?php

// =============================================================================
// ---{ PHP Configuration }-----------------------------------------------------

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// =============================================================================
// ---{ Constants }-------------------------------------------------------------

define('MB_ROOT_DIR',         __DIR__);
define('MB_FILENAME',         basename(__FILE__));
define('MB_DIRECT_ACCESS',    ltrim(get_relative_url(false), '/') === MB_FILENAME);
define('MB_SCRIPT_START',     microtime(true));
define('MB_DEFAULT_TIMEZONE', ($tz = (new DateTime())->getTimezone()) ? $tz->getName() : null);
define('MB_CLI',              strpos(php_sapi_name(), 'cli') !== false);
define('MB_CLI_SELF',         MB_CLI && (basename($_SERVER['SCRIPT_NAME'] ?? '') === MB_FILENAME));

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Boot the application, with the given context:
 *   - 'web' should be used from 'index.php';
 *   - 'cli' will be used automatically when calling the framework's
 *     file from command line;
 *   - 'dev' will be used automatically when openning the framework's file
 *     directly from the web browser.
 * @param  string $ctx Context name
 */

function boot(string $ctx = 'web'): void
{
    if (!defined('MB_VENDOR_AUTOLOAD')) define('MB_VENDOR_AUTOLOAD', get_path('vendor', 'autoload.php'));
    if (MB_VENDOR_AUTOLOAD && is_file(MB_VENDOR_AUTOLOAD)) require_once MB_VENDOR_AUTOLOAD;

    load_config();

    date_default_timezone_set(get_app_timezone()->getName());

    include_files('helpers');
    include_files('library');
    include_files('entities');
    include_files('queries');

    Microbe_Entity::initAll();

    include_files('init');
    dispatch('init');

    if ($ctx === 'dev') {
        if ($taskUid = get('task')) {
            dispatch('before_tasks');
            $taskArgs = get();
            unset($taskArgs['task']);
            execute_task(taskUid: $taskUid, ctx: 'web', args: $taskArgs);
            exit;
        }

        dispatch('before_dev');
        if (!filter('dev_console_override', false)) dev_console();
        exit;
    }

    if ($ctx === 'cli') {
        if (defined('MB_EXEC_TASK') && MB_EXEC_TASK) {
            dispatch('before_tasks');
            if (!($task = get_registered_task(uid: MB_EXEC_TASK))) die("Invalid Task UID.\n");
            execute_task(taskUid: $task->uid, ctx: 'cli');
            exit;
        }
        dispatch('before_cli');
        include_files('console');
        cli_run();
        exit;
    }

    if ($ctx === 'web') {
        dispatch('before_api_endpoints');
        include_files('api');

        dispatch('before_routes');
        dispatch('before_api_routes');
        declare_api_routes();
        dispatch('before_ctrl_routes');

        include_root_files('ctrl');
        foreach (get_bundles_files('ctrl') as $f) {
            clear_route_filters();
            require_once $f;
        }

        if (cfg('~@core.initialized') && !cfg('~@core.routes.found')) {
            if (!call_fallback_route()) throw_404();
        }
        exit;
    }
}

// =============================================================================
// ---{ Boot, when the framework is called directly }---------------------------

if (MB_CLI_SELF) boot('cli');
else if (MB_DIRECT_ACCESS) boot('dev');

// =============================================================================
