<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Returns the name of the cookie used for developer tools authentication.
 * @return string
 */
function dev_cookie_name(): string
{
    return 'MB_DEV_SECRET_KEY';
}

/**
 * Check if the current visitor is allowed to access dev tools.
 * @param  bool $checkCookie Check also if the cookie allows dev access.
 * @return bool              The user is allowed or no.
 */
function dev_is_allowed(bool $checkCookie = true): bool
{
    if (is_env('dev')) return true;
    if (in_array($ip = get_remote_ip(), [ '127.0.0.1', '0000:0000:0000:0000:0000:0000:0000:0001', '0:0:0:0:0:0:0:1', '::1' ])) return true;

    if ($allowedSecretKey = cfg('~@dev.secret_key')) {
        if (cookie(dev_cookie_name()) === hash('sha256', $allowedSecretKey)) {
            return true;
        }
    }

    if (!is_file($path = get_path('TRUSTED_VISITORS'))) return false;
    $rows = preg_split("/[\r\n]/", file_get_contents($path) ?: '');
    if (in_array('ip:' . $ip, $rows)) return true;
    return false;
}

/**
 * Generate the console page for developers.
 */
function dev_console(): void
{
    if (!dev_is_allowed()) {
        if ($allowedSecretKey = cfg('~@dev.secret_key')) {
            if (($secretKey = get_posted('secret_key')) && (trim($secretKey) === $allowedSecretKey)) {
                cookie(name: dev_cookie_name(), value: hash('sha256', $secretKey), lifetime: 365 * 24 * 3600);
                redirect('./');
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        $url = url('./');
        echo <<<HTML
            <html>
            <head>
              <title>Microbe Console | Authentication</title>
              <style>
                * { box-sizing: border-box; font-family: Arial, sans-serif; transition: all .175s; }
                :root, html { font-size: 14px; }
                ::selection { background: #00ff99; color: #0b0a0a; }
                body { margin: 0; padding: 0; background: #0b0a0a; color: #eee; font-family: monospace; }
                div { display: flex; position: fixed; left: 0; right: 0; top: 0; bottom: 0; }
                div > input { display: block; margin: auto 0; padding: 1rem; width: 100%; background: #ffffff11; border: none; outline: none; color: #00ff99; text-align: center; opacity: .1; }
                div > input:focus, div > input:hover { opacity: 1; }
              </style>
            </head>
            <body> <form action="{$url}" method="post"> <div> <input type="text" name="secret_key" spellcheck="false" autocomplete="off" placeholder="Secret Key"> </div> </form> </body>
            </html>
            HTML;
        exit;
    }

    $do = get('do');
    $view = get_str('v');

    // --- General -------------------------------------------------------------

    if ($do === 'logout') { cookie(name: dev_cookie_name(), value: false); redirect('./'); }

    if ($do === 'phpinfo') { phpinfo(); exit; }

    // --- Env -----------------------------------------------------------------

    if ($do === 'env' && is_valid_env($newEnv = get_str('env'))) {
        set_env($newEnv);
        redirect('./', [ 'v' => 'app' ]);
    }

    // --- Updates -------------------------------------------------------------

    if ($do === 'updates.check') {
        $reason = null;
        if (($updates = get_available_updates($reason)) === null) json_error('unable_to_get_updates', [ 'reason' => $reason ]);
        json_success([ 'updates' => $updates ]);
    }

    if ($do === 'updates.update') {
        if (($updates = get_available_updates()) === null) redirect('./', [ 'v' => 'updates', 'e' => "No update available" ]);
        if (!($type = get('type'))) redirect('./', [ 'v' => 'updates', 'e' => "Missing update type" ]);
        if (!($name = get('name')) && $type !== 'all') redirect('./', [ 'v' => 'updates', 'e' => "Missing update name" ]);
        foreach ($updates as $u) {
            if ($type !== 'all' && ($type !== $u->type || $name !== $u->name)) continue;
            if ($u->type === 'framework') {
                update_framework($u->url);
            }
        }
        redirect('./', [ 'v' => 'updates', 'msg' => "Updated" ]);
    }

    // --- Setup ---------------------------------------------------------------

    if ($do === 'setup') {
        if (is_file(get_path('config.json')) && !get_is('confirm')) redirect('./', [ 'v' => 'setup', 'e' => "Action not confirmed. Skipped." ]);
        if (!is_array($scopes = get('scopes'))) $scopes = [];
        setup($scopes);
        redirect('./', [ 'v' => 'setup', 'msg' => "Setup executed" ]);
    }

    // --- Backup --------------------------------------------------------------

    if ($do === 'backup') {
        backup(
            target:     get_str('target'),
            scopes:     get('scopes'),
            exclude:    get_str('exclude'),
            execBefore: get_str('exec_before'),
            execAfter:  get_str('exec_after'),
        );
        redirect('./', [ 'v' => 'backup', 'msg' => "Backuped" ]);
    }

    // --- Security ------------------------------------------------------------

    $security = (object) [ 'check' => null ];

    if ($do === 'security-check') {
        $security->check = security_check();
    }

    // --- Files ---------------------------------------------------------------

    if ($do && str_starts_with($do, 'files.')) {
        $relativePath = get_str('path');
        $absPath = get_path($relativePath);
        if (!($isDir = is_dir($absPath)) && !is_file($absPath)) redirect('./', [ 'v' => 'files', 'e' => "Invalid path" ]);
        if (!is_valid_subpath($absPath, get_root_dir())) redirect('./', [ 'v' => 'files', 'e' => "Path is outside of root folder" ]);

        $moment = microseconds();

        if ($do === 'files.size') {
            if (!$isDir) redirect('./', [ 'v' => 'files', 'e' => "Trying to compute file size" ]);
            $total = 0;
            $folders = [];
            foreach (get_folders($absPath) as $f) {
                $folders[$f->getName()] = ($size = get_folder_size($f->getPath()));
                $total += $size;
            }
            redirect('./', [ 'v' => 'files', 'dir' => $relativePath, 'sizes' => base64_encode(json_encode([ '*' => $total, 'f' => $folders ])) ]);
        } else if ($do === 'files.download') {
            $dlPath = $absPath;
            $deleteFile = false;
            if ($isDir) {
                $dlPath = get_path(basename($absPath) . '.zip');
                zip_folder($absPath, $dlPath);
                $deleteFile = true;
            }
            if (!is_file($dlPath)) redirect('./', [ 'v' => 'files', 'dir' => dirname($relativePath), 'e' => "Unable to generate archive to download (empty folder?)" ]);
            output_download(path: $dlPath, close: false);
            if ($deleteFile) unlink($dlPath);
            close();
        } else if ($do === 'files.preview') {
            if (!is_file($absPath) || !file_seems_ascii($absPath)) redirect('./', [ 'v' => 'files', 'e' => "Trying to preview non-ASCII file" ]);
            header('Content-Type: text/plain; charset=utf-8');
            echo file_get_contents($absPath);
            exit;
        } else if ($do === 'files.unzip') {
            if (!is_file($absPath) || !is_file_extension($absPath, 'zip')) redirect('./', [ 'v' => 'files', 'e' => "Trying to unzip non-ZIP file" ]);
            unzip($absPath, join_path(dirname($absPath), remove_extension(basename($absPath)) . '-' . $moment));
            redirect('./', [ 'v' => 'files', 'dir' => dirname($relativePath) ]);
        } else if ($do === 'files.delete') {
            if ($isDir) rrmdir($absPath);
            else unlink($absPath);
            redirect('?./', [ 'v' => 'files', 'dir' => dirname($relativePath) ]);
        } else if ($do === 'files.put') {
            if (!is_dir($absPath)) redirect('./', [ 'v' => 'files', 'e' => "Trying to put a file in a non-folder" ]);
            if (!($url = get('url'))) redirect('./', [ 'v' => 'files', 'e' => "Trying to put a file without URL" ]);
            if ($name = sanitize_filename(basename($url))) $name = 'MB-' . $moment . '-' . $name;
            else $name = 'MB-' . uid(16) . '.dl';
            curl_get(url: $url, storePath: join_path($absPath, $name));
            redirect('./', [ 'v' => 'files', 'dir' => $relativePath ]);
        }

        redirect('./', [ 'v' => 'files', 'e' => "Invalid action" ]);
    }

    $files = (object) [
        'user'                 => null,
        'dir_relative_path'    => trim(get_str('dir'), '/'),
        'dir_path'             => null,
        'root_path'            => get_root_dir(),
        'is_root'              => true,
        'parent_relative_path' => null,
        'sizes'                => null,
        'folders'              => [],
        'files'                => [],
    ];

    if ($view === 'files') {

        $files->user = (object) [
            'id'   => get_system_user_id(),
            'name' => get_system_user_name(),
        ];

        $files->dir_path = rtrim(get_path($files->dir_relative_path), '/');
        if (!is_dir($files->dir_path) || !is_valid_subpath(path: $files->dir_path, root: get_root_dir())) {
            $files->dir_relative_path = '';
            $files->dir_path = rtrim(get_path($files->dir_relative_path), '/');
        }
        $files->is_root = $files->dir_relative_path === '';
        if (!$files->is_root) $files->parent_relative_path = trim(dirname($files->dir_relative_path), '.') ?: null;
        $files->folders = get_folders($files->dir_path);
        $files->files = get_files($files->dir_path);

        if (($sizes = get('sizes')) && ($sizes = json_decode(base64_decode($sizes), true))) {
            $files->sizes = (object) [
                'all'     => (object) [ 'size' => $s = ($sizes['*'] ?? 0), 'readable_size' => bytes_unit($s) ],
                'folders' => [],
            ];
            foreach ($sizes['f'] as $name => $size) {
                $files->sizes->folders[$name] = (object) [
                    'size' => $size,
                    'readable_size' => bytes_unit($size),
                ];
            }
        }

    }

    // --- Emails --------------------------------------------------------------

    $emails = null;

    if ($do === 'emails.test') {
        $msg = null;
        $err = "Missing parameters";
        if (($to = trim(get('to')))
            && ($subject = trim(get('subject')))
            && ($message = trim(get('message')))) {

            $preview = get('mode') === 'preview';

            send_email(
                debug:   $preview,
                to:      [ 'address' => $to, 'name' => parse_email_address($to)->name ],
                subject: $subject,
                body:    $message,
            );

            $msg = "Email sent";
            $err = null;
        }
        redirect('./', [ 'v' => 'emails', 'e' => $err, 'msg' => $msg ]);
    }

    if ($view === 'emails') {
        if (!preg_match('/^[0-9]{6}$/', $folder = (get('folder') ?: ''))) $folder = null;
        $emails = (object) [
            'folders'         => get_stored_emails(),
            'selected_folder' => $folder,
            'files'           => $folder ? get_stored_emails($folder) : null,
        ];
    }

    // --- Tasks ---------------------------------------------------------------

    $tasks = null;

    if ($do === 'tasks.assert_files') {
        assert_tasks_files(force: true);
        redirect('./', [ 'v' => 'tasks' ]);
    }

    if ($view === 'tasks') {
        $tasks = (object) [
            'all' => get_registered_tasks(),
        ];
    }

    // --- Sitemap --------------------------------------------------------------

    $sitemap = (object) [
        'existing'         => null,
        'sources'          => null,
        'count_per_source' => null,
    ];

    if ($do === 'sitemap.preview') {
        header('Content-Type: text/xml; charset=utf-8');
        echo generate_sitemap(path: false, sourceName: get_nullable_str('src'));
        exit;
    }

    if ($do === 'sitemap.generate') {
        generate_sitemap(sourceName: get_nullable_str('src'));
        redirect('./', [ 'v' => 'sitemap' ]);
    }

    if ($do === 'sitemap.links') {
        header('Content-Type: text/plain; charset=utf-8');
        foreach (fetch_sitemap_links(get_nullable_str('src')) as $link) echo $link['lastmod']->format('Y-m-d H:i:s') . ' ' . $link['loc'] . "\n";
        exit;
    }

    if ($view === 'sitemap') {
        $sitemap->existing = get_existing_sitemaps();
        $sitemap->sources = get_registered_sitemap_sources();
        $sitemap->count_per_source = [];
        foreach ($sitemap->sources as $src) $sitemap->count_per_source[$src->name] = count(fetch_sitemap_links($src->name));
    }

    // --- Deploy --------------------------------------------------------------

    $deploy = (object) [ 'git' => (object) [ 'last_commit' => null ] ];

    if ($view === 'deploy') $deploy->git->last_commit = get_last_commit();

    // --- Errors --------------------------------------------------------------

    $errors = (object) [
        'type'  => get_nullable_str('error_type'),
        'year'  => get_nullable_str('error_year'),
        'month' => get_nullable_str('error_month'),
        'day'   => get_nullable_str('error_day'),
        'limit' => get_int('error_limit') ?: 100,
        'files' => [],
        'lines' => [],
    ];

    if ($do === 'error.500') throw_500(message: "[Debug] Fake 500 Internal Error");
    if ($do === 'error.404') throw_404(message: "[Debug] Fake 404 Not Found Error");
    if ($do === 'error.403') throw_403(message: "[Debug] Fake 403 Unauthorized Error");

    if ($do === 'errors.dl') {
        if ($errors->type && $errors->year && $errors->month && $errors->day) {
            $path = get_errors_file_path($errors->type, $errors->year, $errors->month, $errors->day);
            if ($path && is_file($path)) output_download($path);
        }
        redirect('./', [ 'v' => 'errors', 'e' => "Invalid Error File" ]);
    }

    if ($view === 'errors') {
        if ($errors->type) {
            $errors->files = get_logged_errors_files(type: $errors->type);
            if ($errors->year && $errors->month && $errors->day) {
                $errors->lines = get_logged_errors(
                    type:    $errors->type,
                    year:    $errors->year,
                    month:   $errors->month,
                    day:     $errors->day,
                    limit:   $errors->limit,
                    reverse: true,
                );
            }
        }
    }

    // --- Database ------------------------------------------------------------

    $db = (object) [

        'config'           => cfg('~@db.' . get_env()),
        'is_connected'     => false,
        'connection_error' => null,
        'tables'           => [],
        'snapshots'        => [],

        'migrations' => (object) [
            'all'     => db_migrations(),
            'error'   => null,
            'current' => null,
        ],

        'query' => (object) [
            'multiple'   => null,
            'sql'        => get('sql'),
            'is_select'  => null,
            'table_name' => null,
            'result'     => null,
            'results_nb' => null,
            'error'      => null,
        ],

        'search' => (object) [
            'term'    => get('q'),
            'results' => null,
        ],

    ];

    $dbSnapshotsDir = get_data_dir('db', 'snapshots');

    if (is_dir($dbSnapshotsDir)) {
        $db->snapshots = array_values(array_filter(array_map(function(Microbe_File $f): ?object
        {
            if (!preg_match('/^snapshot-(?<y>[0-9]{4})(?<m>[0-9]{2})(?<d>[0-9]{2})(?<h>[0-9]{2})(?<i>[0-9]{2})(?<s>[0-9]{2})\.sql/', $f->getName(), $m)) return null;
            return (object) [
                'path' => $f->getPath(),
                'name' => $f->getName(),
                'at'   => new DateTime($m['y'] . '-' . $m['m'] . '-' . $m['d'] . ' ' . $m['h'] . ':' . $m['i'] . ':' . $m['s']),
                'size' => $f->getSize(),
            ];
        }, get_files($dbSnapshotsDir))));
        usort($db->snapshots, function(object $a, object $b): int
        {
            if ($a->at > $b->at) return -1;
            if ($a->at < $b->at) return 1;
            return 0;
        });
    }

    try {
        if (!($db->is_connected = db_is_connected())) $db->connection_error = db_get_last_pdo_connect_error();
    } catch (Exception $e) {
        $db->connection_error = $e->getMessage();
    }

    if ($do === 'db.query' && trim($db->query->sql)) {
        $queries = db_split_queries($db->query->sql);
        $db->query->multiple = count($queries) > 1;
        foreach ($queries as $sql) {
            if ($db->query->is_select = preg_match('/^\s*SELECT\s*/im', $sql)) {
                if (preg_match('/^\s*SELECT(\s+.+)\s+FROM\s+(?<table>[a-z0-9_.`-]+)(\s+AS\s+[a-z0-9_.`-]+)?\s+/im', $sql . ' ', $m)) {
                    $db->query->table_name = trim(trim($m['table']), '`');
                }
            }
            try {
                $db->query->result = $db->query->is_select ? db_fetch_all($sql) : db_query($sql);
                if (is_array($db->query->result)) $db->query->results_nb = count($db->query->result);
            } catch (Exception $e) {
                $db->query->result = false;
                $db->query->error = $e->getMessage();
                break;
            }
        }
    } else if ($do === 'db.search' && trim($db->search->term)) {
        $db->search->results = db_search(
            q:          $db->search->term,
            mode:       get_in('mode', [ 'equals', 'like', 'like_jokers' ], 'like_jokers'),
            queryLimit: 50,
        );
    }

    $migration = strpos($do ?: '', 'db.migration.') === 0 && ($m = get('m')) ? db_migration($m) : null;
    $migrationSide = in_array($s = get('s'), [ 'up', 'down', 'downup' ]) ? $s : 'up';

    if ($do === 'db.setup') {

        if (($rootUser = get_posted('root_user')) && ($rootPassword = get_posted('root_password'))) {
            db_create_user($rootUser, $rootPassword);
            db_create_db($rootUser, $rootPassword);
        }

        redirect('./', [ 'v' => 'db' ]);

    } else if ($do === 'db.reset') {

        if (is_env('staging', 'prod')) redirect('./', [ 'v' => 'db.reset', 'e' => "Database Reset Disabled For This Environment" ]);
        db_drop_all_tables();
        redirect('./', [ 'v' => 'db.reset', 'msg' => "All Tables Deleted" ]);

    } else if ($do === 'db.migration.view') {

        if (!$migration) redirect('./', [ 'v' => 'db.migrations' ]);
        header('Content-Type: text/html; charset=utf-8');
        $html = '<strong>' . $migration->name . '</strong><div id="sides">';
        foreach ([ 'up', 'down' ] as $side) {
            $html .= '<div id="' . $side . '"><strong>' . $side . '</strong>';
            if ($migration->files->$side) {
                $html .= '<em>' . $migration->files->$side->path . '</em><div>';
                foreach ($migration->files->$side->queries as $q) $html .= '<div>' . esc($q) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<div id="check">';
        if ($migration->check->checked === null) $html .= "Check file doesn't exists.";
        else if (!$migration->check->code) $html .= "Check file is empty.";
        else $html .= '<strong>Check</strong>'
            . '<em>' . $migration->check->path . '</em>'
            . '<div>' . esc($migration->check->code) . '</div>';
        $html .= '</div>';
        echo <<<HTML
            <html>
            <head>
              <title>Microbe Console | SQL Migration</title>
              <style>
                :root, html { font-size: 14px; }
                ::selection { background: #eee; color: black; }
                body { margin: 0; padding: 0; background: #222; color: #eee; font-family: monospace; }
                body > strong { display: block; margin: 20px 20px 0 20px; padding: 20px; border: 1px solid #00ff99; text-align: center; font-size: 1.5rem; }
                body > div strong { display: block; text-align: center; text-transform: uppercase; font-size: 2rem; }
                body > div em { display: block; text-align: center; font-style: normal; }
                body > div#sides { display: flex; }
                body > div#sides > div { flex: 1; margin: 20px; padding: 20px; border: 1px solid #00ff99; }
                body > div#sides > div#up { margin-right: 10px; }
                body > div#sides > div#down { margin-left: 10px; }
                body > div#sides > div > div > div { margin: 20px 0 0 0; padding: 20px; background: #ffffff22; white-space: pre; }
                body > div#check { margin: 0 20px 20px 20px; padding: 20px; border: 1px solid #00ff99; }
                body > div#check > div { margin: 20px 0 0 0; padding: 20px; background: #ffffff22; white-space: pre; }
              </style>
            </head>
            <body> {$html} </body> </html>
            HTML;
        exit;

    } else if ($do === 'db.migration.exec' || $do === 'db.migration.run') {

        if (!$migration) redirect('./', [ 'v' => 'db.migrations' ]);
        try {
            foreach ($migrationSide === 'downup' ? [ 'down', 'up' ] : [ $migrationSide ] as $side) {
                db_run_migration($migration->name, $side, $do === 'db.migration.exec');
            }
            redirect('./', [ 'v' => 'db.migrations' ]);
        } catch (Exception $e) {
            $db->migrations->error = $e->getMessage();
        }

    } else if ($do === 'db.migration.current') {

        if (!$migration) redirect('./', [ 'v' => 'db.migrations' ]);
        $set = $migration->name;
        if ($set === db_current_migration()) $set = false;
        db_current_migration($set);
        redirect('./', [ 'v' => 'db.migrations' ]);

    } else if ($do === 'db.dumps.dump_dl' || $do === 'db.dumps.dump_store' || $do === 'db.snapshots.create') {

        $dt = new DateTime();

        $name = 'dump-' . db_config()->db_name . '-' . $dt->format('YmdHis') . '.sql';
        $path = join_path(get_root_dir(), $name);
        if ($do === 'db.snapshots.create') {
            $name = 'snapshot-' . $dt->format('YmdHis') . '.sql';
            $path = join_path($dbSnapshotsDir, $name);
            if (!is_dir($dir = dirname($path))) rmkdir($dir);
        }

        db_dump($path);
        if ($do === 'db.dumps.dump_store') redirect('./', [ 'v' => 'db.dumps' ]);
        if ($do === 'db.snapshots.create') redirect('./', [ 'v' => 'db.snapshots' ]);

        output_download($path, close: false);
        unlink($path);
        close();

    } else if ($do === 'db.dumps.upload_exec' || $do === 'db.dumps.exec') {

        $path = get('f');

        if ($do === 'db.dumps.upload_exec') {
            if (!($uf = get_uploaded_file('file'))) redirect('./', [ 'v' => 'db.dumps', 'e' => "No file uploaded" ]);
            $path = $uf->tmp_name;
        }

        if (!$path) redirect('./', [ 'v' => 'db.dumps', 'e' => "No file path" ]);
        if (!is_file($path)) redirect('./', [ 'v' => 'db.dumps', 'e' => "File doesn't exists" ]);
        db_execute_dump($path);
        redirect('./', [ 'v' => 'db.dumps' ]);

    } else if (in_array($do, [ 'db.snapshots.delete', 'db.snapshots.dl', 'db.snapshots.restore' ])) {

        if (!($name = get('s'))) redirect('./', [ 'v' => 'db.snapshots', 'e' => "No file path" ]);
        if (!is_file($path = join_path($dbSnapshotsDir, $name))) redirect('./', [ 'v' => 'db.snapshots', 'e' => "File doesn't exists" ]);

        $msg = null;

        if ($do === 'db.snapshots.delete') {
            unlink($path);
            $msg = "Snapshot Deleted";
        } else if ($do === 'db.snapshots.dl') {
            output_download($path);
        } else if ($do === 'db.snapshots.restore') {
            db_drop_all_tables();
            db_execute_dump($path);
            $msg = "Snapshot Restored";
        }

        redirect('./', [ 'v' => 'db.snapshots', 'msg' => $msg ]);

    }

    if ($db->is_connected) {

        $db->tables = db_get_tables(countRows: true, assocArray: true);
        $db->migrations->current = db_current_migration();

    }

    // --- Render --------------------------------------------------------------

    $sections = [
        (object) [ 'name' => 'app',     'label' => "App",     'emoji' => '🧩' ],
        (object) [ 'name' => 'updates', 'label' => "Updates", 'emoji' => '🔁' ],
        (object) [ 'name' => 'setup',   'label' => "Setup",   'emoji' => '🛠️' ],
        (object) [ 'name' => 'files',   'label' => "Files",   'emoji' => '📁' ],
        (object) [
            'name'     => 'db',
            'label'    => "Database",
            'emoji'    => '🛢️',
            'children' => [
                (object) [ 'name' => 'info',       'label' => "Info",       'emoji' => '🛢️' ],
                (object) [ 'name' => 'migrations', 'label' => "Migrations", 'emoji' => '➡️' ],
                (object) [ 'name' => 'tables',     'label' => "Tables",     'emoji' => '📋' ],
                (object) [ 'name' => 'sql',        'label' => "SQL",        'emoji' => '📄' ],
                (object) [ 'name' => 'search',     'label' => "Search",     'emoji' => '🔍' ],
                (object) [ 'name' => 'dumps',      'label' => "Dumps",      'emoji' => '📤' ],
                (object) [ 'name' => 'snapshots',  'label' => "Snapshots",  'emoji' => '📸' ],
                (object) [ 'name' => 'reset',      'label' => "Reset",      'emoji' => '♻️' ],
            ],
        ],
        (object) [ 'name' => 'emails',   'label' => "Emails",   'emoji' => '✉️' ],
        (object) [ 'name' => 'tasks',    'label' => "Tasks",    'emoji' => '🤖' ],
        (object) [ 'name' => 'sitemap',  'label' => "Sitemap",  'emoji' => '🧭' ],
        (object) [ 'name' => 'deploy',   'label' => "Deploy",   'emoji' => '🚀' ],
        (object) [ 'name' => 'errors',   'label' => "Errors",   'emoji' => '💥' ],
        (object) [ 'name' => 'backup',   'label' => "Backup",   'emoji' => '🛟' ],
        (object) [ 'name' => 'security', 'label' => "Security", 'emoji' => '🛡️' ],
        (object) [ 'name' => 'server',   'label' => "Server",   'emoji' => '🖥️' ],
    ];

    dev_render_console([
        'allowed_by_cookie' => !dev_is_allowed(checkCookie: false),
        'sections'          => $sections,
        'view'              => $view,
        'err'               => get('e'),
        'msg'               => get('msg'),
        'version'           => microbe_version(),
        'files'             => $files,
        'db'                => $db,
        'sql_files'         => ls(get_root_dir(), folders: false, filter: '/\.sql$/i'),
        'backups'           => get_backuped_files(),
        'security'          => $security,
        'deploy'            => $deploy,
        'errors'            => $errors,
        'emails'            => $emails,
        'tasks'             => $tasks,
        'sitemap'           => $sitemap,
    ]);
}

/**
 * Render the HTML of the console page for developers.
 * @param  array  $vars Template variables.
 */
function dev_render_console(array $vars): void
{
    extract($vars);
?>
<!doctype html>
<html lang="en">
    <head>
        <title>Microbe Console</title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🦠</text></svg>">
        <style>

            :root {
                --bg: #0b0a0a;
                --fg: #00ff99;
                --lnk: #27c9fb;
                --danger: #d55044;
                --black: black;
                --white: #eee;
            }

            * { box-sizing: border-box; font-family: monospace; transition: all .1s; }
            :root, html { line-height: 1; font-size: 14px; }
            ::-webkit-scrollbar { width: 8px; height: 8px; background-color: #ffffff22; }
            ::-webkit-scrollbar-thumb { background: var(--fg); }
            ::-webkit-scrollbar-thumb:hover { background: white; }
            ::selection { background: var(--fg); color: var(--bg); }
            body { margin: 0; padding: 0; background: #1c1c1c; color: var(--white); cursor: default; user-select: none; }
            body:before { display: block; position: fixed; left: 0; right: 0; top: 0; bottom: 0; background: var(--lnk); content: ""; opacity: 0; filter: grayscale(80%) brightness(.5); pointer-events: none; z-index: 987; }
            body.loading:before { opacity: .5; pointer-events: all; }
            button { font-size: 1rem; }
            a, button.lnk { color: var(--lnk); text-decoration: underline; cursor: pointer; }
            a:hover, button.lnk:hover { color: var(--white); text-decoration: none; }
            a.bt, button.bt { display: inline; margin: 0; padding: 10px; background: var(--lnk); border: none; outline: none; color: var(--bg); text-decoration: none; line-height: 1; font-weight: bold; cursor: pointer; }
            a.bt:hover, button.bt:hover { background: var(--white); }
            a.bt.inactive, button.bt.inactive { background: none; box-shadow: 0 0 0 2px var(--lnk) inset; color: var(--white); font-weight: normal; }
            a.bt.inactive:hover, button.bt.inactive:hover { background: var(--lnk); color: var(--black); }
            .bt-spacer { display: inline-block; margin: 0 10px; width: 3px; height: 1rem; background: var(--white); opacity: .25; }
            form.lnk { display: inline; appearance: none; margin: 0; padding: 0; }
            button.lnk { display: inline; appearance: none; margin: 0; padding: 0; background: none; border: none; font-size: 1rem; }
            button.lnk.code, button.lnk.code * { font-family: monospace; }
            small { font-size: .9em; opacity: .7; }
            hr { display: block; margin: 40px 0; padding: 0; height: 1px; border: none; border-top: 1px dashed #444; }
            .err { color: var(--danger); font-weight: bold; }
            .ico { padding: 0 2px; border: 1px solid var(--lnk); text-decoration: none; line-height: 0; }
            .ico:hover { border-color: var(--white); color: var(--white); }
            .danger { color: var(--danger) !important; font-weight: bold; }
            .low { color: #eeeeee55; }
            .center { text-align: center; }
            .right { text-align: right; }
            .num { color: var(--lnk); text-align: right; }
            .small { font-size: .83rem; }
            .selectable { user-select: all; cursor: pointer; }
            .unselectable { user-select: none; cursor: default; }
            .disabled { pointer-events: none; filter: grayscale(1); }

            .microbe-icon { margin: 40px 0; text-align: center; font-size: 6rem; }
            .microbe-icon:before { content: "🦠"; }

            code { padding: 1px 4px; background: #ffffff16; border-radius: 3px; font-family: monospace; font-size: 1em; cursor: pointer; user-select: all; }
            code > strong { font-family: monospace; font-size: 1em; }
            code:hover { background: #ffffff22; }
            code.block { display: block; margin: 0 0 20px 0; padding: 8px; border-radius: 0; line-height: 1.5; }
            code.block:last-child { margin-top: 0; }
            code.block + code.block { margin-top: -20px; border-top: 1px dashed #ffffff44; }
            code.block + code.block:last-child { margin-bottom: 0; }

            form > ul > li > label { display: flex; align-items: center; margin: 0 0 5px 0; padding: 10px; background: #ffffff22; cursor: pointer; user-select: none; }
            form > ul > li > label:hover { background: #ffffff44; }
            form > ul > li > label > input { display: block; appearance: none; margin: 0 10px 0 0; padding: 0; min-width: none; min-height: none; width: auto; height: auto; background: none; border: none; cursor: pointer; }
            form > ul > li > label > input:before { content: "⬜"; }
            form > ul > li > label > input:checked:before { content: "✅"; }

            form[enctype] { display: flex; margin: 10px 0 20px 0; border: 1px solid #ffffff44; }
            form[enctype] > input[type="file"] { flex: 1; padding: 10px; cursor: pointer; }
            form[enctype] > button[type="submit"] { display: block; padding: 0 15px; background: var(--lnk); border: none; outline: none; color: var(--bg); text-decoration: none; cursor: pointer; }
            form[enctype] > button[type="submit"]:hover { background: var(--white); }

            #wrapper { display: flex; position: absolute; left: 40px; right: 40px; top: 40px; bottom: 40px; }

            #pills { position: relative; z-index: 2; }
            #pills > ul { margin: 0; padding: 0; }
            #pills > ul > li { position: relative; margin: 0; padding: 0; }
            #pills > ul > li > strong { display: block; }
            #pills > ul > li > strong > a { display: block; padding: 8px 10px; background: color-mix(in srgb, var(--fg) 90%, transparent); color: var(--black); text-align: center; text-decoration: none; font-weight: bold; }
            #pills > ul > li > strong > a:hover, #pills > ul > li.active > strong > a { background: var(--fg); color: var(--black); }
            #pills > ul li > a { display: block; padding: 8px 20px 8px 10px; background: #2b2b2b; color: var(--lnk); text-decoration: none; }
            #pills > ul li > a > i { font-style: normal; }
            #pills > ul li > a > span {}
            #pills > ul li > a:hover, #pills > ul li.active > a { background: var(--lnk); color: var(--black); }
            #pills > ul li.logout > a { opacity: .75; }
            #pills > ul li.logout > a:hover { opacity: 1; }
            #pills > ul li.disabled > a { opacity: .5; filter: grayscale(1); pointer-events: none; }
            #pills > ul > li > a {}
            #pills > ul > li > div {}
            #pills > ul > li > div > ul { margin: 0; padding: 0; }
            #pills > ul > li > div > ul > li { margin: 0; padding: 0; }
            #pills > ul > li > div > ul > li > a { padding-left: 20px; background: #2b2b2b; }
            #pills > ul > li > div > ul > li > a:before { display: inline-block; margin: 0 10px 0 0; content: "\21b3"; opacity: .5; }
            #pills > ul > li > div > ul > li > a:hover, #pills > ul > li > div > ul > li.active > a { background: color-mix(in srgb, var(--lnk) 80%, transparent); }

            #panel { position: relative; flex: 1; background: var(--bg); border-left: 2px solid var(--fg); z-index: 1; }
            #panel .notice { margin: 0; padding: 8px 40px; background: #2b2b2b; color: #898989; }
            #panel .notice.notice-error { background: #7b2c2c; color: white; font-weight: bold; }
            #panel .notice.notice-success { background: #075b39; color: white; font-weight: bold; }
            #panel-container { flex: 1; }
            #panel-container-scrollable { position: absolute; overflow: auto; left: 0; right: 0; top: 0; bottom: 0; padding: 40px 0 0 0; z-index: 1; }

            .cards { display: flex; margin: 0 -10px 20px -10px; }
            .cards > .card { flex: 1; margin: 0 10px; padding: 20px; border: 1px dashed var(--fg); }
            .cards > .card > strong { display: block; margin: 0 0 20px 0; padding: 0 0 10px 0; border-bottom: 1px solid var(--fg); color: var(--fg); }
            .cards > .card > ul {}
            .cards > .card > ul > li {}
            .cards > .card > ul > li > a {}
            .cards > .card > form {}
            .cards > .card > form > input[type="file"] {}
            .cards > .card > form > button[type="submit"] {}

            dl { display: flex; flex-wrap: wrap; margin: 0 0 20px 0; padding: 0; }
            dl:last-child { margin-bottom: 0; }
            dl > dt { flex: 0 0 150px; margin: 0 0 10px 0; padding: 0 0 5px 0; border-bottom: 1px dashed #444; font-weight: bold; }
            dl > dd { flex: 0 0 calc(100% - 150px); margin: 0 0 10px 0; padding: 0 0 5px 0; border-bottom: 1px dashed #444; }
            dl > :nth-last-child(2), dl > :last-child { margin-bottom: 0; }

            ul, ul > li { margin: 0; padding: 0; list-style: none; }
            ul { margin: 10px 0 20px 0; }
            ul:last-child { margin-bottom: 0; }
            ul > li { margin: 0 0 1px 0; }
            ul > li:last-child { margin-bottom: 0; }
            ul > li > .bt:only-child { display: inline-block; }

            .field { display: flex; margin: 0; }
            .field > * { padding: 8px; background: transparent; border: 1px solid #444; outline: none; color: var(--white); }
            .field > :not(:first-child) { border-left: none; }
            .field > label { display: flex; align-items: center; font-weight: bold; }
            .field > input, div#w > fieldset .field > textarea { flex: 1; resize: vertical; }
            .field > textarea { flex: 1; font-family: monospace; }
            .field > select { appearance: none; padding-left: 10px; padding-right: 10px; cursor: pointer; }
            .field > select:hover { background: color-mix(in srgb, var(--white) 10%, transparent); }
            .field > select > option { background: var(--bg); color: var(--white); }
            .field > input:hover, div#w > fieldset .field > textarea:hover { background: #ffffff11; }
            .field > input:focus, div#w > fieldset .field > textarea:focus { background: #ffffff22; }
            .field > button { display: inline; appearance: none; margin: 0; color: var(--lnk); text-decoration: none; font-size: 1rem; cursor: pointer; }
            .field > button:hover { background: var(--white); color: var(--bg); }
            .field + .field { margin-top: 10px; }
            .field + .bt { margin-top: 30px; }

            #updates-available, #no-update-available, #update-error, #update-loading { display: none; }

            fieldset { margin: 40px 40px 0 40px; padding: 40px 30px; min-width: 0; border: 1px solid var(--fg); }
            fieldset > legend { margin: 0; padding: 10px; border: 1px double var(--fg); }
            fieldset > legend > h2 { margin: 0; padding: 0px; color: var(--fg); text-transform: uppercase; font-weight: bold; font-size: 1rem; }
            fieldset h3 { margin: 0 0 20px 0; padding: 0px; color: var(--fg); text-transform: uppercase; font-weight: bold; font-size: 1rem; }
            fieldset p { margin: 0 0 20px 0; }
            fieldset p:last-child { margin-bottom: 0; }
            fieldset .h-scroll { margin: 0 0 20px 0; max-width: 100%; overflow-x: auto; padding: 2px; border: 1px solid var(--fg); }
            fieldset form { margin: 0 0 20px 0; }
            fieldset form:last-child { margin-bottom: 0; }

            table { margin: 0 0 20px 0; width: 100%; border-collapse: collapse; }
            table.autow { width: auto; }
            table:last-child { margin-bottom: 0; }
            table th, table td { padding: 8px; border: 1px dashed #444; vertical-align: top; }
            table th.fit, table td.fit { width: .1%; white-space: nowrap; }
            table > thead > tr > th { background: #222; border-color: #444; border-style: solid; color: #666; text-transform: uppercase; }
            table > tbody > tr:hover > :not([rowspan]) { background: #ffffff22; }
            table > tbody > tr > td.lnk { padding: 0; width: .1%; white-space: nowrap; }
            table > tbody > tr > td.lnk > a { display: block; padding: 8px; text-align: center; text-decoration: none; }
            table > tbody > tr > td.lnk:has(> a.active) { background: var(--lnk); color: var(--black); }
            table > tbody > tr > td.lnk:has(> a.active) > a.active { color: var(--black); }
            table > tbody > tr > td.lnk.unfit { width: auto; }
            table > tbody > tr > td.lnk.unfit > a { text-align: left; }
            table > tbody > tr.hr-above > td { border-top: 4px solid var(--lnk) !important; }
            table > tbody > tr.hr-below > td { border-bottom: 4px solid var(--lnk) !important; }
            table > :first-child > tr:first-child > * { border-top-color: #444; border-top-style: solid; }
            table > :last-child > tr:last-child > * { border-bottom-color: #444; border-bottom-style: solid; }
            table tr > :first-child { border-left-color: #444; border-left-style: solid; }
            table tr > :last-child { border-right-color: #444; border-right-style: solid; }
            table > tbody > tr > td.inner-table { padding: 0; }
            table > tbody > tr > td.inner-table > table {}
            table > tbody > tr > td.inner-table > table > tbody > tr > td { padding: 5px 3px 2px 3px; font-size: .85rem; }
            table > tbody > tr > td.inner-table > table > :first-child > tr:first-child > * { border-top: none; }
            table > tbody > tr > td.inner-table > table > :last-child > tr:last-child > * { border-bottom: none; }
            table > tbody > tr > td.inner-table > table tr > :first-child { border-left: none; }
            table > tbody > tr > td.inner-table > table tr > :last-child { border-right: none; }
            table > tbody > tr > td.inner-table > table td.db-column-type { width: 19rem; }
            table > tbody > tr > td.inner-table > table td.db-column-bool { width: 3rem; text-align: center; }

            .inline-form-container { display: inline-flex; }

            form.inline-form { display: inline-flex; }
            form.inline-form > div { display: inline-flex; }
            form.inline-form > div > span { margin: 0 .4rem; color: var(--fg); }
            form.inline-form > div > input { appearance: none; margin: 0; padding: .5rem 0 .7rem 0; width: auto; field-sizing: content; height: 1rem; background: none; border: none; border-bottom: 1px dashed var(--fg); outline: none; color: var(--fg); font-size: 1rem; }
            form.inline-form > div > input:hover { border-bottom-color: var(--white); }
            form.inline-form > div > input:focus { border-bottom-color: var(--white); color: var(--white); }
            form.inline-form > button { appearance: none; margin: -1px 0 0 10px; padding: 0 0 2px 0; width: auto; height: auto; background: none; border: none; border-bottom: 1px dashed var(--fg); outline: none; color: var(--fg); font-size: 1rem; font-weight: bold; cursor: pointer; }
            form.inline-form > button:hover { border-bottom: 1px solid var(--white); color: var(--white); }

        </style>
    </head>
    <body>
        <div id="wrapper">
            <div id="pills">
                <ul>
                    <li <?php if (!$view): ?> class="active" <?php endif; ?>><strong><a href="<?php _url('./'); ?>">🦠 Microbe 🦠</a></strong></li>
                    <?php foreach ($sections as $section): ?>
                        <li <?php if ($view === $section->name || str_starts_with($view, $section->name . '.')): ?> class="active" <?php endif; ?>>
                            <a href="<?php _url('./', [ 'v' => $section->name ]); ?>">
                                <i><?php echo $section->emoji; ?></i>
                                <span><?php echo $section->label; ?></span>
                            </a>
                            <?php if ($section->children ?? null): ?>
                                <div>
                                    <ul>
                                        <?php foreach ($section->children as $child): ?>
                                            <li <?php if ($view === $section->name . '.' . $child->name): ?> class="active" <?php endif; ?>>
                                                <a href="<?php _url('./', [ 'v' => $section->name . '.' . $child->name ]); ?>">
                                                    <i><?php echo $child->emoji; ?></i>
                                                    <span><?php echo $child->label; ?></span>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <li class="logout <?php if (!$allowed_by_cookie): ?>disabled<?php endif; ?>"><a href="<?php _url('./', [ 'do' => 'logout' ]); ?>">🛏️ Sign Out</a></li>
                </ul>
            </div>
            <div id="panel">
                <?php if ($err): ?>
                    <div class="notice notice-error"><?php echo $err; ?></div>
                <?php elseif ($msg): ?>
                    <div class="notice notice-success"><?php echo $msg; ?></div>
                <?php else: ?>
                    <div class="notice">&nbsp;</div>
                <?php endif; ?>
                <div id="panel-container">
                    <div id="panel-container-scrollable">

                        <?php if (!$view): ?>

                            <fieldset>
                                <legend><h2>🦠 Microbe Framework</h2></legend>
                                <dl>
                                    <dt>Microbe Path</dt> <dd><code><?php echo __FILE__; ?></code></dd>
                                    <dt>Version</dt> <dd><code><?php echo $version && $version->version ? $version->version : '?'; ?></code></dd>
                                    <dt>Hash</dt> <dd><code><?php echo $version && $version->hash ? $version->hash : '?'; ?></code></dd>
                                    <dt>Meta Hash</dt> <dd><code><?php echo $version && $version->meta_hash ? $version->meta_hash : '?'; ?></code></dd>
                                </dl>
                            </fieldset>

                            <div class="microbe-icon"></div>

                        <?php elseif ($view === 'app'): ?>

                            <fieldset>
                                <legend><h2>🧩 App</h2></legend>
                                <dl>
                                    <dt>Root URL</dt> <dd><code><?php _url('/', true); ?></code> <a href="<?php _url('/', true); ?>" target="_blank" class="ico">&#x2197;</a></dd>
                                    <dt>Root Directory</dt> <dd><code><?php echo get_root_dir(); ?></code></dd>
                                    <dt>App Name</dt> <dd><code><?php __cfg('~@app.name'); ?></code></dd>
                                </dl>
                            </fieldset>

                            <fieldset>
                                <legend><h2>🗂️ Environment</h2></legend>
                                <?php $current_env = get_env(); ?>
                                <table class="autow">
                                    <tbody>
                                        <tr>
                                            <?php foreach (get_valid_env() as $valid_env): ?>
                                                <td class="lnk unfit">
                                                    <a
                                                        href="<?php _url('./', [ 'do' => 'env', 'env' => $valid_env ]); ?>"
                                                        <?php if ($valid_env !== $current_env): ?> class="low" <?php endif; ?>
                                                        >
                                                        <?php if ($valid_env === $current_env): ?> 🟢 <strong> <?php else: ?> ⚫ <?php endif; ?>
                                                        <?php _esc($valid_env); ?>
                                                        <?php if ($valid_env === $current_env): ?> </strong> <?php endif; ?>
                                                    </a>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </fieldset>

                        <?php elseif ($view === 'updates'): ?>

                            <fieldset>
                                <legend><h2>🔁 Updates</h2></legend>
                                <div id="updates-available" data-url="<?php _url('./', [ 'do' => 'updates.check' ]); ?>">
                                    <p>
                                        <span id="updates-nb"></span> update(s) available &nbsp;
                                        <a href="#" class="bt" data-updates-refresh>🔄 Refresh</a> &nbsp;
                                        <a href="<?php _url('./', [ 'do' => 'updates.update', 'type' => 'all' ]); ?>" class="bt">🔁 Update All</a>
                                    </p>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th colspan="3">Item</th>
                                                <th>Current</th>
                                                <th>Available</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td class="fit">__type_capitalized__</td>
                                                <td class="fit"><code>__name__</code></td>
                                                <td><strong>__title__</strong></td>
                                                <td class="fit"><code>__current__</code></td>
                                                <td class="fit"><code>__available__</code></td>
                                                <td class="lnk"><a href="<?php _url('./', [ 'do' => 'updates.update', 'type' => '__type__', 'name' => '__name__' ]); ?>">🔄 Update</a></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div id="updates-uptodate"><p>Everything is up-to-date. &nbsp; <a href="#" class="bt" data-updates-refresh>🔄 Refresh</a></p></div>
                                <div id="updates-error" class="danger"><p><code></code> &nbsp; <a href="#" class="bt" data-updates-refresh>🔄 Retry</a></p></div>
                                <div id="updates-loading">Loading updates&hellip;</div>
                                <div id="updates-wait"><a href="#" class="bt" data-updates-refresh>🔄 Fetch Updates</a></div>
                            </fieldset>

                        <?php elseif ($view === 'setup'): ?>

                            <fieldset>
                                <legend><h2>🛠️ Setup</h2></legend>
                                <form action="<?php _url('./', [ 'do' => 'setup' ]); ?>" method="post">
                                    <ul>
                                        <li><label><input type="checkbox" name="scopes[]" checked value="config"> <span>Config</span></label></li>
                                        <li><label><input type="checkbox" name="scopes[]" checked value="tree"> <span>Tree</span></label></li>
                                        <li><label><input type="checkbox" name="scopes[]" checked value="samples"> <span>Samples</span></label></li>
                                        <?php if (is_file(get_path('config.json'))): ?>
                                            <li><label><input type="checkbox" name="confirm" value="1"> <span>The environment is not empty. Setup will probably erase something. Check this to confirm your action.</span></label></li>
                                        <?php endif; ?>
                                    </ul>
                                    <button type="submit" class="bt">🛠️ Setup</button>
                                </form>
                            </fieldset>

                        <?php elseif ($view === 'files'): ?>

                            <fieldset>
                                <legend><h2>📁 Files</h2></legend>
                                <dl>
                                    <dt>Current User</dt>
                                    <dd><code><?php _esc($files->user->name); ?></code> <span class="low">(#<code><?php _esc($files->user->id); ?></code>)</span></dd>
                                    <dt>Root Folder</dt>
                                    <dd>
                                        <?php if (!$files->is_root): ?> <code class="unselectable"> <a href="<?php _url('./', [ 'v' => 'files' ]); ?>">
                                        <?php else: ?> <code> <?php endif; ?>
                                        <?php _esc($files->root_path); ?>
                                        <?php if ($files->is_root): ?> </code> <?php else: ?> </a> </code> <?php endif; ?>
                                    </dd>
                                    <dt>Current Folder</dt> <dd><code><?php _esc($files->dir_path); ?></code></dd>
                                    <dt>Download</dt> <dd><a href="<?php _url('./', [ 'path' => $files->dir_relative_path, 'do' => 'files.download' ]); ?>">📥 Download This Folder</a></dd>
                                    <dt>Folder Size</dt>
                                    <dd>
                                        <?php if ($files->sizes !== null): ?>
                                            <?php _esc($files->sizes->all->readable_size); ?>
                                            <span class="low">(<?php _esc($files->sizes->all->size); ?> B)</span>
                                        <?php else: ?>
                                            <a href="<?php _url('./', [ 'path' => $files->dir_relative_path, 'do' => 'files.size' ]); ?>">⚖️ Compute Folder Size</a>
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th class="fit" colspan="2">⚖️ Size</th>
                                            <th class="fit">🤵 Owner</th>
                                            <th class="fit">🔒 Rights</th>
                                            <th class="fit">📥 Download</th>
                                            <th class="fit">🔗 URL</th>
                                            <th class="fit">👁️ Preview</th>
                                            <th class="fit">🗃️ Unzip</th>
                                            <th class="fit">🗑️ Delete</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <?php if ($files->is_root): ?>
                                                <td colspan="5" class="low">⬆️ Parent Folder</td>
                                            <?php else: ?>
                                                <td class="lnk unfit" colspan="5"><a href="<?php _url('./', [ 'v' => 'files', 'dir' => $files->parent_relative_path ]); ?>">⬆️ Parent Folder</a></td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php foreach (array_merge($files->folders, $files->files) as $f): ?>
                                            <?php $relative_path = $f->getRelativePath(); ?>
                                            <?php if ($is_dir = $f->isDir()): ?>
                                                <?php $readable_size = $files->sizes && ($s = $files->sizes->folders[$f->getName()] ?? null) ? $s->readable_size : null; ?>
                                                <?php $size = $files->sizes && ($s = $files->sizes->folders[$f->getName()] ?? null) ? $s->size : null; ?>
                                            <?php else: ?>
                                                <?php $readable_size = $f->getSize(true); ?>
                                                <?php $size = $f->getSize(); ?>
                                            <?php endif; ?>
                                            <tr>
                                                <?php if ($is_dir): ?>
                                                    <td class="lnk unfit">
                                                        <a href="<?php _url('./', [ 'v' => 'files', 'dir' => $relative_path ]); ?>">
                                                            📁 <strong><?php _esc($f->getName()); ?></strong>
                                                        </a>
                                                    </td>
                                                <?php else: ?>
                                                    <td>📄 <?php _esc($f->getName()); ?></td>
                                                <?php endif; ?>
                                                <?php if ($readable_size): ?> <td class="fit right"><?php _esc($readable_size); ?></td> <?php else: ?> <td class="fit center low">?</td> <?php endif; ?>
                                                <?php if ($size): ?> <td class="fit right low"><code><?php _esc($size); ?></code> B</td> <?php else: ?> <td class="fit center low">?</td> <?php endif; ?>
                                                <?php list($f_user_name, $f_group_name, $f_user_id, $f_group_id) = $f->getOwner(); ?>
                                                <td class="fit"><?php echo '<code>' . (esc($f_user_name) ?: '&mdash;') . '</code>:<code>' . (esc($f_group_name) ?: '&mdash;') . '</code>'; ?></td>
                                                <td class="fit"><?php echo esc($f->getPerms(readable: true)) ?: '&mdash;'; ?></td>
                                                <td class="lnk"><a href="<?php _url('./', [ 'path' => $relative_path, 'do' => 'files.download' ]); ?>">📥 Download</a></td>
                                                <td class="lnk"><a href="<?php _esc($f->getUrl()); ?>" target="_blank">🔗 URL</a></td>
                                                <?php if ($f->seemsAscii()): ?>
                                                    <td class="lnk"><a href="<?php _url('./', [ 'path' => $relative_path, 'do' => 'files.preview' ]); ?>" target="_blank">👁️ Preview</a></td>
                                                <?php else: ?>
                                                    <td class="low center">N/A</td>
                                                <?php endif; ?>
                                                <?php if ($f->hasExtension('zip')): ?>
                                                    <td class="lnk"><a href="<?php _url('./', [ 'path' => $relative_path, 'do' => 'files.unzip' ]); ?>">🗃️ Unzip</a></td>
                                                <?php else: ?>
                                                    <td class="low center">N/A</td>
                                                <?php endif; ?>
                                                <td class="lnk"><a href="<?php _url('./', [ 'path' => $relative_path, 'do' => 'files.delete' ]); ?>">🗑️ Delete</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </fieldset>

                            <fieldset>
                                <legend><h2>📥 Put File By URL</h2></legend>
                                <div class="inline-form-container">
                                    <div>Put file</div>
                                    <form action="<?php _url('./', [ 'do' => 'files.put' ]); ?>" class="inline-form" method="post">
                                        <div>
                                            <span>in dir =</span>
                                            <input
                                                type="text"
                                                name="path"
                                                readonly
                                                spellcheck="false"
                                                value="<?php _esc($files->dir_relative_path); ?>"
                                                <?php if (!$files->dir_relative_path): ?> placeholder="(root folder)" <?php endif; ?>
                                                >
                                        </div>
                                        <div> <span>from url =</span> <input type="text" name="url" spellcheck="false" autocomplete="off" placeholder="https://&hellip;"> </div>
                                        <button type="submit">Put</button>
                                    </form>
                                </div>
                            </fieldset>

                        <?php elseif ($view === 'db' || str_starts_with($view, 'db.')): ?>

                            <?php if ($db->config): ?>

                                <?php if ($view === 'db.info' || !$db->is_connected): ?>

                                    <fieldset>
                                        <legend><h2>🛢️ Database Info</h2></legend>
                                        <dl>
                                            <dt>Host</dt>
                                            <dd><code><?php echo esc($db->config['host']) ?: '&mdash;'; ?></code> : <code><?php echo esc($db->config['port']) ?: '&mdash;'; ?></code></dd>
                                            <dt>Username</dt> <dd><code><?php echo esc($db->config['username']) ?: '&mdash;'; ?></code></dd>
                                            <dt>Database Name</dt> <dd><code><?php echo esc($db->config['db_name']) ?: '&mdash;'; ?></code></dd>
                                            <dt>Password</dt> <dd><strong><?php echo $db->config['password'] ? 'Yes' : 'No'; ?></strong></dd>
                                            <dt>Connected</dt><dd><strong><?php echo $db->is_connected ? 'Yes' : 'No'; ?></strong></dd>
                                        </dl>
                                    </fieldset>

                                <?php endif; ?>

                                <?php if ($db->is_connected): ?>

                                    <?php if ($view === 'db.migrations'): ?>

                                        <fieldset>
                                            <legend><h2>➡️ Migrations</h2></legend>
                                            <?php if ($db->migrations->all): ?>
                                                <?php if ($db->migrations->error): ?>
                                                    <p class="danger">An error occured while running the migration:</p>
                                                    <code class="block"><?php echo esc($db->migrations->error); ?></code>
                                                <?php endif; ?>
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th></th>
                                                            <th>Name</th>
                                                            <th>Description</th>
                                                            <th>Dependencies</th>
                                                            <th colspan="2">Queries</th>
                                                            <th colspan="3">Execute</th>
                                                            <th colspan="2">Run</th>
                                                            <th>Check</th>
                                                            <th colspan="2">Current</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php $nb_migrations = count($db->migrations->all); ?>
                                                        <?php $current_matched = false; ?>
                                                        <?php foreach ($db->migrations->all as $idx => $m): ?>
                                                            <?php $can_down = $db->migrations->current && !$current_matched; ?>
                                                            <?php $can_up = !$db->migrations->current || $current_matched; ?>
                                                            <?php if ($is_current_migration = $db->migrations->current === $m->name) $current_matched = true; ?>
                                                            <tr <?php if ($db->migrations->current === null && $idx === 0): ?> class="hr-above" <?php elseif (($is_current_migration)): ?> class="hr-below" <?php endif; ?>>
                                                                <td class="fit right"><?php echo $idx + 1 . ' / ' . $nb_migrations; ?></td>
                                                                <td><code><strong><?php echo esc($m->name); ?></strong></code></td>
                                                                <td class="low"><?php if ($m->comments): ?><?php _esc(implode(' - ', $m->comments)); ?><?php else: ?>&mdash;<?php endif; ?></td>
                                                                <td><?php if ($m->dependencies): ?> <?php foreach ($m->dependencies as $idx => $dep): ?><?php if ($idx > 0) echo ', '; ?><code><?php echo $dep; ?></code><?php endforeach; ?> <?php else: ?> <span class="low">&mdash;</span> <?php endif; ?></td>
                                                                <td class="fit"><code><?php echo count($m->files->up->queries); ?></code> / <code><?php echo $m->files->down ? count($m->files->down->queries) : '&mdash;'; ?></code></td>
                                                                <td class="lnk"><a href="<?php _url('./', [ 'do' => 'db.migration.view', 'm' => $m->name ]); ?>" data-popup target="_blank">📋 View</a></td>
                                                                <td class="lnk"><?php if ($m->files->down): ?><a href="<?php _url('./', [ 'v' => 'db.migrations', 'do' => 'db.migration.exec', 'm' => $m->name, 's' => 'down' ]); ?>">⬇️ Down</a><?php else: ?>&mdash;<?php endif; ?></td>
                                                                <td class="lnk"><a href="<?php _url('./', [ 'v' => 'db.migrations', 'do' => 'db.migration.exec', 'm' => $m->name, 's' => 'up' ]); ?>">⬆️ Up</a></td>
                                                                <td class="lnk"><a href="<?php _url('./', [ 'v' => 'db.migrations', 'do' => 'db.migration.exec', 'm' => $m->name, 's' => 'downup' ]); ?>">⤵️ Down and Up</a></td>
                                                                <?php if ($can_down): ?><td class="lnk"><?php if ($m->files->down): ?><a href="<?php _url('./', [ 'v' => 'db.migrations', 'do' => 'db.migration.run', 'm' => $m->name, 's' => 'down' ]); ?>">⬇️ Down</a><?php else: ?>&mdash;<?php endif; ?></td>
                                                                <?php else: ?><td class="low fit">⬇️ Down</td><?php endif; ?>
                                                                <?php if ($can_up): ?><td class="lnk"><a href="<?php _url('./', [ 'v' => 'db.migrations', 'do' => 'db.migration.run', 'm' => $m->name, 's' => 'up' ]); ?>">⬆️ Up</a></td>
                                                                <?php else: ?><td class="low fit">⬆️ Up</td><?php endif; ?>
                                                                <td class="fit center"><?php if ($m->check->checked === true): ?> ✅ <?php elseif ($m->check->checked === false): ?> ❌ <?php else: ?> ❔ <?php endif; ?></td>
                                                                <?php if ($is_current_migration): ?><td class="fit">✅ Current</td><?php endif; ?>
                                                                <td class="lnk"<?php if (!$is_current_migration): ?> colspan="2"<?php endif; ?>><a href="<?php _url('./', [ 'do' => 'db.migration.current', 'm' => $m->name ]); ?>"><?php echo $is_current_migration ? "❌ Unset" : "✔️ Set"; ?></a></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <p>No migration exists in this app.</p>
                                            <?php endif; ?>
                                        </fieldset>

                                    <?php elseif ($view === 'db.tables'): ?>

                                        <fieldset>
                                            <legend><h2>📋 Tables</h2></legend>
                                            <?php if ($db->tables): ?>
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th class="fit">Table</th>
                                                            <th>Rows</th>
                                                            <th>Columns [ Name / Type / Null ]</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($db->tables as $t): ?>
                                                            <?php $nb_columns = count($t->columns); ?>
                                                            <tr>
                                                                <td class="fit">
                                                                    <form action="<?php _url('./'); ?>" class="lnk" method="post">
                                                                        <input type="hidden" name="v" value="db.sql">
                                                                        <input type="hidden" name="do" value="db.query">
                                                                        <input type="hidden" name="sql" value="SELECT * FROM `<?php echo esc($t->name); ?>` LIMIT 0, 50">
                                                                        <button type="submit" class="lnk code"><strong><?php echo esc($t->name); ?></strong></button>
                                                                    </form>
                                                                </td>
                                                                <td class="fit"><code><?php echo $t->count; ?></code></td>
                                                                <td class="inner-table">
                                                                    <table>
                                                                        <tbody>
                                                                            <?php foreach ($t->columns as $idx => $c): ?>
                                                                                <tr>
                                                                                    <td><code><?php echo esc($c->name); ?></code></td>
                                                                                    <td class="db-column-type"><code><?php echo esc($c->type); ?></code></td>
                                                                                    <td class="db-column-bool"><code><?php echo esc($c->null ? 'Yes' : 'No'); ?></code></td>
                                                                                </tr>
                                                                            <?php endforeach; ?>
                                                                        </tbody>
                                                                    </table>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php else: ?>
                                                <p>No table was created in this database.</p>
                                            <?php endif; ?>
                                        </fieldset>

                                    <?php elseif ($view === 'db.sql'): ?>

                                        <fieldset>
                                            <legend><h2>📄 SQL</h2></legend>
                                            <form action="<?php _url('./'); ?>" method="post">
                                                <input type="hidden" name="v" value="db.sql">
                                                <input type="hidden" name="do" value="db.query">
                                                <div class="field">
                                                    <textarea cols="" rows="5" name="sql" spellcheck="false" placeholder="SELECT * FROM &hellip; LIMIT 0, 50"><?php echo $db->query->sql ?: ''; ?></textarea>
                                                    <button type="submit">🚀 Run</button>
                                                </div>
                                            </form>
                                            <?php if ($db->query->sql): ?>
                                                <?php if ($db->query->result === false): ?>
                                                    <p class="danger">An error occured while executing the query:</p>
                                                    <code class="block"><?php echo esc($db->query->error ?: 'Unknown Error'); ?></code>
                                                <?php else: ?>
                                                <?php if ($db->query->is_select): ?>
                                                    <?php if ($db->query->result): ?>
                                                        <p>Showing <?php echo count($db->query->result); ?> row(s) &mdash; <a href="<?php _url('./'); ?>">Reset</a></p>
                                                        <div class="h-scroll">
                                                            <table>
                                                                <thead>
                                                                    <tr>
                                                                        <?php foreach ($db->query->result as $row): ?>
                                                                        <?php foreach ($row as $k => $v): ?> <th><?php echo esc($k); ?></th> <?php endforeach; ?>
                                                                        <?php break; endforeach; ?>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($db->query->result as $row): ?>
                                                                        <tr>
                                                                            <?php foreach ($row as $k => $v): ?>
                                                                                <td class="db-value">
                                                                                    <?php $v = ($v === null ? 'NULL' : (is_numeric($v) ? (string) $v : $v)); ?>
                                                                                    <?php if ($db->query->results_nb > 1 && strlen($v) > 64) $v = substr($v, 0, 64) . unesc('&hellip;'); ?>
                                                                                    <code><?php echo esc($v); ?></code>
                                                                                    <?php if ($db->query->results_nb > 1 && $db->query->table_name && strtolower($k) === 'id'): ?>
                                                                                        <form action="<?php _url('./'); ?>" class="lnk" method="post">
                                                                                            <input type="hidden" name="v" value="db.sql">
                                                                                            <input type="hidden" name="do" value="db.query">
                                                                                            <input type="hidden" name="sql" value="SELECT * FROM `<?php _esc($db->query->table_name); ?>` WHERE `<?php _esc($k); ?>` = <?php echo is_int_val($v) ? $v : ('"' . esc() . '"'); ?> LIMIT 0, 1">
                                                                                            <button type="submit" class="lnk code"><strong>&#10035;</strong></button>
                                                                                        </form>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                            <?php endforeach; ?>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php else: ?>
                                                        <p>No result.</p>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <p>Query executed successfully.</p>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </fieldset>

                                    <?php elseif ($view === 'db.search'): ?>

                                        <fieldset>
                                            <legend><h2>🔍 Search</h2></legend>
                                            <form action="<?php _url('./'); ?>" method="post">
                                                <input type="hidden" name="v" value="db.search">
                                                <input type="hidden" name="do" value="db.search">
                                                <div class="field">
                                                    <input type="text" spellcheck="false" autocomplete="off" name="q" placeholder="Search for terms in all database&hellip;" value="<?php _esc($db->search->term); ?>">
                                                    <select name="mode">
                                                        <option value="like_jokers" selected>Column contains the value (LIKE '%&hellip;%')</option>
                                                        <option value="like">Column is like the value (LIKE '&hellip;')</option>
                                                        <option value="equals">Column equals the value (= '&hellip;')</option>
                                                    </select>
                                                    <button type="submit">🔍 Search</button>
                                                </div>
                                            </form>
                                        </fieldset>

                                        <?php if ($db->search->results): ?>
                                            <fieldset>
                                                <legend><h2>📋 Results</h2></legend>
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Table</th>
                                                            <th>Matches</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($db->search->results as $r): ?>
                                                            <tr>
                                                                <?php if ($r->matches): ?>
                                                                    <td><strong><?php _esc($r->table); ?></strong></td>
                                                                    <td class="num fit"><?php _esc($r->matches); ?></td>
                                                                    <td class="fit">
                                                                        <form action="<?php _url('./'); ?>" class="lnk" method="post">
                                                                            <input type="hidden" name="v" value="db.sql">
                                                                            <input type="hidden" name="do" value="db.query">
                                                                            <input type="hidden" name="sql" value="<?php _esc($r->query); ?>">
                                                                            <button type="submit" class="lnk code"><strong>📋 See Rows</strong></button>
                                                                        </form>
                                                                    </td>
                                                                <?php else: ?>
                                                                    <td class="low"><?php _esc($r->table); ?></td>
                                                                    <td class="num fit"><span class="low"><?php _esc($r->matches); ?></span></td>
                                                                    <td></td>
                                                                <?php endif; ?>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </fieldset>
                                        <?php endif; ?>

                                    <?php elseif ($view === 'db.dumps'): ?>

                                        <fieldset>
                                            <legend><h2>📤 Dumps</h2></legend>
                                            <div class="cards">
                                                <div class="card">
                                                    <strong>Create Dump</strong>
                                                    <ul>
                                                        <li><a href="<?php _url('./', [ 'do' => 'db.dumps.dump_dl' ]); ?>" class="bt">📥 Dump database and download</a></li>
                                                        <li><a href="<?php _url('./', [ 'do' => 'db.dumps.dump_store' ]); ?>" class="bt">💾 Dump database and store on server</a></li>
                                                    </ul>
                                                </div>
                                                <div class="card">
                                                    <strong>Upload and execute SQL file</strong>
                                                    <form action="<?php _url('./', [ 'do' => 'db.dumps.upload_exec' ]); ?>" method="post" enctype="multipart/form-data">
                                                        <input type="file" name="file" accept=".sql">
                                                        <button type="submit">📤 Upload and Import SQL File</button>
                                                    </form>
                                                    <strong>Execute SQL file stored on server</strong>
                                                    <?php if ($sql_files): ?>
                                                        <ul>
                                                            <?php foreach ($sql_files as $f): ?>
                                                                <li><a href="<?php _url('./', [ 'do' => 'db.dumps.exec', 'f' => $f->getName() ]); ?>" class="bt"><?php echo esc($f->getName()); ?></a></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <p>No SQL file located in root directory.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </fieldset>

                                    <?php elseif ($view === 'db.snapshots'): ?>

                                        <fieldset>
                                            <legend><h2>📸 Snapshots</h2></legend>
                                            <div class="cards">
                                                <div class="card">
                                                    <strong>Create Snapshot</strong>
                                                    <ul>
                                                        <li><a href="<?php _url('./', [ 'do' => 'db.snapshots.create' ]); ?>" class="bt">➕ Create Snapshot</a></li>
                                                    </ul>
                                                </div>
                                                <div class="card">
                                                    <strong>Snapshots</strong>
                                                    <?php if ($db->snapshots): ?>
                                                        <table>
                                                            <tbody>
                                                                <?php foreach ($db->snapshots as $s): ?>
                                                                    <tr>
                                                                        <td><strong><?php _esc($s->at->format('Y-m-d H:i:s')); ?></strong></td>
                                                                        <td><?php _esc(bytes_unit($s->size)); ?></td>
                                                                        <td class="lnk"><a href="<?php _url('./', [ 'do' => 'db.snapshots.dl', 's' => $s->name ]); ?>">📥 Download</a></td>
                                                                        <td class="lnk"><a href="<?php _url('./', [ 'do' => 'db.snapshots.delete', 's' => $s->name ]); ?>">🗑️ Delete</a></td>
                                                                        <td class="lnk"><a href="<?php _url('./', [ 'do' => 'db.snapshots.restore', 's' => $s->name ]); ?>">✔️ Restore</a></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php else: ?>
                                                        <p>No snapshot created yet.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </fieldset>

                                    <?php elseif ($view === 'db.reset'): ?>

                                        <fieldset>
                                            <legend><h2>♻️ Reset Database</h2></legend>
                                            <?php if (is_env('staging', 'prod')): ?>
                                                <p>Database reset is disabled for this environment.</p>
                                            <?php else: ?>
                                                <ul><li><a href="<?php _url('./', [ 'do' => 'db.reset' ]); ?>" class="bt">🗑️ Delete All Tables</a></li></ul>
                                            <?php endif; ?>
                                        </fieldset>

                                    <?php endif; ?>

                                <?php elseif ($db->connection_error): ?>

                                    <fieldset>
                                        <legend><h2>🛢️ Database</h2></legend>
                                        <p class="danger">An error occured while trying to connect to the database:</p>
                                        <p><code class="block"><?php echo preg_replace('/^([A-Z]+\[[^\]]+\]\s*\[[^\]]+\])\s*/', '$1<br>', $db->connection_error); ?></code></p>
                                        <?php if (preg_match('/(Access denied)/', $db->connection_error)): ?>
                                            <p>Maybe you want to execute as root some of those queries:</p>
                                            <p>
                                                <code class="block">CREATE DATABASE <?php echo $db->config['db_name']; ?>;</code>
                                                <code class="block">
                                                    CREATE USER '<?php echo $db->config['username']; ?>'@'<?php echo $db->config['host']; ?>' IDENTIFIED BY '<?php echo $db->config['password'] === 'dfgdfg' ? $db->config['password'] : 'PasswordOfThisUser'; ?>';<br>
                                                    GRANT ALL PRIVILEGES ON <?php echo $db->config['db_name']; ?>.* TO '<?php echo $db->config['username']; ?>'@'localhost';<br>
                                                    FLUSH PRIVILEGES;
                                                </code>
                                            </p>
                                            <div class="inline-form-container">
                                                <div>Or maybe you want me to try to do it for you, with</div>
                                                <form action="<?php _url('./', [ 'do' => 'db.setup' ]); ?>" class="inline-form" method="post">
                                                    <div> <span>root username =</span> <input type="text" name="root_user" spellcheck="false" autocomplete="off" value="root"> </div>
                                                    <div> <span>and root password =</span> <input type="text" name="root_password" spellcheck="false" autocomplete="off" value="dfgdfg"> </div>
                                                    <button type="submit">Create</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </fieldset>

                                <?php endif; ?>

                            <?php else: ?>

                                <fieldset>
                                    <legend><h2>🛢️ Database</h2></legend>
                                    <p>The database is not configured.</p>
                                </fieldset>

                            <?php endif; ?>

                        <?php elseif ($view === 'emails'): ?>

                            <fieldset>
                                <legend><h2>✉️ Emails</h2></legend>
                                <dl>
                                    <dt>Carrier</dt><dd><code><?php _esc(($carrier_name = cfg('~@emails.current_carrier')) ?: "None"); ?></code></dd>
                                </dl>
                            </fieldset>

                            <fieldset>
                                <legend><h2>📤 Test</h2></legend>
                                <div class="inline-form-container">
                                    <div>Send Test Email</div>
                                    <form action="<?php _url('./', [ 'do' => 'emails.test' ]); ?>" class="inline-form" method="post">
                                        <div>   <span>to =</span>      <input type="text" name="to" spellcheck="false" value="<?php __cfg('~@emails.addresses.to.address'); ?>" placeholder="jane.doe@domain.tld"> </div>
                                        <div> ; <span>subject =</span> <input type="text" name="subject" spellcheck="false" value="Email Test From <?php __cfg('~@app.name'); ?>" placeholder="Some Subject"> </div>
                                        <div> ; <span>message =</span> <input type="text" name="message" spellcheck="false" value="Hello World!" placeholder="Hello World!"> </div>
                                        <button type="submit" name="mode" value="preview">Preview</button>
                                        <button type="submit" name="mode" value="send">Send</button>
                                    </form>
                                </div>
                            </fieldset>

                            <?php if ($emails->folders): ?>
                                <fieldset>
                                    <legend><h2>📁 Stored Emails</h2></legend>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="fit">Folder</th>
                                                <th class="fit">Date</th>
                                                <th class="fit">Microtime</th>
                                                <th>Recipient Address</th>
                                                <th class="fit">File Size</th>
                                                <th class="fit"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($emails->folders as $folder): ?>
                                                <?php $files = $folder->getName() === $emails->selected_folder ? $emails->files : null; ?>
                                                <?php foreach ($files ?: [ null ] as $idx => $file): ?>
                                                    <tr>
                                                        <?php if ($idx === 0): ?>
                                                            <td
                                                                class="lnk"
                                                                <?php if ($file): ?> rowspan="<?php echo count($files); ?>" <?php endif; ?>
                                                                >
                                                                <a href="<?php _url('./', [ 'v' => 'emails', 'folder' => $folder->getName() ]); ?>">
                                                                    <?php _esc($folder->getName()); ?>
                                                                </a>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($file === null): ?>
                                                            <td colspan="5" class="center low">&mdash;</td>
                                                        <?php else: ?>
                                                            <?php if (preg_match('/^(?<dt>[0-9]+)-(?<mt>[0-9]+)-(?<dest>.+)\.html$/', $file->getName(), $m)): ?>
                                                                <td class="code fit"><?php _esc($m['dt']); ?></td>
                                                                <td class="code fit"><?php _esc($m['mt']); ?></td>
                                                                <td class="code"><?php _esc(str_replace('--at--', '@', $m['dest'])); ?></td>
                                                                <td class="code fit right"><?php _esc($file->getSize(readable: true)); ?></td>
                                                                <td class="lnk"><a href="<?php _esc(path_to_url($file->getPath())); ?>" target="_blank">🔗 View</a></td>
                                                            <?php else: ?>
                                                                <td colspan="5" class="center low">?</td>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </fieldset>
                            <?php endif; ?>

                        <?php elseif ($view === 'tasks'): ?>

                            <fieldset>
                                <legend><h2>🤖 Tasks</h2></legend>
                                <?php if ($tasks->all): ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="fit">Bundle</th>
                                                <th class="fit">Task Name</th>
                                                <th>Web</th>
                                                <th>Cli</th>
                                                <th>Arguments</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tasks->all as $t): ?>
                                                <tr>
                                                    <td class="fit" rowspan="2"><code><strong><?php _esc($t->bundle); ?></strong></code></td>
                                                    <td class="fit" rowspan="2"><code><strong><?php _esc($t->name); ?></strong></code></td>
                                                    <?php if ($t->enabled->web): ?>
                                                        <td class="lnk" rowspan="2"><a href="<?php _esc(generate_task_url($t->uid)); ?>" target="_blank">🔗 Task URL</a></td>
                                                    <?php else: ?>
                                                        <td class="low fit" rowspan="2">Disabled</td>
                                                    <?php endif; ?>

                                                    <?php if ($t->enabled->cli): ?>
                                                        <td><small><code><?php _esc(generate_task_cli_path($t->uid)); ?></code></small></td>
                                                    <?php else: ?>
                                                        <td class="low" rowspan="2">Disabled</td>
                                                    <?php endif; ?>

                                                    <?php if ($t->args): ?>
                                                        <td rowspan="2">
                                                            <?php $is_first_arg = true; ?>
                                                            <?php foreach ($t->args as $arg_key => $arg_info): ?>
                                                                <?php if (!$is_first_arg): ?> <br> <?php endif; ?>
                                                                <strong><code><?php _esc($arg_key); ?></code></strong>
                                                                <?php if ($arg_info['optional']): ?><small>(optional)</small><?php endif; ?>
                                                                <?php if ($arg_info['desc']): ?><span><?php _esc($arg_info['desc']); ?></span><?php endif; ?>
                                                            <?php $is_first_arg = false; endforeach; ?>
                                                        </td>
                                                    <?php else: ?>
                                                        <td class="low" rowspan="2">(None)</td>
                                                    <?php endif; ?>
                                                </tr>
                                                <tr>
                                                    <td><small><code><?php _esc($t->file); ?></code></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p>To task registered.</p>
                                <?php endif; ?>
                            </fieldset>

                            <fieldset>
                                <legend><h2>⚙️ Tasks Actions</h2></legend>
                                <a href="<?php _url('./', [ 'do' => 'tasks.assert_files' ]); ?>" class="bt">⚙️ Rewrite Tasks Files</a>
                            </fieldset>

                        <?php elseif ($view === 'sitemap'): ?>

                            <fieldset>
                                <legend><h2>🧭 Sitemap</h2></legend>
                                <a href="<?php _url('./', [ 'do' => 'sitemap.generate' ]); ?>" class="bt">⚙️ Generate Sitemap</a>
                                <a href="<?php _url('./', [ 'do' => 'sitemap.preview' ]); ?>" class="bt" target="_blank">👁️ Preview Sitemap</a>
                                <a href="<?php _url('./', [ 'do' => 'sitemap.links' ]); ?>" class="bt" target="_blank">📋 List Links</a>
                            </fieldset>

                            <fieldset>
                                <legend><h2>📁 Existing Sitemaps</h2></legend>
                                <?php if ($sitemap->existing): ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th class="fit" colspan="2">Size</th>
                                                <th class="fit" colspan="2">Last Update</th>
                                                <th class="fit">Links</th>
                                                <th colspan="2"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sitemap->existing as $existing): ?>
                                                <tr>
                                                    <td><strong><?php _esc($existing->file->getName()); ?></strong></td>
                                                    <td class="fit right"><?php _esc($existing->file->getSize(readable: true)); ?></td>
                                                    <td class="fit right low"><?php _esc(number_format($existing->file->getSize())); ?> B</td>
                                                    <td class="fit"><?php _esc($existing->file->getModifiedAt('Y-m-d H:i:s')); ?></td>
                                                    <td class="fit low"><?php _esc(get_time_ago($existing->file->getModifiedAt(), translate: false)); ?></td>
                                                    <td class="fit right"><?php _esc($existing->links); ?></td>
                                                    <td class="lnk"><a href="<?php _url('./', [ 'path' => $existing->file->getRelativePath(), 'do' => 'files.delete', 'after' => url('.') ]); ?>">🗑️ Delete</a></td>
                                                    <td class="lnk"><a href="<?php _esc($existing->file->getUrl()); ?>" target="_blank">🔍 View</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p>To sitemap created yet.</p>
                                <?php endif; ?>
                            </fieldset>

                            <fieldset>
                                <legend><h2>📚 Sources</h2></legend>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Priority</th>
                                            <th>Source Name</th>
                                            <th>Links</th>
                                            <th colspan="3"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sitemap->sources as $src): ?>
                                            <tr>
                                                <td class="fit right"><?php _esc($src->priority); ?></td>
                                                <td><strong><?php _esc($src->name); ?></strong></td>
                                                <td class="fit right"><?php _esc($sitemap->count_per_source[$src->name] ?? 0); ?></td>
                                                <td class="lnk"><a href="<?php _url('./', [ 'do' => 'sitemap.links', 'src' => $src->name ]); ?>" target="_blank">📋 List Links</a></td>
                                                <td class="lnk"><a href="<?php _url('./', [ 'do' => 'sitemap.preview', 'src' => $src->name ]); ?>" target="_blank">👁️ Preview Distinct Sitemap</a></td>
                                                <td class="lnk"><a href="<?php _url('./', [ 'do' => 'sitemap.generate', 'src' => $src->name ]); ?>">⚙️ Generate Distinct Sitemap</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </fieldset>

                        <?php elseif ($view === 'deploy'): ?>

                            <fieldset>
                                <?php $deploy_enabled = (bool) cfg('~@deploy.enabled'); ?>
                                <legend><h2>🚀 Deploy</h2></legend>
                                <dl>
                                    <dt>Enabled</dt> <dd><?php echo $deploy_enabled ? "Yes" : "No"; ?></dd>
                                    <dt>Log</dt> <dd><?php echo cfg('~@deploy.log') ? "Yes" : "No"; ?></dd>
                                    <dt>Command</dt> <dd><code><?php echo cfg('~@deploy.commands.pull') ?: "N/A"; ?></code></dd>
                                </dl>
                                <?php if ($deploy_enabled): ?>
                                    <?php if ($keys = (cfg('~@deploy.keys') ?: [])): ?>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>🔑 Key</th>
                                                    <th class="fit">🔗 Hook URL</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($keys as $key): ?>
                                                    <tr>
                                                        <td class="small"><code><?php _esc($key); ?></code></td>
                                                        <td class="lnk"><a href="<?php _url('./', [ 'do' => 'deploy', 'key' => $key ], host: true); ?>" target="_blank">🔗 Hook URL</a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <hr>
                                        <a href="<?php _url('./', [ 'do' => 'deploy', 'key' => $keys[0] ]); ?>" class="bt" target="_blank">
                                            🚀 Deploy Now Using First Key
                                        </a>
                                    <?php else: ?>
                                        <p>No deployment keys defined in configuration.</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p>The deployment is disabled.</p>
                                <?php endif; ?>
                            </fieldset>

                            <?php if ($deploy->git->last_commit): ?>
                                <fieldset>
                                    <legend><h2>🕰️ Last Commit</h2></legend>
                                    <dl>
                                        <dt>Commit</dt> <dd><code><?php _esc($deploy->git->last_commit->commit); ?></code></dd>
                                        <dt>Author</dt> <dd><code><?php _esc($deploy->git->last_commit->author); ?></code></dd>
                                        <dt>Date</dt> <dd><code><?php _esc($deploy->git->last_commit->date->format('Y-m-d H:i:s')); ?></code> <span class="low">(<?php _esc(strtolower(get_time_ago($deploy->git->last_commit->date, translate: false))); ?>)</span></dd>
                                        <dt>Message</dt> <dd><code><?php _esc($deploy->git->last_commit->message); ?></code></dd>
                                    </dl>
                                </fieldset>
                            <?php endif; ?>

                        <?php elseif ($view === 'errors'): ?>

                            <fieldset>
                                <legend><h2>💥 Errors</h2></legend>
                                <a href="<?php _url('./', [ 'v' => 'errors', 'error_type' => '500' ]); ?>" class="bt <?php if ($errors->type !== '500'): ?> inactive <?php endif; ?>">🐞 500 Internal Error</a>
                                <a href="<?php _url('./', [ 'v' => 'errors', 'error_type' => '404' ]); ?>" class="bt <?php if ($errors->type !== '404'): ?> inactive <?php endif; ?>">❓ 404 Not Found</a>
                                <a href="<?php _url('./', [ 'v' => 'errors', 'error_type' => '403' ]); ?>" class="bt <?php if ($errors->type !== '403'): ?> inactive <?php endif; ?>">🚫 403 Unauthorized</a>
                            </fieldset>

                            <?php if ($errors->type && in_array($errors->type, [ '500', '404', '403' ])): ?>
                                <fieldset>
                                    <legend><h2>📋 Errors Entries</h2></legend>
                                    <?php if ($errors->files): ?>
                                        <table>
                                            <tbody>
                                                <tr>
                                                    <td class="fit">
                                                        <table>
                                                            <tbody>

                                                                <?php $years = []; ?>
                                                                <?php $this_year = null; ?>
                                                                <?php $this_month = null; ?>
                                                                <?php $months = []; ?>

                                                                <?php foreach ($errors->files as $f): ?>

                                                                    <?php if (!array_key_exists($f->year, $years)): ?>
                                                                        <?php $this_year = $f->year; ?>
                                                                        <?php $this_month = null; ?>
                                                                        <?php $months = []; ?>
                                                                        <tr>
                                                                            <td class="lnk">
                                                                                <a
                                                                                    href="<?php _url('./', [ 'v' => 'errors', 'error_type' => $errors->type, 'error_year' => $this_year ]); ?>"
                                                                                    <?php if ($errors->year === $this_year): ?> class="active" <?php endif; ?>
                                                                                    >
                                                                                    <?php _esc($this_year); ?>
                                                                                </a>
                                                                            </td>
                                                                            <td class="lnk"><a href="#" class="disabled">&nbsp;&nbsp;</a></td>
                                                                            <td class="lnk"><a href="#" class="disabled">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</a></td>
                                                                        </tr>
                                                                        <?php $years[$f->year] = true; ?>
                                                                    <?php endif; ?>

                                                                    <?php if (!$errors->year) continue; ?>

                                                                    <?php if (!array_key_exists($f->month, $months)): ?>
                                                                        <?php $this_month = $f->month; ?>
                                                                        <tr>
                                                                            <td class="lnk"><a href="#" class="disabled">&nbsp;&nbsp;&nbsp;&nbsp;</a></td>
                                                                            <td class="lnk">
                                                                                <a
                                                                                    href="<?php _url('./', [ 'v' => 'errors', 'error_type' => $errors->type, 'error_year' => $this_year, 'error_month' => $this_month ]); ?>"
                                                                                    <?php if ($errors->year === $this_year && $errors->month === $this_month): ?> class="active" <?php endif; ?>
                                                                                    >
                                                                                    <?php _esc($this_month); ?>
                                                                                </a>
                                                                            </td>
                                                                            <td class="lnk"><a href="#" class="disabled">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</a></td>
                                                                        </tr>
                                                                        <?php $months[$f->month] = true; ?>
                                                                    <?php endif; ?>

                                                                    <?php if (!$errors->month) continue; ?>

                                                                    <?php $this_day = $f->dt->format('d'); ?>

                                                                    <tr>
                                                                        <td class="lnk"><a href="#" class="disabled">&nbsp;&nbsp;&nbsp;&nbsp;</a></td>
                                                                        <td class="lnk"><a href="#" class="disabled">&nbsp;&nbsp;</a></td>
                                                                        <td class="lnk">
                                                                            <a
                                                                                href="<?php _url('./', [ 'v' => 'errors', 'error_type' => $errors->type, 'error_year' => $this_year, 'error_month' => $this_month, 'error_day' => $this_day ]); ?>"
                                                                                <?php if ($errors->day === $this_day): ?> class="active" <?php endif; ?>
                                                                                >
                                                                                <?php echo esc($this_day) . ' &ndash; ' . esc($f->short_day_name); ?>
                                                                            </a>
                                                                        </td>
                                                                    </tr>

                                                                <?php endforeach; ?>

                                                            </tbody>
                                                        </table>
                                                    </td>
                                                    <?php if ($errors->year && $errors->month && $errors->day): ?>
                                                        <?php if ($errors->lines): ?>
                                                            <td>
                                                                <table>
                                                                    <thead>
                                                                        <tr>
                                                                            <th class="fit">At</th>
                                                                            <th class="fit">IP</th>
                                                                            <th class="fit">Method</th>
                                                                            <th>Url</th>
                                                                            <th>Referer</th>
                                                                            <th class="fit">Browser</th>
                                                                            <th>Message</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($errors->lines as $line): ?>
                                                                            <tr>
                                                                                <td class="fit"><small class="selectable"><?php _esc($line->at); ?></small></td>
                                                                                <td class="fit"><small class="selectable"><?php _esc($line->ip); ?></small></td>
                                                                                <td class="fit"><small class="selectable"><?php _esc($line->http_method); ?></small></td>
                                                                                <td><small><a href="<?php _esc($line->url); ?>" target="_blank">🔗</a></small> <small class="selectable"><?php _esc(truncate_str(preg_replace('/^\.{3}/', unesc('&hellip;'), $line->pretty_url ?: ''), maxLength: 64, ellipsis: true)); ?></small></td>
                                                                                <td>
                                                                                    <?php if ($line->referer): ?>
                                                                                        <small><a href="<?php _esc($line->referer); ?>" target="_blank">🔗</a></small> <small class="selectable"><?php _esc(truncate_str(preg_replace('/^\.{3}/', unesc('&hellip;'), $line->pretty_referer ?: ''), maxLength: 32, ellipsis: true)); ?></small>
                                                                                    <?php else: ?>
                                                                                        <small class="low">N/A</small>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td class="fit"><small class="selectable"><?php _esc($line->browser ? $line->browser->name . ' ' . ($line->browser->version ?: '') : 'N/A'); ?></small></td>
                                                                                <td><small class="selectable"><?php _esc($line->message); ?></small></td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        <?php else: ?>
                                                            <td>No Error Logged For This Day</td>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <td></td>
                                                    <?php endif; ?>
                                                </tr>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p>No Error File Found.</p>
                                    <?php endif; ?>

                                    <hr>

                                    <a href="<?php _url('./', [ 'v' => 'errors', 'error_type' => $errors->type, 'error_year' => $errors->year, 'error_month' => $errors->month, 'error_day' => $errors->day ]); ?>" class="bt <?php if ($errors->limit !== 100): ?> inactive <?php endif; ?>">🗂️ 100</a>
                                    <a href="<?php _url('./', [ 'v' => 'errors', 'error_type' => $errors->type, 'error_year' => $errors->year, 'error_month' => $errors->month, 'error_day' => $errors->day, 'error_limit' => 500 ]); ?>" class="bt <?php if ($errors->limit !== 500): ?> inactive <?php endif; ?>">🗂️ 500</a>
                                    <a href="<?php _url('./', [ 'v' => 'errors', 'error_type' => $errors->type, 'error_year' => $errors->year, 'error_month' => $errors->month, 'error_day' => $errors->day, 'error_limit' => 1000 ]); ?>" class="bt <?php if ($errors->limit !== 1000): ?> inactive <?php endif; ?>">🗂️ 1000</a>
                                    <span class="bt-spacer"></span>
                                    <a href="<?php _url('./', [ 'do' => 'errors.dl', 'error_type' => $errors->type, 'error_year' => $errors->year, 'error_month' => $errors->month, 'error_day' => $errors->day ]); ?>" class="bt">📥 Download Log File</a>

                                </fieldset>
                            <?php endif; ?>

                            <fieldset>
                                <legend><h2>💣 Generate Fake Errors</h2></legend>
                                <a href="<?php _url('./', [ 'do' => 'error.500' ]); ?>" target="_blank" class="bt">💣 500 Internal Error</a>
                                <a href="<?php _url('./', [ 'do' => 'error.404' ]); ?>" target="_blank" class="bt">💣 404 Not Found</a>
                                <a href="<?php _url('./', [ 'do' => 'error.403' ]); ?>" target="_blank" class="bt">💣 403 Unauthorized</a>
                            </fieldset>

                        <?php elseif ($view === 'backup'): ?>

                            <fieldset>
                                <legend><h2>🛟 Backup</h2></legend>
                                <?php $scopes = cfg('~@backup.scopes') ?: []; ?>
                                <form action="<?php _url('./', [ 'do' => 'backup' ]); ?>" method="post">
                                    <ul>
                                        <li><label><input type="checkbox" name="scopes[]" value="db" <?php if (in_array('db', $scopes)): ?> checked <?php endif; ?>> <span>Database</span></label></li>
                                        <li><label><input type="checkbox" name="scopes[]" value="files" <?php if (in_array('files', $scopes)): ?> checked <?php endif; ?>> <span>Files</span></label></li>
                                    </ul>
                                    <div class="field">
                                        <label>Exclude</label>
                                        <input type="text" name="exclude" spellcheck="false" autocomplete="off" value="<?php _esc(implode(', ', cfg('~@backup.exclude'))); ?>">
                                    </div>
                                    <div class="field">
                                        <label>Target Directory</label>
                                        <input type="text" name="target" spellcheck="false" autocomplete="off" value="<?php _esc(get_backup_target()); ?>">
                                    </div>
                                    <div class="field">
                                        <label>Execute Before Backup (PHP file)</label>
                                        <input type="text" name="exec_before" spellcheck="false" autocomplete="off" value="<?php _esc(cfg('~@backup.exec.before')); ?>">
                                    </div>
                                    <div class="field">
                                        <label>Execute After Backup (PHP file)</label>
                                        <input type="text" name="exec_after" spellcheck="false" autocomplete="off" value="<?php _esc(cfg('~@backup.exec.after')); ?>">
                                    </div>
                                    <button type="submit" class="bt">🛟 Backup Now</button>
                                </form>
                            </fieldset>

                            <?php if ($backups): ?>
                                <fieldset>
                                    <legend><h2>🗃️ Backup Archives</h2></legend>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>⌚ Moment</th>
                                            <th colspan="2">📁 Files</th>
                                            <th colspan="2">🛢️ Database</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($backups as $backup): ?>
                                            <tr>
                                                <td><?php _esc($backup->moment->format('Y-m-d H:i:s')); ?></td>
                                                <?php foreach ([ 'files', 'db' ] as $scope): ?>
                                                    <?php if ($backup->scopes->$scope): ?>
                                                        <td class="lnk"><a href="<?php _esc($backup->scopes->$scope->url); ?>">📥 <?php _esc(basename($backup->scopes->$scope->path)); ?></a></td>
                                                        <td class="fit right"><?php _esc($backup->scopes->$scope->readable_size); ?></td>
                                                    <?php else: ?>
                                                        <td class="low center" colspan="2">Nothing</td>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                        <?php elseif ($view === 'security'): ?>

                            <fieldset>
                                <legend><h2>🛡️ Security</h2></legend>
                                <a href="<?php _url('./', [ 'v' => 'security', 'do' => 'security-check' ]); ?>" class="bt">
                                    🚀 <?php if ($security->check): ?> Perform Security Check Again <?php else: ?> Perform Security Check Now <?php endif; ?>
                                </a>
                            </fieldset>

                            <?php if ($security->check): ?>
                                <fieldset>
                                    <legend><h2>🧾 Check Results</h2></legend>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="fit">Item</th>
                                                <th class="fit">Result</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($security->check as $item): ?>
                                                <tr>
                                                    <td class="fit"><?php _esc($item->name); ?></td>
                                                    <td class="fit center">
                                                        <?php echo match ($item->level) {
                                                            MB_SECURITY_LEVEL_CRITICAL => '🚨',
                                                            MB_SECURITY_LEVEL_DANGER   => '⚠️',
                                                            MB_SECURITY_LEVEL_INFO     => 'ℹ️',
                                                            MB_SECURITY_LEVEL_SUCCESS  => '✅',
                                                        }; ?>
                                                        <strong><?php _esc(strtoupper($item->level)); ?></strong>
                                                    </td>
                                                    <td><?php echo $item->details; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </fieldset>
                            <?php endif; ?>

                        <?php elseif ($view === 'server'): ?>

                            <fieldset>
                                <legend><h2>🖥️ Server</h2></legend>
                                <dl>
                                    <dt>Time</dt> <dd><code><?php echo (new DateTime())->format('c'); ?></code></dd>
                                    <dt>PHP</dt>
                                    <dd><code><?php echo phpversion(); ?></code> &mdash; <a href="<?php _url('./', [ 'do' => 'phpinfo' ]); ?>" target="_blank">phpinfo()</a></dd>
                                </dl>
                            </fieldset>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // --- Loading -----------------------------------------------------
            (function() { window.addEventListener("beforeunload", (e) => { document.body.classList.add("loading"); }); });
            // --- Popup -------------------------------------------------------
            (function() {
                [... document.querySelectorAll("[data-popup]")].forEach((a) => {
                    a.addEventListener("click", (e) => { e.preventDefault(); popup({ url: a.getAttribute("href"), w: 1700, h: 950 }); });
                });
                const popup = ({ url, title, w, h }) => {
                    const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
                    const dualScreenTop  = window.screenTop  !== undefined ? window.screenTop  : window.screenY;
                    const width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
                    const height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;
                    const systemZoom = width / window.screen.availWidth;
                    const left = (width - w) / 2 / systemZoom + dualScreenLeft;
                    const top = (height - h) / 2 / systemZoom + dualScreenTop;
                    const newWindow = window.open("about:blank", title || ("w" + Math.round(Math.random() * 1000000)), [
                        "scrollbars=yes",
                        "width=" + (w / systemZoom),
                        "height=" + (h / systemZoom),
                        "top=" + top,
                        "left=" + left,
                    ].join(","));
                    if (window.focus) {
                        newWindow.focus();
                        const intv = window.setInterval(() => {
                            if (newWindow.document && newWindow.document.body) {
                                newWindow.document.body.style.backgroundColor = "black";
                                window.clearInterval(intv);
                                newWindow.location = url;
                            }
                        }, 50);
                    }
                };
            });
            // --- Updates -----------------------------------------------------
            (function() {
                if (!document.getElementById("updates-wait")) return;
                const $upd = {
                    wait:      document.getElementById("updates-wait"),
                    available: document.getElementById("updates-available"),
                    none:      document.getElementById("updates-uptodate"),
                    error:     document.getElementById("updates-error"),
                    loading:   document.getElementById("updates-loading"),
                    nb:        document.getElementById("updates-nb"),
                    tbody:     null,
                    tr:        null,
                };
                [... document.querySelectorAll("[data-updates-refresh]")].forEach((a) => {
                    a.addEventListener("click", (e) => { e.preventDefault(); refresh(); });
                });
                $upd.tbody = $upd.available.getElementsByTagName("tbody")[0];
                $upd.tr = $upd.tbody.innerHTML;
                const url = $upd.available.getAttribute("data-url");
                const reset = () => {
                    $upd.wait.style.display = "none";
                    $upd.available.style.display = "none";
                    $upd.none.style.display = "none";
                    $upd.error.style.display = "none";
                    $upd.loading.style.display = "none";
                    $upd.tbody.innerHTML = "";
                };
                reset();
                $upd.wait.style.display = "block";
                const refresh = () => {
                    reset();
                    $upd.loading.style.display = "block";
                    fetch(url)
                    .then((response) => { return response.json(); })
                    .then((data) => {
                        $upd.loading.style.display = "none";
                        if (!data.success) {
                            $upd.error.getElementsByTagName("code")[0].innerHTML = data.error;
                            $upd.error.style.display = "block";
                            return;
                        }
                        if (data.updates.length === 0) {
                            $upd.none.style.display = "block";
                            return;
                        }
                        data.updates.forEach((p) => {
                            $upd.tbody.innerHTML += $upd.tr
                                .replace(/__type__/g, p.type)
                                .replace(/__type_capitalized__/g, p.type.charAt(0).toUpperCase() + p.type.slice(1))
                                .replace(/__name__/g, p.name)
                                .replace(/__title__/g, p.title)
                                .replace(/__current__/g, p.current)
                                .replace(/__available__/g, p.available)
                                .replace(/__url__/g, p.url);
                        });
                        $upd.nb.innerText = data.updates.length;
                        $upd.available.style.display = "block";
                    });
                };
            })();
            // --- Emails ------------------------------------------------------
            (function() {
                [... document.querySelectorAll("[data-set-field]")].forEach((bt) => {
                    bt.addEventListener("click", (e) => {
                        const form = bt.closest("form");
                        if (!form) return;
                        e.preventDefault();
                        const input = form.querySelector("[name='" + bt.getAttribute("data-set-field") + "']");
                        if (input) input.value = bt.getAttribute("data-set-field-value");
                        form.submit();
                    });
                });
            })();
            // -----------------------------------------------------------------
        </script>
    </body>
</html>
<?php
}

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

// =============================================================================
