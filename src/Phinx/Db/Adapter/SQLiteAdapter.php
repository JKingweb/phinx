<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2015 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */
namespace Phinx\Db\Adapter;

use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite as SqliteDriver;
use Phinx\Db\Table\Column;
use Phinx\Db\Table\ForeignKey;
use Phinx\Db\Table\Index;
use Phinx\Db\Table\Table;
use Phinx\Db\Util\AlterInstructions;
use Phinx\Util\Literal;

/**
 * Phinx SQLite Adapter.
 *
 * @author Rob Morgan <robbym@gmail.com>
 * @author Richard McIntyre <richard.mackstars@gmail.com>
 */
class SQLiteAdapter extends PdoAdapter implements AdapterInterface
{
    protected static $supportedColumnTypes = [
        self::PHINX_TYPE_BIG_INTEGER,
        self::PHINX_TYPE_BINARY,
        self::PHINX_TYPE_BLOB,
        self::PHINX_TYPE_BOOLEAN,
        self::PHINX_TYPE_CHAR,
        self::PHINX_TYPE_DATE,
        self::PHINX_TYPE_DATETIME,
        self::PHINX_TYPE_DOUBLE,
        self::PHINX_TYPE_FILESTREAM,
        self::PHINX_TYPE_FLOAT,
        self::PHINX_TYPE_INTEGER,
        self::PHINX_TYPE_JSON,
        self::PHINX_TYPE_JSONB,
        self::PHINX_TYPE_SMALL_INTEGER,
        self::PHINX_TYPE_STRING,
        self::PHINX_TYPE_TEXT,
        self::PHINX_TYPE_TIME,
        self::PHINX_TYPE_UUID,
        self::PHINX_TYPE_TIMESTAMP,
        self::PHINX_TYPE_VARBINARY
    ];
    protected static $unsupportedColumnTypes = [
        self::PHINX_TYPE_BIT,
        self::PHINX_TYPE_CIDR,
        self::PHINX_TYPE_DECIMAL,
        self::PHINX_TYPE_ENUM,
        self::PHINX_TYPE_GEOMETRY,
        self::PHINX_TYPE_INET,
        self::PHINX_TYPE_INTERVAL,
        self::PHINX_TYPE_LINESTRING,
        self::PHINX_TYPE_MACADDR,
        self::PHINX_TYPE_POINT,
        self::PHINX_TYPE_POLYGON,
        self::PHINX_TYPE_SET
    ];
    protected $definitionsWithLimits = [
        'CHAR',
        'CHARACTER',
        'VARCHAR',
        'VARYING CHARACTER',
        'NCHAR',
        'NATIVE CHARACTER',
        'NVARCHAR'
    ];

    protected $suffix = '.sqlite3';

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if ($this->connection === null) {
            if (!class_exists('PDO') || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('You need to enable the PDO_SQLITE extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }

            $db = null;
            $options = $this->getOptions();

            // use a memory database if the option was specified
            if (!empty($options['memory'])) {
                $dsn = 'sqlite::memory:';
            } else {
                $dsn = 'sqlite:' . $options['name'] . $this->suffix;
            }

            try {
                $db = new \PDO($dsn);
            } catch (\PDOException $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: %s',
                    $exception->getMessage()
                ));
            }

            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->setConnection($db);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setOptions(array $options)
    {
        parent::setOptions($options);

        if (isset($options['suffix'])) {
            $this->suffix = $options['suffix'];
        }
        //don't "fix" the file extension if it is blank, some people
        //might want a SQLITE db file with absolutely no extension.
        if (strlen($this->suffix) && substr($this->suffix, 0, 1) !== '.') {
            $this->suffix = '.' . $this->suffix;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTransactions()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        $this->getConnection()->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function commitTransaction()
    {
        $this->getConnection()->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function rollbackTransaction()
    {
        $this->getConnection()->rollBack();
    }

    /**
     * {@inheritdoc}
     */
    public function quoteTableName($tableName)
    {
        return str_replace('.', '`.`', $this->quoteColumnName($tableName));
    }

    /**
     * {@inheritdoc}
     */
    public function quoteColumnName($columnName)
    {
        return '`' . str_replace('`', '``', $columnName) . '`';
    }

    /**
     * @param string $tableName Table name
     * @return array
     */
    protected function getSchemaName($tableName)
    {
        if (preg_match("/.\.([^\.]+)$/", $tableName, $match)) {
            $table = $match[1];
            $schema = substr($tableName, 0, strlen($tableName) - strlen($match[0]) + 1);
            $result = ['schema' => $schema, 'table' => $table];
        } else {
            $result = ['schema' => '', 'table' => $tableName];
        }

        return $result;
    }

    protected function getTableInfo($tableName, $pragma = 'table_info')
    {
        $info = $this->getSchemaName($tableName);
        $table = $info['table'];
        $schema = strlen($info['schema']) ? $this->quoteColumnName($info['schema']) . '.' : '';
        return $this->fetchAll(sprintf('PRAGMA %s%s(%s)', $schema, $pragma, $this->quoteColumnName($table)));
    }

    /**
     * {@inheritdoc}
     */
    public function hasTable($tableName)
    {
        $info = $this->getSchemaName($tableName);
        if ($info['schema'] === '') {
            // if no schema is specified we search all schemata
            $rows = $this->fetchAll('PRAGMA database_list;');
            $schemata = [];
            foreach ($rows as $row) {
                $schemata[] = $row['name'];
            }
        } else {
            // otherwise we search just the specified schema
            $schemata = (array)$info['schema'];
        }

        $table = strtolower($info['table']);
        foreach ($schemata as $schema) {
            if (strtolower($schema) === 'temp') {
                $master = 'sqlite_temp_master';
            } else {
                $master = sprintf('%s.%s', $this->quoteColumnName($schema), 'sqlite_master');
            }
            try {
                $rows = $this->fetchAll(sprintf('SELECT name FROM %s WHERE type=\'table\' AND lower(name) = %s', $master, $this->quoteString($table)));
            } catch (\PDOException $e) {
                // an exception can occur if the schema part of the table refers to a database which is not attached
                return false;
            }

            // this somewhat pedantic check with strtolower is performed because the SQL lower function may be redefined,
            // and can act on all Unicode characters if the ICU extension is loaded, while SQL identifiers are only case-insensitive for ASCII
            foreach ($rows as $row) {
                if (strtolower($row['name']) === $table) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function createTable(Table $table, array $columns = [], array $indexes = [])
    {
        // Add the default primary key
        $options = $table->getOptions();
        if (!isset($options['id']) || (isset($options['id']) && $options['id'] === true)) {
            $options['id'] = 'id';
        }

        if (isset($options['id']) && is_string($options['id'])) {
            // Handle id => "field_name" to support AUTO_INCREMENT
            $column = new Column();
            $column->setName($options['id'])
                   ->setType('integer')
                   ->setIdentity(true);

            array_unshift($columns, $column);
        }

        $sql = 'CREATE TABLE ';
        $sql .= $this->quoteTableName($table->getName()) . ' (';
        foreach ($columns as $column) {
            $sql .= $this->quoteColumnName($column->getName()) . ' ' . $this->getColumnSqlDefinition($column) . ', ';

            if (isset($options['primary_key']) && $column->getIdentity()) {
                //remove column from the primary key array as it is already defined as an autoincrement
                //primary id
                $identityColumnIndex = array_search($column->getName(), $options['primary_key']);
                if ($identityColumnIndex !== false) {
                    unset($options['primary_key'][$identityColumnIndex]);

                    if (empty($options['primary_key'])) {
                        //The last primary key has been removed
                        unset($options['primary_key']);
                    }
                }
            }
        }

        // set the primary key(s)
        if (isset($options['primary_key'])) {
            $sql = rtrim($sql);
            $sql .= ' PRIMARY KEY (';
            if (is_string($options['primary_key'])) { // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($options['primary_key']);
            } elseif (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
                $sql .= implode(',', array_map([$this, 'quoteColumnName'], $options['primary_key']));
            }
            $sql .= ')';
        } else {
            $sql = substr(rtrim($sql), 0, -1); // no primary keys
        }

        $sql = rtrim($sql) . ');';
        // execute the sql
        $this->execute($sql);

        foreach ($indexes as $index) {
            $this->addIndex($table, $index);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getChangePrimaryKeyInstructions(Table $table, $newColumns)
    {
        $instructions = new AlterInstructions();

        // Drop the existing primary key
        $primaryKey = $this->getPrimaryKey($table->getName());
        if (!empty($primaryKey)) {
            $instructions->merge(
                // FIXME: array access is a hack to make this incomplete implementation work with a correct getPrimaryKey implementation
                $this->getDropPrimaryKeyInstructions($table, $primaryKey[0])
            );
        }

        // Add the primary key(s)
        if (!empty($newColumns)) {
            if (!is_string($newColumns)) {
                throw new \InvalidArgumentException(sprintf(
                    "Invalid value for primary key: %s",
                    json_encode($newColumns)
                ));
            }

            $instructions->merge(
                $this->getAddPrimaryKeyInstructions($table, $newColumns)
            );
        }

        return $instructions;
    }

    /**
     * {@inheritdoc}
     */
    protected function getChangeCommentInstructions(Table $table, $newComment)
    {
        throw new \BadMethodCallException('SQLite does not have table comments');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenameTableInstructions($tableName, $newTableName)
    {
        $sql = sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->quoteTableName($tableName),
            $this->quoteTableName($newTableName)
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDropTableInstructions($tableName)
    {
        $sql = sprintf('DROP TABLE %s', $this->quoteTableName($tableName));

        return new AlterInstructions([], [$sql]);
    }

    /**
     * {@inheritdoc}
     */
    public function truncateTable($tableName)
    {
        $sql = sprintf(
            'DELETE FROM %s',
            $this->quoteTableName($tableName)
        );

        $this->execute($sql);
    }

    /**
     * 
     * Parses a default-value expression to yield either a Literal representing 
     * a string value, a string representing an expression, or some other scalar
     * 
     * @param mixed $v The default-value expression to interpret
     * @param string $t The Phinx type of the column
     * @return mixed
     */
    protected function parseDefaultValue($v, $t) 
    {

        if (is_null($v)) {
            return null;
        }

        $matchPattern = function ($p, $v, &$m = null) {
            // this whole process is complicated by an SQLite bug; see http://sqlite.1065341.n5.nabble.com/Bug-in-table-info-pragma-td107176.html
            $out = preg_match("/^($p)(?:\s*(?:\/\*|--))?/i", $v, $m);
            if ($m) {
                array_shift($m);
            }
            return $out;
        };

        if ($matchPattern("'((?:[^']|'')*)'", $v, $m)) {
            // string literal
            return Literal::from(str_replace("''", "'", $m[1]));
        } elseif ($matchPattern('true|false', $v)) {
            // boolean literal (since SQLite 3.23)
            return (strtolower($v[0]) === 't');
        } elseif ($matchPattern('current_(?:date|time(?:stamp)?)', $v, $m)) {
            // magic date/time keywords
            return strtoupper($m[0]);
        } elseif ($matchPattern("[-+]?(\d+(?:\.\d*)?|\.\d+)(e[-+]?\d+)?",$v, $m)) {
            // decimal number; see https://sqlite.org/syntax/numeric-literal.html
            if ($t === self::PHINX_TYPE_BOOLEAN) {
                return (bool)(float)$m[0];
            } elseif (abs(fmod((float)$m[0], 1)) > 0) {
                return (float)$m[0];
            } else {
                return (int)$m[0];
            }
        } elseif ($matchPattern('null', $v)) {
            // explicit null
            return null;
        } else {
            // some other expression
            // this includes blob literals, hexadecimal literals, and arbitrary expressions
            return $v;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getColumns($tableName)
    {
        $columns = [];

        $info = $this->getSchemaName($tableName, true);
        $rows = $this->fetchAll(sprintf(
            'PRAGMA %s.table_info(%s)', 
            $this->quoteColumnName($info['schema']),
            $this->quoteColumnName($info['table'])
        ));

        foreach ($rows as $columnInfo) {
            $column = new Column();
            $type = $this->getPhinxType(strtolower($columnInfo['type']));
            $default = $this->parseDefaultValue($columnInfo['dflt_value'], $type['name']);
            
            $column->setName($columnInfo['name'])
                   ->setNull($columnInfo['notnull'] !== '1')
                   ->setDefault($default)
                   ->setType($type['name'])
                   ->setLimit($type['limit'])
                   ->setScale($type['scale']);

            if ($columnInfo['pk'] == 1) {
                $column->setIdentity(true);
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function hasColumn($tableName, $columnName)
    {
        $rows = $this->fetchAll(sprintf('pragma table_info(%s)', $this->quoteTableName($tableName)));
        foreach ($rows as $column) {
            if (strcasecmp($column['name'], $columnName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAddColumnInstructions(Table $table, Column $column)
    {
        $alter = sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteTableName($table->getName()),
            $this->quoteColumnName($column->getName()),
            $this->getColumnSqlDefinition($column)
        );

        return new AlterInstructions([], [$alter]);
    }

    /**
     * Returns the original CREATE statement for the give table
     *
     * @param string $tableName The table name to get the create statement for
     * @return string
     */
    protected function getDeclaringSql($tableName)
    {
        $rows = $this->fetchAll('select * from sqlite_master where `type` = \'table\'');

        $sql = '';
        foreach ($rows as $table) {
            if ($table['tbl_name'] === $tableName) {
                $sql = $table['sql'];
            }
        }

        return $sql;
    }

    /**
     * Copies all the data from a tmp table to another table
     *
     * @param string $tableName The table name to copy the data to
     * @param string $tmpTableName The tmp table name where the data is stored
     * @param string[] $writeColumns The list of columns in the target table
     * @param string[] $selectColumns The list of columns in the tmp table
     * @return void
     */
    protected function copyDataToNewTable($tableName, $tmpTableName, $writeColumns, $selectColumns)
    {
        $sql = sprintf(
            'INSERT INTO %s(%s) SELECT %s FROM %s',
            $this->quoteTableName($tableName),
            implode(', ', $writeColumns),
            implode(', ', $selectColumns),
            $this->quoteTableName($tmpTableName)
        );
        $this->execute($sql);
    }

    /**
     * Modifies the passed instructions to copy all data from the tmp table into
     * the provided table and then drops the tmp table.
     *
     * @param AlterInstructions $instructions The instructions to modify
     * @param string $tableName The table name to copy the data to
     * @return AlterInstructions
     */
    protected function copyAndDropTmpTable($instructions, $tableName)
    {
        $instructions->addPostStep(function ($state) use ($tableName) {
            $this->copyDataToNewTable(
                $tableName,
                $state['tmpTableName'],
                $state['writeColumns'],
                $state['selectColumns']
            );

            $this->execute(sprintf('DROP TABLE %s', $this->quoteTableName($state['tmpTableName'])));

            return $state;
        });

        return $instructions;
    }

    /**
     * Returns the columns and type to use when copying a table to another in the process
     * of altering a table
     *
     * @param string $tableName The table to modify
     * @param string $columnName The column name that is about to change
     * @param string|false $newColumnName Optionally the new name for the column
     * @return AlterInstructions
     */
    protected function calculateNewTableColumns($tableName, $columnName, $newColumnName)
    {
        $columns = $this->fetchAll(sprintf('pragma table_info(%s)', $this->quoteTableName($tableName)));
        $selectColumns = [];
        $writeColumns = [];
        $columnType = null;
        $found = false;

        foreach ($columns as $column) {
            $selectName = $column['name'];
            $writeName = $selectName;

            if ($selectName == $columnName) {
                $writeName = $newColumnName;
                $found = true;
                $columnType = $column['type'];
                $selectName = $newColumnName === false ? $newColumnName : $selectName;
            }

            $selectColumns[] = $selectName;
            $writeColumns[] = $writeName;
        }

        $selectColumns = array_filter($selectColumns, 'strlen');
        $writeColumns = array_filter($writeColumns, 'strlen');
        $selectColumns = array_map([$this, 'quoteColumnName'], $selectColumns);
        $writeColumns = array_map([$this, 'quoteColumnName'], $writeColumns);

        if (!$found) {
            throw new \InvalidArgumentException(sprintf(
                'The specified column doesn\'t exist: ' . $columnName
            ));
        }

        return compact('writeColumns', 'selectColumns', 'columnType');
    }

    /**
     * Returns the initial instructions to alter a table using the
     * rename-alter-copy strategy
     *
     * @param string $tableName The table to modify
     * @return AlterInstructions
     */
    protected function beginAlterByCopyTable($tableName)
    {
        $instructions = new AlterInstructions();
        $instructions->addPostStep(function ($state) use ($tableName) {
            $createSQL = $this->getDeclaringSql($tableName);

            $tmpTableName = 'tmp_' . $tableName;
            $this->execute(
                sprintf(
                    'ALTER TABLE %s RENAME TO %s',
                    $this->quoteTableName($tableName),
                    $this->quoteTableName($tmpTableName)
                )
            );

            return compact('createSQL', 'tmpTableName') + $state;
        });

        return $instructions;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenameColumnInstructions($tableName, $columnName, $newColumnName)
    {
        $instructions = $this->beginAlterByCopyTable($tableName);
        $instructions->addPostStep(function ($state) use ($columnName, $newColumnName) {
            $newState = $this->calculateNewTableColumns($state['tmpTableName'], $columnName, $newColumnName);

            return $newState + $state;
        });

        $instructions->addPostStep(function ($state) use ($columnName, $newColumnName) {
            $sql = str_replace(
                $this->quoteColumnName($columnName),
                $this->quoteColumnName($newColumnName),
                $state['createSQL']
            );
            $this->execute($sql);

            return $state;
        });

        return $this->copyAndDropTmpTable($instructions, $tableName);
    }

    /**
     * {@inheritdoc}
     */
    protected function getChangeColumnInstructions($tableName, $columnName, Column $newColumn)
    {
        $instructions = $this->beginAlterByCopyTable($tableName);

        $newColumnName = $newColumn->getName();
        $instructions->addPostStep(function ($state) use ($columnName, $newColumnName) {
            $newState = $this->calculateNewTableColumns($state['tmpTableName'], $columnName, $newColumnName);

            return $newState + $state;
        });

        $instructions->addPostStep(function ($state) use ($columnName, $newColumn) {
            $sql = preg_replace(
                sprintf("/%s(?:\/\*.*?\*\/|\([^)]+\)|'[^']*?'|[^,])+([,)])/", $this->quoteColumnName($columnName)),
                sprintf('%s %s$1', $this->quoteColumnName($newColumn->getName()), $this->getColumnSqlDefinition($newColumn)),
                $state['createSQL'],
                1
            );
            $this->execute($sql);

            return $state;
        });

        return $this->copyAndDropTmpTable($instructions, $tableName);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDropColumnInstructions($tableName, $columnName)
    {
        $instructions = $this->beginAlterByCopyTable($tableName);

        $instructions->addPostStep(function ($state) use ($columnName) {
            $newState = $this->calculateNewTableColumns($state['tmpTableName'], $columnName, false);

            return $newState + $state;
        });

        $instructions->addPostStep(function ($state) use ($columnName) {
            $sql = preg_replace(
                sprintf("/%s\s%s.*(,\s(?!')|\)$)/U", preg_quote($this->quoteColumnName($columnName)), preg_quote($state['columnType'])),
                "",
                $state['createSQL']
            );

            if (substr($sql, -2) === ', ') {
                $sql = substr($sql, 0, -2) . ')';
            }

            $this->execute($sql);

            return $state;
        });

        return $this->copyAndDropTmpTable($instructions, $tableName);
    }

    /**
     * Get an array of indexes from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getIndexes($tableName)
    {
        $indexes = [];
        $rows = $this->fetchAll(sprintf('pragma index_list(%s)', $tableName));

        foreach ($rows as $row) {
            $indexData = $this->fetchAll(sprintf('pragma index_info(%s)', $row['name']));
            if (!isset($indexes[$tableName])) {
                $indexes[$tableName] = ['index' => $row['name'], 'columns' => []];
            }
            foreach ($indexData as $indexItem) {
                $indexes[$tableName]['columns'][] = strtolower($indexItem['name']);
            }
        }

        return $indexes;
    }

    /**
     * {@inheritdoc}
     */
    public function hasIndex($tableName, $columns)
    {
        $columns = array_map('strtolower', (array)$columns);
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function hasIndexByName($tableName, $indexName)
    {
        $indexes = $this->getIndexes($tableName);

        foreach ($indexes as $index) {
            if ($indexName === $index['index']) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAddIndexInstructions(Table $table, Index $index)
    {
        $indexColumnArray = [];
        foreach ($index->getColumns() as $column) {
            $indexColumnArray[] = sprintf('`%s` ASC', $column);
        }
        $indexColumns = implode(',', $indexColumnArray);
        $sql = sprintf(
            'CREATE %s ON %s (%s)',
            $this->getIndexSqlDefinition($table, $index),
            $this->quoteTableName($table->getName()),
            $indexColumns
        );

        return new AlterInstructions([], [$sql]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDropIndexByColumnsInstructions($tableName, $columns)
    {
        $indexes = $this->getIndexes($tableName);
        $columns = array_map('strtolower', (array)$columns);
        $instructions = new AlterInstructions();

        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                $instructions->addPostStep(sprintf(
                    'DROP INDEX %s',
                    $this->quoteColumnName($index['index'])
                ));
            }
        }

        return $instructions;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDropIndexByNameInstructions($tableName, $indexName)
    {
        $indexes = $this->getIndexes($tableName);
        $instructions = new AlterInstructions();

        foreach ($indexes as $index) {
            if ($indexName === $index['index']) {
                $instructions->addPostStep(sprintf(
                    'DROP INDEX %s',
                    $this->quoteColumnName($indexName)
                ));
            }
        }

        return $instructions;
    }

    /**
     * {@inheritdoc}
     */
    public function hasPrimaryKey($tableName, $columns, $constraint = null)
    {
        $columns = array_map('strtolower', (array)$columns);
        $primaryKey = array_map('strtolower', $this->getPrimaryKey($tableName));

        if (array_diff($primaryKey, $columns)) {
            return false;
        } elseif (array_diff($columns, $primaryKey)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get the primary key from a particular table.
     *
     * @param string $tableName Table Name
     * @return string[]
     */
    protected function getPrimaryKey($tableName)
    {
        $primaryKey = [];

        $rows = $this->getTableInfo($tableName);

        foreach ($rows as $row) {
            if ($row['pk'] > 0) {
                $primaryKey[$row['pk'] - 1] = $row['name'];
            }
        }

        return $primaryKey;
    }

    /**
     * {@inheritdoc}
     */
    public function hasForeignKey($tableName, $columns, $constraint = null)
    {
        $columns = array_map('strtolower', (array)$columns);
        $foreignKeys = $this->getForeignKeys($tableName);

        foreach ($foreignKeys as $key) {
            $key = array_map('strtolower', $key);
            if (array_diff($key, $columns)) {
                continue;
            } elseif (array_diff($columns, $key)) {
                continue;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
    protected function getForeignKeys($tableName)
    {
        $foreignKeys = [];

        $rows = $this->getTableInfo($tableName, 'foreign_key_list');

        foreach ($rows as $row) {
            if (!isset($foreignKeys[$row['id']])) {
                $foreignKeys[$row['id']] = [];
            }
            $foreignKeys[$row['id']][$row['seq']] = $row['from'];
        }

        return $foreignKeys;
    }

    /**
     * @param Table $table The Table
     * @param string $column Column Name
     * @return AlterInstructions
     */
    protected function getAddPrimaryKeyInstructions(Table $table, $column)
    {
        $instructions = $this->beginAlterByCopyTable($table->getName());

        $tableName = $table->getName();
        $instructions->addPostStep(function ($state) use ($column) {
            $matchPattern = "/(`$column`)\s+(\w+(\(\d+\))?)\s+((NOT )?NULL)/";

            $sql = $state['createSQL'];

            if (preg_match($matchPattern, $state['createSQL'], $matches)) {
                if (isset($matches[2])) {
                    if ($matches[2] === 'INTEGER') {
                        $replace = '$1 INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT';
                    } else {
                        $replace = '$1 $2 NOT NULL PRIMARY KEY';
                    }

                    $sql = preg_replace($matchPattern, $replace, $state['createSQL'], 1);
                }
            }

            $this->execute($sql);

            return $state;
        });

        $instructions->addPostStep(function ($state) {
            $columns = $this->fetchAll(sprintf('pragma table_info(%s)', $this->quoteTableName($state['tmpTableName'])));
            $names = array_map([$this, 'quoteColumnName'], array_column($columns, 'name'));
            $selectColumns = $writeColumns = $names;

            return compact('selectColumns', 'writeColumns') + $state;
        });

        return $this->copyAndDropTmpTable($instructions, $tableName);
    }

    /**
     * @param Table $table Table
     * @param string $column Column Name
     * @return AlterInstructions
     */
    protected function getDropPrimaryKeyInstructions($table, $column)
    {
        $instructions = $this->beginAlterByCopyTable($table->getName());

        $instructions->addPostStep(function ($state) use ($column) {
            $newState = $this->calculateNewTableColumns($state['tmpTableName'], $column, $column);

            return $newState + $state;
        });

        $instructions->addPostStep(function ($state) {
            $search = "/(,?\s*PRIMARY KEY\s*\([^\)]*\)|\s+PRIMARY KEY(\s+AUTOINCREMENT)?)/";
            $sql = preg_replace($search, '', $state['createSQL'], 1);

            if ($sql) {
                $this->execute($sql);
            }

            return $state;
        });

        return $this->copyAndDropTmpTable($instructions, $table->getName());
    }

    /**
     * {@inheritdoc}
     */
    protected function getAddForeignKeyInstructions(Table $table, ForeignKey $foreignKey)
    {
        $instructions = $this->beginAlterByCopyTable($table->getName());

        $tableName = $table->getName();
        $instructions->addPostStep(function ($state) use ($foreignKey) {
            $this->execute('pragma foreign_keys = ON');
            $sql = substr($state['createSQL'], 0, -1) . ',' . $this->getForeignKeySqlDefinition($foreignKey) . ')';
            $this->execute($sql);

            return $state;
        });

        $instructions->addPostStep(function ($state) {
            $columns = $this->fetchAll(sprintf('pragma table_info(%s)', $this->quoteTableName($state['tmpTableName'])));
            $names = array_map([$this, 'quoteColumnName'], array_column($columns, 'name'));
            $selectColumns = $writeColumns = $names;

            return compact('selectColumns', 'writeColumns') + $state;
        });

        return $this->copyAndDropTmpTable($instructions, $tableName);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDropForeignKeyInstructions($tableName, $constraint)
    {
        throw new \BadMethodCallException('SQLite does not have named foreign keys');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDropForeignKeyByColumnsInstructions($tableName, $columns)
    {
        $instructions = $this->beginAlterByCopyTable($tableName);

        $instructions->addPostStep(function ($state) use ($columns) {
            $newState = $this->calculateNewTableColumns($state['tmpTableName'], $columns[0], $columns[0]);

            $selectColumns = $newState['selectColumns'];
            $columns = array_map([$this, 'quoteColumnName'], $columns);
            $diff = array_diff($columns, $selectColumns);

            if (!empty($diff)) {
                throw new \InvalidArgumentException(sprintf(
                    'The specified columns don\'t exist: ' . implode(', ', $diff)
                ));
            }

            return $newState + $state;
        });

        $instructions->addPostStep(function ($state) use ($columns) {
            $sql = '';

            foreach ($columns as $columnName) {
                $search = sprintf(
                    "/,[^,]*\(%s(?:,`?(.*)`?)?\) REFERENCES[^,]*\([^\)]*\)[^,)]*/",
                    $this->quoteColumnName($columnName)
                );
                $sql = preg_replace($search, '', $state['createSQL'], 1);
            }

            if ($sql) {
                $this->execute($sql);
            }

            return $state;
        });

        return $this->copyAndDropTmpTable($instructions, $tableName);
    }

    /**
     * {@inheritdoc}
     */
    public function getSqlType($type, $limit = null)
    {
        $name = $type;
        $affinity = '';
        // first decide if a type is supported, and if so its affinity
        // type-names which naturally match an affinity don't need to have it added
        // see https://sqlite.org/datatype3.html#type_affinity for details on affinity
        switch ($type) {
            case self::PHINX_TYPE_STRING:
                $name = "varchar";
                break;
            case self::PHINX_TYPE_CHAR:
            case self::PHINX_TYPE_TEXT:
                break;
            case self::PHINX_TYPE_DATETIME:
            case self::PHINX_TYPE_TIMESTAMP:
            case self::PHINX_TYPE_TIME:
            case self::PHINX_TYPE_DATE:
                // dates and times are not a distinct type, but one can still make useful comparisons
                $affinity = 'text';
                break;
            case self::PHINX_TYPE_JSON:
            case self::PHINX_TYPE_JSONB:
            case self::PHINX_TYPE_UUID:
                // SQLite does have a JSON extension, and UUIDs are opaque
                $affinity = 'text';
                break;
            case self::PHINX_TYPE_SMALL_INTEGER:
            case self::PHINX_TYPE_INTEGER:
            case self::PHINX_TYPE_BIG_INTEGER:
                // NOTE: any identity column should always have the type "INTEGER" regardless of size
                break;
            case self::PHINX_TYPE_FLOAT:
            case self::PHINX_TYPE_DOUBLE:
                break;
            case self::PHINX_TYPE_BLOB:
                break;
            case self::PHINX_TYPE_BINARY:
            case self::PHINX_TYPE_VARBINARY:
            case self::PHINX_TYPE_FILESTREAM:
                $affinity = 'blob';
                break;
            case self::PHINX_TYPE_BOOLEAN:
                $affinity = 'integer';
                break;
            case self::PHINX_TYPE_DECIMAL:
                // SQLite does not support fixed-point numbers, and to silently provide floating-point instead probably would not be helpful
            case self::PHINX_TYPE_BIT:
                // while a bit-field could be encoded as an integer, it would not have equivalent semantics to MySQL's type
            case self::PHINX_TYPE_GEOMETRY:
            case self::PHINX_TYPE_POINT:
            case self::PHINX_TYPE_LINESTRING:
            case self::PHINX_TYPE_POLYGON:
                // SQLite does have a spatial extension; someone more familiar with it would need to ascertain whether declaring these types is at all useful
            case self::PHINX_TYPE_ENUM:
            case self::PHINX_TYPE_SET:
                // there is no way to reliably replicate the behaviour of SQLite enums or sets; the PostgreSQL adapter doesn't try, either
            case self::PHINX_TYPE_CIDR:
            case self::PHINX_TYPE_INET:
            case self::PHINX_TYPE_MACADDR:
            case self::PHINX_TYPE_INTERVAL:
                throw new UnsupportedColumnTypeException('Column type "' . $type . '" is not supported by SQLite.');
            default:
                throw new UnsupportedColumnTypeException('Column type "' . $type . '" is unknown.');
        }
        
        // return the Phinx type with the affinity appended, if necessary
        if ($affinity) {
            $name = sprintf('%s_%s', $name, $affinity);
        }
        return ['name' => $name, 'limit' => $limit];
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @param string $sqlTypeDef SQL type
     * @return array
     */
    public function getPhinxType($sqlTypeDef)
    {
        $limit = null;
        $scale = null;
        if (!preg_match('/^([a-z]+)(_(?:integer|float|text|blob))?(?:\((\d+)(?:,(\d+))?\))?$/i', $sqlTypeDef, $match)) {
            $type = Literal::from($sqlTypeDef);
        } else {
            $type = $match[1];
            $affinity = isset($match[2]) ? $match[2] : '';
            $limit = isset($match[3]) && strlen($match[3]) ? (int)$match[3] : null;
            $scale = isset($match[4]) && strlen($match[4]) ? (int)$match[4] : null;
            switch (strtolower($type)) {
                case 'varchar':
                    $type = static::PHINX_TYPE_STRING;
                    break;
                case 'tinyint':
                case 'tinyinteger':
                    if ($limit == 1) {
                        $type = static::PHINX_TYPE_BOOLEAN;
                        $limit = null;
                    } else {
                        $type = static::PHINX_TYPE_SMALL_INTEGER;
                    }
                    break;
                case 'smallint':
                    $type = static::PHINX_TYPE_SMALL_INTEGER;
                    break;
                case 'int':
                case 'mediumint':
                case 'mediuminteger':
                    $type = static::PHINX_TYPE_INTEGER;
                    break;
                case 'bigint':
                    $type = static::PHINX_TYPE_BIG_INTEGER;
                    break;
                case 'tinytext':
                case 'mediumtext':
                case 'longtext':
                    $type = static::PHINX_TYPE_TEXT;
                    break;
                case 'tinyblob':
                case 'mediumblob':
                case 'longblob':
                    $type = static::PHINX_TYPE_BLOB;
                    break;
                case 'real':
                case 'numeric':
                    $type = self::PHINX_TYPE_FLOAT;
                    break;
                case self::PHINX_TYPE_STRING:
                case self::PHINX_TYPE_CHAR:
                case self::PHINX_TYPE_TEXT:
                case self::PHINX_TYPE_DATETIME:
                case self::PHINX_TYPE_TIMESTAMP:
                case self::PHINX_TYPE_TIME:
                case self::PHINX_TYPE_DATE:
                case self::PHINX_TYPE_JSON:
                case self::PHINX_TYPE_JSONB:
                case self::PHINX_TYPE_UUID:
                case self::PHINX_TYPE_SMALL_INTEGER:
                case self::PHINX_TYPE_INTEGER:
                case self::PHINX_TYPE_BIG_INTEGER:
                case self::PHINX_TYPE_FLOAT:
                case self::PHINX_TYPE_DOUBLE:
                case self::PHINX_TYPE_BLOB:
                case self::PHINX_TYPE_BINARY:
                case self::PHINX_TYPE_VARBINARY:
                case self::PHINX_TYPE_FILESTREAM:
                case self::PHINX_TYPE_BOOLEAN:
                    $type = strtolower($type);
                    break;
                case self::PHINX_TYPE_DECIMAL:
                case self::PHINX_TYPE_BIT:
                case self::PHINX_TYPE_GEOMETRY:
                case self::PHINX_TYPE_POINT:
                case self::PHINX_TYPE_LINESTRING:
                case self::PHINX_TYPE_POLYGON:
                case self::PHINX_TYPE_ENUM:
                case self::PHINX_TYPE_SET:
                case self::PHINX_TYPE_CIDR:
                case self::PHINX_TYPE_INET:
                case self::PHINX_TYPE_MACADDR:
                case self::PHINX_TYPE_INTERVAL:
                    // type is not supported, but we pass it through anyway
                    $type = Literal::from(strtolower($type));
                    break;
                default:
                    // type is unknown
                    $type = Literal::from($type.$affinity);
            }
        }

        return [
            'name' => $type,
            'limit' => $limit,
            'scale' => $scale
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function createDatabase($name, $options = [])
    {
        touch($name . $this->suffix);
    }

    /**
     * {@inheritdoc}
     */
    public function hasDatabase($name)
    {
        return is_file($name . $this->suffix);
    }

    /**
     * {@inheritdoc}
     */
    public function dropDatabase($name)
    {
        if ($this->getOption('memory')) {
            $this->disconnect();
            $this->connect();
        }
        if (file_exists($name . $this->suffix)) {
            unlink($name . $this->suffix);
        }
    }

    /**
     * Gets the SQLite Column Definition for a Column object.
     *
     * @param \Phinx\Db\Table\Column $column Column
     * @return string
     */
    protected function getColumnSqlDefinition(Column $column)
    {
        $isLiteralType = $column->getType() instanceof Literal;
        if ($isLiteralType) {
            $def = (string)$column->getType();
        } else {
            $sqlType = $this->getSqlType($column->getType());
            $def = strtoupper($sqlType['name']);

            $limitable = in_array(strtoupper($sqlType['name']), $this->definitionsWithLimits);
            if (($column->getLimit() || isset($sqlType['limit'])) && $limitable) {
                $def .= '(' . ($column->getLimit() ?: $sqlType['limit']) . ')';
            }
        }
        if ($column->getPrecision() && $column->getScale()) {
            $def .= '(' . $column->getPrecision() . ',' . $column->getScale() . ')';
        }
        if (($values = $column->getValues()) && is_array($values)) {
            $def .= " CHECK({$this->quoteColumnName($column->getName())} IN ('" . implode("', '", $values) . "'))";
        }

        $default = $column->getDefault();

        $def .= (!$column->isIdentity() && ($column->isNull() || is_null($default))) ? ' NULL' : ' NOT NULL';
        $def .= $this->getDefaultValueDefinition($default, $column->getType());
        $def .= $column->isIdentity() ? ' PRIMARY KEY AUTOINCREMENT' : '';

        if ($column->getUpdate()) {
            $def .= ' ON UPDATE ' . $column->getUpdate();
        }

        $def .= $this->getCommentDefinition($column);

        return $def;
    }

    /**
     * Gets the comment Definition for a Column object.
     *
     * @param \Phinx\Db\Table\Column $column Column
     * @return string
     */
    protected function getCommentDefinition(Column $column)
    {
        if ($column->getComment()) {
            return ' /* ' . $column->getComment() . ' */ ';
        }

        return '';
    }

    /**
     * Gets the SQLite Index Definition for an Index object.
     *
     * @param \Phinx\Db\Table\Table $table Table
     * @param \Phinx\Db\Table\Index $index Index
     * @return string
     */
    protected function getIndexSqlDefinition(Table $table, Index $index)
    {
        if ($index->getType() === Index::UNIQUE) {
            $def = 'UNIQUE INDEX';
        } else {
            $def = 'INDEX';
        }
        if (is_string($index->getName())) {
            $indexName = $index->getName();
        } else {
            $indexName = $table->getName() . '_';
            foreach ($index->getColumns() as $column) {
                $indexName .= $column . '_';
            }
            $indexName .= 'index';
        }
        $def .= ' `' . $indexName . '`';

        return $def;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnTypes()
    {
        return self::$supportedColumnTypes;
    }

    /**
     * Gets the SQLite Foreign Key Definition for an ForeignKey object.
     *
     * @param \Phinx\Db\Table\ForeignKey $foreignKey
     * @return string
     */
    protected function getForeignKeySqlDefinition(ForeignKey $foreignKey)
    {
        $def = '';
        if ($foreignKey->getConstraint()) {
            $def .= ' CONSTRAINT ' . $this->quoteColumnName($foreignKey->getConstraint());
        } else {
            $columnNames = [];
            foreach ($foreignKey->getColumns() as $column) {
                $columnNames[] = $this->quoteColumnName($column);
            }
            $def .= ' FOREIGN KEY (' . implode(',', $columnNames) . ')';
            $refColumnNames = [];
            foreach ($foreignKey->getReferencedColumns() as $column) {
                $refColumnNames[] = $this->quoteColumnName($column);
            }
            $def .= ' REFERENCES ' . $this->quoteTableName($foreignKey->getReferencedTable()->getName()) . ' (' . implode(',', $refColumnNames) . ')';
            if ($foreignKey->getOnDelete()) {
                $def .= ' ON DELETE ' . $foreignKey->getOnDelete();
            }
            if ($foreignKey->getOnUpdate()) {
                $def .= ' ON UPDATE ' . $foreignKey->getOnUpdate();
            }
        }

        return $def;
    }

    /**
     * {@inheritDoc}
     *
     */
    public function getDecoratedConnection()
    {
        $options = $this->getOptions();
        $options['quoteIdentifiers'] = true;
        $database = ':memory:';

        if (!empty($options['name'])) {
            $options['database'] = $options['name'];

            if (file_exists($options['name'] . $this->suffix)) {
                $options['database'] = $options['name'] . $this->suffix;
            }
        }

        $driver = new SqliteDriver($options);
        if (method_exists($driver, 'setConnection')) {
            $driver->setConnection($this->connection);
        } else {
            $driver->connection($this->connection);
        }

        return new Connection(['driver' => $driver] + $options);
    }
}
