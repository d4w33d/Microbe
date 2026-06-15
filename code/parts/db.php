<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Returns the database config as an object.
 * @return object|null Object representing the database configuration.
 */
function db_config(): ?object
{
    if (!($config = cfg('~@db.' . get_env()))) return null;
    if (!$config['host'] || !$config['username'] || !$config['db_name']) return null;

    return (object) array_merge([
        'host'     => null,
        'port'     => null,
        'username' => null,
        'password' => null,
        'db_name'  => null,
        'charset'  => null,
    ], $config);
}

/**
 * <USER>
 * Returns the DSN string of the database connection.
 * @param  object|null Database configuration got from <db_config()>.
 * @return string|null DSN String.
 */
function db_dsn(?object $config = null): ?string
{
    if ($config === null && !($config = db_config())) return null;

    return 'mysql:host=' . $config->host
        . ';port=' . $config->port
        . ';dbname=' . $config->db_name
        . ';charset=' . ($config->charset ?: 'utf8mb4');
}

/**
 * Create a PDO object based on the entries in configuration.
 * @param  string      $ref        The database reference. Default is 'main'.
 * @param  object|null $config     The configuration object. Default is the one
 *                                 set in the configuration file, for the
 *                                 current environment.
 * @param  boolean     $forceRenew Force renewal of PDO object.
 * @return PDO|null                The PDO object, if the database
 *                                 configuration is defined.
 */
function db_setup(string $ref = 'main', ?object $config = null, bool $forceRenew = false): ?PDO
{
    if ($dbh = cfg($key = ('~@core.db_dbh.' . $ref))) return $dbh;

    if ($config === null && !($config = db_config())) return null;
    if (!($dsn = db_dsn($config))) return null;
    try {
        $dbh = new PDO($dsn, $config->username, $config->password);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        cfg('@core.db.last_pdo_connect_error', $e->getMessage());
        return null;
    }

    cfg($key, $dbh);
    return $dbh;
}

/**
 * <USER>
 * Get last PDO connection error if available.
 * @return string|null PDO connection error message.
 */
function db_get_last_pdo_connect_error(): ?string
{
    return cfg('~@core.db.last_pdo_connect_error');
}

/**
 * <USER>
 * Get the current PDO object, and try to instanciate one if not created yet.
 * @param  string   $ref The database reference. Default is 'main'.
 * @return PDO|null      The PDO object, or null if unable to create one.
 */
function db_dbh(string $ref = 'main'): ?PDO
{
    if (!($dbh = cfg('~@core.db_dbh.' . $ref))) $dbh = db_setup();
    return $dbh;
}

/**
 * <USER>
 * Check if the PDO instance is initialized or can be initialized.
 * @param  string $ref The database reference. Default is 'main'.
 * @return bool        If the PDO instance is properly initialized and
 *                     connected, true. Else, false.
 */
function db_is_connected(string $ref = 'main'): bool
{
    return (bool) db_dbh($ref);
}

/**
 * Get the PDO argument type, based on the given value, before binding
 * it to the PDOStatement.
 * @param  mixed  $arg  Value to check.
 * @param  string $k    Optional parameter key, for debugging purpose.
 * @param  string $sql  Optional SQL query, for debugging purpose.
 * @return int          The PDO::PARAM_* constant corresponding
 *                      to the given value.
 */
function db_get_arg_type(mixed $arg, ?string $k = null, ?string $sql = null): int
{
    $types = [
        'double'  => PDO::PARAM_STR,
        'integer' => PDO::PARAM_INT,
        'boolean' => PDO::PARAM_BOOL,
        'null'    => PDO::PARAM_NULL,
        'string'  => PDO::PARAM_STR,
    ];

    $type = strtolower(gettype($arg) ?: '');
    if (!array_key_exists($type, $types)) {
        throw new Microbe_Exception("Argument is of invalid type ({$type}" . (is_object($arg) ? ': ' . get_class($arg) : '') . ")"
            . ($k ? "\nParameter: {$k}" : '')
            . ($sql ? "\nSQL Query:\n\n{$sql}" : ''));
    }

    return $types[$type];
}

/**
 * <USER>
 * Escape the parameter value for usage in MySQL strings.
 * @param  mixed  $str Value.
 * @return string      MySQL escaped string.
 */
function db_esc(mixed $str): string
{
    if ($str === null) return 'NULL';
    if ($str === true) return 'TRUE';
    if ($str === false) return 'FALSE';
    if (is_numeric($str)) return (string) $str;
    return db_dbh()->quote($str);
}

/**
 * <USER>
 * Check if the format of the field name is a proper MySQL table or column name.
 * Only the format will be checked. Not the field existence.
 * It can be used to protect a query from SQL injection, when the table name or
 * the column name can be passed through a form.
 * @param  string $fieldName Table or column name.
 * @return bool              True if the table or column name format is secure
 *                           for injecting in SQL query.
 */
function db_is_valid_field_format(string $fieldName): bool
{
    return (bool) preg_match('/^[a-z0-9_.-]+$/i', $fieldName);
}

/**
 * <USER>
 * Check if the format of the field name is a proper MySQL table or column name
 * using <db_is_valid_field_format> to avoid SQL injection.
 * If the format is invalid, an exception will be thrown.
 * @param  string $fieldName Table or column name.
 * @return string            Field name.
 */
function db_assert_field_format(string $fieldName): string
{
    if (db_is_valid_field_format($fieldName)) return $fieldName;
    throw new Microbe_Exception("Format of database field name is not valid");
}

/**
 * <USER>
 * Check if the given string is a valid SQL comparator.
 * @param  string $cmp Possible comparator string.
 * @return bool        True if the table or column name format is secure
 *                     for injecting in SQL query.
 */
function db_is_valid_comparator(string $cmp): bool
{
    return in_array(strtoupper($cmp), [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE',
    ]);
}

/**
 * <USER>
 * Check if the given string is a valid SQL comparator.
 * If the format is invalid, an exception will be thrown.
 * @param  string $cmp Possible comparator string.
 * @return string      Comparator.
 */
function db_assert_comparator(string $cmp): string
{
    if (db_is_valid_comparator($cmp)) return $cmp;
    throw new Microbe_Exception("The SQL comparator is not valid");
}

/**
 * <USER>
 * Prepare the PDOStatement based on the SQL query and the given variables.
 * @param  string $sql       The SQL query.
 * @param  array  $vars      A key/value associative array with the variables
 *                           to replace in the query. Note: the variables
 *                           should be prefixed with a semicolon in the SQL.
 * @param  string $ref       The database reference. Default is 'main'.
 * @return PDOStatement|null The PDOStatement, or null if the database cannot
 *                           be initialized.
 */
function db_query(string $sql, array $vars = [], string $ref = 'main'): ?PDOStatement
{
    if (!($conn = db_dbh($ref))) return null;
    if (!($smt = $conn->prepare($sql))) throw new Microbe_Exception($conn->errorInfo());
    foreach ($vars as $k => $v) {
        if ($v instanceof DateTime) $v = $v->format('Y-m-d H:i:s');
        $smt->bindValue(':' . $k, $v, db_get_arg_type($v, $k, $sql));
    }
    cfg('@core.db.last_query', db_compute_sql($sql, $vars));
    cfg('@core.db.last_statement', $smt);
    try {
        $smt->execute();
    } catch (PDOException $e) {
        throw new Microbe_Exception($e->getMessage()
            . "\n\nSQL Query:\n\n" . $sql
            . "\n\nVariables:\n\n" . print_r($vars, true)
            . "\n\nComputed SQL Query:\n\n" . db_compute_sql($sql, $vars));
    }
    return $smt;
}

/**
 * <USER>
 * Returns the last query if available.
 * @return string|null Last query SQL if available.
 */
function db_last_query(): ?string
{
    return cfg('~@core.db.last_query');
}

/**
 * <USER>
 * Returns the last PDO statement if available.
 * @return PDOStatement|null Last PDO statement if available.
 */
function db_last_statement(): ?PDOStatement
{
    return cfg('~@core.db.last_statement');
}

/**
 * <USER>
 * Compute SQL with parameters and returns a ready-to-use query.
 * @param  string $sql    SQL query.
 * @param  array  $params Parameters to be applyed on query.
 * @return string         Computed SQL.
 */
function db_compute_sql(string $sql, array $params = []): string
{
    foreach ($params as $k => $v) $sql = str_replace(':' . $k, db_esc($v), $sql);
    return $sql;
}

/**
 * <USER>
 * Read a SQL file and execute queries.
 * @param  string $path Path to the SQL file.
 */
function db_execute_dump(string $path): void
{
    $buffer = '';
    $lines = file($path);
    foreach ($lines as $line) {
        if (substr($line, 0, 2) === '--' || $line === '') continue;
        $buffer .= $line;
        if (substr(trim($line), -1, 1) !== ';') continue;
        db_query($buffer);
        $buffer = '';
    }
}

/**
 * <USER>
 * Create a dump file of the database.
 * @param  string $path Destination SQL file path.
 */
function db_dump(string $path): void
{
    if (!($config = db_config()) || !($dsn = db_dsn($config))) throw new Microbe_Exception("Unable to dump database without a valid database configuration.");

    $dump = new Mysqldump($dsn, $config->username, $config->password);
    $dump->start($path);
}

/**
 * <USER>
 * Get database's size.
 * @return int Database size in bytes.
 */
function db_get_size(): int
{
    return (int) (db_fetch_value("SELECT SUM(`DATA_LENGTH` + `INDEX_LENGTH`) AS `s`
                                  FROM `INFORMATION_SCHEMA`.`TABLES`
                                  WHERE `TABLE_SCHEMA` = :db_name
                                  GROUP BY `TABLE_SCHEMA`", [ 'db_name' => db_config()->db_name ]) ?: 0);
}

/**
 * <USER>
 * Get database's tables infos.
 * @param  bool $countRows  Returns number of rows for each table.
 * @param  bool $assocArray Returns an associative array with table name as key.
 * @return array            Array of tables infos.
 */
function db_get_tables(bool $countRows = false, bool $assocArray = false): array
{
    $tables = [];
    $rows = db_fetch_all("SELECT `TABLE_NAME` AS `t`,
                                 `COLUMN_NAME` AS `c_name`,
                                 `COLUMN_TYPE` AS `c_type`,
                                 `COLUMN_KEY` AS `c_key`,
                                 `IS_NULLABLE` AS `is_nullable`
                          FROM `INFORMATION_SCHEMA`.`COLUMNS`
                          WHERE `TABLE_SCHEMA` = :db_name
                          ORDER BY `TABLE_NAME`, `ORDINAL_POSITION`", [ 'db_name' => db_config()->db_name ]);

    foreach ($rows as $row) {
        if (!array_key_exists($row->t, $tables)) {
            $tables[$row->t] = (object) [ 'name' => $row->t, 'columns' => [] ];
            if ($countRows) {
                $tables[$row->t]->count = (int) (db_fetch_value("SELECT COUNT(*) FROM `" . db_assert_field_format($row->t) . "`") ?: 0);
            }
        }
        $tables[$row->t]->columns[] = (object) [
            'name' => $row->c_name,
            'type' => strtoupper($row->c_type) . ($row->c_key === 'PRI' ? ' PRIMARY KEY' : ''),
            'null' => strtoupper($row->is_nullable) === 'YES',
        ];
    }

    ksort($tables);
    return $assocArray ? $tables : array_values($tables);
}

/**
 * <USER>
 * Delete all tables.
 * @param  bool $dropForeignKeys First, drop all foreign keys, or not.
 */
function db_drop_all_tables(bool $dropForeignKeys = true): void
{
    if ($dropForeignKeys) db_drop_all_foreign_keys();
    foreach (db_get_tables() as $table) db_query("DROP TABLE `{$table->name}`");
}

/**
 * <USER>
 * Get all foreign keys from the database.
 */
function db_get_all_foreign_keys(): array
{
    return db_fetch_all("SELECT `TABLE_NAME` AS `table_name`, `CONSTRAINT_NAME` AS `key_name`
                         FROM `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`
                         WHERE `CONSTRAINT_SCHEMA` = :db_name
                         AND `REFERENCED_TABLE_NAME` IS NOT NULL", [ 'db_name' => db_config()->db_name ]);
}

/**
 * <USER>
 * Delete all foreign keys from the database.
 */
function db_drop_all_foreign_keys(): void
{
    foreach (db_get_all_foreign_keys() as $fk) {
        db_query("ALTER TABLE `{$fk->table_name}` DROP FOREIGN KEY `{$fk->key_name}`");
    }
}

/**
 * <USER>
 * Check if the table exists.
 * @param  string $tableName Table name.
 * @return bool              The table exists or not.
 */
function db_table_exists(string $tableName): bool
{
    return (bool) (int) db_fetch_value("SELECT COUNT(*)
        FROM `information_schema`.`tables`
        WHERE `table_schema` = DATABASE()
        AND `table_name` = :table_name", [ 'table_name' => $tableName ]);
}

/**
 * <USER>
 * Check if the column exists in given table.
 * @param  string $tableName  Table name.
 * @param  string $columnName Column name.
 * @return bool               The table AND the column exists or not.
 */
function db_column_exists(string $tableName, string $columnName): bool
{
    return (bool) (int) db_fetch_value("SELECT COUNT(*)
        FROM `information_schema`.`columns`
        WHERE `table_schema` = DATABASE()
          AND `table_name` = :table_name
          AND `column_name` = :column_name", [ 'table_name' => $tableName, 'column_name' => $columnName ]);
}

/**
 * <USER>
 * Execute a <db_query> and fetch all the rows as an object.
 * @param  string       $sql      SQL Query.
 * @param  array        $vars     Query variables.
 * @param  Closure|null $modifier An optional modifier callback, called on
 *                                each row. The modifier should take the $row
 *                                as unique argument, and return the modified
 *                                $row.
 * @return array                  The array containing the rows.
 */
function db_fetch_all(string $sql, array $vars = [], ?Closure $modifier = null): array
{
    if (!($result = db_query($sql, $vars))) return [];
    $rows = [];
    foreach ($result->fetchAll(PDO::FETCH_CLASS, 'stdClass') as $row) {
        if ($modifier) $row = call_user_func($modifier, $row);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * <USER>
 * Execute <db_fetch_all> and returns the first row.
 * Note that this function doesn't assert that the SQL query limits the result
 * to only one result, so the calling to <db_fetch_all> will be very long if
 * the SQL is not adapted.
 * @param  string       $sql      SQL Query.
 * @param  array        $vars     Query variables.
 * @param  Closure|null $modifier An optional modifier callback, called on
 *                                each row. The modifier should take the $row
 *                                as unique argument, and return the modified
 *                                $row.
 * @return object|null            The first row's object if there is one.
 *                                Else, null.
 */
function db_fetch_one(string $sql, array $vars = [], ?Closure $modifier = null): ?object
{
    foreach (db_fetch_all($sql, $vars, $modifier) as $row) return $row;
    return null;
}

/**
 * <USER>
 * Fetch the first column's value of the first row of the database query,
 * after calling <db_fetch_one>.
 * @param  string       $sql      SQL Query.
 * @param  array        $vars     Query variables.
 * @param  Closure|null $modifier An optional modifier callback, called on
 *                                each row. The modifier should take the $row
 *                                as unique argument, and return the modified
 *                                $row.
 * @return mixed                  The value, or null if no result.
 */
function db_fetch_value(string $sql, array $vars = [], ?Closure $modifier = null): mixed
{
    if (!($row = db_fetch_one($sql, $vars, $modifier))) return null;
    foreach ($row as $k => $v) return $v;
    return null;
}

/**
 * <USER>
 * Fetch the first column's value for every row returned by <db_fetch_all>.
 * @param  string       $sql      SQL Query.
 * @param  array        $vars     Query variables.
 * @param  Closure|null $modifier An optional modifier callback, called on
 *                                each row. The modifier should take the $row
 *                                as unique argument, and return the modified
 *                                $row.
 * @return array                  An array containing all the values.
 */
function db_fetch_values(string $sql, array $vars = [], ?Closure $modifier = null): array
{
    return array_map(function($row)
    {
        foreach ($row as $k => $v) return $v;
    }, db_fetch_all($sql, $vars, $modifier));
}

/**
 * <USER>
 * Returns the last insert ID.
 * @param  string $ref The database reference. Default is 'main'.
 * @return int|null    Last insert ID.
 */
function db_id(string $ref = 'main'): ?int
{
    try {
        if (!($dbh = db_dbh($ref))) return null;
        return (int) $dbh->lastInsertId();
    } catch (Exception $e) {}
    return null;
}

/**
 * <USER>
 * Generate a UID unique in the table $tableName.
 * @param  string $tableName Table name.
 * @param  string $fieldName UID field name (default 'uid').
 * @return string            Generated UID.
 */
function db_uid(string $tableName, string $fieldName = 'uid', ?Closure $generate = null): string
{
    db_assert_field_format($tableName);
    db_assert_field_format($fieldName);

    if (!$generate) $generate = function(): string { return uid(); };

    $sql = "SELECT 1 FROM `{$tableName}` WHERE `{$fieldName}` LIKE :uid";
    $uid = null;
    while ($uid === null || (int) db_fetch_value($sql, [ 'uid' => $uid ])) $uid = $generate();
    return $uid;
}

/**
 * <USER>
 * Generate a unique slug in the table $tableName. It can be based on a
 * specified string ($text) which will be sanitized and shortened, and
 * optionaly can take in account an original ID for the object which will get
 * its slug (should be provided if the slug may not change).
 * @param  string          $tableName     Table name.
 * @param  string|null     $text          Base text (default, a random SHA-1
 *                                        string).
 * @param  int|string|null $originalId    ID of the object which should take
 *                                        the slug.
 * @param  string          $slugFieldName Slug field name (default 'slug').
 * @param  string          $idFieldName   ID field name (default 'id').
 * @param  string          $where         Additional WHERE conditions.
 * @return string                         The generated slug.
 */
function db_slug(
    string              $tableName,
    ?string             $text          = null,
    int | string | null $originalId    = null,
    string              $slugFieldName = 'slug',
    string              $idFieldName   = 'id',
    ?string             $where         = null,
): string
{
    db_assert_field_format($tableName);
    db_assert_field_format($slugFieldName);
    db_assert_field_format($idFieldName);

    if ($text === null) $text = sha1(uniqid('', true));
    $slug = ($baseSlug = sanitize_string($text));

    $args = [];
    $andWhere = '';
    if ($originalId) {
        $andWhere .= " AND `{$idFieldName}` != :original_id ";
        $args['original_id'] = $originalId;
    }

    if ($where) $andWhere .= " AND ( {$where} ) ";

    $sql = "SELECT 1 FROM `{$tableName}` WHERE `{$slugFieldName}` LIKE :slug {$andWhere}";

    $idx = 0;
    while ((int) db_fetch_value($sql, array_merge($args, [ 'slug' => $slug ]))) $slug = $baseSlug . '-' . (++$idx);
    return $slug;
}

/**
 * <USER>
 * Returns a ready-to-use "Y-m-d H:i:s" now time string for database usage.
 * @return string Now's date-time.
 */
function db_now(): string
{
    return (new DateTime())->format('Y-m-d H:i:s');
}

/**
 * <USER>
 * Check if the table exists, and optionnaly if the column in this table exists.
 * @param  string      $tableName  Table name to check.
 * @param  string|null $columnName If not null, will also check if the column
 *                                 name exists in this table.
 * @return bool                    Returns true if the table and optionnaly
 *                                 the column exists.
 */
function db_field_exists(string $tableName, ?string $columnName = null): bool
{
    foreach (db_fetch_all('SHOW TABLES') as $t) {
        foreach ($t as $k => $t) { break; }
        if ($t !== $tableName) continue;
        if ($columnName === null) return true;
        foreach (db_fetch_all('SHOW COLUMNS FROM `' . $t . '`') as $c) {
            $c = $c->Field;
            if ($c === $columnName) return true;
        }
        return false;
    }
    return false;
}

/**
 * <USER>
 * Set FOREIGN_KEY_CHECKS to the specified value.
 * @param  bool $check Check or not.
 */
function db_foreign_keys_check(bool $check): void
{
    db_query('SET FOREIGN_KEY_CHECKS = ' . ($check ? '1' : '0'));
}

/**
 * <USER>
 * Build a WHERE clause based on a key/value array, where the value can be
 * represented by a scalar value which should match, or an array or an object
 * with the properties 'comparision' and 'value'. The 'comparision' should
 * be one of those tested in <db_is_valid_comparator>.
 * @param  array  $conditions Key/Value array with the conditions.
 * @return array              Numeric array with two entries: the WHERE clause
 *                            string, and the arguments which should be passed
 *                            to the query.
 */
function db_build_where(array $conditions): array
{
    $args = [];
    $conds = [];

    foreach ($conditions as $k => $v) {
        db_assert_field_format($k);

        if (is_object($v)) $v = to_array($v);
        if (!is_array($v)) $v = [ 'value' => $v ];
        $v = (object) array_merge([ 'comparision' => '=', 'value' => '' ], $v);

        db_assert_comparator($v->comparision);

        $w = "`{$k}` ";
        if ($v->value === null) {
            $w .= $v->comparision === '=' ? "IS NULL" : "IS NOT NULL";
        } else {
            $w .= $v->comparision . " :val_{$k}";
            $args["val_{$k}"] = $v->value;
        }
        $conds[] = $w;
    }

    if ($str = implode(' AND ', $conds)) $str = ' AND ' . $str;
    return [ $str, $args ];
}

// ---{ Positions/Sorting Helpers }---------------------------------------------

/**
 * <USER>
 * Get the last (maximum) position for a given rows set.
 * @param  string     $tableName          Table name.
 * @param  array      $where              Where conditions as a key/value array.
 * @param  string     $positionColumnName Column name of the position
 *                                        (default 'position').
 * @return int                            The maximum position in the set.
 */
function db_last_position(
    string $tableName,
    array  $where              = [],
    string $positionColumnName = 'position',
): int
{
    db_assert_field_format($tableName);
    db_assert_field_format($positionColumnName);

    list($whereString, $whereArgs) = db_build_where($where);

    return (int) db_fetch_value(<<<SQL
        SELECT `{$positionColumnName}`
        FROM `{$tableName}`
        WHERE 1 {$whereString}
        ORDER BY `{$positionColumnName}` DESC
        LIMIT 0, 1
        SQL, $whereArgs) ?: 0;
}

/**
 * <USER>
 * Get the last (maximum) position for a given rows set, and at 1.
 * @param  string     $tableName          Table name.
 * @param  array      $where              Where conditions as a key/value array.
 * @param  string     $positionColumnName Column name of the position
 *                                        (default 'position').
 * @return int                            The maximum position in the set, +1.
 */
function db_next_position(
    string $tableName,
    array  $where              = [],
    string $positionColumnName = 'position',
): int
{
    return db_last_position($tableName, $where, $positionColumnName) + 1;
}

/**
 * <USER>
 * Set a new position for a given element in a rows set.
 * @param string     $tableName          Table name.
 * @param int|string $itemId             ID of the item we want to move.
 * @param int|string $position           New position to set, as an index or a
 *                                       special position string:
 *                                       'first', 'last', 'up' and 'down'.
 * @param array      $where              Where conditions as a key/value array.
 * @param string     $positionColumnName Column name of the position
 *                                       (default 'position').
 * @param string     $idColumnName       Column name of the ID (default 'id').
 */
function db_set_position(
    string       $tableName,
    int | string $itemId,
    int | string $position,
    array        $where              = [],
    string       $positionColumnName = 'position',
    string       $idColumnName       = 'id',
): void
{
    db_assert_field_format($tableName);
    db_assert_field_format($positionColumnName);
    db_assert_field_format($idColumnName);

    // We retrieve the current item position.
    $currentPosition = db_fetch_value(<<<SQL
        SELECT `{$positionColumnName}`
        FROM `{$tableName}`
        WHERE `{$idColumnName}` = :id
        SQL, [ 'id' => $itemId ]);

    // We retrieve the last position of the set.
    $lastPosition = db_last_position(
        tableName:          $tableName,
        where:              $where,
        positionColumnName: $positionColumnName,
    );

    // Special positions: first, last, up and down.
         if ($position === 'first') $position = 0;
    else if ($position === 'last')  $position = $lastPosition;
    else if ($position === 'up')    $position = max(0, $currentPosition - 1);
    else if ($position === 'down')  $position = min($lastPosition, $currentPosition + 1);

    // We compute the WHERE condition for the further queries.
    list($whereString, $whereArgs) = db_build_where($where);
    $args = array_merge($whereArgs, [
        'new_position' => $position,
        'old_position' => $currentPosition,
    ]);

    // We move down the conflicted items in case of we move up
    db_query(<<<SQL
        UPDATE `{$tableName}`
        SET `{$positionColumnName}` = `{$positionColumnName}` + 1
        WHERE `{$positionColumnName}` >= :new_position AND `{$positionColumnName}` <= :old_position {$whereString}
        SQL, $args);

    // We move up the conflicted items in case of we move down
    db_query(<<<SQL
        UPDATE `{$tableName}`
        SET `{$positionColumnName}` = `{$positionColumnName}` - 1
        WHERE `{$positionColumnName}` <= :new_position AND `{$positionColumnName}` > :old_position {$whereString}
        SQL, $args);

    // We set the new position of the item
    db_query(<<<SQL
        UPDATE `{$tableName}`
        SET `{$positionColumnName}` = :new_position
        WHERE `{$idColumnName}` = :id
        SQL, [ 'new_position' => $position, 'id' => $itemId, ]);
}

/**
 * <USER>
 * Cleanup the positions for the given set.
 * @param string     $tableName          Table name.
 * @param array      $where              Where conditions as a key/value array.
 * @param string     $positionColumnName Column name of the position
 *                                       (default 'position').
 * @param string     $idColumnName       Column name of the ID (default 'id').
 */
function db_clean_positions(
    string $tableName,
    array  $where              = [],
    string $positionColumnName = 'position',
    string $idColumnName       = 'id',
): void
{
    db_assert_field_format($tableName);
    db_assert_field_format($positionColumnName);
    db_assert_field_format($idColumnName);

    // We compute the WHERE condition for the further queries.
    list($whereString, $whereArgs) = db_build_where($where);

    // Retrieve the items IDs.
    $idx = 0;
    $rows = db_fetch_all(<<<SQL
        SELECT `{$idColumnName}`
        FROM `{$tableName}`
        WHERE 1 {$whereString}
        ORDER BY `{$positionColumnName}` ASC, `{$idColumnName}` ASC
        SQL, $whereArgs);

    // Update the new positions.
    foreach ($rows as $row) {
        db_query(<<<SQL
            UPDATE `{$tableName}`
            SET `{$positionColumnName}` = :position
            WHERE `{$idColumnName}` = :id
            SQL, [
                'position' => $idx++,
                'id'       => $row->$idColumnName,
            ]);
    }
}

/**
 * <USER>
 * Search in database, in all columns of all tables (or specified with $tables).
 * @param  string     $q             Search term.
 * @param  string     $mode          Search mode: equals, like or like_jokers.
 * @param  array|null $includeTables Tables included for search.
 * @param  int        $queryOffset   Select query offset.
 * @param  int|null   $limitOffset   Select query limit (null for unlimited).
 * @return array                     Array of tables names, string columns and
 *                                   matches count.
 */
function db_search(
    string $q,
    string $mode          = 'equals',
    ?array $includeTables = null,
    int    $queryOffset   = 0,
    ?int   $queryLimit    = null,
): array
{
    $tables = [];
    foreach (db_get_tables() as $table) {
        if ($includeTables !== null && !in_array($table->name, $includeTables)) continue;

        $columns = [];

        $qb = db($table->name);
        $orX = [];
        $sqlWhere = [];
        foreach ($table->columns as $col) {
            if (!preg_match('/^VARCHAR|TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT/i', trim($col->type))) continue;
            $columns[] = $col->name;
            if ($mode === 'like') {
                $orX[] = $qb->isLike($col->name, $q);
                $sqlWhere[] = "`{$col->name}` LIKE " . db_esc($q);
            } else if ($mode === 'like_jokers') {
                $orX[] = $qb->isLike($col->name, $qj = '%' . trim($q, '%') . '%');
                $sqlWhere[] = "`{$col->name}` LIKE " . db_esc($qj);
            } else {
                $orX[] = $qb->isEqual($col->name, $q);
                $sqlWhere[] = "`{$col->name}` = " . db_esc($q);
            }
        }

        if (!$columns) continue;
        $tables[] = (object) [
            'table'   => $table->name,
            'columns' => $columns,
            'matches' => $qb->select()->where($qb->orX($orX))->count(),
            'query'   => "SELECT * FROM `{$table->name}` WHERE " . implode(' OR ', $sqlWhere)
                . ($queryLimit !== null ? " LIMIT {$queryOffset}, {$queryLimit} " : ''),
        ];
    }

    usort($tables, function(object $a, object $b): int
    {
        if ($a->matches > 0 && $b->matches === 0) return -1;
        if ($a->matches === 0 && $b->matches > 0) return 1;
        if (($at = strtolower($a->table)) < ($bt = strtolower($b->table))) return -1;
        if ($at > $bt) return 1;
        return 0;
    });

    return $tables;
}

/**
 * <USER>
 * Returns the ID of a given entity. It will not check if the $entity is the
 * proper entity, but returns an integer corresponding to what seems to be
 * the ID, or null if the $entity cannot be matched to an integer or an object
 * or a Microbe_Entity instance containing an ID.
 * @param  mixed       $entity    [description]
 * @param  string|null $className Name of the associated class. If given, the
 *                                method fetchOneMixed will be called.
 * @param  string      $propName  Property name.
 * @param  bool        $throw     Throw exception if unable to get the ID.
 * @return int|null               ID if found.
 */
function get_entity_id(mixed $entity, ?string $className = null, string $propName = 'id', bool $throw = true): ?int
{
    if (!$entity) {
        if ($throw) throw new Microbe_Exception("Entity is null.");
        return null;
    }
    if (is_int($entity)) return $entity;
    if (ctype_digit($entity)) return (int) $entity;
    if ($entity instanceof Microbe_Entity) {
        if (is_int($id = $entity->get($propName, useGetter: true)) || ctype_digit($id)) return (int) $id;
        if ($throw) throw new Microbe_Exception("Unable to get a valid ID from the Microbe_Entity instance.");
        return null;
    }
    if (is_object($entity)) {
        if (!property_exists($entity, $propName)) {
            if ($throw) throw new Microbe_Exception("Unable to get the ID property from given object.");
            return null;
        }
        if (is_int($entity->$propName) || ctype_digit($entity->$propName)) return (int) $entity->$propName;
        if ($throw) throw new Microbe_Exception("The value of the ID property of the given object is not a valid integer.");
        return null;
    }
    if (!$className) {
        if ($throw) throw new Microbe_Exception("Unable to find the entity ID without a valid class name.");
        return null;
    }
    if (!($entity = $className::fetchOneMixed($entity))) {
        if ($throw) throw new Microbe_Exception("Unable to fetch the entity with given entity ID or UID.");
        return null;
    }
    return $entity->get($propName, useGetter: true);
}

// ---{ Query Builder }---------------------------------------------------------

/**
 * <USER>
 * Returns a new Microbe_Query_Builder instance, which can be instanciated with
 * a specific table name and alias.
 * @param  string|null           $tableName Name of the first/main DB table.
 * @param  string|null           $alias     Alias of this table.
 * @return Microbe_Query_Builder            A Microbe_Query_Builder instance.
 */
function db(?string $tableName = null, ?string $alias = '_t', ?int $id = null, ?string $objectClass = null): Microbe_Query_Builder
{
    return new Microbe_Query_Builder(tableName: $tableName, alias: $alias, id: $id, objectClass: $objectClass);
}

// ---{ Class: Microbe Query Builder }---

class Microbe_Query_Builder
{

    public const GETTER_VALUE    = '{%~___O°o_^_o°O___~%}';
    public const TMP_REPLACEMENT = '{%~___U°u_^_u°U___~%}';

    private bool | array         $select        = false;
    private bool | array         $insertColumns = false;
    private bool | array         $insertRows    = false;
    private bool | array         $update        = false;
    private bool | null | string $delete        = false;

    private array                $tables        = [];
    private array                $join          = [];
    private array                $where         = [];
    private array                $group         = [];
    private array                $having        = [];
    private array                $order         = [];
    private int                  $offset        = 0;
    private ?int                 $limit         = null;
    private array                $args          = [];
    private ?string              $objectClass   = null;

    private int                  $whereArgIdx   = 0;

    public function __construct(?string $tableName = null, ?string $alias = null, ?int $id = null, ?string $objectClass = null)
    {
        if ($tableName) $this->table($tableName, $alias);
        if ($id) $this->where($this->isEqual('id', $id));
        if ($objectClass) $this->objectClass = $objectClass;
    }

    // -------------------------------------------------------------------------
    // Execution

    public function execute(): ?PDOStatement { return db_query($this->sql(), $this->args); }

    public function all(?Closure $modifier = null): array
    {
        $rows = db_fetch_all($this->sql(), $this->args, $modifier);
        if (!($objectClass = $this->objectClass)) return $rows;
        return array_map(function(object $row) use ($objectClass): mixed
        {
            return (new $objectClass())->load($row);
        }, $rows);exit;
    }

    public function one(): ?object  { foreach ($this->all() as $row) return $row; return null; }
    public function value(): mixed  { return db_fetch_value($this->sql(), $this->args); }
    public function values(?Closure $modifier = null): array { return db_fetch_values($this->sql(), $this->args, $modifier); }
    public function count(): ?int   { return (($nb = db_fetch_value($this->sql(countOnly: true), $this->args)) !== null) ? (int) $nb : null; }

    public function insert(array $data, bool $getObject = false): null | object | int
    {
        if (!$this->tables) throw new Microbe_Exception("Trying to insert data without a defined table");
        if (count($this->tables) > 1) throw new Microbe_Exception("Trying to insert data with more than one table");
        $this->tables[0]['alias'] = null;

        if (is_assoc_array($data)) $data = [ $data ];
        $this->insertColumns = [];
        $this->insertRows = [];
        foreach (array_values($data) as $idx => $row) {
            $r = [];
            foreach ($row as $k => $v) {
                if ($idx === 0) $this->insertColumns[] = $k;
                $r[] = $v;
            }
            $this->insertRows[] = $r;
        }

        $this->execute();
        $id = db_id();
        if (!$getObject) return $id;
        return ($qb = db($this->tables[0]['name'])->select('*'))->where($qb->isEqual('id', $id))->one();
    }

    // -------------------------------------------------------------------------
    // Special executions

    public function truncate(): self
    {
        foreach ($this->tables as $t) {
            db($t['name'])->delete();
            db_query("ALTER TABLE `{$t['name']}` AUTO_INCREMENT = 1");
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Actions

    public function select(string | array $expr = '*'): self
    {
        if (!is_array($expr)) $expr = [ $expr ];
        foreach ($expr as $e) {
            if (!$e) continue;
            if (!is_array($this->select)) $this->select = [];
            $this->select[] = $e;
        }
        return $this;
    }

    public function clearSelect(): self
    {
        $this->select = [];
        return $this;
    }

    public function update(array $fields): ?PDOStatement
    {
        $this->update = array_merge($this->update ?: [], $fields);
        return $this->execute();
    }

    public function delete(?string $expr = '_t'): ?PDOStatement
    {
        $this->delete = $expr;
        return $this->execute();
    }

    // -------------------------------------------------------------------------
    // Tables

    public function table(?string $tableName = null, ?string $alias = null, ?string $expr = null): self
    {
        $this->tables[] = [ 'name' => $tableName, 'alias' => $alias, 'expr' => $expr ];
        return $this;
    }

    public function clearTables(): self
    {
        $this->tables = [];
        return $this;
    }

    // -------------------------------------------------------------------------
    // Join

    public function join(string $expr, ?string $on = null, string $side = 'INNER'): self
    {
        if (!in_array($side = strtoupper($side), [ 'LEFT', 'RIGHT', 'INNER' ])) throw new Microbe_Exception("Invalid SQL join side '{$side}'.");
        $this->join[] = $side . ' JOIN ' . $expr . ($on ? ' ON ' . $on : '');
        return $this;
    }

    public function innerJoin(?string $tableName = null, ?string $alias = null, ?string $on = null, ?string $expr = null): self { return $this->join(expr: ($expr ? "({$expr})" : "`{$tableName}`") . ($alias ? " AS `{$alias}`" : ''), on: $on, side: 'INNER'); }
    public function leftJoin(?string $tableName = null,  ?string $alias = null, ?string $on = null, ?string $expr = null): self { return $this->join(expr: ($expr ? "({$expr})" : "`{$tableName}`") . ($alias ? " AS `{$alias}`" : ''), on: $on, side: 'LEFT');  }
    public function rightJoin(?string $tableName = null, ?string $alias = null, ?string $on = null, ?string $expr = null): self { return $this->join(expr: ($expr ? "({$expr})" : "`{$tableName}`") . ($alias ? " AS `{$alias}`" : ''), on: $on, side: 'RIGHT'); }

    // -------------------------------------------------------------------------
    // Where / Having

    public function getWhereArgIdx(): int
    {
        return $this->whereArgIdx;
    }

    public function setWhereArgIdx(int $value = 0): self
    {
        $this->whereArgIdx = $value;
        return $this;
    }

    public function where(null | string | array $sqlOrBindOrCmp): self
    {
        if (!$sqlOrBindOrCmp) return $this;
        $this->where[] = $sqlOrBindOrCmp;
        return $this;
    }

    public function having(string | array $sqlOrBindOrCmp): self
    {
        $this->having[] = $sqlOrBindOrCmp;
        return $this;
    }

    public function andX(array $conditions): array { return [ '@' => 'bind', '@type' => 'AND', 'conditions' => $conditions ]; }
    public function orX(array $conditions): array  { return [ '@' => 'bind', '@type' => 'OR',  'conditions' => $conditions ]; }
    public function xorX(array $conditions): array { return [ '@' => 'bind', '@type' => 'XOR', 'conditions' => $conditions ]; }

    public function isNull(string $expr): array                             { return [ '@' => 'cmp', 'sql' => "{$expr} IS NULL" ]; }
    public function isNotNull(string $expr): array                          { return [ '@' => 'cmp', 'sql' => "{$expr} IS NOT NULL" ]; }
    public function isEqual(string $expr, mixed $value): array              { return ($value === null) ? $this->isNull($expr) : [ '@' => 'cmp', 'sql' => "{$expr} = :_arg0_",  'args' => [ '_arg0_' => $value ] ]; }
    public function isNotEqual(string $expr, mixed $value): array           { return ($value === null) ? $this->isNotNull($expr) : [ '@' => 'cmp', 'sql' => "{$expr} != :_arg0_", 'args' => [ '_arg0_' => $value ] ]; }
    public function isLessThan(string $expr, mixed $value): array           { return [ '@' => 'cmp', 'sql' => "{$expr} < :_arg0_", 'args' => [ '_arg0_' => $value ] ]; }
    public function isLessOrEqualThan(string $expr, mixed $value): array    { return [ '@' => 'cmp', 'sql' => "{$expr} <= :_arg0_", 'args' => [ '_arg0_' => $value ] ]; }
    public function isGreaterThan(string $expr, mixed $value): array        { return [ '@' => 'cmp', 'sql' => "{$expr} > :_arg0_", 'args' => [ '_arg0_' => $value ] ]; }
    public function isGreaterOrEqualThan(string $expr, mixed $value): array { return [ '@' => 'cmp', 'sql' => "{$expr} >= :_arg0_", 'args' => [ '_arg0_' => $value ] ]; }
    public function isLike(string $expr, mixed $value): array               { return ($value === null) ? $this->isNull($expr) : [ '@' => 'cmp', 'sql' => "{$expr} LIKE :_arg0_",  'args' => [ '_arg0_' => $value ] ]; }
    public function isNotLike(string $expr, mixed $value): array            { return ($value === null) ? $this->isNotNull($expr) : [ '@' => 'cmp', 'sql' => "{$expr} NOT LIKE :_arg0_",  'args' => [ '_arg0_' => $value ] ]; }
    public function isTrue(string $expr): array                             { return [ '@' => 'cmp', 'sql' => "{$expr} = 1", 'args' => [] ]; }
    public function isFalse(string $expr): array                            { return [ '@' => 'cmp', 'sql' => "({$expr} IS NULL OR {$expr} = 0)", 'args' => [] ]; }
    public function isTrueOrFalse(string $expr, bool $value): array         { return $value === true ? $this->isTrue($expr) : $this->isFalse($expr); }
    public function isRaw(string $expr, array $args = []): array            { $this->args($args); return [ '@' => 'cmp', 'sql' => $expr, 'args' => [] ]; }

    public function isIn(string $expr, array $values, bool $not = false): array
    {
        $argsNames = [];
        $argsValues = [];
        foreach (array_values($values) as $idx => $value) {
            $argsNames[] = ($k = ('_arg' . $idx . '_'));
            $argsValues[$k] = $value;
        }
        return [ '@' => 'cmp', 'sql' => "{$expr} " . ($not ? 'NOT ' : '') . "IN (:" . implode(', :', $argsNames) . ")", 'args' => $argsValues ];
    }

    public function isNotIn(string $expr, array $values): array
    {
        return $this->isIn(expr: $expr, values: $values, not: true);
    }

    public function computeConditionsSql(?array $sqlOrBindOrCmp = null, string $bind = 'AND', bool $isRoot = true): string
    {
        if ($sqlOrBindOrCmp === null) $sqlOrBindOrCmp = $this->where;

        $s = self::TMP_REPLACEMENT;
        $sql = '';
        foreach ($sqlOrBindOrCmp as $item) {
            if (is_string($item)) {
                $sql .= $s . '(' . $s . $item . $s . ')' . $s;
                continue;
            }
            if ($sql) $sql .= $s . $bind . $s;
            if ($item['@'] === 'bind') {
                $sql .= $s . $this->computeConditionsSql(
                    sqlOrBindOrCmp: $item['conditions'],
                    bind:           $item['@type'],
                    isRoot:         false,
                ) . $s;
            } else if ($item['@'] === 'cmp') {
                $snippet = $item['sql'];
                foreach ($item['args'] ?? [] as $k => $v) {
                    $this->arg($argName = ('_auto_cond_' . ($this->whereArgIdx++)), $v);
                    $snippet = str_replace(':' . $k, ':' . $argName, $snippet);
                }
                $sql .= $s . '(' . $s . $snippet . $s . ')' . $s;
            }
        }
        $sql = $sql ? $s . '(' . $s . $sql . $s . ')' . $s : '';
        if (!$isRoot) return $sql;

        $sql = trim(preg_replace('/(' . preg_quote($s, '/') . ')+/', ' ', $sql));
        if ($sql) $sql = '( ' . $sql . ' )';
        while (($n = preg_replace('/\(\s*\(([^()]*)\)\s*\)/', '($1)', $sql)) !== $sql) $sql = $n;
        return $sql;
    }

    // -------------------------------------------------------------------------
    // Group by

    public function group(string $expr): self
    {
        $this->group[] = $expr;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Order by

    public function clearOrder(): self
    {
        $this->order = [];
        return $this;
    }

    public function order(string | array $expr, string $side = 'ASC'): self
    {
        if (is_array($expr)) {
            foreach ($expr as list($field, $dir)) $this->order($field, $dir);
            return $this;
        }

        $this->order[] = [ 'expr' => $expr, 'side' => strtoupper($side) === 'DESC' ? 'DESC' : 'ASC' ];
        return $this;
    }

    // -------------------------------------------------------------------------
    // Offset and limit

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function limit(?int $limit = null): self
    {
        $this->limit = $limit !== null ? max(0, $limit) : null;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Arguments

    public function args(?array $args = null): self | array
    {
        if ($args === null) return $this->args;
        foreach ($args as $k => $v) $this->arg($k, $v);
        return $this;
    }

    public function arg(string $key, mixed $value = self::GETTER_VALUE): mixed
    {
        if ($value === self::GETTER_VALUE) return $this->args[$key] ?? null;
        $this->args[$key] = $value;
        return $this;
    }

    // -------------------------------------------------------------------------
    // SQL Building

    public function sql(bool $countOnly = false): string
    {
        if ($this->select === false && $countOnly) throw new Microbe_Exception("Unable to count rows when the query is not a SELECT");

        $sql = [];

        $tables = implode(', ', array_map(function(array $t): string
        {
            return ($t['expr'] ? ('(' . $t['expr'] . ')') : ('`' . $t['name'] .'`')) . ($t['alias'] ? ' AS `' . $t['alias'] . '`' : '');
        }, $this->tables));

        if ($this->select !== false) {
            $sql[] = $countOnly ? "SELECT COUNT(*)" : ('SELECT' . ' ' . implode(', ', $this->select));
            $sql[] = 'FROM ' . $tables;
        } else if ($this->delete !== false) {
            $sql[] = 'DELETE' . ($this->delete ? ' ' . $this->delete : '');
            $sql[] = 'FROM ' . $tables;
        } else if ($this->insertColumns !== false && $this->insertRows !== false) {
            $sql[] = 'INSERT INTO ' . $tables . ' (`' . implode('`, `', $this->insertColumns) . '`) VALUES';
            $idx = 0;
            foreach ($this->insertRows as $row) {
                $rowValues = [];
                foreach ($row as $val) {
                    $this->arg($argName = ('_auto_insert_' . ($idx++)), $val);
                    $rowValues[] = ':' . $argName;
                }
                $sql[] = '(' . implode(', ', $rowValues) . ')';
            }
        } else if ($this->update !== false) {
            $sql[] = 'UPDATE ' . $tables;
            $set = [];
            $idx = 0;
            foreach ($this->update as $k => $v) {
                $this->arg($argName = ('_auto_update_' . ($idx++)), $v);
                $set[] = $k . " = :{$argName}";
            }
            $sql[] = 'SET ' . implode(', ', $set);
        }

        foreach ($this->join as $joinSql) $sql[] = $joinSql;

        if ($whereSql = $this->computeConditionsSql($this->where)) $sql[] = 'WHERE ' . $whereSql;

        if ($this->select !== false) {
            if ($this->group) $sql[] = 'GROUP BY ' . implode(', ', $this->group);
            if ($havingSql = $this->computeConditionsSql($this->having)) $sql[] = 'HAVING ' . $havingSql;
            if (!$countOnly) {
                if ($this->order) $sql[] = 'ORDER BY ' . implode(', ', array_map(function(array $o): string { return $o['expr'] . ' ' . $o['side']; }, $this->order));
                if ($this->limit !== null) {
                    $sql[] = "LIMIT {$this->offset}, {$this->limit}";
                } else if ($this->offset > 0) throw new Microbe_Exception("SQL (with MySQL compatibility) doesn't allows OFFSET without a LIMIT");
            }
        }

        return implode("\n", $sql);
    }

    public function computeSql(): string
    {
        return db_compute_sql($this->sql(), $this->args);
    }

}

// ---{ Class: Microbe Entity }---

class Microbe_Entity
{

    public const T_MIXED                   = 'mixed';
    public const T_BOOL                    = 'bool';
    public const T_INT                     = 'int';
    public const T_FLOAT                   = 'float';
    public const T_STRING                  = 'string';
    public const T_DATE                    = 'date';
    public const T_TIME                    = 'time';
    public const T_DATETIME                = 'datetime';
    public const T_COORDINATE              = 'coordinate';

    public const CRUD_METHOD_MEMORY        = 'memory';
    public const CRUD_METHOD_DB            = 'db';

    public const CRUD_METHOD               = self::CRUD_METHOD_MEMORY;
    public const TABLE_NAME                = null;
    public const AUTO_ID                   = true;
    public const FIELD_PARENT              = 'id_parent';
    public const FIELD_ID                  = 'id';
    public const FIELD_UID                 = 'uid';
    public const FIELD_POSITION            = 'position';
    public const FIELD_CREATED_AT          = 'created_at';
    public const FIELD_UPDATED_AT          = 'updated_at';
    public const FIELD_SLUG                = 'slug';
    public const SET_POSITION_ON_INSERT    = false;
    public const NOT_UPDATED_FIELDS        = [ self::FIELD_ID ];
    public const RESETED_ON_DELETE_FIELDS  = [ self::FIELD_ID, self::FIELD_UID, self::FIELD_POSITION, self::FIELD_CREATED_AT, self::FIELD_UPDATED_AT ];
    public const SLUG_FORMAT               = null;
    public const SET_SLUG_ON_CREATE        = true;
    public const SET_SLUG_ON_UPDATE        = false;
    public const DELETE_CASCADE            = false;
    public const DEFAULT_ORDER_BY          = [ [ self::FIELD_POSITION, 'ASC' ], [ self::FIELD_ID, 'ASC' ] ];
    public const CHILDREN_DEFAULT_ORDER_BY = self::DEFAULT_ORDER_BY;
    public const SHORT_UID_LENGTH          = 8;

    // ==={ Static }============================================================

    public static function initAll(): void
    {
        foreach (get_declared_classes() as $cl) {
            if (!is_subclass_of($cl, 'Microbe_Entity')) continue;
            if (method_exists($cl, 'init')) call_user_func($cl . '::init');
        }
    }

    public static function __callStatic(string $methodName, array $args)
    {
        $mode = null;
        $propertyName = null;
        $comparisionValue = null;

        if (preg_match('/^(?<action>fetchOne|fetchAll|countAll)((?<by>By)(?<property>[a-zA-Z0-9]+)?)?$/', $methodName, $m)) {
            $mode = $m['action'];
            if (array_key_exists('property', $m) && $m['property']) {
                $propertyName = snake_case($m['property']);
                $comparisionValue = $args ? array_shift($args) : null;
            } else if (array_key_exists('by', $m) && $m['by'] === 'By') {
                if (!$args) throw new Microbe_Exception("Trying to query data from a Microbe_Entity object, without giving the comparision property.");
                if (!is_string($propertyName = array_shift($args))) throw new Microbe_Exception("Trying to query data from a Microbe_Entity object, without a valid comparision property.");
                $comparisionValue = $args ? array_shift($args) : null;
            }
        }

        $modifier = $args ? array_shift($args) : null;
        if (!($modifier instanceof Closure)) $modifier = null;
        if (!$mode) throw new Microbe_Exception("The method '{$methodName}' is not a valid Microbe_Entity method.");

        return static::fetch(
            mode:             $mode,
            propertyName:     $propertyName,
            comparisionValue: $comparisionValue,
            modifier:         $modifier,
        );
    }

    public static function query(string $alias = '_t'): Microbe_Query_Builder
    {
        (new static())->assertDatabaseStored(id: false);

        return db(
            tableName:   static::TABLE_NAME,
            alias:       $alias,
            objectClass: static::class,
        );
    }

    public static function fetch(
        string   $mode,
        ?string  $propertyName     = null,
        mixed    $comparisionValue = null,
        ?Closure $modifier         = null,
    ): array | object | int | null
    {
        ($entity = new static())->assertDatabaseStored(id: false);
        $qb = static::query()->select();

        if ($propertyName) {
            if (!($prop = $entity->getRegisteredProperty($propertyName))) throw new Microbe_Exception("Trying to fetch data from a Microbe_Entity with an invalid property: '{$propertyName}'.");
            $qb->where($qb->isEqual('`_t`.`' . $prop->db_field_name . '`', $comparisionValue));
        }

        foreach (static::DEFAULT_ORDER_BY as $o) {
            if (!is_array($o)) $o = [ $o ];
            if (count($o = array_values($o)) < 2) $o[] = 'ASC';
            if (!$prop = $entity->getRegisteredProperty($o[0])) continue;
            $qb->order('`_t`.`' . $prop->db_field_name . '`', strtoupper($o[1]));
        }

        if ($modifier instanceof Closure) $modifier($qb);

        if ($mode === 'fetchAll') return $qb->all();
        if ($mode === 'fetchOne') return $qb->offset(0)->limit(1)->one();
        if ($mode === 'countAll') return $qb->count();

        throw new Microbe_Exception("Invalid mode '{$mode}' while querying a Microbe_Entity.");
    }

    public static function fetchOneMixed(mixed $query = null, int | array $uidLengths = 64): ?static
    {
        (new static())->assertDatabaseStored();

        if (!$query) return null;
        if ($query instanceof static) return $query;
        if (is_object($query)) return (new static())->load($query);
        if (is_uid($query, $uidLengths)) return static::fetchOneByUid($query);
        if (is_int_val($query)) return static::fetchOneById($query);
        return null;
    }

    public static function fetchOneByShortUid(?string $shortUid = null): ?static
    {
        ($entity = new static())->assertDatabaseStored();
        if (!($prop = $entity->getRegisteredProperty(static::FIELD_UID))) throw new Microbe_Exception("Trying to fetch a Microbe_Entity by Short UID without a valid UID property.");

        if (!$shortUid) return null;
        return self::fetchOne(function(Microbe_Query_Builder $qb) use ($prop, $shortUid): void
        {
            $qb->where($qb->isLike($prop->db_field_name, $shortUid . '%'));
        });
    }

    public static function fetchOneByFullSlug(string $slug): ?static
    {
        ($entity = new static())->assertDatabaseStored();

        if (!($parentProp = $entity->getRegisteredProperty(static::FIELD_PARENT))) throw new Microbe_Exception("Trying to fetch a Microbe_Entity by full slug without a parent field registered.");
        if (!($slugProp = $entity->getRegisteredProperty(static::FIELD_SLUG))) throw new Microbe_Exception("Trying to fetch a Microbe_Entity by full slug without a slug field registered.");
        $idProp = $entity->getRegisteredProperty(static::FIELD_ID);

        $columns = explode('/', $slug);

        $page = null;
        foreach ($columns as $col) {
            $page = static::fetchOne(function(Microbe_Query_Builder $qb) use ($page, $col, $slugProp, $idProp): void
            {
                if ($page === null) $qb->where($qb->isNull(static::FIELD_PARENT));
                else $qb->where($qb->isEqual(static::FIELD_PARENT, $page->get($idProp->name)));
                $qb->where($qb->isEqual($slugProp->name, $col));
            });
            if (!$page) return null;
        }
        return $page;
    }

    public static function getConstants(string $filter, ?Closure $func = null): array
    {
        return get_class_constants(static::class, $filter, $func);
    }

    // ==={ Entity }============================================================

    protected array $fields     = [];
    private   array $properties = [];
    protected array $extraData  = [];

    final public function __construct()
    {
        $fields = $this->fields;
        $this->fields = [];
        foreach ($fields as $field) {
            $field = (object) array_merge([
                'name'          => null,
                'type'          => self::T_MIXED,
                'db_field_name' => null,
                'default'       => null,
                'public'        => false,
                'class'         => null,
            ], is_object($field) ? to_array($field) : $field);

            if (!$field->name) throw new Microbe_Exception("Declared an unnamed Microbe Entity's field.");
            if ($field->db_field_name === null) $field->db_field_name = $field->name;

            $this->fields[$field->name] = $field;
        }

        $this->ready();
    }

    public function ready(): void {}

    public function getRegisteredProperties(): array { return $this->fields; /*static::$propertiesCollection;*/ }

    public function getRegisteredProperty(string $name): ?object { return $this->getRegisteredProperties()[$name] ?? null; }
    public function isRegisteredProperty(?string $name = null): bool { return $name && $this->getRegisteredProperty($name); }
    public function getRegisteredPropertiesNames(): array { return array_keys($this->getRegisteredProperties()); }

    public function getRegisteredPropertyByDbFieldName(string $dbFieldName): ?object
    {
        foreach (self::getRegisteredProperties() as $prop) if ($prop->db_field_name === $dbFieldName) return $prop;
        return null;
    }

    public function getProperties(): array { return array_merge(array_fill_keys($this->getRegisteredPropertiesNames(), null), $this->properties); }

    public function __call(string $methodName, array $args): mixed
    {
        if (preg_match('/^(?<action>get|set|is)(?<prop>.+)$/', $methodName, $m)) {
            $firstArg = $args[0] ?? null;;

            $prop = snake_case($m['prop']);
            if ($m['action'] === 'get' || $m['action'] === 'is') {
                return $this->get(
                    propName:      $prop,
                    useGetter:     false,
                    booleanMode:   $m['action'] === 'is',
                    valueModifier: $firstArg,
                );
            } else if ($m['action'] === 'set') {
                return $this->set($prop, $firstArg);
            }
        }

        return static::__callStatic($methodName, $args);
    }

    public function get(
        string $propName,
        bool   $useGetter     = false,
        bool   $booleanMode   = false,
        mixed  $valueModifier = null,
    ): mixed
    {
        $booleanDateMode = false;
        if ($booleanMode) {
            if ($this->getRegisteredProperty($pn = ('is_' . $propName))) {
                $propName = $pn;
            } else if ($this->getRegisteredProperty($pn = ($propName . '_at'))) {
                $propName = $pn;
                $booleanDateMode = true;
            }
        }

        $getInstance = false;
        if (!($prop = $this->getRegisteredProperty($propName))) {
            if ($prop = $this->getRegisteredProperty($idPropName = ('id_' . $propName))) {
                if ($prop->class) {
                    $getInstance = true;
                    $prop = $idPropName;
                } else throw new Microbe_Exception("The property '{$propName}' was not defined as a Microbe_Entity property (checking 'id_{$propName}' as well).");
            } else throw new Microbe_Exception("The property '{$propName}' was not defined as a Microbe_Entity property.");
        }

        if ($useGetter) return $this->{'get' . pascal_case($prop->name)}();

        $v = $this->properties[$prop->name] ?? $prop->default;

        if ($v === null) return $v;

        if ($getInstance) {
            $className = $prop->class;
            return $className::fetchOneMixed($v);
        }

             if ($prop->type === static::T_INT) $v = (int) $v;
        else if ($prop->type === static::T_FLOAT) $v = (float) $v;
        else if ($prop->type === static::T_BOOL) $v = (bool) (int) $v;
        else if ($prop->type === static::T_DATETIME || $prop->type === static::T_DATE) {
            if ($valueModifier === true || is_string($valueModifier)) {
                $dt = new DateTime($v . ($prop->type === static::T_DATE ? ' 00:00:00' : ''));
                return $valueModifier === true ? $dt : $dt->format($valueModifier);
            }
        }
        if ($booleanDateMode) $v = $v <= db_now();

        return $v;
    }

    public function set(
        string $propName,
        mixed  $value      = null,
        bool   $useSetter  = false,
        bool   $uniqueSlug = true,
    ): static
    {
        if (!($prop = $this->getRegisteredProperty($propName))) throw new Microbe_Exception("The property '{$propName}' was not defined as a Microbe_Entity property.");
        if ($useSetter) return $this->{'set' . pascal_case($prop->name)}($value);
        if ($value === null) {
            if (array_key_exists($prop->name, $this->properties)) unset($this->properties[$prop->name]);
            return $this;
        }

        if ($prop->type === static::T_BOOL) $value = value_seems_true($value);

        if ($value instanceof DateTime) {
                 if ($prop->type === static::T_DATETIME) $value = $value->format('Y-m-d H:i:s');
            else if ($prop->type === static::T_DATE) $value = $value->format('Y-m-d');
        }

        if ($uniqueSlug && $prop->name === static::FIELD_SLUG) {
            $value = db_slug(
                tableName:     static::TABLE_NAME,
                text:          $value,
                originalId:    $this->get(static::FIELD_ID),
                slugFieldName: $prop->db_field_name,
                idFieldName:   $this->getRegisteredProperty(static::FIELD_ID)->db_field_name,
            );
        }

        $this->properties[$prop->name] = $value;
        return $this;
    }

    public function getExtraData(?string $k = null): mixed
    {
        if ($k === null) return $this->extraData;
        return $this->extraData[$k] ?? null;
    }

    public function isDatabaseStored(bool $id = true): bool
    {
        return (static::CRUD_METHOD === static::CRUD_METHOD_DB)
            && static::TABLE_NAME
            && (!$id || (static::FIELD_ID && $this->isRegisteredProperty(static::FIELD_ID)));
    }

    public function assertDatabaseStored(bool $id = true): void
    {
        if ($this->isDatabaseStored(id: $id)) return;
        throw new Microbe_Exception("Trying to make a database action on a Microbe_Entity object not registered for database usage.");
    }

    public function load(string | int | object | null $obj = null): ?static
    {
        $this->assertDatabaseStored(id: $obj === null || !is_object($obj));

        if ($obj === null) $obj = $this->get(static::FIELD_ID);
        if ($obj === null) throw new Microbe_Exception("Trying to load a Microbe_Entity instance from database without a valid ID.");

        $data = is_object($obj) ? $obj : (($qb = db(static::TABLE_NAME))
            ->select('*')
            ->where($qb->isEqual($this->getRegisteredProperty(static::FIELD_ID)->db_field_name, $obj))
            ->one());

        if (!$data) return null;
        foreach ($data as $k => $v) {
            if ($prop = $this->getRegisteredPropertyByDbFieldName($k)) {
                $this->set($prop->name, $v, useSetter: true);
            } else {
                $this->extraData[$k] = $v;
            }
        }

        return $this;
    }

    public function save(string $method = 'auto', ?bool $updateSlug = null): static
    {
        $this->assertDatabaseStored(id: $method !== 'insert');

        $idProp = $this->getRegisteredProperty(static::FIELD_ID);
        $slugProp = $this->getRegisteredProperty(static::FIELD_SLUG);

        $id = $idProp ? $this->get(static::FIELD_ID) : null;
        if ($method === 'auto') $method = $id === null ? 'insert' : 'update';

        if ($slugProp) {
            if ($method === 'insert') { if ($updateSlug || ($updateSlug === null && static::SET_SLUG_ON_CREATE)) $this->updateSlug(force: false); }
            else if ($updateSlug || ($updateSlug === null && static::SET_SLUG_ON_UPDATE)) $this->updateSlug(force: false);
        }

        $data = [];
        foreach ($this->getRegisteredProperties() as $prop) {
            if (in_array($prop->name, static::NOT_UPDATED_FIELDS)) continue;
            $data[$prop->db_field_name] = $this->get($prop->name, useGetter: true);
        }

        $now = db_now();
        if ($prop = $this->getRegisteredProperty(static::FIELD_UPDATED_AT)) {
            $data[$prop->db_field_name] = $now;
            $this->set(static::FIELD_UPDATED_AT, $data[$prop->db_field_name]);
        }

        $qb = db(static::TABLE_NAME);

        if ($method === 'insert') {

            if ($prop = $this->getRegisteredProperty(static::FIELD_UID)) {
                $data[$prop->db_field_name] = db_uid(static::TABLE_NAME);
                $this->set(static::FIELD_UID, $data[$prop->db_field_name]);
            }

            if ($prop = $this->getRegisteredProperty(static::FIELD_CREATED_AT)) {
                $data[$prop->db_field_name] = $now;
                $this->set(static::FIELD_CREATED_AT, $data[$prop->db_field_name]);
            }

            if (static::SET_POSITION_ON_INSERT && ($prop = $this->getRegisteredProperty(static::FIELD_POSITION))) {
                $data[$prop->db_field_name] = db_last_position(
                    tableName:          static::TABLE_NAME,
                    positionColumnName: static::FIELD_POSITION,
                    where:              ($parentProp = $this->getRegisteredProperty(static::FIELD_PARENT))
                                      ? [ $parentProp->db_field_name => $this->get(static::FIELD_PARENT) ]
                                      : [],
                ) + 1;
            }

            $insertedId = $qb->insert($data);

            if (static::AUTO_ID && $idProp) $this->set(static::FIELD_ID, $insertedId);

        } else {

            $qb
                ->where($qb->isEqual($this->getRegisteredProperty(static::FIELD_ID)->db_field_name, $id))
                ->update($data);

        }

        return ($idProp && (($id = $this->get(static::FIELD_ID)) !== null)) ? (new static())->load($id) : $this;
    }

    public function delete(): static
    {
        $this->assertDatabaseStored();

        if (static::DELETE_CASCADE) {
            foreach ($this->getChildren() as $child) $child->delete();
        }

        ($qb = db(static::TABLE_NAME))
            ->where($qb->isEqual($this->getRegisteredProperty(static::FIELD_ID)->db_field_name, $this->get(static::FIELD_ID)))
            ->delete();

        foreach (static::RESETED_ON_DELETE_FIELDS as $fieldName) {
            if ($this->isRegisteredProperty($fieldName)) $this->set($fieldName, null);
        }

        return $this;
    }

    public function generateSlug(): ?string
    {
        $this->assertDatabaseStored();

        if (!static::SLUG_FORMAT) return null;
        if (!($slugProp = $this->getRegisteredProperty(static::FIELD_SLUG))) return null;

        if (preg_match_all('/\{(?<k>[a-z0-9_]+)\}/i', $str = static::SLUG_FORMAT, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $v = null;
                if ($this->isRegisteredProperty($m['k'])) {
                    $v = $this->get($m['k']);
                } else if (method_exists($this, $methodName = 'get' . pascal_case($m['k']))) {
                    $v = $this->$methodName();
                } else {
                    throw new Microbe_Exception("The slug format contains some field missing in the Microbe_Entity object.");
                }
                $str = str_replace($m[0], $v, $str);
            }
        }

        return db_slug(
            tableName:     static::TABLE_NAME,
            text:          $str,
            originalId:    $this->get(static::FIELD_ID),
            slugFieldName: $slugProp->db_field_name,
            idFieldName:   $this->getRegisteredProperty(static::FIELD_ID)->db_field_name,
        );
    }

    public function updateSlug(bool $force = true): static
    {
        $this->assertDatabaseStored();

        if (!static::SLUG_FORMAT) return $this;
        if (!($slugProp = $this->getRegisteredProperty(static::FIELD_SLUG))) return $this;
        if ($this->get(static::FIELD_SLUG) && !$force) return $this;

        if ($slug = $this->generateSlug()) $this->set(static::FIELD_SLUG, $slug, useSetter: true);
        return $this;
    }

    public function getAncestors(bool $asc = true, ?Closure $filter = null): array
    {
        $this->assertDatabaseStored();

        if (!($prop = $this->getRegisteredProperty(static::FIELD_PARENT))) {
            throw new Microbe_Exception("Trying to get ancestors on a Microbe_Entity which is missing a valid parent property.");
        }

        $entity = $this;
        $ancestors = [ clone $entity ];
        while ($idParent = $entity->get($prop->name)) {
            if (!($entity =  static::fetchOneBy(static::FIELD_ID, $idParent))) throw new Microbe_Exception("The ancestors branch seems broken.");
            if ($filter && !$filter($entity)) continue;
            $ancestors[] = clone $entity;
        }

        return $asc ? array_reverse($ancestors) : $ancestors;
    }

    public function hasAncestor(Microbe_Entity $ancestor): bool
    {
        $this->assertDatabaseStored();

        $id = $ancestor->get(static::FIELD_ID);
        foreach ($this->getAncestors() as $a) if ($id === $a->get(static::FIELD_ID)) return true;
        return false;
    }

    public function getFullSlug(bool $asString = true): ?string
    {
        $this->assertDatabaseStored();

        $slugs = [];
        foreach ($this->getAncestors() as $ancestor) $slugs[] = $ancestor->getSlug();
        return $asString ? implode('/', $slugs) : $slugs;
    }

    public function getShortUid(?int $length = null): string
    {
        if (!$this->isRegisteredProperty(static::FIELD_UID)) throw new Microbe_Exception("Trying to get a short UID on a Microbe_Entity without a valid UID property.");
        return short_uid($this->get(static::FIELD_UID), $length ?: self::SHORT_UID_LENGTH);
    }

    public function hasBeenUpdated(): bool
    {
        if (!($createdAtProp = $this->getRegisteredProperty(static::FIELD_CREATED_AT))) throw new Microbe_Exception("Trying to check if the user has been updated without a valid created_at property.");
        if (!($updatedAtProp = $this->getRegisteredProperty(static::FIELD_UPDATED_AT))) throw new Microbe_Exception("Trying to check if the user has been updated without a valid updated_at property.");
        $createdAt = $this->get($createdAtProp->name);
        $updatedAt = $this->get($updatedAtProp->name);
        return $createdAt !== null && $updatedAt !== null && $createdAt !== $updatedAt;
    }

    public function toPublic(?Closure $modifier = null): array
    {
        $data = [];
        foreach ($this->getRegisteredProperties() as $prop) {
            if (!$prop->public) continue;
            $data[$prop->name] = $this->get($prop->name);
        }
        if ($modifier) $data = $modifier($this, $data);
        return $data;
    }

    public function createChild(): static
    {
        $this->assertDatabaseStored();

        if (!$this->isRegisteredProperty(static::FIELD_PARENT)) throw new Microbe_Exception("Trying to create a child on a Microbe_Entity without a valid parent property.");
        if (($id = $this->get(static::FIELD_ID)) === null) throw new Microbe_Exception("Trying to create a Microbe_Entity child on a parent with a missing ID.");

        $child = new static();
        $child->set(static::FIELD_PARENT, $id);
        return $child;
    }

    public function getChildren(?array $orderBy = null, ?Closure $query = null): array
    {
        $this->assertDatabaseStored();

        if (!($parentProp = $this->getRegisteredProperty(static::FIELD_PARENT))) throw new Microbe_Exception("Trying to fetch children of a Microbe_Entity which doesn't have a valid parent field.");
        if (!($thisId = $this->get(static::FIELD_ID))) throw new Microbe_Exception("Trying to fetch children of a Microbe_Entity which is missing an ID.");

        ($qb = db(static::TABLE_NAME))
            ->select()
            ->where($qb->isEqual($parentProp->db_field_name, $thisId));

        foreach ($orderBy ?: static::CHILDREN_DEFAULT_ORDER_BY as $o) {
            if (!is_array($o)) $o = [ $o ];
            if (count($o = array_values($o)) < 2) $o[] = 'ASC';
            if (!$prop = $this->getRegisteredProperty($o[0])) continue;
            $qb->order($prop->db_field_name, strtoupper($o[1]));
        }

        if ($query) $query($qb);

        $children = [];
        foreach ($qb->values() as $childRow) $children[] = (new static())->load($childRow);
        return $children;
    }

    public function moveTo(string | int $position, ?array $where = null): static
    {
        $this->assertDatabaseStored();

        if (!($positionProp = $this->getRegisteredProperty(static::FIELD_POSITION))) throw new Microbe_Exception("Trying to move a Microbe_Entity without a position field registered.");
        $idProp = $this->getRegisteredProperty(static::FIELD_ID);

        if ($where === null) {
            $where = ($parentProp = $this->getRegisteredProperty(static::FIELD_PARENT))
                   ? [ $parentProp->db_field_name => $this->get(static::FIELD_PARENT) ]
                   : [];
        }

        if ($position === 'first' || $position === 'top') $position = 0;
        else if ($position === 'last' || $position === 'bottom') $position = db_last_position(static::TABLE_NAME, $where, $positionProp->db_field_name) + 1;
        else if (is_int_val($position)) $position = (int) $position;
        else $position = (((int) $this->get(static::FIELD_POSITION)) ?: 0) + ((int) $position);

        db_set_position(
            tableName:          static::TABLE_NAME,
            itemId:             $this->get(static::FIELD_ID),
            position:           $position,
            where:              $where,
            positionColumnName: $positionProp->db_field_name,
            idColumnName:       $idProp->db_field_name,
        );

        $this->set(static::FIELD_POSITION, $position);

        db_clean_positions(
            tableName:          static::TABLE_NAME,
            where:              $where,
            positionColumnName: $positionProp->db_field_name,
            idColumnName:       $idProp->db_field_name,
        );

        return $this;
    }

}

// ---{ Migrations }------------------------------------------------------------

/**
 * Returns an array containing the migrations files informations.
 * @return array List of available migrations (up and down).
 */
function db_migrations(): array
{
    $all = [];

    foreach (get_bundles() as $bundle) {
        foreach ($bundle->migrations as $migration) {
            $all[] = db_migration($migration->name);
        }
    }

    usort($all, function(object $a, object $b): int
    {
        if ($a->name < $b->name) return -1;
        if ($a->name > $b->name) return 1;
        return 0;
    });

    $sortDependencies = function($items) use (&$sortDependencies) {
        $res = [];
        $done = [];
        while (count($items) > count($res)) {
            $doneSomething = false;
            foreach ($items as $item) {
                if (array_key_exists($item->name, $done)) continue; // Item is already in the result set
                $resolved = true;
                if (isset($item->dependencies)) {
                    foreach ($item->dependencies as $dep) {
                        if (!isset($done[$dep])) {
                            // There is an unmet dependency
                            $resolved = false;
                            break;
                        }
                    }
                }
                if ($resolved) {
                    // All dependencies are met
                    $done[$item->name] = true;
                    $res[] = $item;
                    $doneSomething = true;
                }
            }
            if (!$doneSomething) throw new Microbe_Exception("Migration dependency not found: ");
        }
        return $res;
    };

    return $sortDependencies($all);
}

/**
 * Returns a database migration description, with parsed queries.
 * @param  string      $name Name of the migration.
 * @return object|null       Object representing the migration.
 */
function db_migration(string $name): ?object
{
    if (!preg_match('/^@(?<bundle>[a-z0-9_.-]+)\/(?<migration>[a-z0-9_.-]+)$/i', $name, $match)) return null;

    $m = (object) [
        'name'         => $name,
        'bundle'       => $match['bundle'],
        'migration'    => $match['migration'],
        'comments'     => [],
        'dependencies' => [],
        'check'        => (object) [ 'path' => null, 'code' => null, 'checked' => null ],
        'files'        => (object) [ 'up' => null, 'down' => null ],
    ];

    if (is_file($fCheck = join_path(get_bundle($m->bundle)->dir, 'migrations', $m->migration . '-check.php'))) {
        $m->check->path = $fCheck;
        $m->check->code = trim(preg_replace('/<\?php[^\n]*\n/', '', file_get_contents($fCheck) ?: ''));
        $m->check->checked = include $fCheck;
    }

    foreach ($m->files as $side => &$file) {
        $path = null;
        $sql = '';

        if (is_file($f = join_path(get_bundle($m->bundle)->dir, 'migrations', $m->migration . '-' . $side . '.sql'))) {
            $sql = file_get_contents($path = $f);
        }

        if (preg_match_all('/^\s*--\s*\$comment\s*:\s*(?<comment>[^\r\n]+)\s*$/imsU', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($comment = trim($match['comment'])) $m->comments[] = $comment;
                $sql = str_replace($match[0], '', $sql);
            }
        }

        if (preg_match_all('/^\s*--\s*\$dependency\s*:\s*(?<name>@[a-z0-9_.-]+\/[a-z0-9_.-]+)\s*$/imsU', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!in_array($match['name'], $m->dependencies)) $m->dependencies[] = $match['name'];
                $sql = str_replace($match[0], '', $sql);
            }
        }

        $file = $sql ? (object) [
            'path'    => $path,
            'queries' => db_split_queries($sql),
        ] : null;
    }

    return $m;
}

/**
 * <USER>
 * Split queries separated with semicolons.
 * @return array Array containing queries.
 */
function db_split_queries(string $sql): array
{
    return array_values(array_filter(array_map(function(string $line): string
    {
        return trim($line);
    }, preg_split('~\([^)]*\)(*SKIP)(*F)|;~', $sql))));
}

/**
 * Execute a migration.
 * @param  string $name       Name of the migration
 * @param  string $side       Migration direction: 'up' or 'down'.
 * @param  bool   $standalone Execute only this migration if true. If false,
 *                            all the missing previous migrations will
 *                            be also run.
 */
function db_run_migration(string $name, string $side = 'up', bool $standalone = true): void
{
    if (!($migration = db_migration($name))) return;
    if (!in_array($side, [ 'up', 'down' ])) throw new Microbe_Exception("Invalid migration direction {$side}");

    if ($standalone) {
        if (!$migration->files->$side) return;
        db_foreign_keys_check(false);
        foreach ($migration->files->$side->queries as $sql) db_query($sql);
        db_foreign_keys_check(true);
        return;
    }

    $migrations = db_migrations();
    if ($side === 'down') $migrations = array_reverse($migrations);
    $current = db_current_migration();
    $started = $current === null;

    foreach ($migrations as $idx => $m) {
        if ($side === 'down' && $m->name === $current) $started = true;
        if ($started) {
            db_run_migration($m->name, $side);
                 if ($side === 'up')   db_current_migration($m->name);
            else if ($side === 'down') db_current_migration(array_key_exists($idx + 1, $migrations) ? $migrations[$idx + 1]->name : false);
            if ($m->name === $name) break;
            continue;
        }
        if ($side === 'up' && $m->name === $current) $started = true;
    }
}

/**
 * Get/Set the current migration name.
 * @param  string|bool|null $name  Optional name of the migration. If false,
 *                                 the current migration will be reset to none.
 * @return string|null             If the $name is null, the current migration
 *                                 will be returned. If a $name is provided, the
 *                                 current migration will be set to it and the
 *                                 function will return null.
 */
function db_current_migration(string | bool | null $name = null): ?string
{
    $k = 'core.migration';
    if ($name === null) return db_cfg($k);
    if ($name === false) {
        db_cfg_delete($k);
        return null;
    }
    db_cfg($k, $name);
    return null;
}

// ---{ Database and User creation }--------------------------------------------

/**
 * Setup the database configuration for creating other
 * MySQL databases and/or users.
 * @param  string $rootUser     The privileged-user's name.
 * @param  string $rootPassword The privileged-user's password.
 * @param  string $ref          The database reference. Default is 'setupdb'.
 * @return array An array containing the configuration object,
 *               the reference name, and the PDO instance.
 */
function db_create_db_or_user_setup(string $rootUser, string $rootPassword, string $ref = 'setupdb'): array
{
    return [ $cfg = db_config(), $ref, db_setup($ref, (object) [
        'host'     => $cfg->host,
        'port'     => $cfg->port,
        'username' => $rootUser,
        'password' => $rootPassword,
        'db_name'  => 'information_schema',
    ]) ];
}

/**
 * Try to create the MySQL user.
 * @param  string $rootUser     The privileged-user's name.
 * @param  string $rootPassword The privileged-user's password.
 */
function db_create_user(string $rootUser, string $rootPassword): void
{
    list($cfg, $ref, $dbh) = db_create_db_or_user_setup($rootUser, $rootPassword);
    db_assert_field_format($cfg->db_name);

    db_query("CREATE USER :username@:host IDENTIFIED BY :password", [
        'username' => $cfg->username,
        'host'     => $cfg->host,
        'password' => $cfg->password,
    ], ref: $ref);

    db_query("GRANT ALL PRIVILEGES ON `{$cfg->db_name}`.* TO :username@:host;", [
        'username' => $cfg->username,
        'host'     => $cfg->host,
    ], ref: $ref);

    db_query("FLUSH PRIVILEGES", ref: $ref);
}

/**
 * Try to create the MySQL database.
 */
function db_create_db(string $rootUser, string $rootPassword): void
{
    list($cfg, $ref, $dbh) = db_create_db_or_user_setup($rootUser, $rootPassword);
    db_assert_field_format($cfg->db_name);
    db_query("CREATE DATABASE `{$cfg->db_name}`", ref: $ref);
    db_query("FLUSH PRIVILEGES", ref: $ref);
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'db' => [
            'dev' => [
                'host'     => 'localhost',
                'port'     => 3306,
                'username' => $w = substr(strtolower(preg_replace('/[0-9_\/]+/', '', base64_encode(sha1(__FILE__)))), 0, 9),
                'password' => 'dfgdfg',
                'db_name'  => $w,
            ],
            'prod' => [
                'host'     => 'localhost',
                'port'     => 3306,
                'username' => null,
                'password' => null,
                'db_name'  => null,
            ],
        ],
    ];
});

// =============================================================================
