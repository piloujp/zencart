<?php
/**
 * @copyright Copyright 2003-2023 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Scott C Wilson 2022 Oct 21 Modified in v1.5.8a $
 *
 */

class zcDatabaseInstaller
{
    protected bool $ignoreLine = false;
    protected int $jsonProgressLoggingCount = 0;
    protected array $basicParseStrings = [];
    protected string $collateSuffix;
    protected bool $completeLine;
    protected QueryFactory $db;
    protected string $dbCharset;
    protected string $dbHost;
    protected string $dbName;
    protected string $dbPassword;
    protected string $dbPrefix;
    protected string $dbType;
    protected string $dbUser;
    protected string $table;
    protected bool $dieOnErrors;
    protected array $errors = [];
    protected array $extendedOptions;
    protected string $fileName;
    protected Closure $func;
    protected int $jsonProgressLoggingTotal;
    protected int $keepTogetherCount;
    protected int $keepTogetherLines;
    protected string $line;
    protected array $lineSplit = [];
    protected string $newLine;
    protected array $upgradeExceptions;

    public function __construct(array $options)
    {
        $this->func = static fn($matches): string => strtoupper($matches[1]);
        $dbtypes = [];
        $path = DIR_FS_ROOT . 'includes/classes/db/';
        $dir = dir($path);
        while ($entry = $dir->read()) {
            if (is_dir($path . $entry) && !str_starts_with($entry, '.')) {
                $dbtypes[] = $entry;
            }
        }
        $dir->close();

        $this->dbHost = $options['db_host'];
        $this->dbUser = $options['db_user'];
        $this->dbPassword = $options['db_password'];
        $this->dbName = $options['db_name'];
        $this->dbPrefix = $options['db_prefix'];
        $this->dbCharset = isset($options['db_charset']) && trim($options['db_charset']) !== '' ? $options['db_charset'] : 'utf8mb4';
        $this->dbType = in_array($options['db_type'], $dbtypes, false) ? $options['db_type'] : 'mysql';
        $this->dieOnErrors = isset($options['dieOnErrors']) && (bool)$options['dieOnErrors'];
        $this->errors = [];
        $this->basicParseStrings = [
            'DROP TABLE ',
            'CREATE TABLE ',
            'REPLACE INTO ',
            'INSERT INTO ',
            'INSERT IGNORE INTO ',
            //    'ALTER IGNORE TABLE ',
            'ALTER TABLE ',
            'TRUNCATE TABLE ',
            'RENAME TABLE ',
            'TO ',
            'UPDATE ',
            'UPDATE IGNORE ',
            'DELETE FROM ',
            'DROP INDEX ',
            'LEFT JOIN ',
            'FROM ',
            ') ENGINE=MYISAM',
        ];
    }

    public function getConnection(): bool
    {
        require_once(DIR_FS_ROOT . 'includes/classes/db/' . $this->dbType . '/query_factory.php');
        $this->db = new queryFactory;
        $options = ['dbCharset' => $this->dbCharset];
        return $this->db->Connect($this->dbHost, $this->dbUser, $this->dbPassword, $this->dbName, 'false', $this->dieOnErrors, $options);
    }

    public function runZeroDateSql($options = null): ?bool
    {
        $file = DIR_FS_INSTALL . 'sql/install/zero_dates_cleanup.sql';
        return $this->parseSqlFile($file, $options);
    }

    public function parseSqlFile($fileName, $options = null): bool
    {
        $this->extendedOptions = $options ?? [];
        $lines = file($fileName);
        if (false === $lines) {
            logDetails('COULD NOT OPEN FILE: ' . $fileName, $fileName);
            die('HERE_BE_MONSTERS - could not open file');
        }
        $this->fileName = $fileName;
        $this->upgradeExceptions = [];
        if (!isset($lines) || !is_array($lines)) {
            logDetails('HERE BE MONSTERS', $fileName);
            die('HERE_BE_MONSTERS');
        }
        $this->doJSONProgressLoggingStart(count($lines));
        $this->keepTogetherCount = 0;
        $this->newLine = "";
        foreach ($lines as $line) {
            $this->jsonProgressLoggingCount++;
            $this->processline($line);
        }
//if (count($lines) < 200) sleep(5);
        $this->doJsonProgressLoggingEnd();

        return false;
        /**
         * @todo further enhancement could add an advanced mode which returns the actual exceptions list to the browser.
         *       For now, since outputting them has usually just raised unnecessary questions and confusion for end-users, simply returning false to suppress their display.
         *       Advanced users/integrators can check the upgrade_exceptions database table or the /logs/ folder for the details.
         */
//    return (count($this->upgradeExceptions) > 0);
    }

    private function doJsonProgressLoggingStart($count): void
    {
        if (isset($this->extendedOptions['doJsonProgressLogging'])) {
            $this->jsonProgressLoggingTotal = $count;
            $this->jsonProgressLoggingCount = 0;
            $fileName = $this->extendedOptions['doJsonProgressLoggingFileName'];
            $fp = fopen($fileName, "w");
            if ($fp) {
                $arr = ['total' => $count, 'progress' => 0, 'message' => $this->extendedOptions['message']];
                fwrite($fp, json_encode($arr));
                fclose($fp);
            }
        }
    }

    private function processLine($line): void
    {
        $this->keepTogetherLines = 1;
        $this->line = trim($line);
        if (str_starts_with($this->line, '#NEXT_X_ROWS_AS_ONE_COMMAND:')) {
            $this->keepTogetherLines = (int)substr($this->line, 28);
        }
        if (!str_starts_with($this->line, '#') && !str_starts_with($this->line, '-') && $this->line !== '') {
            $this->parseLineContent();
            $this->newLine .= $this->line . ' ';
            if (str_ends_with($this->line, ';')) {
                if (str_ends_with($this->newLine, ' ')) {
                    $this->newLine = substr($this->newLine, 0, (strlen($this->newLine) - 1));
                }
                if (str_ends_with($this->newLine, ')')) {
                    $this->newLine = substr($this->newLine, 0, (strlen($this->newLine) - 1)) . ' )';
                }
                $this->keepTogetherCount++;
                if ($this->keepTogetherCount === $this->keepTogetherLines) {
                    $this->completeLine = true;
                    $this->keepTogetherCount = 0;
                    if (isset($this->collateSuffix) && $this->collateSuffix !== ''
                        && (!defined('IGNORE_DB_CHARSET') || (defined('IGNORE_DB_CHARSET') && IGNORE_DB_CHARSET !== false))
                    ) {
                        $this->newLine = rtrim($this->newLine, ';') . $this->collateSuffix . ';';
                        $this->collateSuffix = '';
                    }
                } else {
                    $this->completeLine = false;
                }
            }
//      echo $this->newLine;
            if ($this->completeLine) {
                $output = (trim(str_replace(';', '', $this->newLine)) !== '' && !$this->ignoreLine) ? $this->tryExecute($this->newLine) : '';
                $this->doJsonProgressLoggingUpdate();
                $this->newLine = "";
                $this->ignoreLine = false;
                $this->completeLine = false;
                $this->keepTogetherLines = 1;
            }
        }
    }

    private function parseLineContent(): void
    {
        $this->lineSplit = explode(" ", (str_ends_with($this->line, ';')) ? rtrim($this->line, ';') : $this->line);
        if (!isset($this->lineSplit[3])) {
            $this->lineSplit[3] = "";
        }
        if (!isset($this->lineSplit[4])) {
            $this->lineSplit[4] = "";
        }
        if (!isset($this->lineSplit[5])) {
            $this->lineSplit[5] = "";
        }
        foreach ($this->basicParseStrings as $parseString) {
            $parseMethod = 'parser' . trim($this->camelize($parseString));

            if (str_starts_with(strtoupper($this->line), $parseString)) {
                if ($parseMethod === 'parser)Engine=myisam') {
                    $parseMethod = 'parserEngineInnodb';
                }
                if (method_exists($this, $parseMethod)) {
                    $this->$parseMethod();
                }
            }
        }
    }

    private function camelize($parseString): array|string
    {
        $parseString = preg_replace_callback('/\s([0-9,a-z])/', $this->func, strtolower($parseString));
        $parseString[0] = strtoupper($parseString[0]);
        return $parseString;
    }

    public function tryExecute(string $sql)
    {
//    echo $sql;
//    $this->writeUpgradeExceptions($this->line, '', $this->sqlFile);
//    logDetails($sql, $this->sqlFile);
        $result = $this->db->Execute($sql);
        if (!$result || $result->link->errno !== 0) {
            $this->writeUpgradeExceptions($this->line, $this->db->error_number . ': ' . $this->db->error_text);
            error_log("MySQL error " . $this->db->error_number . " encountered during zc_install:\n" . $this->db->error_text . "\n" . $this->line . "\n---------------\n\n");
        }
    }

    public function writeUpgradeExceptions($line, $message, $sqlFile = ''): queryFactoryResult
    {
        logDetails($line . '  ' . $message . '  ' . $sqlFile, 'upgradeException');
        $this->upgradeExceptions[] = $message;
        $this->createExceptionsTable();
        $sql = "INSERT INTO " . $this->dbPrefix . TABLE_UPGRADE_EXCEPTIONS . " (sql_file, reason, errordate, sqlstatement) VALUES (:file:, :reason:, now(), :line:)";
        $sql = $this->db->bindVars($sql, ':file:', $sqlFile, 'string');
        $sql = $this->db->bindVars($sql, ':reason:', $message, 'string');
        $sql = $this->db->bindVars($sql, ':line:', $line, 'string');
        return $this->db->Execute($sql);
    }

    public function createExceptionsTable(): void
    {
        if (!$this->tableExists(TABLE_UPGRADE_EXCEPTIONS)) {
            $this->db->Execute(
        "CREATE TABLE " . $this->dbPrefix . TABLE_UPGRADE_EXCEPTIONS . " (
                    upgrade_exception_id SMALLINT(5) NOT NULL AUTO_INCREMENT,
                    sql_file VARCHAR(128) DEFAULT NULL,
                    reason TEXT DEFAULT NULL,
                    errordate DATETIME DEFAULT NULL,
                    sqlstatement TEXT,
                    PRIMARY KEY  (upgrade_exception_id)
                  ) ENGINE=MyISAM"
            );
        }
    }

    public function tableExists($table): bool
    {
        $tables = $this->db->Execute("SHOW TABLES LIKE '" . $this->dbPrefix . $table . "'");
        return $tables->RecordCount() > 0;
    }

    private function doJsonProgressLoggingUpdate(): void
    {
        if (isset($this->extendedOptions['doJsonProgressLogging'])) {
            $fileName = $this->extendedOptions['doJsonProgressLoggingFileName'];
            $progress = ($this->jsonProgressLoggingCount / $this->jsonProgressLoggingTotal * 100);
            $fp = fopen($fileName, "w");
            if ($fp) {
                $arr = ['total' => '0', 'progress' => $progress, 'message' => $this->extendedOptions['message']];
                fwrite($fp, json_encode($arr));
                fclose($fp);
            }
        }
    }

    private function doJsonProgressLoggingEnd(): void
    {
        if (isset($this->extendedOptions['doJsonProgressLogging'])) {
            $this->jsonProgressLoggingCount = 0;
            $fileName = $this->extendedOptions['doJsonProgressLoggingFileName'];
            $fp = fopen($fileName, "w");
            if ($fp) {
                $arr = ['total' => '0', 'progress' => 100, 'message' => $this->extendedOptions['message']];
                fwrite($fp, json_encode($arr));
                fclose($fp);
            }
        }
    }

    public function parserDropTable(): void
    {
        $table = (strtoupper($this->lineSplit[2] . ' ' . $this->lineSplit[3]) === 'IF EXISTS') ? $this->lineSplit[4] : $this->lineSplit[2];

        if (!$this->tableExists($table) && (strtoupper($this->lineSplit[2] . ' ' . $this->lineSplit[3]) !== 'IF EXISTS')) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $table) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            if (strtoupper($this->lineSplit[2] . ' ' . $this->lineSplit[3]) !== 'IF EXISTS') {
                $this->line = 'DROP TABLE ' . $this->dbPrefix . substr($this->line, 11);
            } else {
                $this->line = 'DROP TABLE IF EXISTS ' . $this->dbPrefix . substr($this->line, 21);
            }
        }
    }

    public function parserCreateTable(): void
    {
        $table = (strtoupper($this->lineSplit[2] . ' ' . $this->lineSplit[3] . ' ' . $this->lineSplit[4]) === 'IF NOT EXISTS') ? $this->lineSplit[5] : $this->lineSplit[2];
        $this->table = $table;
        if ($this->tableExists($table)) {
            $this->ignoreLine = true;
            if (strtoupper($this->lineSplit[2] . ' ' . $this->lineSplit[3] . ' ' . $this->lineSplit[4]) !== 'IF NOT EXISTS') {
                $this->writeUpgradeExceptions($this->line, sprintf(REASON_TABLE_ALREADY_EXISTS, $table), $this->fileName);
            }
        } else {
            $this->line = (strtoupper($this->lineSplit[2] . ' ' . $this->lineSplit[3] . ' ' . $this->lineSplit[4]) === 'IF NOT EXISTS')
                ? 'CREATE TABLE IF NOT EXISTS ' . $this->dbPrefix . substr($this->line, 27) : 'CREATE TABLE ' . $this->dbPrefix . substr($this->line, 13);
            if (stripos($this->line, ' COLLATE ') === false) {
                $this->collateSuffix = (strtoupper($this->lineSplit[3]) === 'AS' || (isset($this->lineSplit[6]) && strtoupper($this->lineSplit[6]) === 'AS'))
                    ? ''
                    : ' COLLATE ' . $this->dbCharset . '_general_ci';
            }
        }
    }

    public function parserInsertInto(): void
    {
        if (($this->lineSplit[2] === 'configuration' && ($result = $this->checkConfigKey($this->line))) ||
            ($this->lineSplit[2] === 'product_type_layout' && ($result = $this->checkProductTypeLayoutKey($this->line))) ||
            ($this->lineSplit === 'configuration_group' && ($result = $this->checkCfggroupKey($this->line))) ||
            (!$this->tableExists($this->lineSplit[2]))) {
            if (!isset($result)) {
                $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            }
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'INSERT INTO ' . $this->dbPrefix . substr($this->line, 12);
        }
    }

    public function checkConfigKey($line): bool|string
    {
        $values = explode("'", $line);
        //INSERT INTO configuration blah blah blah VALUES ('title','key', blah blah blah);
        //[0]=INSERT INTO.....
        //[1]=title
        //[2]=,
        //[3]=key
        //[4]=blah blah
        $title = $values[1];
        $key = $values[3];
        $sql = "SELECT configuration_title FROM " . $this->dbPrefix . "configuration WHERE configuration_key='" . $key . "'";
        $result = $this->db->Execute($sql);
        if ($result->RecordCount() > 0) {
            return sprintf(REASON_CONFIG_KEY_ALREADY_EXISTS, $key);
        }
        return false;
    }

    public function checkProductTypeLayoutKey($line): bool|string
    {
        $values = explode("'", $line);
        $title = $values[1];
        $key = $values[3];
        $sql = "SELECT configuration_title FROM " . $this->dbPrefix . "product_type_layout WHERE configuration_key='" . $key . "'";
        $result = $this->db->Execute($sql);
        if ($result->RecordCount() > 0) {
            return sprintf(REASON_PRODUCT_TYPE_LAYOUT_KEY_ALREADY_EXISTS, $key);
        }
        return false;
    }

    public function checkCfggroupKey($line): bool|string
    {
        $values = explode("'", $line);
        $id = $values[1];
        $title = $values[3];
        $sql = "SELECT configuration_group_title FROM " . $this->dbPrefix . "configuration_group WHERE configuration_group_title='" . $title . "'";
        $result = $this->db->Execute($sql);
        if ($result->RecordCount() > 0) {
            return sprintf(REASON_CONFIG_GROUP_KEY_ALREADY_EXISTS, $title);
        }
        $sql = "SELECT configuration_group_title FROM " . $this->dbPrefix . "configuration_group WHERE configuration_group_id='" . $id . "'";
        $result = $this->db->Execute($sql);
        if ($result->RecordCount() > 0) {
            return sprintf(REASON_CONFIG_GROUP_ID_ALREADY_EXISTS, $id);
        }
        return false;
    }

    public function parserInsertIgnoreInto(): void
    {
        if (!$this->tableExists($this->lineSplit[3])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[3]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'INSERT IGNORE INTO ' . $this->dbPrefix . substr($this->line, 19);
        }
    }

    public function parserTruncateTable(): void
    {
        if (!$this->tableExists($this->lineSplit[2])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'TRUNCATE TABLE ' . $this->dbPrefix . substr($this->line, 15);
        }
    }

    public function parserFrom(): void
    {
        if (!$this->tableExists($this->lineSplit[1])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[1]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'FROM ' . $this->dbPrefix . substr($this->line, 5);
        }
    }

    public function parserDeleteFrom(): void
    {
        if (!$this->tableExists($this->lineSplit[2])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'DELETE FROM ' . $this->dbPrefix . substr($this->line, 12);
        }
    }

    public function parserReplaceInto(): void
    {
        if (($this->lineSplit[2] === 'configuration' && ($result = $this->checkConfigKey($this->line))) ||
            ($this->lineSplit[2] === 'product_type_layout' && ($result = $this->checkProductTypeLayoutKey($this->line))) ||
            ($this->lineSplit === 'configuration_group' && ($result = $this->checkCfggroupKey($this->line))) ||
            (!$this->tableExists($this->lineSplit[2]))) {
            if (!isset($result)) {
                $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            }
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'REPLACE INTO ' . $this->dbPrefix . substr($this->line, 12);
        }
    }

    public function parserUpdate(): void
    {
        if (!$this->tableExists($this->lineSplit[1])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[1]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            $this->line = 'UPDATE ' . $this->dbPrefix . substr($this->line, 7);
        }
    }

    public function parserAlterTable(): void
    {
        if (!$this->tableExists($this->lineSplit[2])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
        } else {
            $this->line = 'ALTER TABLE ' . $this->dbPrefix . substr($this->line, 12);

            switch (strtoupper($this->lineSplit[3])) {
                case 'ADD':
                case 'DROP':
                    // Check to see if the column / index already exists
                    $exists = false;
                    switch (strtoupper($this->lineSplit[4])) {
                        case 'COLUMN':
                            $exists = $this->tableColumnExists($this->lineSplit[2], $this->lineSplit[5]);
                            break;
                        case 'INDEX':
                        case 'KEY':
                            // Do nothing if the index_name is ommitted
                            if ($this->lineSplit[5] !== 'USING' && !str_starts_with($this->lineSplit[5], '(')) {
                                $exists = $this->tableIndexExists($this->lineSplit[2], $this->lineSplit[5]);
                            }
                            break;
                        case 'UNIQUE':
                        case 'FULLTEXT':
                        case 'SPATIAL':
                            if ($this->lineSplit[6] === 'INDEX' || $this->lineSplit[6] === 'KEY') {
                                // Do nothing if the index_name is ommitted
                                if ($this->lineSplit[7] !== 'USING' && !str_starts_with($this->lineSplit[7], '(')) {
                                    $exists = $this->tableIndexExists($this->lineSplit[2], $this->lineSplit[7]);
                                }
                            } // Do nothing if the index_name is ommitted
                            else {
                                if ($this->lineSplit[6] !== 'USING' && !str_starts_with($this->lineSplit[6], '(')) {
                                    $exists = $this->tableIndexExists($this->lineSplit[2], $this->lineSplit[6]);
                                }
                            }
                            break;
                        case 'CONSTRAINT':
                        case 'PRIMARY':
                        case 'FOREIGN':
                            // Do nothing (no checks at this time)
                            break;
                        default:
                            // No known item added, MySQL defaults to column definition unless the action is to drop the item, then it is the reverse.
                            $exists = strtoupper($this->lineSplit[3]) !== 'DROP' && $this->tableColumnExists($this->lineSplit[2], $this->lineSplit[4]);
                    }
                    // Ignore this line if the column / index already exists
                    if ($exists) {
                        $this->ignoreLine = true;
                    }

                    break;
                default:
                    // Do nothing
            }
        }
    }

    public function tableColumnExists($table, $column): bool
    {
        if (!defined('DB_DATABASE')) {
            define('DB_DATABASE', $this->dbName);
        }
        $check = $this->db->Execute(
            'SHOW COLUMNS FROM `' . DB_DATABASE . '`.`' . $this->dbPrefix . $this->db->prepare_input($table) . '` ' .
            'WHERE `Field` = \'' . $this->db->prepare_input($column) . '\''
        );
        return !$check->EOF;
    }

    public function tableIndexExists($table, $index): bool
    {
        if (!defined('DB_DATABASE')) {
            define('DB_DATABASE', $this->dbName);
        }
        $check = $this->db->Execute(
            'SHOW INDEX FROM `' . DB_DATABASE . '`.`' . $this->dbPrefix . $this->db->prepare_input($table) . '` ' .
            'WHERE `Key_name` = \'' . $this->db->prepare_input($index) . '\''
        );
        return !$check->EOF;
    }

    public function parserRenameTable(): void
    {
        if (!$this->tableExists($this->lineSplit[2])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            $this->ignoreLine = true;
        } else {
            if ($this->tableExists($this->lineSplit[4])) {
                if (!isset($result)) {
                    $result = sprintf(REASON_TABLE_ALREADY_EXISTS, $this->lineSplit[4]);
                }
                $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
                $this->ignoreLine = true;
            } else {
                $this->line = 'RENAME TABLE ' . $this->dbPrefix . $this->lineSplit[2] . ' TO ' . $this->dbPrefix . substr($this->line, (13 + strlen($this->lineSplit[2]) + 4));
            }
        }
    }

    public function parserLeftJoin(): void
    {
        if (!$this->tableExists($this->lineSplit[2])) {
            $result = sprintf(REASON_TABLE_NOT_FOUND, $this->lineSplit[2]) . ' CHECK PREFIXES!';
            $this->writeUpgradeExceptions($this->line, $result, $this->fileName);
            error_log($result . "\n" . $this->line . "\n---------------\n\n");
        } else {
            $this->line = 'LEFT JOIN ' . $this->dbPrefix . substr($this->line, 10);
        }
    }

    public function parserEngineInnodb(): void
    {
        if (!defined('USE_INNODB') || USE_INNODB === false) {
            return;
        }
        if (!$this->table) {
            return;
        }
        $exceptions = (defined('INNODB_BLACKLIST')) ? INNODB_BLACKLIST : [];
        if (!is_array($exceptions)) {
            $exceptions = [];
        }
        if (in_array($this->table, $exceptions, true)) {
            return;
        }
        $this->line = str_replace('MyISAM', 'InnoDb', $this->line);
    }

    public function updateConfigKeys(): false|string
    {
        $error_message = false;
        if (isset($_POST['http_server_catalog']) && $_POST['http_server_catalog'] !== '') {
            // not tracking errors for this; if it fails, it fails silently; the storeowner can/will override these in Admin anyway.
            $email_stub = preg_replace('~.*\/\/(www.)*~', 'YOUR_EMAIL@', $_POST['http_server_catalog']);
            $sql = "UPDATE " . $this->dbPrefix . "configuration SET configuration_value=:emailStub: WHERE configuration_key IN ('STORE_OWNER_EMAIL_ADDRESS', 'EMAIL_FROM')";
            $sql = $this->db->bindVars($sql, ':emailStub:', $email_stub, 'string');
            $this->db->Execute($sql);
        }
        return $error_message;
    }

    public function doCompletion($options): void
    {
        global $request_type;
        if ($request_type === 'SSL') {
            $sql = "UPDATE " . $this->dbPrefix . "configuration SET configuration_value = '1:1', last_modified = now() WHERE configuration_key = 'SSLPWSTATUSCHECK'";
            $this->db->Execute($sql);
        }
        $sql = "UPDATE " . $this->dbPrefix . "admin
                SET admin_name = '" . $options['admin_user'] . "', admin_email = '" . $options['admin_email'] . "',
                    admin_pass = '" . zen_encrypt_password($options['admin_password']) . "',
                    pwd_last_change_date = " . ($request_type === 'SSL' ? 'NOW()' : 'timestamp("1970-01-01 00:00:00")')
                . ($request_type === 'SSL' ? '' : ", reset_token = '" . (time() + (72 * 60 * 60)) . "}" . zen_encrypt_password($options['admin_password']) . "'") . "
                WHERE admin_id = 1";
        $this->db->Execute($sql);

        if (defined('DEVELOPER_MODE') && DEVELOPER_MODE === true && defined('DEVELOPER_CONFIGS') && is_array(DEVELOPER_CONFIGS)) {
            foreach (DEVELOPER_CONFIGS as $key => $value) {
                if (null === $value) {
                    continue;
                }
                $sql = "UPDATE " . $this->dbPrefix . "configuration SET configuration_value = '" . $this->db->prepareInput($value) . "'
                        WHERE configuration_key = '" . $this->db->prepareInput($key) . "'";
                $this->db->Execute($sql);
            }
        }
    }
}
