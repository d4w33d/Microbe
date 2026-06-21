<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Check if a value seems to represent a true or not (e.g, '1', 'yes', etc.).
 * @param  mixed  $value Value to be checked.
 * @return bool          Is it a true... or a false...
 */
function value_seems_true(mixed $value): bool
{
    if (!is_scalar($value)) return false;
    if ($value === true) return true;
    return in_array(strtolower((string) ($value ?: '')), [ '1', 'y', 'yes', 't', 'true' ], true);
}

/**
 * Returns the dump (aka 'var_dump') of a variable.
 * @param  mixed  $something Variable to dump.
 * @return string            Dump string describing the variable.
 */
function dump(mixed $something): string
{
    ob_start();
    var_dump($something);
    return ob_get_clean();
}

/**
 * <USER>
 * Check if the given object is an object and return its ID property as defined
 * as key parameter, if exists, or null.
 * If the given object is a scalar variable, check if this value is numeric and
 * returns it (assuming it's already the ID).
 * If nothing works, it will returns null.
 * @param  mixed   $obj Object, string or integer thing.
 * @param  string  $key ID key.
 * @return integer      Integer ID, or null if not found.
 */
function object_id(mixed $obj, string $key = 'id'): ?int
{
    if (!$obj) return null;

    if (is_object($obj)) {
        if (property_exists($obj, $key)) return $obj->$key ?: null;
        return null;
    }

    $obj = (string) $obj;
    if (preg_match('/^[0-9]+$/', $obj)) return (int) $obj;
    return null;
}

/**
 * <USER>
 * Merge several array and/or objects together, like array_merge,
 * but type-tolerant.
 * @param  array|object ...$things Arrays or objects to merge together.
 * @return object                  Object corresponding to the merged arguments.
 */
function object_merge(array | object ... $things): object
{
    $a = [];
    foreach ($things as $t) $a = array_merge($a, to_array($t));
    return to_object($a);
}

/**
 * <USER>
 * Delete unwanted keys in array or object.
 * @param  array|object $thing Array or object to process.
 * @param  array        $keys  Allowed keys.
 * @return array|object        Array or object processed.
 */
function restrict_keys(array | object $thing, array $keys): array | object
{
    $isObject = is_object($thing);
    foreach ($thing as $k => $v) {
        if (!in_array($k, $keys)) {
            if ($isObject) unset($thing->$k);
            else unset($thing[$k]);
        }
    }
    return $thing;
}

/**
 * <USER>
 * Map an object, exactly like <array_map> does for arrays.
 * @param  Closure $func   Callback function executed for each item. The first
 *                         parameter is the item itself. The second is the
 *                         property name.
 * @param  object  $object Input object.
 * @return object          Output object.
 */
function object_map(Closure $func, object $object): object
{
    foreach ($object as $k => $v) $object->$k = $func($v, $k);
    return $object;
}

/**
 * <USER>
 * Convert recursively an array or an object to an array.
 * @param  array|object $d Object/array to convert.
 * @return array           Converted array.
 */
function to_array(array | object $d): array
{
    return json_decode(json_encode($d), true);
}

/**
 * <USER>
 * Convert recursively an array or an object to an object.
 * @param  array|object $d Object/array to convert.
 * @return object|array    Converted object. If root is a numerical array,
 *                         an array (perhaps containing objects) will
 *                         still be returned.
 */
function to_object(array | object $d): object | array
{
    return json_decode(json_encode($d));
}

/**
 * <USER>
 * Loop on an array and returns the maximum length of values or keys strings.
 * @param  array|object $arr     Array to process.
 * @param  bool|boolean $useKeys Check length on keys instead of values.
 * @return int                   Maximum length.
 */
function get_array_items_max_length(array | object $arr, bool $useKeys = false): int
{
    $max = 0;
    foreach ($arr as $k => $v) $max = max($max, strlen($useKeys ? $k : $v));
    return $max;
}

/**
 * <USER>
 * Get the constants defined in a specific class, optionaly altered by a
 * regexp and/or a user function.
 * @param  string       $className Name of the class
 * @param  string|null  $filter    Regular expression used for
 * @param  Closure|null $func      Alteration function.
 * @return array                   Array of constants.
 */
function get_class_constants(string $className, ?string $filter = null, ?Closure $func = null): array
{
    if ($filter && !seems_regex($filter)) $filter = joker_to_regex($filter);
    $constants = [];
    foreach ((new ReflectionClass($className))->getConstants() as $k => $v) {
        if ($filter && !preg_match($filter, $k)) continue;
        $constants[$k] = $func ? $func($k, $v) : $v;
    }
    return $constants;
}

/**
 * <USER>
 * Create an array containing object pairs with 'from' and 'to' index values,
 * based on a unidimensional array containing all indexes.
 * E.g. [ 2, 1, 3, 0, 2, 4, 8, 7 ] => [ { from: 0, to: 4 }, { from: 7, 8 } ]
 * @param  array  $indexes Array of indexes.
 * @return array           Array of object containing simplified indexes.
 */
function create_ranges_from_indexes(array $indexes): array
{
    $indexes = array_unique($indexes);
    sort($indexes);
    $ranges = [];
    foreach ($indexes as $idx) {
        $last = count($ranges) - 1;
        if ($last < 0 || ($ranges[$last]->to !== ($idx - 1))) {
            $ranges[] = (object) [ 'from' => $idx, 'to' => $idx ];
        } else {
            $ranges[$last]->to = $idx;
        }
    }
    return $ranges;
}

/**
 * <USER>
 * Shuffle an associative array, preserving keys.
 * @param  array &$array Input key/value array.
 * @return array         The shuffled array.
 */
function shuffle_assoc(&$array)
{
    $keys = array_keys($array);
    shuffle($keys);
    $new = [];
    foreach ($keys as $k) $new[$k] = $array[$k];
    $array = $new;
    return true;
}

/**
 * <USER>
 * Check if the given value is an integer, whatever it's inside a string or not.
 * @param  mixed   $value Value to verify.
 * @return boolean Is the value a valid integer?
 */
function is_int_val(mixed $v): bool
{
    if (!is_scalar($v) || is_bool($v)) return false;
    return (bool) preg_match('/^[0-9]+$/', (string) $v);
}
/**
 * <USER>
 * Check if the given value is a float, whatever it's inside a string or not.
 * @param  mixed   $value Value to verify.
 * @return boolean Is the value a valid float?
 */
function is_float_val(mixed $v): bool
{
    if (!is_scalar($v) || is_bool($v) || !$v) return false;
    return (bool) preg_match('/^[0-9]*(\.[0-9]+)?$/', (string) $v);
}

/**
 * <USER>
 * Check if the given argument is an associative array, aka an array with
 * non-numerical keys and/or some non-consecutive numerical keys.
 * @param  mixed   $arr Array to verify.
 * @return boolean      Is the given array an associative array or not.
 */
function is_assoc_array(mixed $arr): bool
{
    if (!is_array($arr)) return false;
    if ([] === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * <USER>
 * Check if the given argument is an array with perfect consecutive
 * numerical keys or not.
 * @param  mixed   $arr Array to verify.
 * @return boolean      Is the given array a numeric array or not.
 */
function is_numeric_array(mixed $arr): bool
{
    return is_array($arr) && !is_assoc_array($arr);
}

/**
 * <USER>
 * Filter an array recursively.
 * @param  array        $arr               Array to filter.
 * @param  Closure|null $callback          Function to execute on each leave.
 *                                         If the function is null, a boolean
 *                                         comparision will be made.
 * @param  bool         $removeEmptyArrays If true, the array without leaves
 *                                         will be removed. Else, the empty
 *                                         array will stay in place.
 * @return array                           The array filtered.
 */
function array_filter_recursive(array $arr, ?Closure $callback = null, bool $removeEmptyArrays = false): array
{
    foreach ($arr as $key => &$value) {
        if (is_array($value)) {
            $value = array_filter_recursive($value, $callback, $removeEmptyArrays);
            if ($removeEmptyArrays && !(bool) $value) {
                unset($arr[$key]);
            }
        } else {
            if (!is_null($callback) && !$callback($value, $key)) {
                unset($arr[$key]);
            } elseif (!(bool) $value) {
                unset($arr[$key]);
            }
        }
    }
    unset($value);
    return $arr;
}

/**
 * <USER>
 * Execute a function recursively on items of an array,
 * looking for the children using a specific key.
 * @param  array       $arr         Array of items.
 * @param  string      $childrenKey Key to look for on each item, to
 *                                  find its own children.
 * @param  Closure     $func        Function to execute. This function will
 *                                  accept three parameters:
 *                                  - the given child;
 *                                  - its depth;
 *                                  - its parents.
 * @param  int         $depth       Current depth. Default starting depth
 *                                  is zero. It's used by the function itself
 *                                  to increment the depth while walking
 *                                  diving inside the array.
 */
function recursive(array $arr, string $childrenKey, Closure $func, int $depth = 0, array $parents = []): void
{
    foreach ($arr as $a) {
        if ($func($a, $depth, $parents) === false) return;

        $children = null;
        if (is_object($a) && property_exists($a, $childrenKey)) $children = $a->$childrenKey;
        else if (is_array($a) && array_key_exists($childrenKey, $a)) $children = $a[$childrenKey];
        if (!is_array($children) || !$children) continue;
        recursive($children, $childrenKey, $func, $depth + 1, array_merge($parents, [ $a ]));
    }
}

/**
 * <USER>
 * Walk through a recursive object and/or array to find the chained key,
 * in which keys are separated by $separator (default '.').
 * E.g. walk_data([ 'a' => [ 'aa' => 'foo' ] ], 'a/aa', '/') => 'foo'
 * @param  mixed  $data      Recursive object and/or array to walk through.
 * @param  string $key       Chained key.
 * @param  string $separator Keys separator. Default '.'.
 * @return mixed             Found value. Else, null.
 */
function walk_data(mixed $data, string $key, string $separator = '.'): mixed
{
    if (!is_array($data) && !is_object($data)) return null;
    foreach (explode($separator, $key) as $k) {
        if (is_object($data)) {
            if (!property_exists($data, $k)) return null;
            $data = $data->$k;
        } else if (is_array($data)) {
            if (!array_key_exists($k, $data)) return null;
            $data = $data[$k];
        }
    }
    return $data;
}

/**
 * <USER>
 * Cast a recursive data, using the pattern given as $data.
 * E.g. $casted = cast_data($inputData, (object) [
 *          'name' => '?str',
 *          'meta' => (object) [
 *              'categories' => '?str[]',
 *              'url' => '?str',
 *              'timestamp' => '?int',
 *          ],
 *      ]);
 * @param  array   $input     Input value.
 * @param  mixed   $data      Pattern of the data we are waiting for.
 * @param  bool    $trim      Trim string values or not (default true)?
 * @param  string  $separator Chained keys separator (when default one may be
 *                            found in one of the key names).
 * @param  string  $key       Current walking key. Internal use only.
 * @return mixed              Casted data.
 */
function cast_data(object | array $input, mixed $data, bool $trim = true, string $separator = '§', string $key = ''): mixed
{
    foreach ($data as $k => &$v) {
        $kk = trim($key . $separator . $k, $separator);

        if (is_assoc_array($v) || is_object($v)) {
            cast_data($input, $v, $trim, $separator, $kk);
            continue;
        }

        if (!is_string($v)) continue;

        $value = walk_data($input, $kk, $separator);

        $nullable = str_starts_with($v, '?');
        $array = str_ends_with($v, '[]');
        $type = strtolower(str_replace([ '?', '[]' ], '', $v));

             if ($type === 'string') $type = 'str';
        else if ($type === 'boolean') $type = 'bool';
        else if ($type === 'integer') $type = 'int';
        else if ($type === 'decimal' || $type === 'dec') $type = 'float';

        if (!is_array($value)) $value = [ $value ];
        $value = array_values($value);

        foreach ($value as $i => $v) {
            if ($type === 'str') {
                $v = is_scalar($v) ? (string) $v : '';
                if ($trim) $v = trim($v);
                if ($nullable && !$v) $v = null;
                $value[$i] = $v;
            } else if ($type === 'bool') {
                $value[$i] = ($nullable && ($v === null || $v === '')) ? null : value_seems_true($v);
            } else if ($type === 'int' || $type === 'float') {
                $v = is_float_val($v) ? (float) $v : null;
                if (!$nullable && $v === null) $v = 0;
                if ($type === 'int' && $v !== null) $v = (int) $v;
                $value[$i] = $v;
            } else if ($type === 'page') {
                $value[$i] = get_page_number(page: $v);
            }
        }

        if (!$array) $value = $value ? array_shift($value) : null;
        if (!$array && !$nullable && $value === null) {
                 if ($type === 'str') $value = '';
            else if ($type === 'bool') $value = false;
            else if ($type === 'int' || $type === 'float') $value = 0;
        }

        $v = $value;
    }
    return $data;
}

/**
 * <USER>
 * Uncompress a ZIP file into a destination folder.
 * @param  string $zipPath  ZIP file path.
 * @param  string $destPath Folder where the zip should be unzip.
 * @return bool             Success or not.
 */
function unzip(string $zipPath, string $destPath): bool
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return false;
    $zip->extractTo($destPath);
    $zip->close();
    return true;
}

/**
 * <USER>
 * Compress a folder into a ZIP file.
 * @param  string       $folderPath Folder which should be compressed.
 * @param  string       $zipPath    ZIP file destination path.
 * @param  Closure|null $filter     Filter function.
 */
function zip_folder(string $folderPath, string $zipPath, ?Closure $filter = null): void
{
    $folderPath = rtrim(realpath($folderPath), '\\/');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($files as $f) {
        if ($f->isDir()) continue;
        $filePath = $f->getRealPath();
        $relativePath = substr($filePath, strlen($folderPath) + 1);
        if ($filter !== null && !$filter($filePath, $relativePath)) continue;
        $zip->addFile($filePath, $relativePath);
    }
    $zip->close();
}

// =============================================================================
