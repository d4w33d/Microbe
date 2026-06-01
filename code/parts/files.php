<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Returns 0755, aka the default new directory chmod.
 * @return int The chmod 0755.
 */
function get_mkdir_chmod(): int
{
    return 0755;
}

/**
 * <USER>
 * Returns 0644, aka the default new file chmod.
 * @return int The chmod 0644.
 */
function get_new_file_chmod(): int
{
    return 0644;
}

/**
 * <USER>
 * Check if the path or the file name extension is not one of known
 * non-ASCII extensions.
 * @param  string $path Path or file name.
 * @return bool         Does file name seems ASCII or name?
 */
function file_seems_ascii(string $path): bool
{
    if (is_dir($path)) return false;
    return !preg_match('/\.(jpg|jpeg|png|gif|bmp|tiff|tif|webp|ico|psd|heic|mp3|wav|flac|aac|ogg|wma|m4a|aiff|mp4|mkv|avi|mov|wmv|flv|webm|mpeg|mpg|zip|rar|7z|tar|gz|bz2|xz|lzma|exe|dll|so|dylib|bin|elf|app|pdf|doc|docx|xls|xlsx|ppt|pptx|odt|ods|odp|sqlite|db|mdb|accdb|parquet|avro|ttf|otf|woff|woff2|class|jar|pyc|wasm|iso|img)$/i', $path);
}

/**
 * <USER>
 * Returns file type based on extension.
 * @param  string      $path Path or file name.
 * @return string|null       Category name. If no type matches, null.
 */
function guess_file_type(string $path): ?string
{
    $types = [
        'image'    => '/\.(jpg|jpeg|png|gif|webp|bmp|tiff|tif|heic|heif|svg|ico|raw|cr2|nef|arw|dng)$/i',
        'video'    => '/\.(mp4|mkv|avi|mov|wmv|flv|webm|mpeg|mpg|m4v|3gp|ogv)$/i',
        'audio'    => '/\.(mp3|wav|aac|flac|ogg|m4a|wma|aiff|aif|alac|opus|mid|midi)$/i',
        'document' => '/\.(pdf|doc|docx|odt|rtf|txt|md|html|htm|xls|xlsx|ods|csv|ppt|pptx|odp|epub|tex)$/i',
        'archive'  => '/\.(zip|rar|7z|tar|gz|tgz|bz2|xz|lz|lzma|cab|iso|arj)$/i',
        'code'     => '/\.(c|h|cpp|hpp|cs|java|kt|swift|go|rs|php|py|rb|js|mjs|cjs|ts|jsx|tsx|scala|lua|pl|sh|bash|ps1|sql|r|dart|asm|vb|vbs|groovy|styl|scss|less|gitignore|gitkeep|htaccess|htpasswd|phtml|xml|json)$/i',
    ];
    foreach ($types as $n => $re) if (preg_match($re, $path)) return $n;
    return null;
}

/**
 * <USER>
 * Create recursively a directory.
 * @param  string $path Path of directory to create
 */
function rmkdir(string $path): void
{
    if (!is_dir($path)) mkdir($path, get_mkdir_chmod(), true);
}

/**
 * <USER>
 * Sanitize a filename, removing special characters and accents,
 * and shortifying the name if too long.
 * @param  string $name      Original file name.
 * @param  string $separator Separator used in replacement of
 *                           non-alphanumerical characters.
 * @param  int    $maxLength Maximum length of the part before the extension.
 * @return string            Sanitized file name.
 */
function sanitize_filename(string $name, string $separator = '_', int $maxLength = 32): string
{
    return sanitize_string(str: $name, separator: $separator, keepExtension: true, maxLength: $maxLength);
}

/**
 * <USER>
 * Check if a filename seems secure, without slashes, double dots,
 * control characters, banned Windows characters and NULL character.
 * @param  mixed $name Filename (should probably be a string).
 * @return bool        Does filename seems secure or not?
 */
function filename_seems_secure(mixed $name): bool
{
    if (!is_string($name) || !$name) return false;
    return !preg_match('/(\.\.|[\/\\\\]|[\x00-\x1F\x7F<>:"|?*])/', $name);
}

/**
 * <USER>
 * Returns a hashed string, suffixed optionnaly with an extension, in order
 * to use as a filename. We replace the 'c' followed by a 'd', by a 'f'
 * to avoid sometimes problematic 'cd'.
 * @param  string      $str String to hash.
 * @return string|null $ext Extension.
 */
function hashed_filename(string $str, ?string $ext = null): string
{
    $str = hash('sha256', $str);
    return str_replace('cd', 'fd', $str) . ($ext ? '.' . $ext : '');
}

/**
 * <USER>
 * Convert a size into a human-readable value. The input size and the output
 * size can be one of those units:
 *   - B: bytes
 *   - KB: kilobytes
 *   - MB: megabytes
 *   - GB: gigabytes
 *   - TB: terabytes
 *   - PB: petabytes
 * @param  float       $size     Size.
 * @param  string      $from     Input unit.
 * @param  string|null $to       Output unit. If null, the detection will be
 *                               made to find the most readable unit.
 * @param  bool        $asConfig Remove spaces and 'B', to make a string usable
 *                               for PHP or server configuration.
 * @return string                Number suffixed with the proper unit.
 */
function bytes_unit(int | float $size, string $from = 'B', string $to = null, bool $asConfig = false): string
{
         if ($from === 'KB') $size *= 1000;
    else if ($from === 'MB') $size *= 1000 * 1000;
    else if ($from === 'GB') $size *= 1000 * 1000 * 1000;
    else if ($from === 'TB') $size *= 1000 * 1000 * 1000 * 1000;
    else if ($from === 'PB') $size *= 1000 * 1000 * 1000 * 1000 * 1000;

    if ($to === null) {
        if ($size >= 1000 * 1000 * 1000 * 1000 * 1000) $to = 'PB';
        else if ($size >= 1000 * 1000 * 1000 * 1000)   $to = 'TB';
        else if ($size >= 1000 * 1000 * 1000)          $to = 'GB';
        else if ($size >= 1000 * 1000)                 $to = 'MB';
        else if ($size >= 1000)                        $to = 'KB';
        else                                           $to = 'B';
    }

         if ($to === 'PB') $size /= 1000 * 1000 * 1000 * 1000 * 1000;
    else if ($to === 'TB') $size /= 1000 * 1000 * 1000 * 1000;
    else if ($to === 'GB') $size /= 1000 * 1000 * 1000;
    else if ($to === 'MB') $size /= 1000 * 1000;
    else if ($to === 'KB') $size /= 1000;

    if ($asConfig) return round($size) . str_replace('B', '', $to);
    return format($size, 'number') . ' ' . $to;
}

/**
 * <USER>
 * Returns the bytes value of an input size, which can be suffixed with one of
 * the units returned by <bytes_unit>. E.g. '345.67MB' will return 345670000.
 * @param  string|int|float  $size Size suffixed or not with a unit.
 * @return int                     Size in bytes.
 */
function size_string_to_bytes(string | int | float $size): int
{
    $regex = '/^([0-9]+(\.[0-9]+)?)([KMGT])?B?$/i';
    if (preg_match($regex, (string) $size, $matches)) {
        $size = (float) $matches[1];
        if (count($matches) >= 4) {
            $unit = strtoupper($matches[3]);
                 if ($unit === 'K') $size *= 1000;
            else if ($unit === 'M') $size *= pow(1000, 2);
            else if ($unit === 'G') $size *= pow(1000, 3);
            else if ($unit === 'T') $size *= pow(1000, 4);
        }
    }
    return (int) $size;
}

/**
 * <USER>
 * Get hash for the given file.
 * @param  string  $path  File path.
 * @param  bool    $force Force hash computing even if already stored.
 * @param  string  $algo  Hashing algorithm.
 * @param  bool    $store Store computed hash.
 * @return string         Hash.
 */
function get_file_hash(string $path, string $algo = 'sha1', bool $force = false, bool $store = true): string
{
    $algo = strtolower($algo);
    $hashName = hash('sha256', get_relative_path($path) . ':' . filemtime($path) . ':' . filesize($path)) . '-' . $algo;
    $hashPath = get_data_dir('hashes', substr($hashName, 0, 2), substr($hashName, 2, 2), $hashName);
    if (!$force && is_file($hashPath) && ($hash = trim(file_get_contents($hashPath)))) return $hash;
    $hash = hash_file($algo, $path);
    if ($store) {
        rmkdir(dirname($hashPath));
        file_put_contents($hashPath, $hash);
    }
    return $hash;
}

/**
 * <USER>
 * Returns the file's extension.
 * @param  string $f     File name or path.
 * @param  bool   $dot   Prepend the dot before the extension. Default false.
 * @param  bool   $lower Lowercase the extension. Default false.
 * @return string        File extension, or an empty string if none found.
 */
function get_file_extension(string $f, bool $dot = false, bool $lower = false): string
{
    $ext = preg_match('/\.([^.\/]+)$/', $f, $m) ? $m[1] : null;
    if (!$ext) return '';
    if ($dot) $ext = rtrim('.' . $ext, '.');
    if ($lower) $ext = strtolower($ext);
    return $ext;
}

/**
 * <USER>
 * Returns true if the file extension corresponds to the given one(s).
 * @param  string        $f   File name or path.
 * @param  string|array  $ext Extension or array of extensions.
 * @return boolean            Is $f's extension equals or in $ext?
 */
function is_file_extension(string $f, string | array $ext): bool
{
    return in_array(get_file_extension($f, lower: true), array_map(function(string $e): string
    {
        return strtolower(trim($e, '.'));
    }, is_array($ext) ? $ext : [ $ext ]));
}

/**
 * <USER>
 * Removes a file's extension.
 * @param  string $f File name or path.
 * @return string    File name or path, stripped from extension.
 */
function remove_extension(string $f): string
{
    return preg_replace('/\.[^.]+$/', '', $f);
}

/**
 * <USER>
 * Returns an array containing the owner of a path (file or directory).
 * @param  string $path Path to the file or directory.
 * @return array        An array with 4 entries:
 *                        - User name;
 *                        - Group name;
 *                        - User ID;
 *                        - Group ID.
 */
function get_path_owner(string $path): array
{
    $owner = [ null, null, null, null ];
    if (($userId = fileowner($path)) !== false) {
        $owner[2] = $userId;
        $owner[0] = get_system_user_name_by_id($userId);
    }
    if (($groupId = filegroup($path)) !== false) {
        $owner[3] = $groupId;
        $owner[1] = get_system_user_name_by_id($groupId);
    }
    return $owner;
}

/**
 * <USER>
 * Returns the file/directory permissions as octal string (0644, 0777, etc.).
 * @param  string      $path Path to the file or directory.
 * @return string|null       Octal string.
 */
function get_path_perms_octal_string(string $path): ?string
{
    if (($p = fileperms($path)) === false) return null;
    return substr(sprintf('%o', $p), -4);
}

/**
 * <USER>
 * Returns the file/directory permissions as a human readable string.
 * @param  string      $path Path to the file or directory.
 * @return string|null       Readable rights.
 */
function get_path_perms_readable_string(string $path): ?string
{
    if (($perms = fileperms($path)) === false) return null;

    $type = match ($perms & 0xF000) {
        0xC000  => 's', // Socket
        0xA000  => 'l', // Symbolic Link
        0x8000  => '-', // Regular File
        0x6000  => 'b', // Block Device
        0x4000  => 'd', // Directory
        0x2000  => 'c', // Character Device
        0x1000  => 'p', // FIFO pipe
        default => 'u', // Unknown
    };

    return $type
        // Owner
        . (($perms & 0x0100) ? 'r' : '-')
        . (($perms & 0x0080) ? 'w' : '-')
        . (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-'))
        // Group
        . (($perms & 0x0020) ? 'r' : '-')
        . (($perms & 0x0010) ? 'w' : '-')
        . (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-'))
        // Others
        . (($perms & 0x0004) ? 'r' : '-')
        . (($perms & 0x0002) ? 'w' : '-')
        . (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-'));
}

/**
 * <USER>
 * Check if a given path is a valid path to a file or a folder, and if this
 * file is properly located inside a root directory.
 * @param  string  $path        Path to be checked.
 * @param  string  $root        Base directory.
 * @param  boolean $rootAllowed Does $path can be $root itself?
 * @return boolean              Is $path a valid subpath of $root?
 */
function is_valid_subpath(string $path, string $root, bool $rootAllowed = true): bool
{
    if (!is_dir($root) || !($root = realpath($root))) throw new Microbe_Exception("The given root is not a valid directory");
    if (!file_exists($path) || !($path = realpath($path))) return false;
    $path = trim($path, '/\\');
    $root = trim($root, '/\\');
    if ($root === $path) return $rootAllowed;
    return strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
}

/**
 * <USER>
 * Returns the file name as an array containing the name and the extension.
 * @param  string $f            File name.
 * @param  bool   $includingDot Include the extension's dot or not.
 * @return array                Array containing the file name, and optionaly
 *                              the extension.
 */
function get_file_name_parts(string $f, bool $includingDot = true): array
{
    $parts = explode('.', $f);
    $ext = count($parts) >= 2 ? ($includingDot ? '.' : '') . array_pop($parts) : null;
    return array_filter([ implode('.', $parts), $ext ]);
}

/**
 * <USER>
 * Get the maximum upload size, based on the PHP configuration
 * ('post_max_size' and 'upload_max_filesize'), and an optional
 * configuration context defined with the configuration
 * key '@upload.{ctx}.max_size'.
 * @param  string|null $ctx Context.
 * @return int              Maximum upload size in bytes.
 */
function get_max_upload_size(string $ctx = null): int
{
    $size = min(
        size_string_to_bytes(ini_get('post_max_size')),
        size_string_to_bytes(ini_get('upload_max_filesize')));

    if ($ctx !== null) {
        $size = min($size, size_string_to_bytes(cfg('@upload.' . $ctx . '.max_size')));
    }

    return $size;
}

/**
 * <USER>
 * Get the maximum upload size requested by configuration.
 * @return int|null Upload size in bytes.
 */
function get_max_config_upload_size(): ?int
{
    $max = null;
    foreach (cfg('@upload') ?: [] as $ctx => $ctxCfg) {
        if (!($size = ($ctxCfg['max_size'] ?? null))) continue;
        if (!($size = size_string_to_bytes($size))) continue;
        $max = max($max ?: 0, $size);
    }
    return $max;
}

/**
 * <USER>
 * Get uploaded files metadata, from $_FILES.
 * @param  string            $name Name of the posted file field.
 * @param  bool              $one  If false (default), it will return the
 *                                 uploaded file metadata as an array of
 *                                 objects representing each uploaded file with
 *                                 the given post name.
 * @return object|array|null       Return an object representing the $_FILES
 *                                 entry if there is only one file. If multiple
 *                                 upload for a POST var like 'files[]',
 *                                 returns an array containing those objects.
 *                                 If the file was not posted, null will
 *                                 be returned.
 */
function get_uploaded_files(string $name, bool $one = false): object | array | null
{
    if (!array_key_exists($name, $_FILES)) return null;
    if (!is_array($_FILES[$name]) || !array_key_exists('tmp_name', $_FILES[$name])) return null;

    $files = [];

    if (!is_array($_FILES[$name]['tmp_name'])) {
        $files[] = $_FILES[$name];
    } else {
        foreach (array_keys($_FILES[$name]['tmp_name']) as $idx) {
            $files[$idx] = [];
            foreach (array_keys($_FILES[$name]) as $prop) $files[$idx][$prop] = $_FILES[$name][$prop][$idx];
        }
    }

    $files = array_map(function(array $f): object
    {
        $f = (object) array_merge([
            'name'      => null,
            'type'      => null,
            'size'      => null,
            'tmp_name'  => null,
            'error'     => null,
            'full_path' => null,
        ], $f);
        $f->extension = get_file_extension($f->name);
        return $f;
    }, $files);

    if (!$one) return $files;
    foreach ($files as $f) return $f;
    return null;
}

/**
 * <USER>
 * Get uploaded file metadata, from $_FILES, using <get_uploaded_files> with
 * $one as true.
 * @param  string      $name Name of the posted file field.
 * @return object|null       Return an object representing the $_FILES
 *                           entry. If the file was not posted, null will
 *                           be returned.
 */
function get_uploaded_file(string $name): ?object
{
    return get_uploaded_files($name, true);
}

/**
 * <USER>
 * Move the uploaded file from its temporary path to the given one, and create
 * the subdirectories if asked and needed.
 * @param  object $file  File object got from <get_uploaded_file> or
 *                       <get_uploaded_files>.
 * @param  string $path  Path where the file will be saved (new file's path).
 * @param  bool   $mkdir Make the (sub)directories if doesn't exists.
 * @return bool          Is it a success or not?
 */
function save_uploaded_file(object $file, string $path, bool $mkdir = true): bool
{
    $dir = dirname($path);
    if ($mkdir && !is_dir($dir)) mkdir($dir, get_mkdir_chmod(), true);
    if (!is_writable($dir)) return false;
    if (!move_uploaded_file($file->tmp_name, $path)) return false;
    return true;
}

/**
 * <USER>
 * Perform the file upload, based on the file object got from
 * <get_uploaded_files>. If an error occur, an 'ErrorException' will be
 * thrown. The options are the following:
 *   - 'path':                   The path of the new file.
 *   - 'keep_filename':          If true, the 'path' will be considerated as a
 *                               directory and the file will be stored inside
 *                               this directory with its sanitized uploaded
 *                               file name. If false, the 'path' will be kept
 *                               as the new file path. Default: true.
 *   - 'keep_extension':         If true, the extension of the uploaded will
 *                               be kept, and the extension given in 'path'
 *                               will be ignored. Default: true.
 *   - 'letters_folders_levels': Append X levels of letter folders
 *                               (e.g. when 2: '/path/to/images/a/b/abc.jpg').
 *                               Missing folders will be created.
 *                               Default: 0.
 *   - 'iterate_filename':       Iterate to the file name if conflict.
 *                               Default: true.
 *   - 'iterate_format':         Iteration suffix format. Default: '_%s'.
 *   - 'default_folders_chmod':  Default: the result of <get_mkdir_chmod>.
 *   - 'default_files_chmod':    Default: the result of <get_new_file_chmod>.
 * @param  object $file Upload file object.
 * @param  array  $opts Upload options.
 * @return string       Uploaded file path (absolute).
 */
function upload_file(object $file, array $opts): string
{
    $opts = (object) array_merge([

        'path'                   => null,
        'keep_filename'          => true,
        'keep_extension'         => true,

        'letters_folders_levels' => 0,

        'iterate_filename'       => true,
        'iterate_format'         => '_%s',

        'default_folders_chmod'  => get_mkdir_chmod(),
        'default_files_chmod'    => get_new_file_chmod(),

    ], $opts);

    $path = rtrim($opts->path, '/');

    if ($opts->keep_filename) {
        $path = join_path($path, sanitize_filename($file->name));
        if (!$opts->keep_extension) {
            $path = preg_replace('/^(.*)\.[^.\/]+$/', '$1', $path);
        }
    } else if ($opts->keep_extension) {
        if (preg_match('/\.([^.\/]+)$/', $file->name, $m)) {
            $path .= '.' . strtolower($m[1]);
        }
    }

    if ($opts->letters_folders_levels > 0) {
        $d = dirname($path);
        $f = basename($path);
        for ($i = 0; $i < $opts->letters_folders_levels; $i++) {
            $d = join_path($d, $f[$i]);
        }
        $path = join_path($d, $f);
    }

    if ($opts->iterate_filename && is_file($path)) {
        $part = preg_replace('/^(.*)\.[^.\/]+$/', '$1', $path);
        $ext = preg_match('/\.([^.\/]+)$/', $path, $m) ? '.' . $m[1] : '';
        $newPathModel = $part . $opts->iterate_format . $ext;
        for ($i = 1; is_file($newPath = sprintf($newPathModel, $i)); $i++);
        $path = $newPath;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, $opts->default_folders_chmod, true);
    }

    if (!is_writable($dir)) {
        throw new Microbe_Exception("Path {$dir} is not writable");
    }

    if (!move_uploaded_file($file->tmp_name, $path)) {
        throw new Microbe_Exception('Error while moving uploaded file');
    }

    chmod($path, $opts->default_files_chmod);

    return $path;
}

/**
 * <USER>
 * Check if $path is already a file. If yes, an increment will be added to
 * the end of the name, before the extension.
 * @param  string $path      File path.
 * @param  string $separator Separator between the filename and the iteration.
 * @return string            Unique file path.
 */
function get_unique_file_path(string $path, string $separator = '_'): string
{
    if (!file_exists($path)) return $path;
    $part = preg_replace('/^(.*)\.[^.\/]+$/', '$1', $path);
    $ext = preg_match('/\.([^.\/]+)$/', $path, $m) ? '.' . $m[1] : '';
    $newPathModel = $part . $separator . '%s' . $ext;
    for ($i = 1; file_exists($newPath = sprintf($newPathModel, $i)); $i++);
    return $newPath;
}

/**
 * <USER>
 * Check if the directory $dir is empty or not.
 * @param  string  $dir Directory path.
 * @return boolean      Empty (true) or not?
 */
function is_dir_empty(string $dir): bool
{
    $handle = opendir($dir);
    while (false !== ($entry = readdir($handle))) {
        if ($entry !== '.' && $entry !== '..') {
            closedir($handle);
            return false;
        }
    }
    closedir($handle);
    return true;
}

/**
 * <USER>
 * Compute directory size.
 * @param  string     $path     Directory path.
 * @param  bool       $readable Returns readable size or not.
 * @return int|string           Size (as bytes integer or readable string).
 */
function get_folder_size(string $path, bool $readable = false): int | string
{
    $total = 0;
    $path = realpath($path);
    if ($path && is_dir($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $f) {
            $total += $f->getSize();
        }
    }
    return $readable ? bytes_unit($total) : $total;
}

/**
 * <USER>
 * Remove a directory and its files recursively.
 * @param  string       $dir        Path to directory.
 * @param  bool         $deleteRoot Delete also the directory $dir itself.
 * @param  Closure|null $filter     Filter function, which will accept the path
 *                                  of the item processed. If this function
 *                                  returns true, the file or the directory
 *                                  will be deleted. If false, the item will
 *                                  be kept.
 * @param  string|null  $rootDir    Path to the original directory (the
 *                                  initial value of $dir).
 */
function rrmdir(string $dir, bool $deleteRoot = true, Closure $filter = null, string $rootDir = null): void
{
    if ($rootDir === null) $rootDir = $dir;
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (is_dir($path) && !is_link($path)) {
            rrmdir($path, $deleteRoot, $filter, $rootDir);
            continue;
        }
        if (!$filter || $filter($path)) unlink($path);
    }
    $shouldDelete = $filter ? $filter($dir) : true;
    if ($shouldDelete && ($deleteRoot || ($dir !== $rootDir))) rmdir($dir);
}

/**
 * Make a file name shorter, by removing middle characters.
 * @param  string   $name      File name.
 * @param  int|null $maxLength Maximum length. If null, the file name will
 *                             not be truncated.
 * @return string              Truncated file name.
 */
function shortify_file_name(string $name, ?int $maxLength = null, string $replacement = '...'): string
{
    if ($maxLength === null) return $name;
    if (($len = strlen($name)) <= $maxLength) return $name;
    $remaining = $maxLength - strlen($replacement);
    $parts = get_file_name_parts($name);
    if (count($parts) === 1) $parts[] = '';
    if ($maxLength < (strlen($parts[1]) + strlen($replacement))) return $name;

    $remaining -= strlen($parts[1]);
    if ($remaining <= 0) {
        $name = $replacement . $parts[1];
    } else if (!$parts[1]) {
        $name = substr($parts[0], 0, $remaining) . $replacement;
    } else {
        $half1 = $half2 = $remaining / 2;
        if (!is_int($half1)) {
            $half1 = (int) ceil($half1);
            $half2 = (int) floor($half2);
        }
        $name = substr($parts[0], 0, $half1) . $replacement . substr($parts[0], -1 * $half2) . $parts[1];
    }

    return str_replace('...', html_entity_decode('&hellip;', ENT_COMPAT, 'utf-8'), $name);
}

/**
 * <USER>
 * Send a file to the browser, with proper headers to force the download.
 * @param  string $path Path to the file
 */
function force_download(string $path, string $name = null): void
{
    header('Content-Type: ' . mime_content_type(basename($path)));
    header('Content-Disposition: attachment; filename="' . ($name ?: basename($path)) . '"');
    header('Content-Length:' . filesize($path));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    readfile($path);
}

/**
 * <USER>
 * List files and folders inside a directory.
 * @param  string       $path    Path to directory.
 * @param  bool         $files   Retrieve files.
 * @param  bool         $folders Retrieve folders.
 * @param  string|null  $filter  Filter regex.
 * @return array                 List of files and folders.
 */
function ls(string $path, bool $files = true, bool $folders = true, ?string $filter = null): array
{
    $all = [];
    foreach (new DirectoryIterator($path) as $f) {
        if ($f->isDot()) continue;
        if ($f->isDir() && !$folders) continue;
        if ($f->isFile() && !$files) continue;
        $filePath = $f->getPathname();
        if ($filter !== null && !preg_match($filter, $filePath)) continue;
        $all[] = new Microbe_File($filePath);
    }
    usort($all, function(Microbe_File $a, Microbe_File $b): int
    {
        if (($aa = strtolower($a->getName())) < ($bb = strtolower($a->getName()))) return -1;
        if ($aa > $bb) return 1;
        return 0;
    });
    return $all;
}

/**
 * <USER>
 * List files inside a directory.
 * @param  string       $path    Path to directory.
 * @param  string|null  $filter  Filter regex.
 * @return array                 List of files and folders.
 */
function get_files(string $path, ?string $filter = null): array
{
    return ls($path, files: true, folders: false, filter: $filter);
}

/**
 * <USER>
 * List folders inside a directory.
 * @param  string       $path    Path to directory.
 * @param  string|null  $filter  Filter regex.
 * @return array                 List of files and folders.
 */
function get_folders(string $path, ?string $filter = null): array
{
    return ls($path, files: false, folders: true, filter: $filter);
}

/**
 * <USER>
 * Read $lines lines from the end of the file.
 * @param  string $path  Path of file to read.
 * @param  int    $lines Number of lines to get.
 * @return array         Array of lines.
 */
function tail_file(string $path, int $lines = 10): array
{
    if (!($f = fopen($path, 'rb'))) return [];
    fseek($f, 0, SEEK_END);
    $pos = ftell($f);
    $buffer = '';
    $lineCount = 0;
    while ($pos > 0 && $lineCount < $lines) {
        $pos--;
        fseek($f, $pos);
        $char = fgetc($f);
        if ($char === "\n") {
            $lineCount++;
            if ($lineCount >= $lines) break;
        }
        $buffer = $char . $buffer;
    }
    fclose($f);
    return array_reverse(explode("\n", rtrim($buffer, "\n")));
}

/**
 * <USER>
 * Read $lines lines from the beginning of the file.
 * @param  string $path  Path of file to read.
 * @param  int    $lines Number of lines to get.
 * @return array         Array of lines.
 */
function head_file(string $path, int $lines = 10): array
{
    if (!($f = fopen($path, 'rb'))) return [];
    $result = [];
    while (!feof($f) && count($result) < $lines) $result[] = rtrim(fgets($f), "\r\n");
    fclose($f);
    return $result;
}

/**
 * <USER>
 * Returns the latest modified file time in a given directory.
 * @param  string          $dir         Directory path.
 * @param  string | null   $filterClass The name of a RecursiveFilterIterator
 *                                      class.
 * @return DateTime | null              The DateTime instance of the last
 *                                      modified time.
 */
function get_latest_modified_file_time(string $dir, ?string $filterClass = null): ?DateTime
{
    $t = 0;
    $directoryIterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $filteredIterator = $directoryIterator;
    if ($filterClass) $filteredIterator = new $filterClass($directoryIterator);
    $iterator = new RecursiveIteratorIterator($filteredIterator);
    foreach ($iterator as $f) {
        $mt = $f->getMTime();
        if ($mt > $t) $t = $mt;
    }
    return $t === 0 ? null : new DateTime('@' . $t);
}

/**
 * <USER>
 * Returns a new instance of Microbe_File.
 * @param  string        $path Path to file.
 * @return Microbe_File        The Microbe_File instance.
 */
function get_file(string $path): ?Microbe_File
{
    if (!file_exists($path)) return null;
    return new Microbe_File($path);
}

class Microbe_File
{

    private string $path;

    final public function __construct(string $path) { $this->path = $path; }

    public function getName(?int $maxLength = null, bool $removeExtension = false): string
    {
        $name = shortify_file_name(basename($this->getPath()), $maxLength);
        if ($removeExtension) $name = remove_extension($name);
        return $name;
    }

    public function getPath(): string { return $this->path; }
    public function getExtension(bool $lower = false): string { return get_file_extension($this->getName(), lower: $lower); }
    public function hasExtension(string $ext): bool { return $this->getExtension(lower: true) === strtolower($ext); }
    public function getParent(): static { return new static(dirname($this->getPath())); }
    public function getParentPath(): string { return $this->getParent()->getPath(); }
    public function isDir(): bool { return is_dir($this->getPath()); }
    public function isFile(): bool { return is_file($this->getPath()); }
    public function getUrl(): string { return path_to_url($this->getPath()); }

    public function getModifiedAt(?string $format = null): DateTime | string | null
    {
        if (!$this->isFile()) return null;
        $dt = new DateTime('@' . filemtime($this->getPath()));
        return $format ? $dt->format($format) : $dt;
    }

    public function getRelativePath(?string $absRoot = null): ?string
    {
        if ($absRoot === null) $absRoot = get_root_dir();
        $absRoot = realpath($absRoot);
        $absPath = realpath($this->getPath());
        if (!str_starts_with($absPath, $absRoot . DIRECTORY_SEPARATOR)) return '';
        return substr($absPath, strlen($absRoot) + 1);
    }

    public function getSize(bool $readable = false): int | string | null
    {
        if ($this->isDir()) return get_folder_size($this->getPath(), readable: $readable);
        $size = filesize($this->getPath());
        return $readable ? bytes_unit($size) : $size;
    }

    public function delete(bool $recursive = false): void
    {
        if ($this->isFile()) {
            unlink($this->getPath());
        } else if ($this->isDir()) {
            if ($recursive) rrmdir($this->getPath(), deleteRoot: true);
            else rmdir($this->getPath());
        }
    }

    public function rename(string $newPath): static
    {
        $newPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $newPath);
        if (!str_contains($newPath, DIRECTORY_SEPARATOR)) $newPath = join_path($this->getParentPath(), $newPath);
        rename($this->getPath(), $newPath);
        $this->path = $newPath;
        return $this;
    }

    public function copy(string $newPath): static
    {
        copy($this->getPath(), $newPath);
        return $this;
    }

    public function getOwner(): array
    {
        return get_path_owner($this->getPath());
    }

    public function getPerms(bool $octal = false, bool $readable = false): int | string | null
    {
        if ($octal) return get_path_perms_octal_string($this->getPath());
        if ($readable) return get_path_perms_readable_string($this->getPath());
        return (($p = fileperms($this->getPath())) === false) ? null : $p;
    }

    public function seemsBinary(): bool
    {
        if (!($raw = $this->read())) return false;
        return !mb_detect_encoding((string) $raw, null, true);
    }

    public function seemsAscii(): bool
    {
        return file_seems_ascii($this->getPath());
    }

    public function read(): string
    {
        if (!$this->isFile()) throw new Microbe_Exception("Trying to read the contents of a folder.");
        return file_get_contents($this->getPath()) ?: '';
    }

    public function write(string $data): static
    {
        if (!$this->isFile()) throw new Microbe_Exception("Trying to write the contents of a folder.");
        file_put_contents($this->getPath(), $data);
        return $this;
    }

}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'upload' => [
            'app' => [
                'max_size' => '10MB',
            ],
        ],
    ];
});

// =============================================================================
