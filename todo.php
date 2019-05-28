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

use Phinx\Db\Table\Column;
use Phinx\Db\Table\Table;
use Phinx\Migration\MigrationInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapter Interface.
 *
 * @author Rob Morgan <robbym@gmail.com>
 * @method \PDO getConnection()
 */
interface AdapterInterface
{
    public function getVersions();
    public function getVersionLog();
    public function setOptions(array $options);
    public function getOptions();
    public function hasOption($name);
    public function getOption($name);
    public function setInput(InputInterface $input);
    public function getInput();
    public function setOutput(OutputInterface $output);
    public function getOutput();
    public function migrated(MigrationInterface $migration, $direction, $startTime, $endTime);
    public function toggleBreakpoint(MigrationInterface $migration);
    public function resetAllBreakpoints();
    public function hasSchemaTable();
    public function createSchemaTable();
    public function getAdapterType();
    public function connect();
    public function disconnect();
    public function execute($sql);
    public function executeActions(Table $table, array $actions);
    public function getQueryBuilder();
    public function query($sql);
    public function fetchRow($sql);
    public function fetchAll($sql);
    public function insert(Table $table, $row);
    public function bulkinsert(Table $table, $rows);
    public function createTable(Table $table, array $columns = [], array $indexes = []);
    public function truncateTable($tableName);
    public function getColumns($tableName);
    public function hasColumn($tableName, $columnName);
    public function hasIndex($tableName, $columns);
    public function hasIndexByName($tableName, $indexName);
    public function isValidColumnType(Column $column);
    public function createDatabase($name, $options = []);
    public function hasDatabase($name);
    public function dropDatabase($name);
    public function createSchema($schemaName = 'public');
    public function dropSchema($schemaName);
    public function castToBool($value);
}
