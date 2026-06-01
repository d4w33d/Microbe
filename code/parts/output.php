<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Declare or update a global template variable.
 * @param string $k Name of the variable
 * @param mixed  $v Value or new value of the variable.
 */
function set_template_var(string $k, mixed $v = null): void
{
    set_template_vars([ $k => $v ]);
}

/**
 * <USER>
 * Set multiple global template variables.
 * @param array $vars    Key/Value array container variables
 *                              to declare/update.
 * @param bool  $replace Replace
 */
function set_template_vars(array $vars, bool $replace = false): void
{
    cfg('@core.tpl.vars', $replace ? $vars : array_merge(get_template_vars(), $vars));
}

/**
 * <USER>
 * Delete a global template variable.
 * @param  string $k Key of the template variable to delete.
 */
function delete_template_var(string $k): void
{
    $vars = get_template_vars();
    if (array_key_exists($k, $vars)) unset($vars[$k]);
    set_template_vars($vars, true);
}

/**
 * <USER>
 * Returns all the global template variables.
 * @return array Template variables.
 */
function get_template_vars(): array
{
    return cfg('~@core.tpl.vars') ?: [];
}

/**
 * <USER>
 * Returns a specific global template variable value.
 * @param  string $key Key of the template variable.
 * @return mixed       Value of the template variable, null if undefined.
 */
function get_template_var(string $key): mixed
{
    $v = get_template_vars();
    return array_key_exists($key, $v) ? $v[$key] : null;
}

/**
 * <USER>
 * Render a template.
 *   - If the given template path is prefixed with a '/', the path of the
 *     looked-up file will be relative to the project root. Else, it will be
 *     relative to the '/tpl' directory.
 *   - If the path doesn't has a recognized extension
 *     (phtml, php, html or htm), the path will be suffixed with '.phtml';
 * @param  string      $tpl              Template name/path.
 * @param  array       $vars             Variables to inject to the template.
 *                                       They will be merged to the global
 *                                       template variables.
 * @param  bool        $return           Returns the result as a string
 *                                       if true. Else, it will be echoed.
 * @param  bool        $preprocessAssets Preprocess the assets if needed,
 *                                       depending on the configuration.
 *                                       If false, the assets preprocessing
 *                                       will be fully skipped.
 * @param  bool        $dispatchEvents   Dispatch the events or not.
 * @param  bool        $throwMissing     Throw an error when the template file
 *                                       is missing.
 * @param  bool        $close            Exit the code with <close()> after
 *                                       rendering.
 * @param  bool        $ajax             Outputs a JSON oject containing the
 *                                       HTML and the underscored variables.
 *                                       Can be a boolean or 'auto'.
 * @param  bool        $updateGlobalVars Update global template vars with $vars.
 * @param  bool        $loadGlobalVars   Load global template into template.
 * @return string|null                   If $return is true, the template
 *                                       result.
 */
function render(
    string        $tpl,
    array         $vars             = [],
    bool          $return           = false,
    bool          $preprocessAssets = true,
    bool          $dispatchEvents   = true,
    bool          $throwMissing     = true,
    bool          $close            = false,
    string | bool $ajax             = false,
    bool          $updateGlobalVars = true,
    bool          $loadGlobalVars   = true,
): ?string
{
    $previousLocale = get_translations_locale();

    if ($ajax === 'auto') $ajax = is_xhr();

    if (!cfg('~' . ($k = '@core.tpl.did_first_render'))) {
        cfg($k, true);
        if ($dispatchEvents) {
            dispatch('before_first_output');
            dispatch('before_first_render');
        }
    }

    if ($dispatchEvents) dispatch('before_render', [
        'tpl'    => $tpl,
        'vars'   => $vars,
        'return' => $return,
    ]);

    $_app_name = cfg('~@app.name');
    $_app_slogan = cfg('~@app.slogan');
    $_dev_is_allowed = dev_is_allowed();
    $_view = null;

    if ($loadGlobalVars) $vars = array_merge(get_template_vars() ?: [], $vars);
    if ($updateGlobalVars) set_template_vars($vars, true);
    extract($vars);

    if (!preg_match('/\.(phtml|php|html?)$/i', $tpl)) $tpl .= '.phtml';

    if ($preprocessAssets) preprocess_assets();

    if (!$return && !headers_sent()) header('Content-Type: text/html; charset=' . (cfg('~@output.html.charset') ?: 'utf-8'));

    $path = null;
    if (!($func = get_inline_template(remove_extension($tpl)))) {
        if (!$tpl) throw new Microbe_Exception("Template name is empty");
        if (!is_file($path = join_path(get_root_dir(), 'templates', $tpl))) {
            if ($tpl[0] !== '/' && $tpl[0] !== '@') $tpl = '@default/' . $tpl;
            if (preg_match('/^@(?<bundleName>[a-z0-9_.-]+)\/(?<suffix>.+)/', $tpl, $m)) {
                if (!($bundle = get_bundle($m['bundleName']))) throw new Microbe_Exception("Template's bundle {$m['bundleName']} is not registered.");
                $tpl = $bundle->synonym[1] . '/' . $bundle->name . '/templates/' . $m['suffix'];
            }
            if (!is_file($path = ($tpl[0] === '/' ? $tpl : join_path(get_root_dir(), $tpl)))) {
                if (!$throwMissing) return null;
                throw new Microbe_Exception("Template {$path} doesn't exists");
            }
        }
    }

    $pretty = (bool) cfg('~@output.render.pretty_html');

    ob_start();

    if ($path) {
        require $path;
    } else {
        $func($vars);
    }

    $out = ob_get_clean();
    if ($pretty) $out = beautify_html(html: $out);

    locale($previousLocale);

    if (!$ajax) {
        if (!$return) echo $out;
        if ($close) close();
        return $return ? $out : null;
    }

    $publicVars = [];
    foreach ($vars as $k => $v) if ($k[0] === '_') $publicVars[ltrim($k, '_')] = $v;
    json_success(array_merge($publicVars, [
        'canonical_url' => url('.'),
        'html'          => $out,
    ]));
    return null;
}

/**
 * <USER>
 * Render a template with <render>, only if the current HTTP request
 * is not a XMLHTTPRequest.
 * @param  string $tpl  Template name/path.
 * @param  array  $vars Variables to inject to the template.
 */
function layout(string $tpl, array $vars = []): void
{
    if (!is_xhr()) render($tpl, $vars);
}

/**
 * <USER>
 * Render a template traditionnaly located in 'widgets' subfolder.
 * This is very close to <render()>, but with the purpose of reusable
 * components taking simple arguments, or only one argument.
 * @param  string      $name   Name of the template. If starting with a '@',
 *                             a standard template path is expected.
 *                             In other cases, the template will be looked for
 *                             in the default bundle directory, under
 *                             templates/widgets.
 * @param  mixed       $args   Single argument, or key-value array containing
 *                             several template variables.
 * @param  bool        $return Returns the template as a string, or echo it
 *                             directly.
 * @return string|null         Null if the template is echoed. Or the string
 *                             corresponding to the result.
 */
function widget(string $name, mixed $args = [], bool $return = false): ?string
{
    if (is_array($args) && !$args) $args = [ 'value' => null ];
    else if (!is_assoc_array($args)) $args = [ 'value' => $args ];
    if (!array_key_exists('value', $args)) $args['value'] = null;
    $raw = render(
        tpl:              str_starts_with($name, '@') ? $name : ('widgets/' . $name),
        vars:             $args,
        updateGlobalVars: false,
        loadGlobalVars:   false,
        return:           true,
    );
    if (!$return) echo $raw;
    return $return ? $raw : null;
}

/**
 * <USER>
 * Begin a template part, which can be reused in another part of the template.
 * @param  string $name Name of the template part.
 */
function begin_tpl_part(string $name): void
{
    cfg('@core.tpl.last_part_name', $name);
    ob_start();
}

/**
 * <USER>
 * Ends the template part and store the content without displaying it.
 */
function end_tpl_part(): void
{
    cfg('@core.tpl.parts.' . cfg('@core.tpl.last_part_name'), ob_get_clean());
}

/**
 * <USER>
 * Returns the template part previously stored.
 * @param  string $name Name of the template part.
 * @return string       Part of a template stored previously.
 */
function get_tpl_part(string $name): string
{
    return cfg('~@core.tpl.parts.' . $name) ?: '';
}

/**
 * <USER>
 * Display the result of <get_tpl_part>.
 * @param  string $name Name of the template part.
 */
function tpl_part(string $name): void
{
    echo get_tpl_part($name);
}

/**
 * <USER>
 * Render a template based on function echoing some HTML.
 * @param  array|Closure      $arg1 The template vars or the echoing function.
 * @param  Closure|array|null $arg2 The echoing function or the template vars.
 * @return string                   The final template code.
 */
function inline_render(array | Closure $arg1, Closure | array | null $arg2 = null): string
{
    $func = null;
    $vars = null;
    if ($arg1 instanceof Closure) $func = $arg1;
    else if ($arg2 instanceof Closure) $func = $arg2;
    if (is_array($arg1)) $vars = $arg1;
    else if (is_array($arg2)) $vars = $arg2;
    ob_start();
    call_user_func($func, $vars);
    return ob_get_clean();
}

/**
 * <USER>
 * Register a template, based on a name and a source code as a string.
 * @param  string  $name Name of the template.
 * @param  Closure $code Function echoing the template code (PHP/HTML).
 */
function register_inline_template(string $name, Closure $func): void
{
    $templates = cfg('~@core.tpl.inline_templates') ?: [];
    $templates[$name] = $func;
    cfg('~@core.tpl.inline_templates', $templates);
}

/**
 * <USER>
 * Returns an inline template rendering function, based on its name.
 * @param  string       $name Name of the inline template.
 * @return Closure|null       Rendering function, or null if template not
 *                            registered.
 */
function get_inline_template(string $name): ?Closure
{
    return (cfg('~@core.tpl.inline_templates') ?: [])[$name] ?? null;
}

/**
 * <USER>
 * Returns an absolute path corresponding to the asset given as argument.
 * @param  string $path Asset's path, relative to the project, which can
 *                      contains a bundle reference.
 * @return string       Absolute path.
 */
function get_asset_path(string $path): string
{
    if ($path[0] === DIRECTORY_SEPARATOR) return $path;

    $bundleName = 'default';
    $pathSuffix = $path;
    if (preg_match('/^@(?<bundleName>[a-z0-9_.-]+)\/(?<suffix>.*)/', $path, $m)) {
        $bundleName = $m['bundleName'];
        $pathSuffix = $m['suffix'];
    }

    if (!($bundle = get_bundle($bundleName))) throw new Microbe_Exception("Trying to compute assets path without a valid bundle name: {$bundleName}.");
    return join_path($bundle->dir, 'assets', $pathSuffix);
}

/**
 * <USER>
 * Generate the proper <link> or <script> code to include the given path.
 * @param  string        $path   Path to asset file (absolute or relative to
 *                               assets root).
 * @param  string | null $name   Reference name of the asset. If given,
 *                               <is_asset_required()> will be checked and the
 *                               asset will be returned or not, depending on
 *                               the result.
 * @param  array|null    $attrs  Array containing additional HTML attributes to
 *                               append to the asset tag.
 * @param  bool          $return Returns as a string. If false (default),
 *                               echo the HTML.
 * @return string | null         Returns the HTML string, or null if $return
 *                               is false.
 */
function include_asset(string $path, string $name = null, ?array $attrs = null, bool $return = false): ?string
{
    if ($name && !is_asset_required($name)) return $return ? '' : null;
    $path = get_asset_path($path);

    $attrsSnippet = '';
    foreach (($attrs ?: []) as $k => $v) $attrsSnippet .= ' ' . $k . ($v === true ? '' : '="' . esc($v) . '"');

    $url = path_to_url($path);
    $ext = get_file_extension($path, lower: true);
    $version = '?v=' . assets_version();
    $snippet = null;

         if ($ext === 'css') $snippet = '<link href="' . $url . $version . '" rel="stylesheet"' . $attrsSnippet . '>';
    else if ($ext === 'js')  $snippet = '<script src="' . $url . $version . '"' . $attrsSnippet . '></script>';

    if (!$return) echo $snippet;
    return $return ? $snippet : null;
}

/**
 * <USER>
 * Generate the proper <link> or <script> code to include the given path.
 * @param  array        $paths   Paths to assets files (absolute or relative to
 *                               assets root).
 * @return array | null          Returns the HTML snippets, or null if $return
 *                               is false.
 */
function include_assets(array $paths, bool $return = false): ?array
{
    $snippets = [];
    foreach ($paths as $p) {
        $files = preg_match('/\/[^\/]*\*[^\/]*$/', $p) ? array_values(glob(get_asset_path($p))) : [ $p ];
        foreach ($files as $f) $snippets[] = include_asset(path: $f, return: true);
    }
    if (!$return) echo implode("\n", $snippets);
    return $return ? $snippets : null;
}

/**
 * <USER>
 * Ask for an asset. In the standard use case with a context template
 * (e.g. homepage), loading the header and the footer with <layout>,
 * this function can be executed immediatly before running
 * <layout>('...header...'). Like this, if the header or the footer are using
 * <is_asset_needed> with the proper asset name, this asset will be handled.
 * @param  string  $name       Name of the asset.
 * @param  boolean $isRequired Always true, except if you finaly want to
 *                             disable this asset.
 */
function require_asset(string $name, bool $isRequired = true): void
{
    cfg('@core.assets.required.' . $name, $isRequired);
}

/**
 * <USER>
 * Check if an asset is asked somewhere.
 * @param  string  $name Name of the asset.
 * @return boolean       Is the asset asked or not?
 */
function is_asset_required(string $name): bool
{
    return (bool) cfg('~@core.assets.required.' . $name);
}

/**
 * Returns the path of the file containing the assets version number.
 * @return string File path.
 */
function get_assets_version_path(): string
{
    return get_data_dir('ASSETS_VERSION');
}

/**
 * <USER>
 * Returns the current assets version number, as written in the assets
 * version file.
 * @param  boolean $checkEnv Check if the environment is excluded from
 *                           assets caching (e.g. 'dev'). If yes, a random
 *                           string will be returned instead of a
 *                           specific number.
 * @return string            Version number.
 */
function assets_version(bool $checkEnv = true): string
{
    if ($checkEnv && cfg('@assets.no_cache')) return substr(sha1(uniqid('', true)), 0, 11);
    $defaultVersion = '0';
    if (!is_file($path = get_assets_version_path())) return $defaultVersion;
    if (!($v = trim(file_get_contents($path) ?: ''))) return $defaultVersion;
    return $v;
}

/**
 * <USER>
 * Increments the assets version number.
 * @param  int $by Increment by how many? Default 1.
 */
function increment_assets_version(int $by = 1): void
{
    $v = assets_version();
    if (!preg_match('/^(?<before>(.*[^0-9])?)(?<version>[0-9]+)$/', $v, $m)) return;
    $v = $m['before'] . (((int) $m['version']) + 1);
    file_put_contents(get_assets_version_path(), $v);
}

/**
 * Returns the path of the file containing the last asset file modified time.
 * @return string File path.
 */
function get_assets_latest_file_time_path(): string
{
    return get_data_dir('ASSETS_LATEST_FILE_TIME');
}

/**
 * <USER>
 * Returns the latest asset file modified time.
 * @return DateTime | null Latest modified time.
 */
function get_assets_latest_file_time_stored(): ?DateTime
{
    if (!(is_file($path = get_assets_latest_file_time_path()))) return null;
    if (!($raw = trim(file_get_contents($path)))) return null;
    return new DateTime($raw);
}

/**
 * <USER>
 * Set the latest asset file modified time.
 * @param  DateTime | null Latest modified time.
 */
function set_assets_latest_file_time_stored(?DateTime $dt = null): void
{
    $path = get_assets_latest_file_time_path();
    if ($dt === null && is_file($path)) unlink($path);
    file_put_contents($path, $dt->format('c'));
}

/**
 * Returns all assets directories (root and bundles ones).
 * @return array Array containing paths of directories.
 */
function get_assets_dirs(): array
{
    $dirs = [];

    if (is_dir($assetsDir = join_path(get_root_dir(), 'assets'))) $dirs[] = $assetsDir;
    if (is_dir($assetsDir = join_path(get_root_dir(), 'src', 'assets'))) $dirs[] = $assetsDir;

    foreach (get_bundles() as $bundle) {
        if (!is_dir($assetsDir = join_path($bundle->dir, 'assets'))) continue;
        $dirs[] = $assetsDir;
    }

    return $dirs;
}

class Microbe_Recursive_Filter_Iterator extends RecursiveFilterIterator
{
    public function accept(): bool
    {
        $p = $this->current()->getPathname();
        return !str_contains($p, '/vendor/')
            && !str_ends_with($p, '.css')
            && !str_ends_with($p, '.js');
    }
}

/**
 * Get the DateTime of the latest asset file modification time.
 * @return DateTime | null The DateTime of the last modification time.
 */
function get_latest_asset_modified_time(?array $dirs = null): ?DateTime
{
    if ($dirs === null) $dirs = get_assets_dirs();
    $t = null;
    foreach ($dirs as $dir) {
        if (($st = get_latest_modified_file_time($dir, 'Microbe_Recursive_Filter_Iterator')) !== null) {
            if ($t === null || $st > $t) $t = $st;
        }
    }
    return $t;
}

/**
 * Execute the assets preprocessors, like Stylus, SASS, LESS, etc.
 * @param  boolean $force Preprocess even if it was already done before
 *                        in this request.
 */
function preprocess_assets(bool $force = false): void
{
    if (!cfg('~@assets.preprocessing.enabled') || (!$force && cfg('~@core.assets.preprocessed'))) {
        return;
    }

    cfg('@core.assets.preprocessed', true);

    $dirs = get_assets_dirs();

    $latestModifiedTime = get_latest_asset_modified_time($dirs);

    if ($latestModifiedTime !== null) {
        if (($previousLatestTime = get_assets_latest_file_time_stored()) !== null) {
            if ($latestModifiedTime <= $previousLatestTime) {
                return;
            }
        }
        set_assets_latest_file_time_stored($latestModifiedTime);
    }

    if (!($preprocessors = cfg('~@core.assets.preprocessors'))) {
        $preprocessors = dispatch('register_assets_preprocessor');
        cfg('@core.assets.preprocessors', $preprocessors);
    }

    $enabledPreprocessors = cfg('~@assets.preprocessing.preprocessors');
    foreach ($preprocessors as $preprocessor) {
        if (!in_array($preprocessor['name'], $enabledPreprocessors)) continue;
        foreach ($dirs as $dir) $preprocessor['func']($dir);
    }
}

/**
 * <USER>
 * Require some JavaScript files in the proper <script/> tag(s).
 * @param array $files  Array of relative paths to the *.js files from the root
 *                      of the project. It can ends by a "/*" to load all the
 *                      files contained in a specific directory.
 * @param bool  $bind   Bind the files together, instead of requiring them
 *                      through separated <script/> tags.
 * @param bool  $minify Minify a bit each files before binding. It will does
 *                      nothing if the binding is not enabled.
 * @param bool  $cached Always use the cache if the files were unchanged,
 *                      or regenerate it each time.
 */
function require_js(
    array $files,
    bool  $bind   = null,
    bool  $minify = null,
    bool  $cached = null,
): void
{
    if ($bind === null) $bind = !is_env('dev');
    if ($minify === null) $minify = $bind;
    if ($cached === null) $cached = !is_env('dev');

    $paths = [];
    foreach ($files as $f) {
        $path = join_path(get_root_dir(), $f);
        if (!str_ends_with($path, DIRECTORY_SEPARATOR . '*')) {
            if (!is_file($path)) throw new Microbe_Exception("Unable to load the JavaScript file {$path}.");
            $paths[] = $path;
            continue;
        }
        if (!is_dir($dir = substr($path, 0, -2))) {
            throw new Microbe_Exception("Unable to load the JavaScript files inside the directory {$dir}: directory not found.");
        }
        $subFiles = glob(join_path($dir, '*.js'));
        sort($subFiles);
        foreach ($subFiles as $ff) $paths[] = $ff;
    }

    if (!$bind) {
        foreach ($paths as $p) echo '<script src="' . path_to_url($p) . '"></script>' . "\n";
        return;
    }

    $signature = [];
    foreach ($paths as $p) $signature .= $p . ':' . filesize($p) . ':' . filemtime($p);
    $signature = sha1($signature);
    $binderPath = join_path(get_assets_dir(), 'js', 'bind', $signature . '.js');
    if ($cached && is_file($binderPath)) {
        echo '<script src="' . path_to_url($binderPath) . '"></script>' . "\n";
        return;
    }

    $binder = [];
    foreach ($paths as $p) {
        $part = "// Module: <". path_to_url($p) . ">\n\n" . file_get_contents($p);
        if ($minify) {
            $part = str_replace("\r\n", "\n", $part);
            $part = str_replace("\r", "\n", $part);
            $part = preg_replace('/^\s*\/\/.*$/m', '', $part);
            $part = preg_replace('/\n+/', "\n", $part);
            $part = trim($part);
        }
        $binder[] = $part;
    }
    $binder = implode("\n\n", $binder);

    if (!is_dir($binderDir = dirname($binderPath))) mkdir($binderDir, get_mkdir_chmod(), true);
    file_put_contents($binderPath, $binder);
    echo '<script src="' . path_to_url($binderPath) . '"></script>' . "\n";
}

/**
 * <USER>
 * Declare some CSS rules, for further inline use (in style="...").
 * @param  array  $rules Key/Value CSS rules. The key is the name of the rule,
 *                       and the value is a string containing some CSS
 *                       declarations, or an associative array representing
 *                       those declarations.
 *                       E.g. ['bigtext'=>['font-size'=>'4rem']].
 */
function declare_inline_css(array $rules): void
{
    cfg('@core.assets.inline_css', array_merge(cfg('~@core.assets.inline_css') ?: [], $rules));
}

/**
 * <USER>
 * Generate a style="..." attribute with the proper inline CSS declarations
 * previously declared with <declare_inline_css>.
 * @param  array   $names       Name or names of the rules to apply.
 * @param  boolean $returnValue Returns the inline CSS if true, or echo the
 *                              style attribute if true (default).
 * @return string|null          CSS declaration if $returnValue is true.
 */
function inline_css(string | array $names, bool $returnValue = false): ?string
{
    $rules = cfg('~@core.assets.inline_css') ?: [];
    if (!is_array($names)) $names = [ $names ];

    $css = [];
    foreach (explode(' ', $names) as $n) {
        if (!($n = trim($n))) continue;
        if (!array_key_exists($n, $rules)) continue;
        $rule = $rules[$n];
        $declarations = [];
        if (!is_array($rule)) $rule = [ $rule ];
        foreach ($rule as $prop => $val) {
            $declarations[] = is_numeric($prop) ? $val : $prop . ': ' . $val;
        }
        if (!$declarations) continue;
        $css[] = implode('; ', $declarations);
    }

    if (!($css = trim(implode(' ', $css)))) return $returnValue ? '' : null;
    if ($returnValue) return $css;
    echo ' style="' . $css . '" ';
    return null;
}

/**
 * <USER>
 * Returns standard CSS units.
 * @return array A simple array of available CSS units.
 */
function get_css_units(): array
{
    return [ 'px', 'rem', 'em', 'vw', 'vh', 'vmin', 'vmax', 'mm', 'cm', 'in', 'pc', 'pt', 'ex', 'ch', 'lh' ];
}

/**
 * <USER>
 * Echo an image size attribute.
 * @param  int      $w     Original width of the image.
 * @param  int|null $h     Original height of the image. If null, the image
 *                         will be considerated as a square of $w x $w.
 * @param  float    $scale Optional scale to apply on se size (default 1).
 */
function size_attr(int $w, int $h = null, float $scale = 1): void
{
    if ($h === null) $h = $w;
    $ratio = $h / $w;
    $w = round($w * $scale);
    $h = round($h * $scale);
    echo ' width="' . $w . '" height="' . $h . '" ';
}

/**
 * <USER>
 * Returns icon string, with the format given by cfg('@output.icons.format'),
 * or a fallback.
 * @param  mixed       $icon   Icon name.
 * @param  string|null $format Icon format.
 * @return string              Icon string.
 */
function icon(mixed $icon, ?string $format = null): string
{
    if (!$icon || !is_string($icon)) return '';
    if ($format === null) $format = cfg('~@output.icons.format') ?: '<i class="%s"></i>';
    return sprintf($format, $icon);
}

/**
 * <USER>
 * Echo icon string, generated with <icon()>.
 * @param  mixed       $icon   Icon name.
 * @param  string|null $format Icon format.
 */
function _icon(mixed $icon, ?string $format = null): void
{
    echo icon($icon, $format);
}

/**
 * <USER>
 * Display a JSON string.
 * @param  array  $data           Data to encode.
 * @param  int    $code           HTTP Code.
 * @param  bool   $pretty         Prettify JSON string or not.
 * @param  bool   $endingLineFeed Echo a \n after the JSON.
 *                                Nicer for terminal display.
 */
function json(array $data, int $code = 200, bool $pretty = false, bool $endingLineFeed = true, bool $dispatchEvents = true): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if ($dispatchEvents) dispatch('before_first_output');
    echo $pretty ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
    if ($endingLineFeed) echo "\n";
    close();
}

/**
 * <USER>
 * Display a JSON string, with a property 'success' set to true.
 * @param  array  $data Optional data to encode.
 * @param  int    $code          HTTP Code.
 * @param  bool   $pretty        Prettify JSON string or not.
 */
function json_success(array $data = [], int $code = 200, bool $pretty = false): void
{
    json(array_merge($data, [ 'success' => true ]), code: $code, pretty: $pretty);
}

/**
 * <USER>
 * Display a JSON string, with a property 'success' set to false, and
 * an 'error' code/message.
 * @param  string $error  Error added in the output JSON object.
 * @param  array  $data   Optional data to encode.
 * @param  int    $code   HTTP Code.
 * @param  bool   $pretty Prettify JSON string or not.
 */
function json_error(string | array $error = 'unknown', array $data = [], int $code = 200, bool $pretty = false): void
{
    if (is_array($error)) {
        $data = $error;
        $error = 'unknown';
    }
    json(array_merge($data, [ 'success' => false, 'error' => $error ]), code: $code, pretty: $pretty);
}

/**
 * <USER>
 * Display a JSON error "unauthorized" with an optional message.
 * @param  string $message  Error message.
 */
function json_403(string $message = null): void
{
    json_error('unauthorized', $message ? [ 'message' => $message ] : []);
}

/**
 * <USER>
 * Display a JSON error "not_found" with an optional message.
 * @param  string $message  Error message.
 */
function json_404(string $message = null): void
{
    json_error('not_found', $message ? [ 'message' => $message ] : []);
}

/**
 * <USER>
 * Display a JSON error "internal_error" with an optional message.
 * @param  string $message  Error message.
 */
function json_500(string $message = null): void
{
    json_error('internal_error', $message ? [ 'message' => $message ] : []);
}

/**
 * <USER>
 * Redirect the user with a HTTP header 'Location'.
 * The URL will be processed through <url> with the optional $vars.
 * @param  string $url  Location URL.
 * @param  array  $vars Optional URL query strings.
 * @param  int    $code HTTP code (default 302).
 */
function redirect(string $url, array $vars = [], int $code = 302): void
{
    if (is_int($vars)) {
        $code = $vars;
        $vars = [];
    }

    header('Location: ' . url($url, $vars), true, $code);
    close();
}

/**
 * <USER>
 * System message to show.
 * @param  string|null $html   HTML string to display.
 * @param  string      $type   Message type for pre-styling. Can be one of:
 *                             'info', 'error' or 'success'.
 * @param  string|null $title  Title, set in a <h1> before the $html.
 * @param  string|null $text   Plain text to display. If $html and $text are
 *                             provided, $text will be escaped then append
 *                             after $html.
 * @param  string|null $before HTML text to display at the beginning of the
 *                             message's code.
 */
function message(
    string $html   = null,
    string $type   = 'info',
    string $title  = null,
    string $text   = null,
    string $before = null,
): void
{
    $types = [
        'info'    => [ 'color' => '0,123,255', 'title' => "Message", 'icon' => 'ℹ️' ],
        'error'   => [ 'color' => '220,53,69', 'title' => "Error",   'icon' => '🚨' ],
        'success' => [ 'color' => '40,167,69', 'title' => "Success", 'icon' => '✅' ],
    ];

    $reset = '"\'>';
    foreach ([ 'option', 'select', 'em', 'strong', 'span', 'a', 'fieldset', 'textarea', 'p', 'div' ] as $tag) {
        for ($i = 0; $i < 5; $i++) $reset .= '</' . $tag . '>';
    }

    $css = [

        'body' => [ 'display: block !important; opacity: 1 !important;' ],

        ':not(#bz-e-msg):not(#bz-e-msg *):not(title):not(style):not(script)' => [ 'display: block !important; z-index: 1 !important;' ],

        '#bz-e-msg' => [
            'position: fixed;', 'overflow: auto;',
            'left: 0;', 'right: 0;', 'top: 0;', 'max-height: 100vh;',
            'background: #111;', 'font-family: monospace;', 'font-size: 16px;',
            'cursor: default;', 'z-index: 99999;',
        ],

        '#bz-e-msg ::selection' => [ 'color: white;', 'background: rgb(' . $types[$type]['color'] . ');' ],

        '#bz-e-msg:before' => [
            'position: fixed;', 'left: 0;', 'right: 0;', 'top: 0;', 'bottom: 0;',
            'background: #111;', 'content: "";', 'z-index: 1;',
        ],

        '#bz-e-msg > div' => [
            'position: relative;', 'margin: 1.5vw auto;', 'max-width: 1500px;',
            'background: #222;', 'border: 5px solid rgba(' . $types[$type]['color'] . ', .8);',
            'box-shadow: 5px 5px 0 0 rgba(' . $types[$type]['color'] . ', .5);',
            'color: #ddd;', 'z-index: 2;',
        ],

        '#bz-e-msg > div > h1' => [
            'display: block;', 'position: relative;', 'margin: 0;', 'padding: 2vw;',
            'background: #00000066;', 'border-bottom: 5px solid rgb(' . $types[$type]['color'] . ', .8);',
            'color: rgb(' . $types[$type]['color'] . ');',
            'line-height: 1; font-size: 150%;', 'font-weight: bold;',
        ],

        '#bz-e-msg > div > div' => [ 'overflow: auto;', 'padding: 3vw 1.5vw 3vw 3vw;', 'white-space: pre;' ],

        '#bz-e-msg > div > div a' => [ 'color: rgb(' . $types[$type]['color'] . ');', 'text-decoration: underline;' ],
        '#bz-e-msg > div > div a:hover' => [ 'color: white;', 'text-decoration: none;' ],
        '#bz-e-msg > div > div i' => [ 'background: #ffffff11;', 'font-style: normal;', 'user-select: all;' ],
        '#bz-e-msg > div > div i > u' => [ 'display: inline-block;', 'width: 0;', 'color: transparent;', 'font-size: 0;' ],
        '#bz-e-msg > div > div i:hover' => [ 'background: #ffffff22;' ],

        '#bz-e-msg > div > div > pre' => [ 'margin: 0;', 'padding: 0;', 'white-space: break-spaces;' ],

        '#bz-e-msg > div > div ul' => [ 'margin: 0;', 'padding: 0;', 'list-style: none;', 'opacity: .5;' ],
        '#bz-e-msg > div > div ul > li' => [ 'position: relative;', 'margin: 0;', 'padding: 0 0 0 25px;', 'list-style: none;' ],
        '#bz-e-msg > div > div ul > li:before' => [
            'display: block;', 'position: absolute;', 'left: 0;', 'top: 6px;', 'width: 6px;', 'height: 6px;',
            'transform: rotate(45deg);',
            'border: 2px solid rgb(' . $types[$type]['color'] . ');', 'border-left: none;', 'border-bottom: none;',
            'content: "";',
        ],

        '#bz-e-msg > div > div ul.bz-e-msg-err-loc > li' => [ 'display: flex;' ],
        '#bz-e-msg > div > div ul.bz-e-msg-err-loc > li > strong' => [ 'flex: 0 0 8em;' ],
        '#bz-e-msg > div > div ul.bz-e-msg-err-loc > li > div' => [ 'flex: 1;' ],

        '#bz-e-msg > div > div ul.bz-e-msg-backtrace' => [ 'opacity: .5;', 'white-space: nowrap;' ],

        '#bz-e-msg > div > div > :not(:last-child)' => [ 'margin-bottom: 3vw;' ],

    ];

    $style = [];
    foreach ($css as $selector => $properties) {
        $style[] = $selector . ' { ' . implode(' ', $properties) . ' }';
    }

    echo ($before ?: '')
       . $reset
       . '<script>'
       . '(function() {'
       . 'let lnk = document.querySelector("link[rel~=\'icon\']");'
       . 'if (!lnk) { lnk = document.createElement("link"); lnk.rel = "icon"; document.head.appendChild(lnk); }'
       . 'lnk.href = "data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>' . $types[$type]['icon'] . '</text></svg>";'
       . '})();'
       . '</script>'
       . '<title>' . unicode_bold($types[$type]['title']) . '</title>'
       . '<style>' . implode(' ', $style) . '</style>'
       . '<div id="bz-e-msg">'
       .   '<div>'
       .     (($title = trim($title ?: '')) ? '<h1>' . $title . '</h1>' : '')
       .     '<div>'
       .       trim($html ?: '')
       .       trim($text ? esc($text) : '')
       .     '</div>'
       .   '</div>'
       . '</div>';

    close();
}

/**
 * <USER>
 * Send headers and readfile, to force download to the client.
 * @param  string      $path          Path to the file.
 * @param  string      $contentType   Content Type of the file.
 * @param  string|null $name          File name passed as header. If null, the
 *                                    basename of the path will be used.
 * @param  bool        $close         Close the app immediatly after reading
 *                                    file?
 * @param  bool        $forceDownload Force download, or allows to render
 *                                    file inline.
 * @param  string|null $data          The raw data, in replacement of the path.
 */
function output_download(
    ?string $path          = null,
    ?string $contentType   = 'application/octet-stream',
    ?string $name          = null,
    bool    $close         = true,
    bool    $forceDownload = true,
    ?string $data          = null,
): void
{
    if ($contentType === null) $contentType = mime_content_type($path);
    if (!$path && !$data) throw new Microbe_Exception("Unable to output a download without a valid path or a raw data.");
    if ($path && !is_file($path)) throw new Microbe_Exception("Trying to output a download with an invalid file path.");

    header('Content-Type: ' . $contentType);
    header('Content-Transfer-Encoding: Binary');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . ($name ?: basename($path)) . '"');
    header('Content-Length: ' . ($path ? filesize($path) : strlen($data)));
    header('Pragma: no-cache');
    header('Cache-Control: max-age=0');
    if ($path) readfile($path);
    else echo $data ?: '';
    if ($close) close();
}

/**
 * <USER>
 * Echo the content of an image file, with the proper headers.
 * @param  string      $path        Path to the image file.
 * @param  string|null $contentType Content type. If null, the function will
 *                                  try to get the proper mime type.
 */
function output_display_image(string $path, ?string $contentType = null): void
{
    if ($contentType === null) $contentType = mime_content_type($path);
    header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
    readfile($path);
    close();
}

/**
 * <USER>
 * Exit the current code whithout a 404 error.
 */
function close(): void
{
    cfg('@core.routes.found', true);
    exit;
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'assets' => [
            'no_cache'      => '%env(dev)',
            'preprocessing' => [
                'enabled'       => '%env(dev)',
                'preprocessors' => [ 'stylus' ],
            ],
        ],
        'output' => [
            'icons' => [
                'format' => '<i class="%s"></i>',
            ],
        ],
    ];
});

listen('register_assets_preprocessor', function(array $data): array
{
    return [
        'name' => 'stylus',
        'func' => function(string $assetsDir): bool
        {
            if (!is_dir($inputDir = join_path($assetsDir, 'styl'))) {
                if (!is_dir($inputDir = join_path($assetsDir, 'stylus'))) {
                    return false;
                }
            }

            $bin = null;
            foreach ([ '/usr/bin/stylus', '/usr/local/bin/stylus' ] as $b) {
                if (is_file($b)) {
                    $bin = $b;
                    break;
                }
            }

            if ($bin === null) return false;

            if (!is_dir($outputDir = join_path($assetsDir, 'css'))) mkdir($outputDir, get_mkdir_chmod(), true);

            $io = popen("{$bin} --compress {$inputDir} --out '{$outputDir}' 2>&1", 'r');
            $response = '';
            while (!feof($io)) $response .= fgets($io, 4096);
            pclose($io);

            if (!preg_match('/(Error): \//', $response)) return true;
            message(
                type:  'error',
                title: "Stylus Error",
                html:  "<pre>An error was returned by Stylus while compiling stylesheets:<br><br>" . trim($response) . "</pre>",
            );
            return false;
        },
    ];
});

// =============================================================================
