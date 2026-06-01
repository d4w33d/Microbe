<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Deploy (git pull).
 * @param  bool|null $log Log result. If null, config value is used.
 */
function deploy(?bool $log = null): void
{
    if (!cfg('~@deploy.enabled')) return;

    chdir(get_root_dir());
    $cmd = cfg('@deploy.commands.pull') . ' 2>&1';
    $result = shell_exec($cmd);

    if (($log === true) || ($log === null && cfg('@deploy.log'))) slog('deploy', $result);

    json_success();
}

/**
 * <USER>
 * Get the last commit information.
 * @return object Last commit information.
 */
function get_last_commit(): ?object
{
    if (!($cmd = cfg('~@deploy.commands.last_commit'))) return null;

    chdir(get_root_dir());
    $cmd = $cmd . ' 2>&1';
    if (!($result = shell_exec($cmd))) return null;
    if (!preg_match('/^\s*commit\s+(?<commit>[a-z0-9]+)\n+\s*author:\s*(?<author>[^\n]+)\n+\s*date:\s*(?<date>[^\n]+)(?<msg>.+)\n\n[a-z].+$/ims', $result, $m)) return null;

    return (object) [
        'commit'  => trim($m['commit']),
        'author'  => trim($m['author']),
        'date'    => new DateTime(trim($m['date'])),
        'message' => trim($m['msg']),
    ];
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('init', function(): void
{
    register_cli_action(
        bundle:      'core',
        name:        'deploy',
        description: "Execute the deploy function.",
        opts:        [],
        func:        function(object $opts): void
        {
            if (is_env('dev')) {
                cli_write("Unable to deploy: current environment is <dev>.");
                exit;
            }

            cli_write("Deploying...");
            deploy();
            cli_write("Done.");
        },
    );
});

register_filter('dev_console_override', function(bool $overrided): bool
{
    if (!MB_DIRECT_ACCESS) return $overrided;
    if (get('do') !== 'deploy') return $overrided;
    if (!cfg('~@deploy.enabled')) return $overrided;

    if (!in_array($secretKey = get('key'), cfg('@deploy.keys'))) json_error('unauthorized');

    deploy();
    close();
    return true;
});

listen('register_cfg_snippets', function(): array
{
    return [
        'deploy' => [
            'enabled'  => false,
            'log'      => true,
            'commands' => [
                'pull'        => '/usr/bin/git pull',
                'last_commit' => '/usr/bin/git log --name-status HEAD^..HEAD',
            ],
            'keys'     => array_map(function(): string
            {
                return password(len: 128, useSpecials: false);
            }, array_fill(0, 8, null)),
        ],
    ];
});

// =============================================================================
