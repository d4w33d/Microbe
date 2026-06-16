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
    foreach (mb_core__get_samples_dirs() as $d) {
        $ad = get_path($d);
        if (!is_dir($ad)) mkdir($ad, get_mkdir_chmod(), true);
    }
}

/**
 * Setup samples.
 */
function setup_samples(): void
{
    foreach (mb_core__get_samples_files() as $f => $encoded) {
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

/**
 * <USER>
 * Sniff the code files to generates a report about PHP extensions required
 * by the code, and the minimum version of PHP.
 * @param  string|null $root            Root directory.
 * @param  string      $excludedPattern Exclude some paths.
 */
function generate_dependencies_report(
    ?string $root            = null,
    string  $excludedPattern = '/(\/vendor\/|\/node_modules\/)/',
    ?string $composerBin     = 'composer',
): object
{
    if ($root === null) $root = get_root_dir();

    $report = (object) [
        'extensions'  => [],
        'php_version' => (object) [
            'framework' => null,
            'app'       => null,
            'composer'  => null,
            'global'    => null,
        ],
    ];

    $extensionsMap = [
        'mysqli'     => [ 'mysqli_', 'mysqli_connect' ],
        'pdo'        => [ 'PDO', 'pdo_', 'new PDO' ],
        'pgsql'      => [ 'pg_connect', 'pg_query', 'pg_' ],
        'oci8'       => [ 'oci_connect', 'oci_' ],
        'sqlite3'    => [ 'sqlite3', 'SQLite3' ],
        'sqlsrv'     => [ 'sqlsrv_' ],
        'ibm_db2'    => [ 'db2_' ],
        'dbase'      => [ 'dbase_' ],
        'odbc'       => [ 'odbc_' ],
        'informix'   => [ 'ifx_' ],
        'sybase'     => [ 'sybase_' ],
        'mbstring'   => [ 'mb_', 'mb_strlen', 'mb_convert' ],
        'iconv'      => [ 'iconv', 'iconv_', 'quoted_printable' ],
        'ctype'      => [ 'ctype_' ],
        'standard'   => [ 'str_getcsv', 'sprintf', 'html_entity', 'serialize', 'dns_get_record', 'getmxrr', 'password_hash', 'md5('  => 'standard', 'sha1('  => 'standard', 'proc_open', 'exec('  => 'standard', 'shell_exec' ],
        'pcre'       => [ 'preg_', 'preg_match', 'preg_replace' ],
        'simplexml'  => [ 'simplexml_', 'SimpleXML' ],
        'dom'        => [ 'DOMDocument', 'DOMElement', 'DOMXPath' ],
        'xmlreader'  => [ 'XMLReader' ],
        'xmlwriter'  => [ 'XMLWriter' ],
        'xml'        => [ 'xml_parser', 'xml_parse' ],
        'libxml'     => [ 'libxml_' ],
        'soap'       => [ 'SoapClient', 'SoapServer' ],
        'xsl'        => [ 'xslt_', 'XSLTProcessor' ],
        'json'       => [ 'json_encode', 'json_decode', 'json_last_error' ],
        'msgpack'    => [ 'msgpack_' ],
        'curl'       => [ 'curl_init', 'curl_exec', 'curl_', 'CurlHandle' ],
        'sockets'    => [ 'socket_create', 'socket_connect', 'socket_' ],
        'ftp'        => [ 'ftp_connect', 'ftp_' ],
        'imap'       => [ 'imap_open', 'imap_' ],
        'ldap'       => [ 'ldap_connect', 'ldap_' ],
        'snmp'       => [ 'snmp_', 'snmp2_' ],
        'openssl'    => [ 'openssl_', 'openssl_encrypt' ],
        'hash'       => [ 'hash('  => 'hash', 'hash_hmac', 'hash_' ],
        'gnupg'      => [ 'gnupg_' ],
        'gd'         => [ 'imagecreate', 'imagepng', 'imagejpeg', 'imagegif', 'gd_info', 'image' ],
        'imagick'    => [ 'Imagick', 'ImagickDraw' ],
        'exif'       => [ 'exif_read', 'exif_', 'read_exif_data' ],
        'cairo'      => [ 'svg_' ],
        'zip'        => [ 'ZipArchive', 'zip_open', 'zip_' ],
        'phar'       => [ 'PharData' ],
        'phar'       => [ 'Phar::' ],
        'bz2'        => [ 'bzip2_', 'bzopen', 'bzcompress' ],
        'zlib'       => [ 'gzopen', 'gzcompress', 'gzencode', 'gzdecode', 'zlib_', 'inflate_' ],
        'lzf'        => [ 'lzf_' ],
        'xdiff'      => [ 'xdiff_' ],
        'bcmath'     => [ 'bcadd', 'bcmul', 'bc' ],
        'gmp'        => [ 'gmp_', 'GMP' ],
        'date'       => [ 'DateTime', 'DateTimeImmutable', 'DateInterval', 'DateTimeZone', 'strtotime', 'mktime' ],
        'intl'       => [ 'Collator', 'NumberFormatter', 'MessageFormatter', 'IntlDateFormatter', 'Locale', 'UConverter', 'intl_', 'transliterator_' ],
        'gettext'    => [ 'gettext('  => 'gettext', 'bindtextdomain' ],
        'session'    => [ 'session_start', 'session_' ],
        'apcu'       => [ 'apcu_', 'apc_' ],
        'opcache'    => [ 'opcache_' ],
        'wincache'   => [ 'wincache_' ],
        'xcache'     => [ 'xcache_' ],
        'redis'      => [ 'Redis', 'RedisCluster' ],
        'memcache'   => [ 'Memcached', 'Memcache' ],
        'mongodb'    => [ 'mongodb_', 'MongoDB\\' ],
        'pcntl'      => [ 'pcntl_', 'pcntl_fork' ],
        'posix'      => [ 'posix_', 'posix_getpid' ],
        'pthreads'   => [ 'Thread', 'Worker' ],
        'swoole'     => [ 'Swoole\\', 'Co::' ],
        'openswoole' => [ 'OpenSwoole\\' ],
        'fpdf'       => [ 'FPDF' ],
        'dom'        => [ 'Dompdf\\' ],
        'pdf'        => [ 'PDFlib', 'pdf_' ],
        'xdebug'     => [ 'xdebug_', 'xdebug_break' ],
        'spl'        => [ 'SplStack', 'SplQueue', 'SplHeap', 'SplDoublyLinkedList', 'ArrayObject' ],
        'reflection' => [ 'ReflectionClass', 'ReflectionMethod', 'Reflection' ],
        'tokenizer'  => [ 'token_get_all', 'token_name' ],
        'filter'     => [ 'filter_var', 'filter_input', 'filter_' ],
        'fileinfo'   => [ 'finfo_', 'finfo_open', 'mime_content_type' ],
        'yaml'       => [ 'yaml_parse', 'yaml_emit', 'yaml_' ],
        'amqp'       => [ 'amqp_', 'AMQPConnection', 'AMQPChannel' ],
        'com_dotnet' => [ 'com_create_guid', 'com_load', 'COM('  => 'com_dotnet' ],
    ];

    $phpVersionPatterns = [
        '5.4' => [
            '/\barray\s*\[/',
            '/\btrait\s+\w+/',
            '/\binsteadof\b/',
            '/(?<![\/\w])array_column\s*\(/',
            '/(?<![\/\w])http_response_code\s*\(/',
            '/(?<![\/\w])callable\b/',
        ],
        '5.5' => [
            '/\bfinally\b/',
            '/\byield\b/',
            '/::class\b/',
            '/(?<![\/\w])boolval\s*\(/',
            '/(?<![\/\w])password_hash\s*\(/',
            '/(?<![\/\w])password_verify\s*\(/',
            '/(?<![\/\w])hash_pbkdf2\s*\(/',
            '/(?<![\/\w])json_last_error_msg\s*\(/',
        ],
        '5.6' => [
            '/\.\.\.\s*\$\w+/',
            '/\*\*/',
            '/\*\*=/',
            '/use\s+\w[\w\\\\]*\s*,\s*\w[\w\\\\]*\s*;/',
            '/(?<![\/\w])__debugInfo\s*\(/',
        ],
        '7.0' => [
            '/\?\?(?!=)/',
            '/<=>/',
            '/\bnew\s+class\b/',
            '/\bThrowable\b/',
            '/\bTypeError\b/',
            '/\bParseError\b/',
            '/(?<![\/\w])random_int\s*\(/',
            '/(?<![\/\w])random_bytes\s*\(/',
            '/(?<![\/\w])intdiv\s*\(/',
            '/(?<![\/\w])error_clear_last\s*\(/',
            '/:\s*(?:int|float|bool|string|array|callable|self)\b/',
            '/(?:int|float|bool|string)\s+\$\w+/',
            '/\bdefine\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[/',
        ],
        '7.1' => [
            '/\?\w+\s+\$\w+/',
            '/:\s*\?\w+/',
            '/:\s*void\b/',
            '/:\s*iterable\b/',
            '/\biterable\s+\$/',
            '/\[.*,.*\]\s*=/',
            '/(?:public|protected|private)\s+const\b/',
            '/(?<![\/\w])pcntl_async_signals\s*\(/',
            '/(?<![\/\w])curl_multi_errno\s*\(/',
        ],
        '7.2' => [
            '/:\s*object\b/',
            '/\bobject\s+\$/',
            '/(?<![\/\w])sodium_\w+\s*\(/',
            '/(?<![\/\w])spl_object_id\s*\(/',
            '/(?<![\/\w])mb_ord\s*\(/',
            '/(?<![\/\w])mb_chr\s*\(/',
            '/(?<![\/\w])mb_scrub\s*\(/',
        ],
        '7.3' => [
            '/(?<![\/\w])array_key_first\s*\(/',
            '/(?<![\/\w])array_key_last\s*\(/',
            '/(?<![\/\w])is_countable\s*\(/',
            '/(?<![\/\w])hrtime\s*\(/',
            '/\bJSON_THROW_ON_ERROR\b/',
            '/(?<![\/\w])net_get_interfaces\s*\(/',
        ],
        '7.4' => [
            '/\bfn\s*\(/',
            '/\?\?=/',
            '/\.\.\.\$\w+\s*(?=\])/',
            '/(?:public|protected|private)\s+(?:static\s+)?(?:\??\w+)\s+\$\w+\s*[;=]/',
            '/(?<![\/\w])WeakReference\b/',
            '/(?<![\/\w])mb_str_split\s*\(/',
            '/(?<![\/\w])fdiv\s*\(/',
            '/#\[/',
        ],
        '8.0' => [
            '/(?<![\/\w])str_contains\s*\(/',
            '/(?<![\/\w])str_starts_with\s*\(/',
            '/(?<![\/\w])str_ends_with\s*\(/',
            '/(?<![\/\w])get_debug_type\s*\(/',
            '/(?<![\/\w])preg_last_error_msg\s*\(/',
            '/(?<![\/\w])fdiv\s*\(/',
            '/\bmatch\s*\(/',
            '/\?\->/',
            '/\w+\s*\|\s*\w+(?:\s*\|\s*\w+)*\s+\$/',
            '/:\s*\w+(?:\|\w+)+/',
            '/\bmixed\b/',
            '/(?<![\/\w])WeakMap\b/',
            '/(?<![\/\w])ValueError\b/',
            '/(?<![\/\w])UnhandledMatchError\b/',
            '/(?:public|protected|private)\s+\w+\s+\$\w+,/',
            '/\bthrow\b(?!\s*;)(?=.*[?:])/',
        ],
        '8.1' => [
            '/\benum\s+\w+/',
            '/\breadonly\b/',
            '/:\s*never\b/',
            '/\bFiber\b/',
            '/\w+\s*&\s*\w+(?:\s*&\s*\w+)*\s+\$/',
            '/:\s*\w+(?:&\w+)+/',
            '/(?<![\/\w])array_is_list\s*\(/',
            '/(?<![\/\w])enum_exists\s*\(/',
            '/(?<![\/\w])fsync\s*\(/',
            '/(?<![\/\w])fdatasync\s*\(/',
            '/=\s*new\s+\w+[^;(]*;/',
            '/\bconst\s+\w+\s*=\s*new\s+/',
        ],
        '8.2' => [
            '/\breadonly\s+class\b/',
            '/\((?:\w+&\w+)\)\s*\|/',
            '/\b(?:true|false|null)\s+\$/',
            '/:\s*(?:true|false|null)\b/',
            '/(?<![\/\w])ini_parse_quantity\s*\(/',
            '/(?<![\/\w])curl_upkeep\s*\(/',
            '/(?<![\/\w])AllowDynamicProperties\b/',
            '/(?<![\/\w])SensitiveParameter\b/',
        ],
        '8.3' => [
            '/(?<![\/\w])json_validate\s*\(/',
            '/(?<![\/\w])mb_str_pad\s*\(/',
            '/(?<![\/\w])gc_status\s*\(/',
            '/(?<![\/\w])stream_context_set_options\s*\(/',
            '/(?<![\/\w])Override\b/',
            '/\bconst\s+\w+\s*:\s*\w+/',
            '/(?<![\/\w])Random\\\\Randomizer\b/',
            '/(?<![\/\w])getBytesFromString\s*\(/',
            '/(?<![\/\w])getFloat\s*\(/',
            '/(?<![\/\w])nextFloat\s*\(/',
        ],
        '8.4' => [
            '/(?<![\/\w])array_find\s*\(/',
            '/(?<![\/\w])array_find_key\s*\(/',
            '/(?<![\/\w])array_any\s*\(/',
            '/(?<![\/\w])array_all\s*\(/',
            '/(?<![\/\w])bcdivmod\s*\(/',
            '/(?<![\/\w])bcfloor\s*\(/',
            '/(?<![\/\w])bcceil\s*\(/',
            '/(?<![\/\w])bcround\s*\(/',
            '/(?<![\/\w])request_parse_body\s*\(/',
            '/(?<![\/\w])http_get_last_response_headers\s*\(/',
            '/(?<![\/\w])mb_trim\s*\(/',
            '/(?<![\/\w])mb_ltrim\s*\(/',
            '/(?<![\/\w])mb_rtrim\s*\(/',
            // '/(?<![\/\w])mb_ucfirst\s*\(/',
            '/(?<![\/\w])mb_lcfirst\s*\(/',
            // '/(?<![\/\w])Deprecated\b/',
            '/(?<![\/\w])NoDiscard\b/',
            '/(?<![\/\w])PDOSqlite\b/',
            '/(?<![\/\w])PDOMysql\b/',
            '/(?<![\/\w])PDOPgsql\b/',
            '/\bpublic\s*\(\s*set\s*\)/',
            '/\bprotected\s*\(\s*set\s*\)/',
        ],
    ];

    $foundExtensions = [];
    $frameworkFileName = get_microbe_file_name();
    $phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($phpFiles as $file) {
        if ($file->getExtension() !== 'php') continue;
        $path = $file->getPathname();

        if (str_contains($path, '/vendor/')) continue;
        if (str_contains($path, '/node_modules/')) continue;

        $isFrameworkFile = str_contains($path, $frameworkFileName);
        $src = file_get_contents($path);
        $src = preg_replace('/(\nfunction generate_dependencies_report\().+return \$report;\s+\}/msU', '$1){}', $src);

        foreach ($extensionsMap as $extensionName => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/[::\\\\\ ]/', $pattern)) $regex = '/' . preg_quote($pattern, '/') . '/i';
                elseif (str_ends_with($pattern, '_')) $regex = '/\b' . preg_quote($pattern, '/') . '/i';
                elseif (ctype_upper($pattern[0])) $regex = '/(?:new\s+|extends\s+|implements\s+|instanceof\s+|use\s+[\w\\\\]*|@\w+\s+)Thread\b/i';
                else $regex = '/\b' . preg_quote($pattern, '/') . '\b/i';

                if (!preg_match($regex, $src, $m)) continue;

                if (!isset($foundExtensions[$extensionName])) $foundExtensions[$extensionName] = [];
                $foundExtensions[$extensionName][$isFrameworkFile ? 'framework' : 'app'] = true;
                break;
            }
        }

        foreach ($phpVersionPatterns as $phpVersion => $patterns) {
            foreach ($patterns as $pattern) {
                if (!preg_match($pattern, $src)) continue;
                $prev = $isFrameworkFile ? $report->php_version->framework : $report->php_version->app;
                if ($prev === null || version_compare($phpVersion, $prev, '>')) {
                    if ($isFrameworkFile) $report->php_version->framework = $phpVersion;
                    else $report->php_version->app = $phpVersion;
                }
                break;
            }
        }
    }

    chdir(get_root_dir());

    if ($composerBin) {
        $response = exec_process($composerBin . ' show --platform');
        foreach (explode("\n", $response) as $line) {
            if (preg_match('/^ext-([a-zA-Z0-9_-]+) /', $line, $m)) $foundExtensions[$m[1]]['composer'] = true;
            else if (preg_match('/^php +([0-9.]+) /', $line, $m)) $report->php_version->composer = $m[1];
        }
    }

    $loadedExtensions = array_map('strtolower', get_loaded_extensions());
    $extensionsInUse = [];

    foreach ($foundExtensions as $extensionName => $requiredBy) {
        $report->extensions[] = (object) [
            'name'       => $extensionName,
            'is_missing' => !in_array($extensionName, $loadedExtensions),
            'required_by' => array_keys($requiredBy),
        ];
    }

    $report->php_version->global = null;
    foreach ($report->php_version as $k => $v) {
        if ($v === null) continue;
        if ($report->php_version->global === null) $report->php_version->global = $v;
        else if (version_compare($v, $report->php_version->global, '>')) $report->php_version->global = $v;
    }

    return $report;
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
