<?php declare(strict_types=1);

// =============================================================================
// |                                                                           |
// |          __  ___   ____   ______   ____     ____     ____     ______      |
// |         /  |/  /  /  _/  / ____/  / __ \   / __ \   / __ )   / ____/      |
// |        / /|_/ /   / /   / /      / /_/ /  / / / /  / __  |  / __/         |
// |       / /  / /  _/ /   / /___   / _, _/  / /_/ /  / /_/ /  / /___         |
// |      /_/  /_/  /___/   \____/  /_/ |_|   \____/  /_____/  /_____/         |
// |                                                                           |
// | _________________________________________________________________________ |
// |                                                                           |
// |   This software is provided by the copyright holders and contributors     |
// |   "as is" and any express or implied warranties, including, but not       |
// |   limited to, the implied warranties of merchantability and fitness for   |
// |   a particular purpose are disclaimed. In no event shall the copyright    |
// |   owner or contributors be liable for any direct, indirect, incidental,   |
// |   special, exemplary, or consequential damages (including, but not        |
// |   limited to, procurement of substitute goods or services; loss of use,   |
// |   data, or profits; or business interruption) however caused and on any   |
// |   theory of liability, whether in contract, strict liability, or tort     |
// |   (including negligence or otherwise) arising in any way out of the use   |
// |   of this software, even if advised of the possibility of such damage.    |
// |                                                                           |
// |   This software consists of voluntary contributions made by               |
// |   many individuals and is licensed under the MIT license. For more        |
// |   information, see <https://microbe.barbichette.net>.                     |
// |                                                                           |
// =============================================================================

/**
 * Microbe's Processor Interface
 *
 * @package    Microbe
 * @subpackage Processor
 * @author     David Mougel <david@barbichette>
 */
interface Microbe_Processor_Interface
{

    /**
     * Should returns the name of the framework's file. Probably 'microbe.php'.
     * @return string Absolute path to the directory.
     */
    public function getFrameworkFileName(): string;

    /**
     * Should returns the absolute path to the directory containing
     * all Microbe's parts.
     * @return string Absolute path to the directory.
     */
    public function getPartsDir(): string;

    /**
     * Should returns the absolute path to the directory containing
     * all Microbe's third-party scripts (e..g PHPMailer, etc.).
     * @return string Absolute path to the directory.
     */
    public function getThirdPartyDir(): string;

    /**
     * Should returns the absolute path to the directory containing
     * all Microbe's samples files, which will be embedded inside output file.
     * @return string Absolute path to the directory.
     */
    public function getSamplesDir(): string;

    /**
     * Should returns the absolute path to the directory which will contains
     * the output framework's file.
     * @return string Absolute path to the directory.
     */
    public function getOutDir(): string;

    /**
     * Should returns the absolute path to the VERSION.json file, which tracks
     * the lastly generated version of the output framework's file.
     * @return string Absolute path to the file.
     */
    public function getOutVersionPath(): string;

    /**
     * Should returns the absolute path to the output framework's file.
     * @return string Absolute path to the file.
     */
    public function getOutFrameworkPath(): string;

    /**
     * Should returns the HTTP scheme ('http' or 'https') of the website
     * referenced in the license comment.
     * @return string HTTP scheme: 'http' or 'https'.
     */
    public function getHTTPScheme(): string;

    /**
     * Should returns the HTTP host (perhaps $_SERVER['HTTP_HOST'])
     * of the website referenced in the license comment.
     * @return string HTTP host.
     */
    public function getHTTPHost(): string;

}

/**
 * Microbe's Processor Abstract Class
 *
 * @package    Microbe
 * @subpackage Processor
 * @author     David Mougel <david@barbichette>
 */
abstract class Microbe_Processor implements Microbe_Processor_Interface
{

    /**
     * Computed SHA-256 hash of build files metadata.
     */
    private ?string $metaHash = null;

    /**
     * Computed SHA-256 hash of build files raw contents.
     */
    private ?string $hash = null;

    /**
     * Compute hash of build files metadata.
     * @param  bool   $force Force hash computing even if it was already done.
     * @return string        Build files metadata hash.
     */
    public function getMetaHash(bool $force = false): string
    {
        if (!$force && $this->metaHash !== null) return $this->metaHash;

        $hash = [];
        foreach ($this->getBuildFiles() as $f) {
            if (!is_file($f)) continue;
            $hash[] = implode(':', [
                $f,
                (new DateTime('@' . filemtime($f)))->format('YmdHis'),
                filesize($f),
            ]);
        }

        $this->metaHash = hash('sha256', implode('|', $hash));
        return $this->metaHash;
    }

    /**
     * Compute hash of build files contents.
     * @param  bool   $force Force hash computing even if it was already done.
     * @return string        Build files raw contents hash.
     */
    public function getHash(bool $force = false): string
    {
        if (!$force && $this->hash !== null) return $this->hash;

        $hash = [];
        foreach ($this->getBuildFiles() as $f) {
            if (!is_file($f)) continue;
            $hash[] = sha1(file_get_contents($f));
        }

        $this->hash = hash('sha256', implode('|', $hash));
        return $this->hash;
    }

    /**
     * Load and returns stored version of the last generation
     * of framework's file.
     * @return object Object representing the version information.
     */
    public function getCurrentVersion(): object
    {
        $default = (object) [
            'meta_hash' => null,
            'hash'      => null,
            'version'   => 0,
        ];

        if (!is_file($path = $this->getOutVersionPath())) return $default;
        if (!($raw = file_get_contents($path))) return $default;
        if (!($data = json_decode($raw)) || !is_object($data)) return $default;
        return $data;
    }

    /**
     * Update current version of generated framework's file.
     * @param  array | object $v New version information.
     * @return object            Object representing saved version information.
     */
    public function setCurrentVersion(array | object $v): object
    {
        file_put_contents($this->getOutVersionPath(), json_encode($v));
        return $this->getCurrentVersion();
    }

    /**
     * Returns the next version number, based on current version.
     * @return int Next version number.
     */
    public function getNextVersion(): int
    {
        return $this->getCurrentVersion()->version + 1;
    }

    /**
     * Returns a sorted array containing the files which will be bind
     * into the output framework's file.
     * @return array An array of absolute paths.
     */
    public function getBuildFiles(): array
    {
        $head = [];
        $head[] = $this->getPartsDir() . DIRECTORY_SEPARATOR . 'const.php';
        $head[] = $this->getPartsDir() . DIRECTORY_SEPARATOR . 'events.php';

        $tail = [];
        $tail[] = $this->getPartsDir() . DIRECTORY_SEPARATOR . 'cli.php';
        $tail[] = $this->getPartsDir() . DIRECTORY_SEPARATOR . 'bootstrap.php';

        $files = [];
        foreach ([ $this->getThirdPartyDir(), $this->getPartsDir() ] as $d) {
            $sub = [];
            foreach (glob($d . DIRECTORY_SEPARATOR . '*.php') as $f) {
                if (in_array($f, $head) || in_array($f, $tail)) continue;
                $sub[] = $f;
            }
            sort($sub);
            $files = array_merge($files, $sub);
        }

        array_reverse($head);
        foreach ($head as $n) array_unshift($files, $n);
        foreach ($tail as $n) $files[] = $n;

        return $files;
    }

    /**
     * Build framework's output file.
     * @param  bool   $force Force generation even if version seems identical.
     * @return string        Framework's file raw contents.
     */
    public function buildSrc(bool $force = true): string
    {
        $metaHash = $this->getMetaHash();
        $version = $this->getCurrentVersion();

        $changed = false;
        if ($version->meta_hash !== $metaHash) {
            if ($version->hash !== ($hash = $this->getHash())) {
                $changed = true;
                $version = $this->setCurrentVersion([
                    'meta_hash' => $metaHash,
                    'hash'      => $hash,
                    'version'   => $this->getNextVersion(),
                ]);
            }
        }

        $outFrameworkPath = $this->getOutFrameworkPath();

        if (!$changed && !$force) {
            if (is_file($outFrameworkPath) && ($code = file_get_contents($outFrameworkPath))) {
                return $code;
            }
        }

        $metaHashPadded = str_pad('-{# ' . substr($version->meta_hash, 0, 16) . ' #}-', 73, ' ', STR_PAD_BOTH);
        $hashPadded = str_pad('-{# ' . $version->hash . ' #}-', 73, ' ', STR_PAD_BOTH);
        $versionPadded = str_pad('-{# ' . $version->version . ' #}-', 73, ' ', STR_PAD_BOTH);
        $datePadded = str_pad('-{# ' . (new DateTime())->format('c') . ' #}-', 73, ' ', STR_PAD_BOTH);
        $licenseURL = $this->getHTTPScheme() . $this->getHTTPHost();
        $licenseURLPadding = str_pad('', 50 - strlen($licenseURL), ' ', STR_PAD_RIGHT);

        $bind = [ <<<PHP
        <?php declare(strict_types=1);

        // =============================================================================
        // |                                                                           |
        // |          __  ___   ____   ______   ____     ____     ____     ______      |
        // |         /  |/  /  /  _/  / ____/  / __ \   / __ \   / __ )   / ____/      |
        // |        / /|_/ /   / /   / /      / /_/ /  / / / /  / __  |  / __/         |
        // |       / /  / /  _/ /   / /___   / _, _/  / /_/ /  / /_/ /  / /___         |
        // |      /_/  /_/  /___/   \____/  /_/ |_|   \____/  /_____/  /_____/         |
        // |                                                                           |
        // | _________________________________________________________________________ |
        // |                                                                           |
        // |   This software is provided by the copyright holders and contributors     |
        // |   "as is" and any express or implied warranties, including, but not       |
        // |   limited to, the implied warranties of merchantability and fitness for   |
        // |   a particular purpose are disclaimed. In no event shall the copyright    |
        // |   owner or contributors be liable for any direct, indirect, incidental,   |
        // |   special, exemplary, or consequential damages (including, but not        |
        // |   limited to, procurement of substitute goods or services; loss of use,   |
        // |   data, or profits; or business interruption) however caused and on any   |
        // |   theory of liability, whether in contract, strict liability, or tort     |
        // |   (including negligence or otherwise) arising in any way out of the use   |
        // |   of this software, even if advised of the possibility of such damage.    |
        // |                                                                           |
        // |   This software consists of voluntary contributions made by               |
        // |   many individuals and is licensed under the MIT license. For more        |
        // |   information, see <{$licenseURL}>. {$licenseURLPadding} |
        // |                                                                           |
        // | ------------------------------------------------------------------------- |
        // |                                                                           |
        // | {$metaHashPadded} |
        // | {$hashPadded} |
        // | {$versionPadded} |
        // | {$datePadded} |
        // |                                                                           |
        // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
        //     +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
        //         +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
        //             +++++++++++++++++++++++++++++++++++++++++++++++++++++
        //                 +++++++++++++++++++++++++++++++++++++++++++++
        //                     +++++++++++++++++++++++++++++++++++++
        //                         +++++++++++++++++++++++++++++
        //                             +++++++++++++++++++++
        //                                 +++++++++++++
        //                                     +++++
        //                                       +
        PHP ];

        foreach ($this->getBuildFiles() as $f) {
            if (!is_file($f)) {
                die("[ERROR] Missing part file '{$f}'\n");
                continue;
            }

            $n = basename($f);

            $raw = file_get_contents($f);
            $raw = trim(preg_replace('/^<\?php/', '', $raw));

            $fileType = '';
            if (str_starts_with($f, $this->getThirdPartyDir())) $fileType = ' Third-Party';

            $prettyFileName = strtoupper(preg_replace('/\.php$/i', '', $n));

            $bind[] = '';
            $bind[] = '// ' . str_pad('', 77, '-');
            $bind[] = '// -- ' . str_pad('{# Beginning' . $fileType . ' ' . $prettyFileName . ' #}', 77 - 6, ' ', STR_PAD_BOTH) . ' --';
            $bind[] = '// ' . str_pad('', 77, '-');
            $bind[] = '';
            $bind[] = $raw;
            $bind[] = '';
            $bind[] = '// ' . str_pad('', 77, '-');
            $bind[] = '// -- ' . str_pad('{# Ending' . $fileType . ' ' . $prettyFileName . ' #}', 77 - 6, ' ', STR_PAD_BOTH) . ' --';
            $bind[] = '// ' . str_pad('', 77, '-');
            $bind[] = '';
        }

        $samplesDir = $this->getSamplesDir();
        $samples = (object) [ 'folders' => [], 'files' => [] ];
        $samplesDirLength = strlen($samplesDir);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($samplesDir));
        foreach ($iterator as $f) {
            $n = $f->getFileName();
            if ($n === '..' || $n === '.gitkeep') continue;
            if (!($relativePath = rtrim(substr($f->getPathname(), $samplesDirLength + 1), '\\/.'))) continue;
            if ($f->isDir()) $samples->folders[] = $relativePath;
            else $samples->files[$relativePath] = base64_encode(file_get_contents($f->getPathname()));
        }
        sort($samples->folders);
        ksort($samples->files);

        $bind[] = '// =============================================================================';
        $bind[] = '';
        $bind[] = 'function mb_core__get_samples_dirs(): array';
        $bind[] = '{';
        $bind[] = '    return [';
        foreach ($samples->folders as $f) $bind[] = "        '{$f}',";
        $bind[] = '    ];';
        $bind[] = '}';

        $bind[] = '';
        $bind[] = 'function mb_core__get_samples_files(): array';
        $bind[] = '{';
        $bind[] = '    return [';
        foreach ($samples->files as $fName => $fEncoded) $bind[] = "        '{$fName}' => '" . $fEncoded . "',";
        $bind[] = '    ];';
        $bind[] = '}';

        $bind[] = <<<PHP

        // =============================================================================
        PHP;

        $code = implode("\n", $bind) . "\n";

        if (!is_dir($dir = dirname($outFrameworkPath))) mkdir($dir, 0755, true);
        file_put_contents($outFrameworkPath, $code);
        return $code;
    }

    /**
     * Apply a specified namespace to some raw Microbe's source.
     * @param  string $src Framework's source code.
     * @param  string $ns  Namespace.
     * @return string      Altered framework's source code.
     */
    public function applyNamespace(string $src, string $ns): string
    {
        if ($ns === 'random') {
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ';
            $rand = '';
            for ($i = 0; $i < 3; $i++) {
                $char = null;
                while ($char === null || str_contains($rand, $char)) $char = $alphabet[mt_rand(0, strlen($alphabet) - 1)];
                $rand .= $char;
            }
            $ns = 'mb' . $rand;
        }

        $src = preg_replace('/^(<\?php([ \t]+declare\(strict_types=1\);)?[ \t]*\n)/ims', "\$1\nnamespace {$ns};\n", $src);
        return $src;
    }

    /**
     * Parse the framework's raw code and generate an object
     * representing a contextual documentation.
     * @param  string $src   Source code of the framework's file.
     * @return object        An object representing the contexts, which
     *                       contains variables and functions descriptions.
     */
    public function buildDoc(string $src): object
    {
        $src = preg_replace('/\n\/\/\s*-+\n\/\/\s*-+\s*\{#\sBeginning\sThird-Party\s.*\n\/\/\s*-+\s*\{#\sEnding\sThird-Party.*--\n\/\/\s*-+\n/imsU', '', $src);

        if (!preg_match_all('/^(\/\/[\s-]*\{#\s*(?<ctx>[^#]+)\s*#\}|\s*\/\*\*\s*\n(?<com>.*)\n\s*\*\/[\s\n]*(function\s+(?<fun>[^\(]+)\(|(?<var>\$[^\s=]+)\s*=))/imsU', $src, $matches, PREG_SET_ORDER)) return [];

        $ctx = null;
        $contexts = (object) [];

        foreach ($matches as $m) {
            $m = array_merge([ 'ctx' => null, 'com' => null, 'fun' => null, 'var' => null ], $m);
            if ($m['ctx']) {
                $ctx = preg_replace('/^.*\s([^\s]+)$/', '$1', strtolower(trim($m['ctx'])));
                continue;
            }

            if (!property_exists($contexts, $ctx)) {
                $contexts->$ctx = (object) [ 'variables' => [], 'functions' => [] ];
            }

            $com = $m['com'];
            $com = preg_replace('/^(\s*\*\s*)-/m', '$1===###', $com);
            $com = preg_replace('/^\s*\*\s*/m', '', $com);

            $parts = preg_split('/^@/m', preg_replace('/^@/m', '@@', $com));

            $com = array_shift($parts);
            $com = str_replace("\n", ' ', $com);
            $com = trim(preg_replace('/\s+/', ' ', $com));

            $user = false;
            if (preg_match('/^\<user\>\s*/i', $com, $um)) {
                $com = trim(str_replace($um[0], '', $com));
                $user = true;
            }

            $params = [];
            $return = $m['fun'] ? (object) [
                'types'        => [ 'void' ],
                'comment'      => '',
                'html_comment' => '',
            ] : null;

            $htmlComment = function(string $str): string
            {
                return '<p>' . str_replace('===###', '</p><p class="li">', htmlentities($str, ENT_COMPAT, 'utf-8')) . '</p>';
            };

            foreach ($parts as $p) {
                $p = str_replace("\n", ' ', $p);
                $p = trim(preg_replace('/\s+/', ' ', $p));

                if (preg_match('/^@(var|param)\s+(?<type>[^\s]+)(\s+\$(?<name>[^\s]+)\s+(?<com>.*))?$/i', $p, $pm)) {

                    $pm = array_merge([ 'type' => null, 'name' => null, 'com' => null ], $pm);

                    $types = array_filter(array_map(function(string $t): string
                    {
                        return strtolower(($t = trim($t))) === 'null' ? 'null' : $t;
                    }, explode('|', $pm['type'] ?: '')));

                    $params[] = (object) [
                        'name'         => $pm['name'],
                        'types'        => $types,
                        'comment'      => $c = trim($pm['com'] ?: ''),
                        'html_comment' => $htmlComment($c),
                        'optional'     => in_array('null', $types),
                    ];

                } else if (preg_match('/^@return\s+(?<type>[^\s]+)\s+(?<com>.*)$/i', $p, $vm)) {

                    $types = array_filter(array_map(function(string $t): string
                    {
                        return strtolower(($t = trim($t))) === 'null' ? 'null' : $t;
                    }, explode('|', $vm['type'])));

                    $return = (object) [
                        'types'        => $types,
                        'comment'      => $c = trim($vm['com']),
                        'html_comment' => $htmlComment($c),
                    ];

                }
            }

            $contexts->$ctx->{ $m['fun'] ? 'functions' : 'variables' }[] = (object) [
                'name'         => $m['fun'] ?: $m['var'],
                'comment'      => $com,
                'html_comment' => $htmlComment($com),
                'params'       => $params,
                'return'       => $return,
                'user'         => $user,
            ];
        }

        return $contexts;
    }

    /**
     * Extra method, called on-demand, to update the PHPMailer source file.
     * Its purpose is to scrap PHPMailer's source file and to process it to
     * rename classes and avoid conflicts with others manual/Composer's
     * installations of PHPMailer in projects.
     * @param  bool        $force Force generation, even if the file exists.
     * @param  bool        $throw Don't be silent: throw exception on error.
     * @return string|null        PHPMailer's altered source.
     */
    public function bindThirdPartyPHPMailer(bool $force = true, bool $throw = true): ?string
    {
        $path = $this->getThirdPartyDir() . DIRECTORY_SEPARATOR . 'phpmailer.php';
        if (!$force && is_file($path) && ($code = file_get_contents($path))) {
            return $code;
        }

        $prefix = 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/';
        $src = [ 'OAuthTokenProvider', 'OAuth', 'SMTP', 'PHPMailer' ];

        foreach ($src as $idx => $n) $src[$idx] = $prefix . $n . '.php';

        $bind = [];
        $head = null;

        foreach ($src as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            if (($code = curl_exec($ch)) === false) {
                if ($throw) { throw new Exception("Unable to download {$url}: " . curl_error($ch) . "."); }
                return null;
            }

            $code = str_replace("\r", "\n", str_replace("\r\n", "\n", $code));

            if ($head === null) {
                if (preg_match('/^(?<c>\/\*\*\s*\*\s*PHPMailer\s*.*\*\/)\s*$/imsU', $code, $m)) {
                    $head = preg_replace('/^[ \t]*\*[ \t]*\n/ms', '', $m['c']);
                    $lines = [];
                    $lines[] = "Microbe sincerely thanks PHPMailer authors / " . (new DateTime())->format('c');
                    $lines[] = "Automatically scraped, tinkered without any refinement, then glued the following URLs:";
                    foreach ($src as $url) $lines[] = "  - " . $url;

                    $w = 0;
                    foreach ($lines as $l) $w = max($w, strlen($l));
                    $div = str_pad('', $w + 4, '-');
                    foreach ($lines as $idx => $l) $lines[$idx] = '| ' . str_pad($l, $w, ' ', STR_PAD_RIGHT) . ' |';
                    array_unshift($lines, $div);
                    $lines[] = $div;

                    $rep = '$2' . implode("\n" . '$2', $lines) . "\n";

                    $head = preg_replace('/(\/\*\*[ \t]*\n)([ \t]*\*[ \t]*)/', '$1' . $rep . '$2', $head);
                }
            }

            $code = preg_replace('/^\s*<\?php[\s\n]/', '', $code);
            $code = preg_replace('/^\s*namespace [a-z0-9\\\_]+;$/im', '', $code);
            $code = preg_replace('/^\s*\/\/.*$/m', '', $code);
            $code = preg_replace('/\/\*.*?\*\//s', '', $code);
            $code = preg_replace('/\n\s*\n/', "\n", $code);
            $code = trim($code);

            $bind[] = $code;
        }

        $code = '<?php' . "\n\n"
            . ($head ? $head . "\n" : '')
            . implode("\n", $bind) . "\n";

        if (preg_match_all('/^(?<before>\s*(interface|class)\s+)(?<cl>[a-z0-9_]+)(?<after>[^\{]*\{)/ims', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $original = $m['cl'];
                $modified = 'Microbe_PHPMailer_' . $original;

                $code = str_replace($m[0], $m['before'] . $modified . $m['after'], $code);
                $code = preg_replace('/([^a-z0-9\$]new\s+)' . preg_quote($original, '/') . '(\s*\()/ims', '$1' . $modified . '$2', $code);
                $code = preg_replace('/(\(\s*|,\s*)' . preg_quote($original, '/') . '(\s)/ims', '$1' . $modified . '$2', $code);
            }
        }

        if (preg_match_all('/^interface\s+Microbe_PHPMailer_(?<cl>[^\s\{]+)\s+\{/ims', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $code = preg_replace('/(\s+implements\s+)' . preg_quote($m['cl'], '/') . '(\s+\{)/ims', '$1Microbe_PHPMailer_' . $m['cl'] . '$2', $code);
            }
        }

        if (preg_match_all('/^\s*use\s+(?<use>[a-z0-9\\\_]+);\n/ims', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $absolute = $m['use'];
                $relative = preg_replace('/^.*\\\([^\\\]+)$/', '$1', $absolute);
                $code = str_replace($m[0], '', $code);
                $code = preg_replace('/([^a-z0-9\$]new\s+)' . preg_quote($relative, '/') . '(\s*\()/ims', '$1\\' . ltrim($absolute, '\\') . '$2', $code);
            }
        }

        $code = preg_replace('/^(\s*)(' . preg_quote('return new \League\OAuth2\Client\Grant\RefreshToken();', '/') . '\s*)$/ims',
            '$1if (!class_exists(\'\League\OAuth2\Client\Grant\RefreshToken\')) throw new Exception("[PHPMailer] Missing class RefreshToken");' . "\n" . '$1$2', $code);

        $code = preg_replace('/^(\s*)(' . preg_quote('if ($this->Debugoutput instanceof \Psr\Log\LoggerInterface) {', '/') . '\s*)$/ims',
            '$1if (!class_exists(\'\Psr\Log\LoggerInterface\')) $this->Debugoutput = \'echo\';' . "\n" . '$1$2', $code);


        foreach ([
            'if (in_array($key, SMTP::$xclient_allowed_attributes)) {',
            'if (!in_array($name, SMTP::$xclient_allowed_attributes)) {',
        ] as $str) {
            $code = preg_replace('/^(\s*)(' . preg_quote($str, '/') . '\s*)$/ims',
                '$1if (!class_exists(\'SMTP\')) throw new Exception("[PHPMailer] Missing class SMTP");' . "\n" . '$1$2', $code);
        }

        $code = str_replace('\\PHPMailer\\PHPMailer\\Exception', 'Exception', $code);

        if (!is_dir($dir = dirname($path))) mkdir($dir, 0755, true);
        file_put_contents($path, $code);
        return $code;
    }

}

/**
 * Microbe's Processor Genuine Class
 *
 * @package    Microbe
 * @subpackage Processor
 * @author     David Mougel <david@barbichette>
 */
final class Microbe_Genuine_Processor extends Microbe_Processor
{

    public function getFrameworkFileName(): string { return 'microbe.php'; }

    public function getPartsDir(): string { return __DIR__ . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'parts'; }
    public function getThirdPartyDir (): string { return __DIR__ . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'third-party'; }
    public function getSamplesDir(): string { return __DIR__ . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'samples'; }

    public function getOutDir(): string { return __DIR__ . DIRECTORY_SEPARATOR . 'out'; }
    public function getOutVersionPath(): string { return $this->getOutDir() . DIRECTORY_SEPARATOR . 'VERSION.json'; }
    public function getOutFrameworkPath(): string { return $this->getOutDir() . DIRECTORY_SEPARATOR . $this->getFrameworkFileName(); }

    public function getHTTPScheme(): string { return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443 ? 'https://' : 'http://'; }
    public function getHTTPHost(): string { return strtolower($_SERVER['HTTP_HOST'] ?? ''); }

}
