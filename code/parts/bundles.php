<?php

// =============================================================================
// ---{ Constants }-------------------------------------------------------------

define('MB_BUNDLES_SYNONYMS', [
    [ 'bundle', 'bundles' ],
    [ 'brick',  'bricks'  ],
    [ 'module', 'modules' ],
    [ 'app',    'apps'    ],
]);

define('MB_BUNDLE_FILES_TYPES', [
    'helpers',
    'queries',
    'init',
    'ctrl',
    'entities',
    'library',
    'console',
    'api',
]);

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Returns an array containing the defined bundles.
 * @param  bool  $refresh Refresh the bundles. If false (default), it will get
 *                        the bundles infos from the ones stored in memory.
 * @return array          Bundles infos.
 */
function get_bundles(bool $refresh = false): array
{
    if (!$refresh && ($bundles = cfg('~' . ($ck = '@core.bundles')))) return $bundles;
    $bundles = [];
    foreach (get_bundles_dirs() as $dir) {
        foreach (ls($dir->path, files: false) as $f) {
            $bundles[] = get_bundle($f->getName(), synonym: $dir->synonym);
        }
    }
    cfg($ck, $bundles);
    return $bundles;
}

/**
 * <USER>
 * Returns all bundles directories, for each synonym available.
 * @return array Array of paths to directories.
 */
function get_bundles_dirs(): array
{
    return array_values(array_filter(array_map(function(array $synonym): ?object
    {
        if (!is_dir($dir = get_path($synonym[1]))) return null;
        return (object) [
            'path'    => $dir,
            'synonym' => $synonym,
        ];
    }, MB_BUNDLES_SYNONYMS)));
}

/**
 * <USER>
 * Check if a bundle exists or not.
 * @param  string       $name    Bundle name.
 * @param  bool|boolean $refresh Refresh the bundle info stored in memory.
 * @return bool                  The bundle exists or not.
 */
function bundle_exists(string $name, bool $refresh = false): bool
{
    return get_bundle(name: $name, refresh: $refresh) !== null;
}

/**
 * <USER>
 * Returns information about a bundle.
 * @param  string      $name    Name of the bundle.
 * @param  bool        $refresh Refresh bundle info, or get information
 *                              previously stored in memory.
 * @param  array       $synonym Synonym used (bundle folder).
 * @return null|object          Object container the bundle information.
 */
function get_bundle(string $name = 'default', bool $refresh = false, ?array $synonym = null): ?object
{
    $bundlesInfos = cfg('~' . ($ck = '@core.bundles_infos')) ?: [];
    if (!$refresh && array_key_exists($name, $bundlesInfos)) return $bundlesInfos[$name];

    $path = null;
    if ($synonym === null) {
        foreach (get_bundles_dirs() as $dir) {
            if (is_dir($bundlePath = join_path($dir->path, $name))) {
                $path = $bundlePath;
                $synonym = [ $singular, $plural ];
                break;
            }
        }
        if (!$path) return null;
    } else if (!is_dir($path = join_path(get_path($synonym[1]), $name))) {
        return null;
    }

    $info = (object) [
        'name'       => $name,
        'dir'        => $path,
        'synonym'    => $synonym,
        'paths'      => (object) array_fill_keys(MB_BUNDLE_FILES_TYPES, []),
        'manifest'   => (object) [ 'path' => null, 'data' => null ],
        'migrations' => [],
    ];

    foreach ($info->paths as $type => &$paths) {
        if (is_file($f = join_path($path, $type . '.php'))) $paths[] = $f;
        if (!is_dir($d = join_path($path, $type))) continue;
        foreach (ls($d, folders: false, filter: '/\.php$/i') as $f) $paths[] = $f->getPath();
        sort($paths);
    }

    foreach (MB_BUNDLES_SYNONYMS as list($singleSynonym, $pluralSynonym)) {
        if (!(is_file($manifestPath = join_path($path, $singleSynonym . '.json')))) continue;
        if (!($manifestData = json_decode(file_get_contents($manifestPath), true))) continue;
        if (!is_array($manifestData)) continue;
        $info->manifest->path = $manifestPath;
        $info->manifest->data = $manifestData;
        break;
    }

    if (!$info->manifest->data) $info->manifest->data = [];

    if (is_dir($d = join_path($path, 'migrations'))) {
        foreach (ls($d, folders: false) as $f) {
            if (!preg_match('/(?<n>.+)-up\.sql$/', $f->getName(), $m)) continue;
            $info->migrations[$m['n']] = (object) [
                'name' => '@' . $name . '/' . $m['n'],
                'up'   => $f->getPath(),
                'down' => is_file($down = preg_replace('/-up\.sql$/', '-down.sql', $f->getPath())) ? $down : null,
            ];
        }
        ksort($info->migrations);
    }

    $bundlesInfos[$name] = $info;
    cfg($ck, $bundlesInfos);
    return $info;
}

/**
 * Returns all files of a specific type (helpers, ctrl, etc.) of all bundles.
 * @param  string $type Type of file (helpers, ctrl, etc.).
 * @return array        An array containing all the paths.
 */
function get_bundles_files(string $type): array
{
    $paths = [];
    foreach (get_bundles() as $bundle) $paths = array_merge($paths, $bundle->paths->$type);
    return $paths;
}

/**
 * Includes all files of a specific type (helpers, ctrl, etc.) of all bundles.
 * @param  string $type Type of file (helpers, ctrl, etc.).
 * @param  bool   $once Require once instead of simply require.
 */
function include_bundles_files(string $type, bool $once = true): void
{
    foreach (get_bundles_files($type) as $path) {
        if ($once) require_once $path;
        else require $path;
    }
}

// =============================================================================
