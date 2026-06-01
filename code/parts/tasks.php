<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * @param  string  $name       Name of the task.
 * @param  Closure $func       Function executed when the task is called.
 *                             This function takes two arguments:
 *                               - string $ctx: The context ('web' or 'cli').
 *                               - object $args: An object containing arguments
 *                                               passed as command line
 *                                               parameter or query string.
 * @param  array   $args       Arguments description, taking associative arrays
 *                             with the following keys: string 'desc' and
 *                             bool 'optional'.
 * @param  bool    $webEnabled Task is enabled on web context.
 * @param  bool    $cliEnabled Task is enabled on cli context.
 */
function register_task(
    string  $bundle,
    string  $name,
    Closure $func,
    array   $args       = [],
    bool    $webEnabled = true,
    bool    $cliEnabled = true,
): void
{
    register_thing('tasks', (object) [
        'bundle'  => $bundle,
        'name'    => $name,
        'uid'     => $uid = str_replace('c', 'z', hash('sha256', 'task:' . $bundle . ':' . $name . ':' . cfg('@security.salt'))),
        'func'    => $func,
        'args'    => $args,
        'file'    => get_data_dir('tasks', 'auto', $uid . '.php'),
        'enabled' => (object) [
            'web' => $webEnabled,
            'cli' => $cliEnabled,
        ],
    ]);
}

/**
 * Assert if the standalone files for each task are created,
 * and delete useless tasks files.
 * @param  array|null $tasks Registered tasks. If null, tasks will
 *                           be retrieved.
 * @param  bool       $force Force generation of files even if exists.
 */
function assert_tasks_files(?array $tasks = null, bool $force = false): void
{
    $snippet = '<' . '?php declare(strict_types=1);' . "\n"
        . 'define(\'MB_HANDLE_ERRORS\', true);' . "\n"
        . 'define(\'MB_EXEC_TASK\', \'{task_uid}\');' . "\n"
        . 'require_once __DIR__ . DIRECTORY_SEPARATOR . \'..\' . DIRECTORY_SEPARATOR . \'..\' . DIRECTORY_SEPARATOR . \'..\' . DIRECTORY_SEPARATOR . \'' . get_microbe_file_name() . '\';' . "\n"
        . 'boot(\'cli\');' . "\n";
    $dir = null;
    $files = [];
    foreach ($tasks ?: get_registered_tasks(assertFiles: false) as $task) {
        if (!$force && is_file($task->file)) continue;
        if ($dir === null) rmkdir($dir = dirname($task->file));
        file_put_contents($task->file, str_replace('{task_uid}', $task->uid, $snippet));
        $files[$task->file] = true;
    }
    if ($dir) {
        foreach (get_files($dir) as $f) {
            if (array_key_exists($f->getPath(), $files)) continue;
            $f->delete();
        }
    }
}

/**
 * Returns registered tasks.
 * @param  bool  $assertFiles Execute assert_tasks_files() in order to control
 *                            existence of standalone PHP files which execute
 *                            the task.
 * @return array              Registered tasks.
 */
function get_registered_tasks(bool $assertFiles = true): array
{
    $tasks = get_registered_things('tasks');
    usort($tasks, function(object $a, object $b): int
    {
        $ab = strtolower($a->bundle);
        $bb = strtolower($b->bundle);
        if ($ab < $bb) return -1;
        if ($ab > $bb) return 1;
        $an = strtolower($a->name);
        $bn = strtolower($b->name);
        if ($an < $bn) return -1;
        if ($an > $bn) return 1;
        return 0;
    });
    if ($assertFiles) assert_tasks_files($tasks);
    return $tasks;
}

/**
 * Returns a specific registered task.
 * @param  string|null $uid    UID of the task.
 * @param  string|null $bundle Bundle of the task (associated with $name).
 * @param  string|null $name   Name of the task (associated with $bundle).
 * @return object|null         Task object, or null if not found.
 */
function get_registered_task(?string $uid = null, ?string $bundle = null, ?string $name = null): ?object
{
    foreach (get_registered_tasks() as $task) {
        if (($uid && ($task->uid === $uid))
            || ($bundle && $name && $bundle === $task->bundle && $name === $task->name)) {
            return $task;
        }
    }
    return null;
}

/**
 * Generate the callable URL for a specific task.
 * @param  string      $taskUid Task UID.
 * @return string|null          Task URL, or null if task not found.
 */
function generate_task_url(string $taskUid): ?string
{
    if (!($task = get_registered_task(uid: $taskUid))) return null;
    return url(get_microbe_file_name(), [ 'task' => $task->uid ], host: true);
}

/**
 * Generate the shell command to execute the task through related CLI action.
 * @param  string $taskUid Task UID.
 * @return string          Shell command.
 */
function generate_task_cli_path(string $taskUid): string
{
    if (!($task = get_registered_task(uid: $taskUid))) return null;
    return 'php ' . __FILE__ . ' core task ' . $task->bundle . ' ' . $task->name;
}

/**
 * <USER>
 * Execute a specific task.
 * @param  string $taskUid Task name or UID.
 * @param  string $ctx     Context: 'web' or 'cli'.
 * @param  array  $args    Arguments passed to the task (usualy CLI arguments
 *                         or query-strings).
 */
function execute_task(string $taskUid, string $ctx, object | array $args = []): void
{
    if (!($task = get_registered_task(uid: $taskUid))) throw new Microbe_Exception("Trying to execute a non-registered task: {$ref}");
    if (!property_exists($task->enabled, $ctx)) throw new Microbe_Exception("Trying to execute a task ({$ref}) with an unknown context: {$ctx}");
    if (!$task->enabled->$ctx) throw new Microbe_Exception("Trying to execute a task ({$ref}) which is disabled for this context: {$ctx}");
    call_user_func_array($task->func, [ $ctx, (object) $args ]);
    exit;
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('init', function(): void
{
    register_cli_action(
        bundle:      'core',
        name:        'task',
        description: "Execute a task.",
        opts:        [],
        func:        function(object $opts, ?string $bundleName = null, ?string $taskName = null): void
        {
            if (!$taskName) {
                cli_table(
                    heading: "Registered Tasks",
                    rows: array_map(function(object $task): array {
                        $args = [];
                        foreach ($task->args as $argKey => $argInfo) {
                            $args[] = "'" . $argKey . "'" . ($argInfo['optional'] ? ' (opt.)' : '') . ($argInfo['desc'] ? ': ' . $argInfo['desc'] : '');
                        }
                        return [
                            "Bundle"    => $task->bundle,
                            "Task Name" => $task->name,
                            "Web"       => $task->enabled->web ? "Yes" : "No",
                            "Cli"       => $task->enabled->cli ? "Yes" : "No",
                            "Arguments" => $args ? implode(' | ', $args) : "None",
                        ];
                    }, get_registered_tasks()),
                );
                return;
            }
            if (!($task = get_registered_task(bundle: $bundleName, name: $taskName))) {
                cli_error("Invalid bundle name or task name.");
                return;
            }
            execute_task(taskUid: $task->uid, ctx: 'cli', args: $opts);
        },
    );
});

// =============================================================================
