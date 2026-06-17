<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * Global variable of the configuration entries.
 * @var array
 */
$_CONFIG = [];

/**
 * Load the configuration file and store it to the global variable '$_CONFIG'.
 */
function load_config(): void
{
    global $_CONFIG;

    $_CONFIG = [];
    foreach (get_config_file_allowed_levels() as $level) {
        $_CONFIG = array_replace_recursive($_CONFIG, load_config_file(get_config_file_path($level)));
    }

    process_config($_CONFIG);
}

/**
 * Returns the levels allowed for configuration files.
 * @return array Array containing levels names as strings.
 */
function get_config_file_allowed_levels(): array
{
    return [ 'global', 'user', 'env' ];
}

/**
 * <USER>
 * Returns the path of the configuration file, based on the given level.
 * @param  string $level Level: 'global', 'user' or 'env'
 * @return string        Absolute path to the file.
 */
function get_config_file_path(string $level = 'global'): string
{
    if ($level === 'global') return get_path('config.json');
    if ($level === 'user') return get_path('config-user.json');
    if ($level === 'env') return get_path('config-' . get_env() . '.json');
    throw new Microbe_Exception("Invalid Configuration File Level Name: {$level}.");
}

/**
 * Load specific configuration file and returns the processed array.
 * @param  string $path Path to the configuration file.
 * @return array        Array representing the configuration.
 */
function load_config_file(string $path): array
{
    if (!is_file($path)) return [];
    if (!($raw = file_get_contents($path))) return [];

    try {
        $cfg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Exception $e) {
        throw new Microbe_Exception('Error while parsing JSON configuration: ' . $e->getMessage());
    }

    foreach ($cfg as $k => $v) {
        $cfg['@' . $k] = $v;
        unset($cfg[$k]);
    }

    return $cfg;
}

/**
 * Process the configuration array to replace the special variables:
 *   - The string '{@...}' will be replaced by the corresponding config entry;
 *   - The string '%env(dev,staging)' will be replaced by the boolean true if
 *     the environment is one of those listed ('dev' or 'staging', here);
 * @param  array &$c Configuration part to process
 * @return array     Configuration part processed
 */
function process_config(array &$c): array
{
    foreach ($c as $k => $v) {
        if (is_array($v)) {
            $c[$k] = process_config($v);
        } else if (!is_string($v) || $v === '') {
            continue;
        } else if (preg_match_all('/\{(@[_.a-z0-9$]+)\}/i', $v, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $foundValue = cfg('~' . str_replace('$env', get_env(), $m[1]));
                if ($v === $m[0]) {
                    $v = $foundValue;
                } else {
                    $v = str_replace($m[0], $foundValue, $v);
                }
            }
            $c[$k] = $v;
        } else if (preg_match('/^%env\(([a-z,]+)\)$/', strtolower($v), $m)) {
            $c[$k] = false;
            foreach (explode(',', $m[1]) as $env) {
                if (!($env = trim($env)) || !is_env($env)) continue;
                $c[$k] = true;
                break;
            }
        }
    }

    return $c;
}

/**
 * <USER>
 * Process a string to replace all configuration references
 * identified with "{@...}".
 * @param  string $str String to process.
 * @return string      String processed.
 */
function process_config_references(string $str): string
{
    if (!preg_match_all('/\{(@[_.a-z0-9$]+)\}/i', $str, $matches, PREG_SET_ORDER)) return $str;
    foreach ($matches as $m) {
        $str = str_replace($m[0], cfg('~' . str_replace('$env', get_env(), $m[1])) ?: '', $str);
    }
    return $str;
}

/**
 * <USER>
 * Get/Set configuration entry.
 * @param  string $var   Entry name, separated with dots for walking deeply in
 *                       the config array. The first column (the part before
 *                       the first dot) should start with '@'. If not, the
 *                       full path will be prefixed with '@app', corresponding
 *                       to the app's specific entries.
 *                       By default, a missing configuration entry will exit
 *                       the code with an error. To get a silent null instead,
 *                       the path should be prefixed with a '~'.
 * @param  mixed  $value To set the value, specify anything different than the
 *                       default value. It should be any JSON stringifyable
 *                       value.
 * @return mixed         Configuration entry's value.
 */
function cfg(?string $var = null, mixed $value = '<@__undefined__@>'): mixed
{
    global $_CONFIG;
    if ($var === null) return $_CONFIG;

    if (is_array($var)) {
        foreach ($var as $k => $v) cfg($k, $v);
        return null;
    }

    $showError = true;
    if (strpos($var, '~') === 0) {
        $var = substr($var, 1);
        $showError = false;
    }

    $v = $_CONFIG;
    if ($var[0] !== '@') $var = '@app.' . $var;
    $columns = explode('.', $var);

    if ($value !== '<@__undefined__@>') {
        $setter = &$_CONFIG;
        $numColumns = count($columns);
        foreach ($columns as $idx => $col) {
            if (!array_key_exists($col, $setter)) {
                // If the key doesn't exists, we set it to an empty table if
                // we will loop again, or null if it's the last iteration.
                $setter[$col] = ($idx + 1) === $numColumns ? null : [];
            }
            $setter = &$setter[$col];
        }
        $setter = $value;
        return null;
    }

    foreach ($columns as $col) {
        if (!array_key_exists($col, $v)) {
            if (!$showError) return null;
            die("Configuration entry {$var} is not defined.\n");
        }
        $v = $v[$col];
    }

    return $v;
}

/**
 * <USER>
 * Echo configuration value
 * @param  string $var   Entry name, like in <cfg()>.
 */
function _cfg(string $var): void
{
    echo cfg($var) ?: '';
}

/**
 * <USER>
 * Echo an escaped configuration value
 * @param  string $var   Entry name, like in <cfg()>.
 */
function _h_cfg(string $var): void
{
    echo esc(cfg($var) ?: '');
}

/**
 * <USER>
 * Updates some configuration key in the configuration file.
 * @param  string                      $key   Key (dot-separated) of the
 *                                            configuration entry.
 * @param  array|string|int|float|bool $value Value to set.
 * @param  string                      $level Level of the configuration file
 *                                            (default: 'user').
 */
function update_config_value(string $key, array | string | int | float | bool $value, string $level = 'user'): void
{
    $path = get_config_file_path($level);
    $data = [];
    if (is_file($path)) {
        $raw = file_get_contents($path) ?: '';
        if (!is_array($data = json_decode($raw, true))) throw new Microbe_Exception("Trying to write config value on a non-JSON file: {$path}.");
    }

    $ref = &$data;
    foreach (explode('.', $key) as $k) {
        if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
        $ref = &$ref[$k];
    }
    $ref = $value;
    unset($ref);

    $raw = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($path, $raw);
}

/**
 * <USER>
 * Updates several configuration keys in the configuration file.
 * @param  array                       $values Key-value array.
 *                                             Leys depth-walking are dot-separated.
 * @param  string                      $level  Level of the configuration file
 *                                             (default: 'user').
 */
function update_config_values(array $values, string $level = 'user'): void
{
    foreach ($values as $k => $v) update_config_value($k, $v, level: $level);
}

/**
 * <USER>
 * Store some data during the processing of the request, using the
 * configuration function, inside the <@stored> configuration section.
 * @param  string $var   Entry name, separated with dots for walking deeply in
 *                       the config array.
 * @param  mixed  $value To set the value, specify anything different than the
 *                       default value. It should be any JSON stringifyable
 *                       value.
 * @return mixed         Configuration entry's value.
 */
function stored(?string $var = null, mixed $value = '<@__undefined__@>'): mixed
{
    return cfg('~@stored.' . $var, $value);
}

/**
 * <USER>
 * Get/Set configuration entry from database key/value table.
 * @param  string $name  Key of the entry to get or set.
 * @param  mixed  $value Value to store in database. If null, no change will
 *                       be done and the existing value in database will
 *                       be returned.
 * @return mixed         Value got from the database, null if not found.
 */
function db_cfg(string $name, mixed $value = null): mixed
{
    $val = null;

    try {
        $val = db_fetch_value("SELECT value FROM config WHERE name = :name", [ 'name' => $name ]);
    } catch (Exception $e) {
        if ($value === null) return null;
        db_query("CREATE TABLE config ( name VARCHAR(128) NOT NULL, value TEXT, PRIMARY KEY (name) );");
    }

    if ($value === null) {
        return $val === null ? $val : json_decode($val, true);
    }

    db_cfg_delete($name, false);
    db_query("INSERT INTO config (name, value) VALUES (:name, :value)", [
        'name'  => $name,
        'value' => json_encode($value),
    ]);

    return $value;
}

/**
 * <USER>
 * Set configuration entry from database key/value table. If $value is null,
 * the configuration entry will be deleted.
 * @param  string $name  Key of the entry to set.
 * @param  mixed  $value Value to store in database. If null, the entry
 *                       will only be deleted.
 */
function db_cfg_set(string $name, mixed $value = null): void
{
    if ($value !== null) db_cfg($name, $value);
    else db_cfg_delete($name);
}

/**
 * <USER>
 * Echo configuration value from database key/value table.
 * @param  string $name  Key of the entry to get or set.
 */
function _db_cfg(string $name): void
{
    echo db_cfg($name) ?: '';
}

/**
 * <USER>
 * Echo an escaped configuration value from database key/value table.
 * @param  string $name  Key of the entry to get or set.
 */
function _h_db_cfg(string $name): void
{
    echo esc(db_cfg($name) ?: '');
}

/**
 * <USER>
 * Delete a configuration entry from database key/value table.
 * @param  string $name        Entry key.
 * @param  bool   $dropIfEmpty Drop the config table if there is no more entry.
 */
function db_cfg_delete(string $name, bool $dropIfEmpty = true): void
{
    try {
        db_query('DELETE FROM config WHERE name = :name', [ 'name' => $name ]);
        if ($dropIfEmpty && db_fetch_value("SELECT 1 FROM config LIMIT 1") === null) {
            db_query("DROP TABLE IF EXISTS config");
        }
    } catch (Exception $e) {}
}

// =============================================================================
