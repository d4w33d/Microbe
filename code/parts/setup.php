<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Setup the framework's environment, with specific scope(s).
 * @param  string|array  $scopes Setup scope(s): web, or config, tree, samples.
 */
function setup(string | array $scopes): void
{
    if (!is_array($scopes)) $scopes = [ $scopes ];

    if (in_array('web', $scopes) || in_array('config', $scopes)) setup_config();
    if (in_array('web', $scopes) || in_array('tree', $scopes)) setup_tree();
    if (in_array('web', $scopes) || in_array('samples', $scopes)) setup_samples();
}

/**
 * Setup configuration.
 */
function setup_config(): void
{
    file_put_contents(get_path('config.json'), get_setup_cfg_json());
}

/**
 * Setup tree.
 */
function setup_tree(): void
{
    foreach (__get_samples_dirs() as $d) {
        $ad = get_path($d);
        if (!is_dir($ad)) mkdir($ad, get_mkdir_chmod(), true);
    }
}

/**
 * Setup samples.
 */
function setup_samples(): void
{
    foreach (__get_samples_files() as $f => $encoded) {
        $af = get_path($f);
        if (!is_dir($ad = dirname($af))) mkdir($ad, get_mkdir_chmod(), true);
        file_put_contents($af, base64_decode($encoded));
    }
}

/**
 * Fire the event 'register_cfg_snippets' and merge all the results to create
 * the sample configuration file based on those returned arrays, then returns
 * this merging as a JSON string.
 * @return string JSON string representing the sample configuration file.
 */
function get_setup_cfg_json(): string
{
    $cfg = [
        'app' => [
            'name'    => ucwords(create_sentence(2, 4, 3, 9, false)),
            'version' => '1.0.0',
        ],
    ];
    $results = dispatch('register_cfg_snippets');
    foreach ($results as $r) $cfg = array_merge($cfg, $r);
    return json_encode($cfg, JSON_PRETTY_PRINT);
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('init', function(): void
{
    $setupScopes = [ 'web', 'config', 'tree', 'samples' ];

    register_cli_action(
        bundle:      'core',
        name:        'setup',
        description: "Setup Microbe's environement (additional arguments: " . implode(', ', $setupScopes) . ").",
        opts:        [],
        func:        function(object $opts, ?string $scope = null) use ($setupScopes): void
        {
            if (!in_array($scope, $setupScopes)) cli_error("Invalid setup scope.");
            setup($scope);
        },
    );
});

// =============================================================================
