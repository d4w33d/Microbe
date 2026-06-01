<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Returns backup target directory.
 * @param  bool   $abs As absolute path (useful if relative path is set in
 *                     config file).
 * @return string      Backup target directory.
 */
function get_backup_target(bool $abs = false): string
{
    $dir = cfg('~@backup.target') ?: 'data/backups';
    return $abs && !str_starts_with($dir, '/') ? get_path($dir) : $dir;
}

/**
 * <USER>
 * Returns backuped files.
 * @param  string|null $target Backup target directory.
 * @return array               Array containing objects with scoped
 *                             backup paths.
 */
function get_backuped_files(?string $target = null): array
{
    if ($target === null) $target = get_backup_target(true);
    if (!str_starts_with($target, DIRECTORY_SEPARATOR)) $target = get_path($target);
    if (!is_dir($target)) return [];
    $files = [];
    foreach (get_files($target) as $f) {
        if (!preg_match('/^BAK-(?<moment>(?<y>[0-9]{4})(?<m>[0-9]{2})(?<d>[0-9]{2})(?<h>[0-9]{2})(?<i>[0-9]{2})(?<s>[0-9]{2})-(?<ms>[0-9]+))-(?<n>.+)\.zip$/', $f->getName(), $m)) continue;
        if (!array_key_exists($m['moment'], $files)) {
            $files[$m['moment']] = (object) [
                'moment' => new DateTime($m['y'] . '-' . $m['m'] . '-' . $m['d'] . ' ' . $m['h'] . ':' . $m['i'] . ':' . $m['s']),
                'scopes' => (object) [ 'files' => null, 'db' => null ],
            ];
        }
        if (property_exists($files[$m['moment']]->scopes, $m['n'])) {
            $files[$m['moment']]->scopes->{$m['n']} = (object) [
                'path'          => $f->getPath(),
                'url'           => path_to_url($f->getPath()),
                'size'          => $size = $f->getSize(),
                'readable_size' => bytes_unit($size),
            ];
        }
    }
    krsort($files);
    return array_values($files);
}

/**
 * <USER>
 * Run backup.
 * @param  string | null         $target     Target directory.
 * @param  array | null          $scopes     Array of scopes: 'db' and/or 'files'.
 * @param  array | string | null $exclude    Exclude some paths. In case of string,
 *                                           excluded substrings should be
 *                                           separated by a comma or a semicolon.
 * @param  string | null         $execBefore Execute some PHP file before.
 * @param  string | null         $execAfter  Execute some PHP file after.
 * @return array                             Array containing paths of created
 *                                           archive files.
 */
function backup(
    ?string               $target     = null,
    ?array                $scopes     = null,
    array | string | null $exclude    = null,
    ?string               $execBefore = null,
    ?string               $execAfter  = null,
): array
{
    if ($scopes === null) $scopes = cfg('~@backup.scopes');
    $scopes = array_values(array_filter($scopes, fn(mixed $scope): bool => in_array($scope, [ 'db', 'files' ])));

    if ($exclude === null) $exclude = cfg('~@backup.exclude') ?: [];
    if (is_string($exclude)) $exclude = array_values(array_filter(array_map(fn(string $e): ?string => trim($e) ?: null, preg_split('/[,;]/', $exclude))));
    $exclude = array_map(fn(string $e): string => ('/' . str_replace('/', '\\/', $e) . '/'), $exclude);

    if ($target === null) $target = get_backup_target(true);
    if (!str_starts_with($target, DIRECTORY_SEPARATOR)) $target = get_path($target);

    if ($execBefore === null) $execBefore = cfg('~@backup.exec.before');
    if ($execAfter === null) $execAfter = cfg('~@backup.exec.after');

    $archives = [];
    $moment = (new DateTime())->format('YmdHis') . '-' . microseconds();

    rmkdir($target);

    $execArgs = [
        'moment'        => $moment,
        'backup_scopes' => implode(',', $scopes),
        'backup_target' => $target,
    ];

    if ($execBefore) exec_php_file($execBefore, $execArgs);

    if (in_array('files', $scopes)) {
        $zipPath = join_path($target, 'BAK-' . $moment . '-files.zip');
        zip_folder(
            folderPath: get_root_dir(),
            zipPath:    $zipPath,
            filter:     function(string $path, string $relativePath) use ($exclude): bool
            {
                foreach ($exclude as $regex) if (preg_match($regex, trim($relativePath, '/'))) return false;
                return true;
            },
        );
        $archives[] = $zipPath;
    }

    if (in_array('db', $scopes)) {
        $bakName = 'BAK-' . $moment . '-db';
        $zipPath = join_path($target, $bakName . '.zip');
        $sqlDir = join_path($target, $bakName);
        $sqlPath = join_path($sqlDir, $bakName . '.sql');
        rmkdir($sqlDir);
        db_dump($sqlPath);
        zip_folder(folderPath: $sqlDir, zipPath: $zipPath);
        unlink($sqlPath);
        rmdir($sqlDir);
        $archives[] = $zipPath;
    }

    foreach ($archives as $archiveIdx => $archivePath) $execArgs['backup_archive_' . $archiveIdx] = $archivePath;
    if ($execAfter) exec_php_file($execAfter, $execArgs);

    return $archives;
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'backup' => [
            'target'  => 'data/backups',
            'scopes'  => [ 'db', 'files' ],
            'exclude' => [ '^(.*/)?BAK-.*\\.zip$', '^data/', '^\\.git/' ],
            'exec'    => [
                'before' => null,
                'after'  => null,
            ],
        ],
    ];
});

listen('init', function(): void
{
    register_task(
        bundle: 'core',
        name:   'backup',
        func:   function(string $ctx, object $args): void
        {
            $archives = backup();
            json_success([ 'archives' => $archives ]);
        },
    );

    register_task(
        bundle: 'core',
        name:   'backup_db',
        func:   function(string $ctx, object $args): void
        {
            $archives = backup(scopes: [ 'db' ]);
            json_success([ 'archives' => $archives ]);
        },
    );

    register_task(
        bundle: 'core',
        name:   'backup_files',
        func:   function(string $ctx, object $args): void
        {
            $archives = backup(scopes: [ 'files' ]);
            json_success([ 'archives' => $archives ]);
        },
    );

});

// =============================================================================
