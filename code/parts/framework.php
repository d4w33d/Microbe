<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Returns the Microbe file path.
 * @return string Probably the Microbe file path.
 */
function get_microbe_file_path(): string
{
    return __FILE__;
}

/**
 * Returns the Microbe file name.
 * @return string Probably the Microbe file name.
 */
function get_microbe_file_name(): string
{
    return basename(get_microbe_file_path());
}

/**
 * <USER>
 * Returns the version and hashes of the framework.
 * @return object An object describing the meta hash, the hash and the
 *                integer version.
 */
function microbe_version(): object
{
    $raw = file_get_contents(__FILE__);

    return (object) [
        'meta_hash' => get_microbe_version_meta_hash($raw),
        'hash'      => get_microbe_version_hash($raw),
        'version'   => get_microbe_version_number($raw),
        'date'      => get_microbe_version_date($raw),
    ];
}

/**
 * Parse the source code to find the meta hash.
 * @param  string      $raw Source code of the framework.
 * @return string|null      Hash, or null if not found.
 */
function get_microbe_version_meta_hash(string $raw): ?string
{
    $r = '/^[ \t]*\/\/[ \t]*\|[ \t]*\-\{\#[ \t]*(?<h>[a-f0-9]{16})[ \t]*\#\}\-[ \t]*\|[ \t]*$/m';
    if (!preg_match($r, $raw, $m)) return null;
    return $m['h'];
}

/**
 * Parse the source code to find the source code hash.
 * @param  string      $raw Source code of the framework.
 * @return string|null      Hash, or null if not found.
 */
function get_microbe_version_hash(string $raw): ?string
{
    $r = '/^[ \t]*\/\/[ \t]*\|[ \t]*\-\{\#[ \t]*(?<h>[a-f0-9]{64})[ \t]*\#\}\-[ \t]*\|[ \t]*$/m';
    if (!preg_match($r, $raw, $m)) return null;
    return $m['h'];
}

/**
 * Parse the source code to find the version number.
 * @param  string   $raw Source code of the framework.
 * @return int|null      Version number, or null if not found.
 */
function get_microbe_version_number(string $raw): ?int
{
    $r = '/^[ \t]*\/\/[ \t]*\|[ \t]*\-\{\#[ \t]*(?<v>[0-9]+)[ \t]*\#\}\-[ \t]*\|[ \t]*$/m';
    if (!preg_match($r, $raw, $m)) return null;
    return abs((int) ($m['v'] ?: 0)) ?: null;
}

/**
 * Parse the source code to find the version date.
 * @param  string      $raw Source code of the framework.
 * @return DateTime|null    Version date, or null if not found.
 */
function get_microbe_version_date(string $raw): ?DateTime
{
    $r = '/^[ \t]*\/\/[ \t]*\|[ \t]*\-\{\#[ \t]*(?<v>[0-9-]+T[0-9:]+[0-9:+-]+)[ \t]*\#\}\-[ \t]*\|[ \t]*$/m';
    if (!preg_match($r, $raw, $m)) return null;
    return new DateTime($m['v']);
}

/**
 * Generate an API URL for framework requests
 * (e.g. Updates and Plugins Warehouse).
 * @param  string $do   Action to perform on the API.
 * @param  array  $args Optional query-string arguments.
 * @return string       URL targeting the API.
 */
function microbe_api_url(string $do, array $args = []): string
{
    $prefix = cfg('~@framework.api_url') ?: 'https://microbe.barbichette.net/api';
    if (is_domain_name('microbe.test')) $prefix = 'http://microbe.framework/api';

    $url = null;

    if ($do === 'warehouse') $url = $prefix . '/warehouse';
    if ($do === 'updates') $url = $prefix . '/updates';

    if ($url === null) throw new Microbe_Exception("Unable to generate API URL of unknown API context {$do}");
    if ($args = array_filter($args)) $url .= '?' . http_build_query($args);
    return $url;
}

/**
 * Retrieve the available updates from the framework's API, based on current
 * framework and plugins versions.
 * @param  string|null &$reason Reason of the error will be set in this
 *                              variable.
 * @return array|null           Updates available, or null if an error occured.
 */
function get_available_updates(?string &$reason = null): ?array
{
    $versions = [ '*:' . microbe_version()->version ];

    $result = curl_post(microbe_api_url('updates'), [
        'c' => implode('|', $versions),
    ]);

    if (!$result) { $reason = "Empty cURL response"; return null; }
    if (!($data = json_decode($result))) { $reason = "Invalid JSON"; return null; }
    if (!property_exists($data, 'success')) { $reason = "Invalid data: missing 'success' property"; return null; }
    if (!$data->success) { $reason = "Response sent an error ('success' is false)"; return null; }
    if (!property_exists($data, 'updates') || !is_array($data->updates)) { $reason = "Invalid updates array"; return null; }
    return $data->updates;
}

/**
 * Perform an update on the framework itself.
 * @param  string $url Download URL got from <get_available_updates>.
 * @return bool        Success (true) or not (false).
 */
function update_framework(string $url): bool
{
    if (!($raw = curl_get($url))) return false;
    if (strpos($raw, '<?php') !== 0) return false;
    file_put_contents(__FILE__, $raw);
    return true;
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'framework' => [
            'api_url' => null,
        ],
    ];
});

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'dev' => [
            'secret_key' => password(128),
        ],
    ];
});

listen('init', function(): void
{
    register_cli_action(
        bundle:      'core',
        name:        'version',
        description: "Get Microbe's Framework installed version.",
        opts:        [],
        func:        function(object $opts): void
        {
            $version = microbe_version();
            cli_write("Version:   " . $version->version);
            cli_write("Hash:      " . $version->hash);
            cli_write("Meta Hash: " . $version->meta_hash);
            cli_write("Date:      " . ($version->date ? $version->date->format('c') : "(unknown)"));
        },
    );

    register_cli_action(
        bundle:      'core',
        name:        'update',
        description: "Update Microbe's Framework file.",
        opts:        [ 'yes' => [ '.', 'y' ] ],
        func:        function(object $opts): void
        {
            $reason = null;
            if (($updates = get_available_updates($reason)) === null) {
                cli_write("Unable to fetch available updates: {$reason}.");
            } else if ($nbUpdates = count($updates)) {
                $paddedNbUpdates = str_pad($nbUpdates, 3, '0', STR_PAD_LEFT);
                cli_write("{$nbUpdates} update(s) found.");
                foreach ($updates as $idx => $u) {
                    $paddedIdx = str_pad($idx + 1, 3, '0', STR_PAD_LEFT);
                    cli_write(" ({$paddedIdx}/{$paddedNbUpdates}) <{$u->title}> {$u->current} -> {$u->available} (Y/n) ", end: null);
                    if ($opts->yes || strtolower(trim(readline(''))) !== 'n') {
                        if ($opts->yes) cli_write("y");
                        if ($u->type === 'framework') {
                            update_framework($u->url);
                        }
                        cli_write("           > Updated.");
                    }
                }
                cli_write("End Of Updates.");
            } else {
                cli_write("Up-to-date.");
            }
        },
    );
});

// =============================================================================
