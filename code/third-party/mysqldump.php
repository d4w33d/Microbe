<?php
/**
 * -------------------------------------------------------------------------------------
 * | Microbe sincerely thanks Ifsnop's Mysqldump authors / 2024-10-23T13:14:43+00:00   |
 * | Scraped manually and tinkered without any refinement, based on the following URL: |
 * |   - https://github.com/ifsnop/mysqldump-php                                       |
 * -------------------------------------------------------------------------------------
 * PHP version of mysqldump cli that comes with MySQL.
 *
 * Tags: mysql mysqldump pdo php7 php5 database php sql hhvm mariadb mysql-backup.
 *
 * @category Library
 * @package  Ifsnop\Mysqldump
 * @author   Diego Torres <ifsnop@github.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://github.com/ifsnop/mysqldump-php
 *
 */
class Mysqldump
{
    const MAXLINESIZE = 1000000;
    const GZIP  = 'Gzip';
    const BZIP2 = 'Bzip2';
    const NONE  = 'None';
    const GZIPSTREAM = 'Gzipstream';
    const UTF8    = 'utf8';
    const UTF8MB4 = 'utf8mb4';
    const BINARY = 'binary';
    public $user;
    public $pass;
    public $dsn;
    public $fileName = 'php://stdout';
    private $tables = [];
    private $views = [];
    private $triggers = [];
    private $procedures = [];
    private $functions = [];
    private $events = [];
    protected $dbHandler = null;
    private $dbType = "";
    private $compressManager;
    private $typeAdapter;
    protected $dumpSettings = [];
    protected $pdoSettings = [];
    private $version;
    private $tableColumnTypes = [];
    private $transformTableRowCallable;
    private $transformColumnValueCallable;
    private $infoCallable;
    private $dbName;
    private $host;
    private $dsnArray = [];
    private $tableWheres = [];
    private $tableLimits = [];
    protected $dumpSettingsDefault = [
        'include-tables' => [],
        'exclude-tables' => [],
        'include-views' => [],
        'compress' => Mysqldump::NONE,
        'init_commands' => [],
        'no-data' => [],
        'if-not-exists' => false,
        'reset-auto-increment' => false,
        'add-drop-database' => false,
        'add-drop-table' => false,
        'add-drop-trigger' => true,
        'add-locks' => true,
        'complete-insert' => false,
        'databases' => false,
        'default-character-set' => Mysqldump::UTF8,
        'disable-keys' => true,
        'extended-insert' => true,
        'events' => false,
        'hex-blob' => true,
        'insert-ignore' => false,
        'net_buffer_length' => self::MAXLINESIZE,
        'no-autocommit' => true,
        'no-create-db' => false,
        'no-create-info' => false,
        'lock-tables' => true,
        'routines' => false,
        'single-transaction' => true,
        'skip-triggers' => false,
        'skip-tz-utc' => false,
        'skip-comments' => false,
        'skip-dump-date' => false,
        'skip-definer' => false,
        'where' => '',
        'disable-foreign-keys-check' => true
    ];
    protected $pdoSettingsDefault = [ PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ];
    public function __construct(
        $dsn = '',
        $user = '',
        $pass = '',
        $dumpSettings = [],
        $pdoSettings = []
    ) {
        $this->user = $user;
        $this->pass = $pass;
        $this->parseDsn($dsn);
        if ("mysql" === $this->dbType) {
            $this->pdoSettingsDefault[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }
        $this->pdoSettings = array_replace_recursive($this->pdoSettingsDefault, $pdoSettings);
        $this->dumpSettings = array_replace_recursive($this->dumpSettingsDefault, $dumpSettings);
        $this->dumpSettings['init_commands'][] = "SET NAMES ".$this->dumpSettings['default-character-set'];
        if (false === $this->dumpSettings['skip-tz-utc']) {
            $this->dumpSettings['init_commands'][] = "SET TIME_ZONE='+00:00'";
        }
        $diff = array_diff(array_keys($this->dumpSettings), array_keys($this->dumpSettingsDefault));
        if (count($diff) > 0) {
            throw new Exception("Unexpected value in dumpSettings: (".implode(",", $diff).")");
        }
        if (!is_array($this->dumpSettings['include-tables']) ||
            !is_array($this->dumpSettings['exclude-tables'])) {
            throw new Exception("Include-tables and exclude-tables should be arrays");
        }
        if (!isset($dumpSettings['include-views'])) {
            $this->dumpSettings['include-views'] = $this->dumpSettings['include-tables'];
        }
        $this->compressManager = CompressManagerFactory::create($this->dumpSettings['compress']);
    }
    public function __destruct()
    {
        $this->dbHandler = null;
    }
    public function setTableWheres(array $tableWheres)
    {
        $this->tableWheres = $tableWheres;
    }
    public function getTableWhere($tableName)
    {
        if (!empty($this->tableWheres[$tableName])) {
            return $this->tableWheres[$tableName];
        } elseif ($this->dumpSettings['where']) {
            return $this->dumpSettings['where'];
        }
        return false;
    }
    public function setTableLimits(array $tableLimits)
    {
        $this->tableLimits = $tableLimits;
    }
    public function getTableLimit($tableName)
    {
        if (!isset($this->tableLimits[$tableName])) {
            return false;
        }
        $limit = $this->tableLimits[$tableName];
        if (!is_numeric($limit)) {
            return false;
        }
        return $limit;
    }
    public function restore($path)
    {
        if(!$path || !is_file($path)){
            throw new Exception("File {$path} does not exist.");
        }
        $handle = fopen($path , 'rb');
        if(!$handle){
            throw new Exception("Failed reading file {$path}. Check access permissions.");
        }
        if(!$this->dbHandler){
            $this->connect();
        }
        $buffer = '';
        while ( !feof($handle) ) {
            $line = fgets($handle);
            if (substr($line, 0, 2) == '--' || !$line) {
                continue; // skip comments
            }
            $buffer .= $line;
            if (';' == substr(rtrim($line), -1, 1)) {
                $this->dbHandler->exec($buffer);
                $buffer = '';
            }
        }
        fclose($handle);
    }
    private function parseDsn($dsn)
    {
        if (empty($dsn) || (false === ($pos = strpos($dsn, ":")))) {
            throw new Exception("Empty DSN string");
        }
        $this->dsn = $dsn;
        $this->dbType = strtolower(substr($dsn, 0, $pos)); // always returns a string
        if (empty($this->dbType)) {
            throw new Exception("Missing database type from DSN string");
        }
        $dsn = substr($dsn, $pos + 1);
        foreach (explode(";", $dsn) as $kvp) {
            $kvpArr = explode("=", $kvp);
            $this->dsnArray[strtolower($kvpArr[0])] = $kvpArr[1];
        }
        if (empty($this->dsnArray['host']) &&
            empty($this->dsnArray['unix_socket'])) {
            throw new Exception("Missing host from DSN string");
        }
        $this->host = (!empty($this->dsnArray['host'])) ?
            $this->dsnArray['host'] : $this->dsnArray['unix_socket'];
        if (empty($this->dsnArray['dbname'])) {
            throw new Exception("Missing database name from DSN string");
        }
        $this->dbName = $this->dsnArray['dbname'];
        return true;
    }
    protected function connect()
    {
        try {
            switch ($this->dbType) {
                case 'sqlite':
                    $this->dbHandler = @new PDO("sqlite:".$this->dbName, null, null, $this->pdoSettings);
                    break;
                case 'mysql':
                case 'pgsql':
                case 'dblib':
                    $this->dbHandler = @new PDO(
                        $this->dsn,
                        $this->user,
                        $this->pass,
                        $this->pdoSettings
                    );
                    foreach ($this->dumpSettings['init_commands'] as $stmt) {
                        $this->dbHandler->exec($stmt);
                    }
                    $this->version = $this->dbHandler->getAttribute(PDO::ATTR_SERVER_VERSION);
                    break;
                default:
                    throw new Exception("Unsupported database type (".$this->dbType.")");
            }
        } catch (PDOException $e) {
            throw new Exception(
                "Connection to ".$this->dbType." failed with message: ".
                $e->getMessage()
            );
        }
        if (is_null($this->dbHandler)) {
            throw new Exception("Connection to ".$this->dbType."failed");
        }
        $this->dbHandler->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        $this->typeAdapter = TypeAdapterFactory::create($this->dbType, $this->dbHandler, $this->dumpSettings);
    }
    public function start($filename = '')
    {
        if (!empty($filename)) {
            $this->fileName = $filename;
        }
        $this->connect();
        $this->compressManager->open($this->fileName);
        $this->compressManager->write($this->getDumpFileHeader());
        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->exec($this->typeAdapter->setup_transaction());
            $this->dbHandler->exec($this->typeAdapter->start_transaction());
        }
        $this->compressManager->write(
            $this->typeAdapter->backup_parameters()
        );
        if ($this->dumpSettings['databases']) {
            $this->compressManager->write(
                $this->typeAdapter->getDatabaseHeader($this->dbName)
            );
            if ($this->dumpSettings['add-drop-database']) {
                $this->compressManager->write(
                    $this->typeAdapter->add_drop_database($this->dbName)
                );
            }
        }
        $this->getDatabaseStructureTables();
        $this->getDatabaseStructureViews();
        $this->getDatabaseStructureTriggers();
        $this->getDatabaseStructureProcedures();
        $this->getDatabaseStructureFunctions();
        $this->getDatabaseStructureEvents();
        if ($this->dumpSettings['databases']) {
            $this->compressManager->write(
                $this->typeAdapter->databases($this->dbName)
            );
        }
        if (0 < count($this->dumpSettings['include-tables'])) {
            $name = implode(",", $this->dumpSettings['include-tables']);
            throw new Exception("Table (".$name.") not found in database");
        }
        $this->exportTables();
        $this->exportTriggers();
        $this->exportFunctions();
        $this->exportProcedures();
        $this->exportViews();
        $this->exportEvents();
        $this->compressManager->write(
            $this->typeAdapter->restore_parameters()
        );
        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->exec($this->typeAdapter->commit_transaction());
        }
        $this->compressManager->write($this->getDumpFileFooter());
        $this->compressManager->close();
        return;
    }
    private function getDumpFileHeader()
    {
        $header = '';
        if (!$this->dumpSettings['skip-comments']) {
            $header = "-- mysqldump-php https://github.com/ifsnop/mysqldump-php".PHP_EOL.
                    "--".PHP_EOL.
                    "-- Host: {$this->host}\tDatabase: {$this->dbName}".PHP_EOL.
                    "-- ------------------------------------------------------".PHP_EOL;
            if (!empty($this->version)) {
                $header .= "-- Server version \t".$this->version.PHP_EOL;
            }
            if (!$this->dumpSettings['skip-dump-date']) {
                $header .= "-- Date: ".date('r').PHP_EOL.PHP_EOL;
            }
        }
        return $header;
    }
    private function getDumpFileFooter()
    {
        $footer = '';
        if (!$this->dumpSettings['skip-comments']) {
            $footer .= '-- Dump completed';
            if (!$this->dumpSettings['skip-dump-date']) {
                $footer .= ' on: '.date('r');
            }
            $footer .= PHP_EOL;
        }
        return $footer;
    }
    private function getDatabaseStructureTables()
    {
        if (empty($this->dumpSettings['include-tables'])) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->dbName)) as $row) {
                array_push($this->tables, current($row));
            }
        } else {
            foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->dbName)) as $row) {
                if (in_array(current($row), $this->dumpSettings['include-tables'], true)) {
                    array_push($this->tables, current($row));
                    $elem = array_search(
                        current($row),
                        $this->dumpSettings['include-tables']
                    );
                    unset($this->dumpSettings['include-tables'][$elem]);
                }
            }
        }
        return;
    }
    private function getDatabaseStructureViews()
    {
        if (empty($this->dumpSettings['include-views'])) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->dbName)) as $row) {
                array_push($this->views, current($row));
            }
        } else {
            foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->dbName)) as $row) {
                if (in_array(current($row), $this->dumpSettings['include-views'], true)) {
                    array_push($this->views, current($row));
                    $elem = array_search(
                        current($row),
                        $this->dumpSettings['include-views']
                    );
                    unset($this->dumpSettings['include-views'][$elem]);
                }
            }
        }
        return;
    }
    private function getDatabaseStructureTriggers()
    {
        if (false === $this->dumpSettings['skip-triggers']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_triggers($this->dbName)) as $row) {
                array_push($this->triggers, $row['Trigger']);
            }
        }
        return;
    }
    private function getDatabaseStructureProcedures()
    {
        if ($this->dumpSettings['routines']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_procedures($this->dbName)) as $row) {
                array_push($this->procedures, $row['procedure_name']);
            }
        }
        return;
    }
    private function getDatabaseStructureFunctions()
    {
        if ($this->dumpSettings['routines']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_functions($this->dbName)) as $row) {
                array_push($this->functions, $row['function_name']);
            }
        }
        return;
    }
    private function getDatabaseStructureEvents()
    {
        if ($this->dumpSettings['events']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_events($this->dbName)) as $row) {
                array_push($this->events, $row['event_name']);
            }
        }
        return;
    }
    private function matches($table, $arr)
    {
        $match = false;
        foreach ($arr as $pattern) {
            if ('/' != $pattern[0]) {
                continue;
            }
            if (1 == preg_match($pattern, $table)) {
                $match = true;
            }
        }
        return in_array($table, $arr) || $match;
    }
    private function exportTables()
    {
        foreach ($this->tables as $table) {
            if ($this->matches($table, $this->dumpSettings['exclude-tables'])) {
                continue;
            }
            $this->getTableStructure($table);
            if (false === $this->dumpSettings['no-data']) { // don't break compatibility with old trigger
                $this->listValues($table);
            } elseif (true === $this->dumpSettings['no-data']
                 || $this->matches($table, $this->dumpSettings['no-data'])) {
                continue;
            } else {
                $this->listValues($table);
            }
        }
    }
    private function exportViews()
    {
        if (false === $this->dumpSettings['no-create-info']) {
            foreach ($this->views as $view) {
                if ($this->matches($view, $this->dumpSettings['exclude-tables'])) {
                    continue;
                }
                $this->tableColumnTypes[$view] = $this->getTableColumnTypes($view);
                $this->getViewStructureTable($view);
            }
            foreach ($this->views as $view) {
                if ($this->matches($view, $this->dumpSettings['exclude-tables'])) {
                    continue;
                }
                $this->getViewStructureView($view);
            }
        }
    }
    private function exportTriggers()
    {
        foreach ($this->triggers as $trigger) {
            $this->getTriggerStructure($trigger);
        }
    }
    private function exportProcedures()
    {
        foreach ($this->procedures as $procedure) {
            $this->getProcedureStructure($procedure);
        }
    }
    private function exportFunctions()
    {
        foreach ($this->functions as $function) {
            $this->getFunctionStructure($function);
        }
    }
    private function exportEvents()
    {
        foreach ($this->events as $event) {
            $this->getEventStructure($event);
        }
    }
    private function getTableStructure($tableName)
    {
        if (!$this->dumpSettings['no-create-info']) {
            $ret = '';
            if (!$this->dumpSettings['skip-comments']) {
                $ret = "--".PHP_EOL.
                    "-- Table structure for table `$tableName`".PHP_EOL.
                    "--".PHP_EOL.PHP_EOL;
            }
            $stmt = $this->typeAdapter->show_create_table($tableName);
            foreach ($this->dbHandler->query($stmt) as $r) {
                $this->compressManager->write($ret);
                if ($this->dumpSettings['add-drop-table']) {
                    $this->compressManager->write(
                        $this->typeAdapter->drop_table($tableName)
                    );
                }
                $this->compressManager->write(
                    $this->typeAdapter->create_table($r)
                );
                break;
            }
        }
        $this->tableColumnTypes[$tableName] = $this->getTableColumnTypes($tableName);
        return;
    }
    private function getTableColumnTypes($tableName)
    {
        $columnTypes = [];
        $columns = $this->dbHandler->query(
            $this->typeAdapter->show_columns($tableName)
        );
        $columns->setFetchMode(PDO::FETCH_ASSOC);
        foreach ($columns as $key => $col) {
            $types = $this->typeAdapter->parseColumnType($col);
            $columnTypes[$col['Field']] = [
                'is_numeric'=> $types['is_numeric'],
                'is_blob' => $types['is_blob'],
                'type' => $types['type'],
                'type_sql' => $col['Type'],
                'is_virtual' => $types['is_virtual']
            ];
        }
        return $columnTypes;
    }
    private function getViewStructureTable($viewName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Stand-In structure for view `{$viewName}`".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_view($viewName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            if ($this->dumpSettings['add-drop-table']) {
                $this->compressManager->write(
                    $this->typeAdapter->drop_view($viewName)
                );
            }
            $this->compressManager->write(
                $this->createStandInTable($viewName)
            );
            break;
        }
    }
    public function createStandInTable($viewName)
    {
        $ret = [];
        foreach ($this->tableColumnTypes[$viewName] as $k => $v) {
            $ret[] = "`{$k}` {$v['type_sql']}";
        }
        $ret = implode(PHP_EOL.",", $ret);
        $ret = "CREATE TABLE IF NOT EXISTS `$viewName` (".
            PHP_EOL.$ret.PHP_EOL.");".PHP_EOL;
        return $ret;
    }
    private function getViewStructureView($viewName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- View structure for view `{$viewName}`".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_view($viewName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->drop_view($viewName)
            );
            $this->compressManager->write(
                $this->typeAdapter->create_view($r)
            );
            break;
        }
    }
    private function getTriggerStructure($triggerName)
    {
        $stmt = $this->typeAdapter->show_create_trigger($triggerName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            if ($this->dumpSettings['add-drop-trigger']) {
                $this->compressManager->write(
                    $this->typeAdapter->add_drop_trigger($triggerName)
                );
            }
            $this->compressManager->write(
                $this->typeAdapter->create_trigger($r)
            );
            return;
        }
    }
    private function getProcedureStructure($procedureName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Dumping routines for database '".$this->dbName."'".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_procedure($procedureName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->create_procedure($r)
            );
            return;
        }
    }
    private function getFunctionStructure($functionName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Dumping routines for database '".$this->dbName."'".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_function($functionName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->create_function($r)
            );
            return;
        }
    }
    private function getEventStructure($eventName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Dumping events for database '".$this->dbName."'".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_event($eventName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->create_event($r)
            );
            return;
        }
    }
    private function prepareColumnValues($tableName, array $row)
    {
        $ret = [];
        $columnTypes = $this->tableColumnTypes[$tableName];
        if ($this->transformTableRowCallable) {
            $row = call_user_func($this->transformTableRowCallable, $tableName, $row);
        }
        foreach ($row as $colName => $colValue) {
            if ($this->transformColumnValueCallable) {
                $colValue = call_user_func($this->transformColumnValueCallable, $tableName, $colName, $colValue, $row);
            }
            $ret[] = $this->escape($colValue, $columnTypes[$colName]);
        }
        return $ret;
    }
    private function escape($colValue, $colType)
    {
        if (is_null($colValue)) {
            return "NULL";
        } elseif ($this->dumpSettings['hex-blob'] && $colType['is_blob']) {
            if ($colType['type'] == 'bit' || !empty($colValue)) {
                return "0x{$colValue}";
            } else {
                return "''";
            }
        } elseif ($colType['is_numeric']) {
            return $colValue;
        }
        return $this->dbHandler->quote($colValue);
    }
    public function setTransformTableRowHook($callable)
    {
        $this->transformTableRowCallable = $callable;
    }
    public function setTransformColumnValueHook($callable)
    {
        $this->transformColumnValueCallable = $callable;
    }
    public function setInfoHook($callable)
    {
        $this->infoCallable = $callable;
    }
    private function listValues($tableName)
    {
        $this->prepareListValues($tableName);
        $onlyOnce = true;
        $colStmt = $this->getColumnStmt($tableName);
        if ($this->dumpSettings['complete-insert']) {
            $colNames = $this->getColumnNames($tableName);
        }
        $stmt = "SELECT ".implode(",", $colStmt)." FROM `$tableName`";
        $condition = $this->getTableWhere($tableName);
        if ($condition) {
            $stmt .= " WHERE {$condition}";
        }
        $limit = $this->getTableLimit($tableName);
        if ($limit !== false) {
            $stmt .= " LIMIT {$limit}";
        }
        $resultSet = $this->dbHandler->query($stmt);
        $resultSet->setFetchMode(PDO::FETCH_ASSOC);
        $ignore = $this->dumpSettings['insert-ignore'] ? '  IGNORE' : '';
        $count = 0;
        $line = '';
        foreach ($resultSet as $row) {
            $count++;
            $vals = $this->prepareColumnValues($tableName, $row);
            if ($onlyOnce || !$this->dumpSettings['extended-insert']) {
                if ($this->dumpSettings['complete-insert']) {
                    $line .= "INSERT$ignore INTO `$tableName` (".
                        implode(", ", $colNames).
                        ") VALUES (".implode(",", $vals).")";
                } else {
                    $line .= "INSERT$ignore INTO `$tableName` VALUES (".implode(",", $vals).")";
                }
                $onlyOnce = false;
            } else {
                $line .= ",(".implode(",", $vals).")";
            }
            if ((strlen($line) > $this->dumpSettings['net_buffer_length']) ||
                    !$this->dumpSettings['extended-insert']) {
                $onlyOnce = true;
                $this->compressManager->write($line . ";".PHP_EOL);
                $line = '';
            }
        }
        $resultSet->closeCursor();
        if ('' !== $line) {
            $this->compressManager->write($line. ";".PHP_EOL);
        }
        $this->endListValues($tableName, $count);
        if ($this->infoCallable) {
            call_user_func($this->infoCallable, 'table', [ 'name' => $tableName, 'rowCount' => $count ]);
        }
    }
    public function prepareListValues($tableName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $this->compressManager->write(
                "--".PHP_EOL.
                "-- Dumping data for table `$tableName`".PHP_EOL.
                "--".PHP_EOL.PHP_EOL
            );
        }
        if ($this->dumpSettings['lock-tables'] && !$this->dumpSettings['single-transaction']) {
            $this->typeAdapter->lock_table($tableName);
        }
        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write(
                $this->typeAdapter->start_add_lock_table($tableName)
            );
        }
        if ($this->dumpSettings['disable-keys']) {
            $this->compressManager->write(
                $this->typeAdapter->start_add_disable_keys($tableName)
            );
        }
        if ($this->dumpSettings['no-autocommit']) {
            $this->compressManager->write(
                $this->typeAdapter->start_disable_autocommit()
            );
        }
        return;
    }
    public function endListValues($tableName, $count = 0)
    {
        if ($this->dumpSettings['disable-keys']) {
            $this->compressManager->write(
                $this->typeAdapter->end_add_disable_keys($tableName)
            );
        }
        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write(
                $this->typeAdapter->end_add_lock_table($tableName)
            );
        }
        if ($this->dumpSettings['lock-tables'] && !$this->dumpSettings['single-transaction']) {
            $this->typeAdapter->unlock_table($tableName);
        }
        if ($this->dumpSettings['no-autocommit']) {
            $this->compressManager->write(
                $this->typeAdapter->end_disable_autocommit()
            );
        }
        $this->compressManager->write(PHP_EOL);
        if (!$this->dumpSettings['skip-comments']) {
            $this->compressManager->write(
                "-- Dumped table `".$tableName."` with $count row(s)".PHP_EOL.
                '--'.PHP_EOL.PHP_EOL
            );
        }
        return;
    }
    public function getColumnStmt($tableName)
    {
        $colStmt = [];
        foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
            if ($colType['is_virtual']) {
                $this->dumpSettings['complete-insert'] = true;
                continue;
            } elseif ($colType['type'] == 'bit' && $this->dumpSettings['hex-blob']) {
                $colStmt[] = "LPAD(HEX(`{$colName}`),2,'0') AS `{$colName}`";
            } elseif ($colType['type'] == 'double' && PHP_VERSION_ID > 80100) {
                $colStmt[] = sprintf("CONCAT(`%s`) AS `%s`", $colName, $colName);
            } elseif ($colType['is_blob'] && $this->dumpSettings['hex-blob']) {
                $colStmt[] = "HEX(`{$colName}`) AS `{$colName}`";
            } else {
                $colStmt[] = "`{$colName}`";
            }
        }
        return $colStmt;
    }
    public function getColumnNames($tableName)
    {
        $colNames = [];
        foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
            if ($colType['is_virtual']) {
                $this->dumpSettings['complete-insert'] = true;
                continue;
            } else {
                $colNames[] = "`{$colName}`";
            }
        }
        return $colNames;
    }
}
abstract class CompressMethod
{
    public static $enums = [ Mysqldump::NONE, Mysqldump::GZIP, Mysqldump::BZIP2, Mysqldump::GZIPSTREAM ];
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}
abstract class CompressManagerFactory
{
    public static function create($c)
    {
        $c = ucfirst(strtolower($c));
        if (!CompressMethod::isValid($c)) {
            throw new Exception("Compression method ($c) is not defined yet");
        }
        $method = "\\"."Compress".$c;
        return new $method;
    }
}
class CompressBzip2 extends CompressManagerFactory
{
    private $fileHandler = null;
    public function __construct()
    {
        if (!function_exists("bzopen")) {
            throw new Exception("Compression is enabled, but bzip2 lib is not installed or configured properly");
        }
    }
    public function open($filename)
    {
        $this->fileHandler = bzopen($filename, "w");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }
        return true;
    }
    public function write($str)
    {
        $bytesWritten = bzwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }
    public function close()
    {
        return bzclose($this->fileHandler);
    }
}
class CompressGzip extends CompressManagerFactory
{
    private $fileHandler = null;
    public function __construct()
    {
        if (!function_exists("gzopen")) {
            throw new Exception("Compression is enabled, but gzip lib is not installed or configured properly");
        }
    }
    public function open($filename)
    {
        $this->fileHandler = gzopen($filename, "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }
        return true;
    }
    public function write($str)
    {
        $bytesWritten = gzwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }
    public function close()
    {
        return gzclose($this->fileHandler);
    }
}
class CompressNone extends CompressManagerFactory
{
    private $fileHandler = null;
    public function open($filename)
    {
        $this->fileHandler = fopen($filename, "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }
        return true;
    }
    public function write($str)
    {
        $bytesWritten = fwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }
    public function close()
    {
        return fclose($this->fileHandler);
    }
}
class CompressGzipstream extends CompressManagerFactory
{
    private $fileHandler = null;
    private $compressContext;
    public function open($filename)
    {
    $this->fileHandler = fopen($filename, "wb");
    if (false === $this->fileHandler) {
        throw new Exception("Output file is not writable");
    }
    $this->compressContext = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 9));
    return true;
    }
    public function write($str)
    {
    $bytesWritten = fwrite($this->fileHandler, deflate_add($this->compressContext, $str, ZLIB_NO_FLUSH));
    if (false === $bytesWritten) {
        throw new Exception("Writting to file failed! Probably, there is no more free space left?");
    }
    return $bytesWritten;
    }
    public function close()
    {
    fwrite($this->fileHandler, deflate_add($this->compressContext, '', ZLIB_FINISH));
    return fclose($this->fileHandler);
    }
}
abstract class TypeAdapter
{
    public static $enums = array(
        "Sqlite",
        "Mysql"
    );
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}
abstract class TypeAdapterFactory
{
    protected $dbHandler = null;
    protected $dumpSettings = [];
    public static function create($c, $dbHandler = null, $dumpSettings = [])
    {
        $c = ucfirst(strtolower($c));
        if (!TypeAdapter::isValid($c)) {
            throw new Exception("Database type support for ($c) not yet available");
        }
        $method = "\\"."TypeAdapter".$c;
        return new $method($dbHandler, $dumpSettings);
    }
    public function __construct($dbHandler = null, $dumpSettings = [])
    {
        $this->dbHandler = $dbHandler;
        $this->dumpSettings = $dumpSettings;
    }
    public function databases()
    {
        return "";
    }
    public function show_create_table($tableName)
    {
        return "SELECT tbl_name as 'Table', sql as 'Create Table' ".
            "FROM sqlite_master ".
            "WHERE type='table' AND tbl_name='$tableName'";
    }
    public function create_table($row)
    {
        return "";
    }
    public function show_create_view($viewName)
    {
        return "SELECT tbl_name as 'View', sql as 'Create View' ".
            "FROM sqlite_master ".
            "WHERE type='view' AND tbl_name='$viewName'";
    }
    public function create_view($row)
    {
        return "";
    }
    public function show_create_trigger($triggerName)
    {
        return "";
    }
    public function create_trigger($triggerName)
    {
        return "";
    }
    public function create_procedure($procedureName)
    {
        return "";
    }
    public function create_function($functionName)
    {
        return "";
    }
    public function show_tables()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='table'";
    }
    public function show_views()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='view'";
    }
    public function show_triggers()
    {
        return "SELECT name FROM sqlite_master WHERE type='trigger'";
    }
    public function show_columns()
    {
        if (func_num_args() != 1) {
            return "";
        }
        $args = func_get_args();
        return "pragma table_info({$args[0]})";
    }
    public function show_procedures()
    {
        return "";
    }
    public function show_functions()
    {
        return "";
    }
    public function show_events()
    {
        return "";
    }
    public function setup_transaction()
    {
        return "";
    }
    public function start_transaction()
    {
        return "BEGIN EXCLUSIVE";
    }
    public function commit_transaction()
    {
        return "COMMIT";
    }
    public function lock_table()
    {
        return "";
    }
    public function unlock_table()
    {
        return "";
    }
    public function start_add_lock_table()
    {
        return PHP_EOL;
    }
    public function end_add_lock_table()
    {
        return PHP_EOL;
    }
    public function start_add_disable_keys()
    {
        return PHP_EOL;
    }
    public function end_add_disable_keys()
    {
        return PHP_EOL;
    }
    public function start_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }
    public function end_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }
    public function add_drop_database()
    {
        return PHP_EOL;
    }
    public function add_drop_trigger()
    {
        return PHP_EOL;
    }
    public function drop_table()
    {
        return PHP_EOL;
    }
    public function drop_view()
    {
        return PHP_EOL;
    }
    public function parseColumnType($colType)
    {
        return [];
    }
    public function backup_parameters()
    {
        return PHP_EOL;
    }
    public function restore_parameters()
    {
        return PHP_EOL;
    }
}
class TypeAdapterPgsql extends TypeAdapterFactory
{
}
class TypeAdapterDblib extends TypeAdapterFactory
{
}
class TypeAdapterSqlite extends TypeAdapterFactory
{
}
class TypeAdapterMysql extends TypeAdapterFactory
{
    const DEFINER_RE = 'DEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`';
    public $mysqlTypes = array(
        'numerical' => array(
            'bit',
            'tinyint',
            'smallint',
            'mediumint',
            'int',
            'integer',
            'bigint',
            'real',
            'double',
            'float',
            'decimal',
            'numeric'
        ),
        'blob' => array(
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
            'binary',
            'varbinary',
            'bit',
            'geometry', /* http://bugs.mysql.com/bug.php?id=43544 */
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        )
    );
    public function databases()
    {
        if ($this->dumpSettings['no-create-db']) {
           return "";
        }
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        $databaseName = $args[0];
        $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'character_set_database';");
        $characterSet = $resultSet->fetchColumn(1);
        $resultSet->closeCursor();
        $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'collation_database';");
        $collationDb = $resultSet->fetchColumn(1);
        $resultSet->closeCursor();
        $ret = "";
        $ret .= "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$databaseName}`".
            " /*!40100 DEFAULT CHARACTER SET {$characterSet} ".
            " COLLATE {$collationDb} */;".PHP_EOL.PHP_EOL.
            "USE `{$databaseName}`;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function show_create_table($tableName)
    {
        return "SHOW CREATE TABLE `$tableName`";
    }
    public function show_create_view($viewName)
    {
        return "SHOW CREATE VIEW `$viewName`";
    }
    public function show_create_trigger($triggerName)
    {
        return "SHOW CREATE TRIGGER `$triggerName`";
    }
    public function show_create_procedure($procedureName)
    {
        return "SHOW CREATE PROCEDURE `$procedureName`";
    }
    public function show_create_function($functionName)
    {
        return "SHOW CREATE FUNCTION `$functionName`";
    }
    public function show_create_event($eventName)
    {
        return "SHOW CREATE EVENT `$eventName`";
    }
    public function create_table($row)
    {
        if (!isset($row['Create Table'])) {
            throw new Exception("Error getting table code, unknown output");
        }
        $createTable = $row['Create Table'];
        if ($this->dumpSettings['reset-auto-increment']) {
            $match = "/AUTO_INCREMENT=[0-9]+/s";
            $replace = "";
            $createTable = preg_replace($match, $replace, $createTable);
        }
        if ($this->dumpSettings['if-not-exists'] ) {
            $createTable = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $createTable);
        }
        $ret = "/*!40101 SET @saved_cs_client     = @@character_set_client */;".PHP_EOL.
            "/*!40101 SET character_set_client = ".$this->dumpSettings['default-character-set']." */;".PHP_EOL.
            $createTable.";".PHP_EOL.
            "/*!40101 SET character_set_client = @saved_cs_client */;".PHP_EOL.
            PHP_EOL;
        return $ret;
    }
    public function create_view($row)
    {
        $ret = "";
        if (!isset($row['Create View'])) {
            throw new Exception("Error getting view structure, unknown output");
        }
        $viewStmt = $row['Create View'];
        $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50013 \2 */'.PHP_EOL;
        if ($viewStmtReplaced = preg_replace(
            '/^(CREATE(?:\s+ALGORITHM=(?:UNDEFINED|MERGE|TEMPTABLE))?)\s+('
            .self::DEFINER_RE.'(?:\s+SQL SECURITY (?:DEFINER|INVOKER))?)?\s+(VIEW .+)$/',
            '/*!50001 \1 */'.PHP_EOL.$definerStr.'/*!50001 \3 */',
            $viewStmt,
            1
        )) {
            $viewStmt = $viewStmtReplaced;
        };
        $ret .= $viewStmt.';'.PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function create_trigger($row)
    {
        $ret = "";
        if (!isset($row['SQL Original Statement'])) {
            throw new Exception("Error getting trigger code, unknown output");
        }
        $triggerStmt = $row['SQL Original Statement'];
        $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50017 \2*/ ';
        if ($triggerStmtReplaced = preg_replace(
            '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(TRIGGER\s.*)$/s',
            '/*!50003 \1*/ '.$definerStr.'/*!50003 \3 */',
            $triggerStmt,
            1
        )) {
            $triggerStmt = $triggerStmtReplaced;
        }
        $ret .= "DELIMITER ;;".PHP_EOL.
            $triggerStmt.";;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function create_procedure($row)
    {
        $ret = "";
        if (!isset($row['Create Procedure'])) {
            throw new Exception("Error getting procedure code, unknown output. ".
                "Please check 'https://bugs.mysql.com/bug.php?id=14564'");
        }
        $procedureStmt = $row['Create Procedure'];
        if ($this->dumpSettings['skip-definer']) {
            if ($procedureStmtReplaced = preg_replace(
                '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(PROCEDURE\s.*)$/s',
                '\1 \3',
                $procedureStmt,
                1
            )) {
                $procedureStmt = $procedureStmtReplaced;
            }
        }
        $ret .= "/*!50003 DROP PROCEDURE IF EXISTS `".
            $row['Procedure']."` */;".PHP_EOL.
            "/*!40101 SET @saved_cs_client     = @@character_set_client */;".PHP_EOL.
            "/*!40101 SET character_set_client = ".$this->dumpSettings['default-character-set']." */;".PHP_EOL.
            "DELIMITER ;;".PHP_EOL.
            $procedureStmt." ;;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.
            "/*!40101 SET character_set_client = @saved_cs_client */;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function create_function($row)
    {
        $ret = "";
        if (!isset($row['Create Function'])) {
            throw new Exception("Error getting function code, unknown output. ".
                "Please check 'https://bugs.mysql.com/bug.php?id=14564'");
        }
        $functionStmt = $row['Create Function'];
        $characterSetClient = $row['character_set_client'];
        $collationConnection = $row['collation_connection'];
        $sqlMode = $row['sql_mode'];
        if ( $this->dumpSettings['skip-definer'] ) {
            if ($functionStmtReplaced = preg_replace(
                '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(FUNCTION\s.*)$/s',
                '\1 \3',
                $functionStmt,
                1
            )) {
                $functionStmt = $functionStmtReplaced;
            }
        }
        $ret .= "/*!50003 DROP FUNCTION IF EXISTS `".
            $row['Function']."` */;".PHP_EOL.
            "/*!40101 SET @saved_cs_client     = @@character_set_client */;".PHP_EOL.
            "/*!50003 SET @saved_cs_results     = @@character_set_results */ ;".PHP_EOL.
            "/*!50003 SET @saved_col_connection = @@collation_connection */ ;".PHP_EOL.
            "/*!40101 SET character_set_client = ".$characterSetClient." */;".PHP_EOL.
            "/*!40101 SET character_set_results = ".$characterSetClient." */;".PHP_EOL.
            "/*!50003 SET collation_connection  = ".$collationConnection." */ ;".PHP_EOL.
            "/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;".PHP_EOL.
            "/*!50003 SET sql_mode              = '".$sqlMode."' */ ;;".PHP_EOL.
            "/*!50003 SET @saved_time_zone      = @@time_zone */ ;;".PHP_EOL.
            "/*!50003 SET time_zone             = 'SYSTEM' */ ;;".PHP_EOL.
            "DELIMITER ;;".PHP_EOL.
            $functionStmt." ;;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.
            "/*!50003 SET sql_mode              = @saved_sql_mode */ ;".PHP_EOL.
            "/*!50003 SET character_set_client  = @saved_cs_client */ ;".PHP_EOL.
            "/*!50003 SET character_set_results = @saved_cs_results */ ;".PHP_EOL.
            "/*!50003 SET collation_connection  = @saved_col_connection */ ;".PHP_EOL.
            "/*!50106 SET TIME_ZONE= @saved_time_zone */ ;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function create_event($row)
    {
        $ret = "";
        if (!isset($row['Create Event'])) {
            throw new Exception("Error getting event code, unknown output. ".
                "Please check 'http://stackoverflow.com/questions/10853826/mysql-5-5-create-event-gives-syntax-error'");
        }
        $eventName = $row['Event'];
        $eventStmt = $row['Create Event'];
        $sqlMode = $row['sql_mode'];
        $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50117 \2*/ ';
        if ($eventStmtReplaced = preg_replace(
            '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(EVENT .*)$/',
            '/*!50106 \1*/ '.$definerStr.'/*!50106 \3 */',
            $eventStmt,
            1
        )) {
            $eventStmt = $eventStmtReplaced;
        }
        $ret .= "/*!50106 SET @save_time_zone= @@TIME_ZONE */ ;".PHP_EOL.
            "/*!50106 DROP EVENT IF EXISTS `".$eventName."` */;".PHP_EOL.
            "DELIMITER ;;".PHP_EOL.
            "/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;".PHP_EOL.
            "/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;".PHP_EOL.
            "/*!50003 SET @saved_col_connection = @@collation_connection */ ;;".PHP_EOL.
            "/*!50003 SET character_set_client  = utf8 */ ;;".PHP_EOL.
            "/*!50003 SET character_set_results = utf8 */ ;;".PHP_EOL.
            "/*!50003 SET collation_connection  = utf8_general_ci */ ;;".PHP_EOL.
            "/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;".PHP_EOL.
            "/*!50003 SET sql_mode              = '".$sqlMode."' */ ;;".PHP_EOL.
            "/*!50003 SET @saved_time_zone      = @@time_zone */ ;;".PHP_EOL.
            "/*!50003 SET time_zone             = 'SYSTEM' */ ;;".PHP_EOL.
            $eventStmt." ;;".PHP_EOL.
            "/*!50003 SET time_zone             = @saved_time_zone */ ;;".PHP_EOL.
            "/*!50003 SET sql_mode              = @saved_sql_mode */ ;;".PHP_EOL.
            "/*!50003 SET character_set_client  = @saved_cs_client */ ;;".PHP_EOL.
            "/*!50003 SET character_set_results = @saved_cs_results */ ;;".PHP_EOL.
            "/*!50003 SET collation_connection  = @saved_col_connection */ ;;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.
            "/*!50106 SET TIME_ZONE= @save_time_zone */ ;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function show_tables()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT TABLE_NAME AS tbl_name ".
            "FROM INFORMATION_SCHEMA.TABLES ".
            "WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='{$args[0]}' ".
            "ORDER BY TABLE_NAME";
    }
    public function show_views()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT TABLE_NAME AS tbl_name ".
            "FROM INFORMATION_SCHEMA.TABLES ".
            "WHERE TABLE_TYPE='VIEW' AND TABLE_SCHEMA='{$args[0]}' ".
            "ORDER BY TABLE_NAME";
    }
    public function show_triggers()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SHOW TRIGGERS FROM `{$args[0]}`;";
    }
    public function show_columns()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SHOW COLUMNS FROM `{$args[0]}`;";
    }
    public function show_procedures()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT SPECIFIC_NAME AS procedure_name ".
            "FROM INFORMATION_SCHEMA.ROUTINES ".
            "WHERE ROUTINE_TYPE='PROCEDURE' AND ROUTINE_SCHEMA='{$args[0]}'";
    }
    public function show_functions()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT SPECIFIC_NAME AS function_name ".
            "FROM INFORMATION_SCHEMA.ROUTINES ".
            "WHERE ROUTINE_TYPE='FUNCTION' AND ROUTINE_SCHEMA='{$args[0]}'";
    }
    public function show_events()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT EVENT_NAME AS event_name ".
            "FROM INFORMATION_SCHEMA.EVENTS ".
            "WHERE EVENT_SCHEMA='{$args[0]}'";
    }
    public function setup_transaction()
    {
        return "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ";
    }
    public function start_transaction()
    {
        return "START TRANSACTION ".
            "/*!40100 WITH CONSISTENT SNAPSHOT */";
    }
    public function commit_transaction()
    {
        return "COMMIT";
    }
    public function lock_table()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return $this->dbHandler->exec("LOCK TABLES `{$args[0]}` READ LOCAL");
    }
    public function unlock_table()
    {
        return $this->dbHandler->exec("UNLOCK TABLES");
    }
    public function start_add_lock_table()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "LOCK TABLES `{$args[0]}` WRITE;".PHP_EOL;
    }
    public function end_add_lock_table()
    {
        return "UNLOCK TABLES;".PHP_EOL;
    }
    public function start_add_disable_keys()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "/*!40000 ALTER TABLE `{$args[0]}` DISABLE KEYS */;".
            PHP_EOL;
    }
    public function end_add_disable_keys()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "/*!40000 ALTER TABLE `{$args[0]}` ENABLE KEYS */;".
            PHP_EOL;
    }
    public function start_disable_autocommit()
    {
        return "SET autocommit=0;".PHP_EOL;
    }
    public function end_disable_autocommit()
    {
        return "COMMIT;".PHP_EOL;
    }
    public function add_drop_database()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "/*!40000 DROP DATABASE IF EXISTS `{$args[0]}`*/;".
            PHP_EOL.PHP_EOL;
    }
    public function add_drop_trigger()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "DROP TRIGGER IF EXISTS `{$args[0]}`;".PHP_EOL;
    }
    public function drop_table()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "DROP TABLE IF EXISTS `{$args[0]}`;".PHP_EOL;
    }
    public function drop_view()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "DROP TABLE IF EXISTS `{$args[0]}`;".PHP_EOL.
                "/*!50001 DROP VIEW IF EXISTS `{$args[0]}`*/;".PHP_EOL;
    }
    public function getDatabaseHeader()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "--".PHP_EOL.
            "-- Current Database: `{$args[0]}`".PHP_EOL.
            "--".PHP_EOL.PHP_EOL;
    }
    public function parseColumnType($colType)
    {
        $colInfo = [];
        $colParts = explode(" ", $colType['Type']);
        if ($fparen = strpos($colParts[0], "(")) {
            $colInfo['type'] = substr($colParts[0], 0, $fparen);
            $colInfo['length'] = str_replace(")", "", substr($colParts[0], $fparen + 1));
            $colInfo['attributes'] = isset($colParts[1]) ? $colParts[1] : null;
        } else {
            $colInfo['type'] = $colParts[0];
        }
        $colInfo['is_numeric'] = in_array($colInfo['type'], $this->mysqlTypes['numerical']);
        $colInfo['is_blob'] = in_array($colInfo['type'], $this->mysqlTypes['blob']);
        $colInfo['is_virtual'] = strpos($colType['Extra'], "VIRTUAL GENERATED") !== false || strpos($colType['Extra'], "STORED GENERATED") !== false;
        return $colInfo;
    }
    public function backup_parameters()
    {
        $ret = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;".PHP_EOL.
            "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;".PHP_EOL.
            "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;".PHP_EOL.
            "/*!40101 SET NAMES ".$this->dumpSettings['default-character-set']." */;".PHP_EOL;
        if (false === $this->dumpSettings['skip-tz-utc']) {
            $ret .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;".PHP_EOL.
                "/*!40103 SET TIME_ZONE='+00:00' */;".PHP_EOL;
        }
        if ($this->dumpSettings['no-autocommit']) {
                $ret .= "/*!40101 SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT */;".PHP_EOL;
        }
        $ret .= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;".PHP_EOL.
            "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;".PHP_EOL.
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;".PHP_EOL.
            "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    public function restore_parameters()
    {
        $ret = "";
        if (false === $this->dumpSettings['skip-tz-utc']) {
            $ret .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;".PHP_EOL;
        }
        if ($this->dumpSettings['no-autocommit']) {
                $ret .= "/*!40101 SET AUTOCOMMIT=@OLD_AUTOCOMMIT */;".PHP_EOL;
        }
        $ret .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;".PHP_EOL.
            "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;".PHP_EOL.
            "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;".PHP_EOL.
            "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;".PHP_EOL.
            "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;".PHP_EOL.
            "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;".PHP_EOL.
            "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;".PHP_EOL.PHP_EOL;
        return $ret;
    }
    private function check_parameters($num_args, $expected_num_args, $method_name)
    {
        if ($num_args != $expected_num_args) {
            throw new Exception("Unexpected parameter passed to $method_name");
        }
        return;
    }
}
