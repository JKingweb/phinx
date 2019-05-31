<?php

namespace Test\Phinx\Db\Adapter;

use Phinx\Db\Adapter\SQLiteAdapter;
use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Table\Column;
use Phinx\Util\Literal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class SQLiteAdapterTest extends TestCase
{
    /**
     * @var \Phinx\Db\Adapter\SQLiteAdapter
     */
    private $adapter;

    public function setUp()
    {
        if (!TESTS_PHINX_DB_ADAPTER_SQLITE_ENABLED) {
            $this->markTestSkipped('SQLite tests disabled. See TESTS_PHINX_DB_ADAPTER_SQLITE_ENABLED constant.');
        }

        $options = [
            'name' => TESTS_PHINX_DB_ADAPTER_SQLITE_DATABASE,
            'suffix' => TESTS_PHINX_DB_ADAPTER_SQLITE_SUFFIX,
            'memory' => TESTS_PHINX_DB_ADAPTER_SQLITE_MEMORY
        ];
        $this->adapter = new SQLiteAdapter($options, new ArrayInput([]), new NullOutput());

        // ensure the database is empty for each test
        $this->adapter->dropDatabase($options['name']);
        $this->adapter->createDatabase($options['name']);

        // leave the adapter in a disconnected state for each test
        $this->adapter->disconnect();
    }

    public function tearDown()
    {
        unset($this->adapter);
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::getConnection */
    public function testGetConnection()
    {
        $this->assertInstanceOf(\PDO::class, $this->adapter->getConnection());
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::disconnect */
    public function testDisconnect()
    {
        $conn1 = $this->adapter->getConnection();
        $conn1->exec('BEGIN; PRAGMA user_version = 2112');
        $this->assertEquals(2112, $conn1->query('PRAGMA user_version')->fetchColumn());
        $this->assertNull($this->adapter->disconnect(), 'Interface violation');
        $conn2 = $this->adapter->getConnection();
        $this->assertNotSame($conn1, $conn2, 'Disconnection did not occur');
        $this->assertEquals(0, $conn2->query('PRAGMA user_version')->fetchColumn(), 'Disconnection did not occur');
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::hasTransactions */
    public function testHasTransactions()
    {
        $this->assertTrue($this->adapter->hasTransactions());
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::beginTransaction
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::rollbackTransaction
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::commitTransaction
    */
    public function testUseTransactions()
    {
        $conn = $this->adapter->getConnection();
        $version = function ($set = null) use ($conn) {
            if (!is_null($set)) {
                $set = (int)$set;
                $conn->exec(sprintf('PRAGMA user_version = %d', $set));
                return $set;
            } else {
                return (int)$conn->query('PRAGMA user_version')->fetchColumn();
            }
        };
        $this->assertEquals(0, $version(), 'Dirty test fixture');
        $this->assertFalse($conn->inTransaction(), 'Dirty test fixture');
        $this->assertNull($this->adapter->beginTransaction(), 'Interface violation');
        $this->assertTrue($conn->inTransaction());
        $version(2112);
        $this->assertEquals(2112, $version());
        $this->assertNull($this->adapter->rollbackTransaction(), 'Interface violation');
        $this->assertFalse($conn->inTransaction());
        $this->assertEquals(0, $version());
        $this->adapter->beginTransaction();
        $version(2112);
        $this->assertNull($this->adapter->commitTransaction(), 'Interface violation');
        $this->assertEquals(2112, $version());
    }

    /** @dataProvider provideTableNamesForQuotingRelaxed
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::quoteTableName */
    public function testQuoteTableNames($tableName, $exp)
    {
        $this->assertSame($exp, $this->adapter->quoteTableName($tableName));
    }

    public function provideTableNamesForQuoting()
    {
        // NOTE: This test-set is pedantic: though quoting identifiers with [] or ` is allowed by SQLite,
        // this is non-standard and is not guaranteed to work in SQLite 4; the test errs on the side of long-term compatibility
        return [
            ['t', '"t"'],
            ['d.t', '"d"."t"'],
            ['d.s.t', '"d"."s"."t"'], // this would be rejected by SQLite after the fact
            ['chars.J "H" S', '"chars"."J ""H"" S"'],
            ['"."', '"""".""""'],
            ['[.]', '"["."]"'],
            ['`.`', '"`"."`"']
        ];
    }

    public function provideTableNamesForQuotingRelaxed()
    {
        // NOTE: This test-set is relaxed about MySQL-style quoting, historically used by the adapter
        return [
            ['t', '`t`'],
            ['d.t', '`d`.`t`'],
            ['d.s.t', '`d`.`s`.`t`'], // this would be rejected by SQLite after the fact
            ['chars.J `H` S', '`chars`.`J ``H`` S`'],
            ['"."', '`"`.`"`'],
            ['[.]', '`[`.`]`'],
            ['`.`', '````.````']
        ];
    }

    /** @dataProvider provideColumnNamesForQuotingRelaxed
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::quoteColumnName */
    public function testQuoteColumnNames($columnName, $exp)
    {
        $this->assertSame($exp, $this->adapter->quoteColumnName($columnName));
    }

    public function provideColumnNamesForQuoting()
    {
        // NOTE: This test-set is pedantic: though quoting identifiers with [] or ` is allowed by SQLite,
        // this is non-standard and is not guaranteed to work in SQLite 4; the test errs on the side of long-term compatibility
        return [
            ['t', '"t"'],
            ['d.t', '"d.t"'],
            ['chars.J "H" S', '"chars.J ""H"" S"'],
            ['"."', '"""."""'],
            ['[.]', '"[.]"'],
            ['`.`', '"`.`"']
        ];
    }

    public function provideColumnNamesForQuotingRelaxed()
    {
        // NOTE: This test-set is relaxed about MySQL-style quoting, historically used by the adapter
        return [
            ['t', '`t`'],
            ['d.t', '`d.t`'],
            ['chars.J `H` S', '`chars.J ``H`` S`'],
            ['"."', '`"."`'],
            ['[.]', '`[.]`'],
            ['`.`', '```.```']
        ];
    }

    /** @dataProvider provideTableNamesForPresenceCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasTable
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::quoteString
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::resolveTable */
    public function testHasTable($createName, $tableName, $exp)
    {
        // Test case for issue #1535
        $conn = $this->adapter->getConnection();
        $conn->exec('ATTACH DATABASE \':memory:\' as etc');
        $conn->exec('ATTACH DATABASE \':memory:\' as "main.db"');
        $conn->exec(sprintf('DROP TABLE IF EXISTS %s', $createName));
        $this->assertFalse($this->adapter->hasTable($tableName), sprintf('Adapter claims table %s exists when it does not', $tableName));
        $conn->exec(sprintf('CREATE TABLE %s (a text)', $createName));
        if ($exp == true) {
            $this->assertTrue($this->adapter->hasTable($tableName), sprintf('Adapter claims table %s does not exist when it does', $tableName));
        } else {
            $this->assertFalse($this->adapter->hasTable($tableName), sprintf('Adapter claims table %s exists when it does not', $tableName));
        }
    }

    public function provideTableNamesForPresenceCheck()
    {
        return [
            'Ordinary table' => ['t', 't', true],
            'Ordinary table with schema' => ['t', 'main.t', true],
            'Temporary table' => ['temp.t', 't', true],
            'Temporary table with schema' => ['temp.t', 'temp.t', true],
            'Attached table' => ['etc.t', 't', true],
            'Attached table with schema' => ['etc.t', 'etc.t', true],
            'Attached table with unusual schema' => ['"main.db".t', 'main.db.t', true],
            'Wrong schema 1' => ['t', 'etc.t', false],
            'Wrong schema 2' => ['t', 'temp.t', false],
            'Missing schema' => ['t', 'not_attached.t', false],
            'Malicious table' => ['"\'"', '\'', true],
            'Malicious missing table' => ['t', '\'', false],
            'Table name case 1' => ['t', 'T', true],
            'Table name case 2' => ['T', 't', true],
            'Schema name case 1' => ['main.t', 'MAIN.t', true],
            'Schema name case 2' => ['MAIN.t', 'main.t', true],
            'Schema name case 3' => ['temp.t', 'TEMP.t', true],
            'Schema name case 4' => ['TEMP.t', 'temp.t', true],
            'Schema name case 5' => ['etc.t', 'ETC.t', true],
            'Schema name case 6' => ['ETC.t', 'etc.t', true],
            'PHP zero string 1' => ['"0"', '0', true],
            'PHP zero string 2' => ['"0"', '0e2', false],
            'PHP zero string 3' => ['"0e2"', '0', false]
        ];
    }

    /** @dataProvider provideIndexColumnsToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getTableInfo
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getIndexes
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::resolveIndex
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasIndex */
    public function testHasIndex($tableDef, $cols, $exp)
    {
        $conn = $this->adapter->getConnection();
        $conn->exec($tableDef);
        $this->assertEquals($exp, $this->adapter->hasIndex('t', $cols));
    }

    public function provideIndexColumnsToCheck()
    {
        return [
            ['create table t(a text)', 'a', false],
            ['create table t(a text); create index test on t(a);', 'a', true],
            ['create table t(a text unique)', 'a', true],
            ['create table t(a text primary key)', 'a', true],
            ['create table t(a text unique, b text unique)', ['a', 'b'], false],
            ['create table t(a text, b text, unique(a,b))', ['a', 'b'], true],
            ['create table t(a text, b text); create index test on t(a,b)', ['a', 'b'], true],
            ['create table t(a text, b text); create index test on t(a,b)', ['b', 'a'], false],
            ['create table t(a text, b text); create index test on t(a,b)', ['a'], false],
            ['create table t(a text, b text); create index test on t(a)', ['a', 'b'], false],
            ['create table t(a text, b text); create index test on t(a,b)', ['A', 'B'], true],
            ['create table t("A" text, "B" text); create index test on t("A","B")', ['a', 'b'], true],
            ['create table not_t(a text, b text, unique(a,b))', ['A', 'B'], false], // test checks table t which does not exist
            ['create table t(a text, b text); create index test on t(a)', ['a', 'a'], false],
            ['create table t(a text unique); create temp table t(a text)', 'a', false],
        ];
    }

    /** @dataProvider provideIndexNamesToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getTableInfo
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getIndexes
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasIndexByName */
    public function testHasIndexByName($tableDef, $index, $exp)
    {
        $conn = $this->adapter->getConnection();
        $conn->exec($tableDef);
        $this->assertEquals($exp, $this->adapter->hasIndexByName('t', $index));
    }

    public function provideIndexNamesToCheck()
    {
        return [
            ['create table t(a text)', 'test', false],
            ['create table t(a text); create index test on t(a);', 'test', true],
            ['create table t(a text); create index test on t(a);', 'TEST', true],
            ['create table t(a text); create index "TEST" on t(a);', 'test', true],
            ['create table t(a text unique)', 'sqlite_autoindex_t_1', true],
            ['create table t(a text primary key)', 'sqlite_autoindex_t_1', true],
            ['create table not_t(a text); create index test on not_t(a);', 'test', false], // test checks table t which does not exist
            ['create table t(a text unique); create temp table t(a text)', 'sqlite_autoindex_t_1', false],
        ];
    }

    /** @dataProvider providePrimaryKeysToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getTableInfo
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasPrimaryKey
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getPrimaryKey */
    public function testHasPrimaryKey($tableDef, $key, $exp)
    {
        $this->assertFalse($this->adapter->hasTable('t'), 'Dirty test fixture');
        $conn = $this->adapter->getConnection();
        $conn->exec($tableDef);
        $this->assertSame($exp, $this->adapter->hasPrimaryKey('t', $key));
    }

    public function providePrimaryKeysToCheck()
    {
        return [
            ['create table t(a integer)', 'a', false],
            ['create table t(a integer)', [], true],
            ['create table t(a integer primary key)', 'a', true],
            ['create table t(a integer primary key)', [], false],
            ['create table t(a integer PRIMARY KEY)', 'a', true],
            ['create table t(`a` integer PRIMARY KEY)', 'a', true],
            ['create table t("a" integer PRIMARY KEY)', 'a', true],
            ['create table t([a] integer PRIMARY KEY)', 'a', true],
            ['create table t(`a` integer PRIMARY KEY)', 'a', true],
            ['create table t(\'a\' integer PRIMARY KEY)', 'a', true],
            ['create table t(`a.a` integer PRIMARY KEY)', 'a.a', true],
            ['create table t(a integer primary key)', ['a'], true],
            ['create table t(a integer primary key)', ['a', 'b'], false],
            ['create table t(a integer, primary key(a))', 'a', true],
            ['create table t(a integer, primary key("a"))', 'a', true],
            ['create table t(a integer, primary key([a]))', 'a', true],
            ['create table t(a integer, primary key(`a`))', 'a', true],
            ['create table t(a integer, b integer primary key)', 'a', false],
            ['create table t(a integer, b text primary key)', 'b', true],
            ['create table t(a integer, b integer default 2112 primary key)', ['a'], false],
            ['create table t(a integer, b integer primary key)', ['b'], true],
            ['create table t(a integer, b integer primary key)', ['b', 'b'], true], // duplicate column is collapsed
            ['create table t(a integer, b integer, primary key(a,b))', ['b', 'a'], true],
            ['create table t(a integer, b integer, primary key(a,b))', ['a', 'b'], true],
            ['create table t(a integer, b integer, primary key(a,b))', 'a', false],
            ['create table t(a integer, b integer, primary key(a,b))', ['a'], false],
            ['create table t(a integer, b integer, primary key(a,b))', ['a', 'b', 'c'], false],
            ['create table t(a integer, b integer, primary key(a,b))', ['a', 'B'], true],
            ['create table t(a integer, "B" integer, primary key(a,b))', ['a', 'b'], true],
            ['create table t(a integer, b integer, constraint t_pk primary key(a,b))', ['a', 'b'], true],
            ['create table t(a integer); create temp table t(a integer primary key)', 'a', true],
            ['create temp table t(a integer primary key)', 'a', true],
            ['create table t("0" integer primary key)', ['0'], true],
            ['create table t("0" integer primary key)', ['0e0'], false],
            ['create table t("0e0" integer primary key)', ['0'], false],
            ['create table not_t(a integer)', 'a', false] // test checks table t which does not exist
        ];
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::hasPrimaryKey */
    public function testHasNamedPrimaryKey()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->adapter->hasPrimaryKey('t', [], 'named_constraint');
    }

    /** @dataProvider provideForeignKeysToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getTableInfo
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasForeignKey
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getForeignKeys */
    public function testHasForeignKey($tableDef, $key, $exp)
    {
        $conn = $this->adapter->getConnection();
        $conn->exec('CREATE TABLE other(a integer, b integer, c integer)');
        $conn->exec($tableDef);
        $this->assertSame($exp, $this->adapter->hasForeignKey('t', $key));
    }

    public function provideForeignKeysToCheck()
    {
        return [
            ['create table t(a integer)', 'a', false],
            ['create table t(a integer)', [], false],
            ['create table t(a integer primary key)', 'a', false],
            ['create table t(a integer references other(a))', 'a', true],
            ['create table t(a integer references other(b))', 'a', true],
            ['create table t(a integer references other(b))', ['a'], true],
            ['create table t(a integer references other(b))', ['a', 'a'], true], // duplicate column is collapsed
            ['create table t(a integer, foreign key(a) references other(a))', 'a', true],
            ['create table t(a integer, b integer, foreign key(a,b) references other(a,b))', 'a', false],
            ['create table t(a integer, b integer, foreign key(a,b) references other(a,b))', ['a', 'b'], true],
            ['create table t(a integer, b integer, foreign key(a,b) references other(a,b))', ['b', 'a'], true],
            ['create table t(a integer, "B" integer, foreign key(a,b) references other(a,b))', ['a', 'b'], true],
            ['create table t(a integer, b integer, foreign key(a,b) references other(a,b))', ['a', 'B'], true],
            ['create table t(a integer, b integer, c integer, foreign key(a,b,c) references other(a,b,c))', ['a', 'b'], false],
            ['create table t(a integer, foreign key(a) references other(a))', ['a', 'b'], false],
            ['create table t(a integer references other(a), b integer references other(b))', ['a', 'b'], false],
            ['create table t(a integer references other(a), b integer references other(b))', ['a', 'b'], false],
            ['create table t(a integer); create temp table t(a integer references other(a))', ['a'], true],
            ['create temp table t(a integer references other(a))', ['a'], true],
            ['create table t("0" integer references other(a))', '0', true],
            ['create table t("0" integer references other(a))', '0e0', false],
            ['create table t("0e0" integer references other(a))', '0', false],
        ];
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::hasForeignKey */
    public function testHasNamedForeignKey()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->adapter->hasForeignKey('t', [], 'named_constraint');
    }

    /** @dataProvider providePhinxTypes
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSqlType */
    public function testGetSqlType($phinxType, $limit, $exp)
    {
        if ($exp instanceof \Exception) {
            $this->expectException(get_class($exp));
            $this->adapter->getSqlType($phinxType, $limit);
        } else {
            $exp = ['name' => $exp, 'limit' => $limit];
            $this->assertEquals($exp, $this->adapter->getSqlType($phinxType, $limit));
        }
    }

    public function providePhinxTypes()
    {
        $unsupported = new \Phinx\Db\Adapter\UnsupportedColumnTypeException;
        return [
            [SQLiteAdapter::PHINX_TYPE_BIG_INTEGER, null, SQLiteAdapter::PHINX_TYPE_BIG_INTEGER],
            [SQLiteAdapter::PHINX_TYPE_BINARY, null, SQLiteAdapter::PHINX_TYPE_BINARY . '_blob'],
            [SQLiteAdapter::PHINX_TYPE_BIT, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_BLOB, null, SQLiteAdapter::PHINX_TYPE_BLOB],
            [SQLiteAdapter::PHINX_TYPE_BOOLEAN, null, SQLiteAdapter::PHINX_TYPE_BOOLEAN . '_integer'],
            [SQLiteAdapter::PHINX_TYPE_CHAR, null, SQLiteAdapter::PHINX_TYPE_CHAR],
            [SQLiteAdapter::PHINX_TYPE_CIDR, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_DATE, null, SQLiteAdapter::PHINX_TYPE_DATE . '_text'],
            [SQLiteAdapter::PHINX_TYPE_DATETIME, null, SQLiteAdapter::PHINX_TYPE_DATETIME . '_text'],
            [SQLiteAdapter::PHINX_TYPE_DECIMAL, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_DOUBLE, null, SQLiteAdapter::PHINX_TYPE_DOUBLE],
            [SQLiteAdapter::PHINX_TYPE_ENUM, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_FILESTREAM, null, SQLiteAdapter::PHINX_TYPE_FILESTREAM . '_blob'],
            [SQLiteAdapter::PHINX_TYPE_FLOAT, null, SQLiteAdapter::PHINX_TYPE_FLOAT],
            [SQLiteAdapter::PHINX_TYPE_GEOMETRY, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_INET, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_INTEGER, null, SQLiteAdapter::PHINX_TYPE_INTEGER],
            [SQLiteAdapter::PHINX_TYPE_INTERVAL, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_JSON, null, SQLiteAdapter::PHINX_TYPE_JSON . '_text'],
            [SQLiteAdapter::PHINX_TYPE_JSONB, null, SQLiteAdapter::PHINX_TYPE_JSONB . '_text'],
            [SQLiteAdapter::PHINX_TYPE_LINESTRING, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_MACADDR, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_POINT, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_POLYGON, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_SET, null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, null, SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER],
            [SQLiteAdapter::PHINX_TYPE_STRING, null, 'varchar'],
            [SQLiteAdapter::PHINX_TYPE_TEXT, null, SQLiteAdapter::PHINX_TYPE_TEXT],
            [SQLiteAdapter::PHINX_TYPE_TIME, null, SQLiteAdapter::PHINX_TYPE_TIME . '_text'],
            [SQLiteAdapter::PHINX_TYPE_TIMESTAMP, null, SQLiteAdapter::PHINX_TYPE_TIMESTAMP . '_text'],
            [SQLiteAdapter::PHINX_TYPE_UUID, null, SQLiteAdapter::PHINX_TYPE_UUID . '_text'],
            [SQLiteAdapter::PHINX_TYPE_VARBINARY, null, SQLiteAdapter::PHINX_TYPE_VARBINARY . '_blob'],
            ['notAType', null, $unsupported],
            [SQLiteAdapter::PHINX_TYPE_STRING, 5, 'varchar'],
        ];
    }

    /** @dataProvider provideSqlTypes
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getPhinxType */
    public function testGetPhinxType($sqlType, $exp)
    {
        $this->assertEquals($exp, $this->adapter->getPhinxType($sqlType));
    }

    public function provideSqlTypes()
    {
        return [
            ['varchar',         ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => null, 'scale' => null]],
            ['string',          ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => null, 'scale' => null]],
            ['string_text',     ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => null, 'scale' => null]],
            ['varchar(5)',      ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => 5, 'scale' => null]],
            ['varchar(55,2)',   ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => 55, 'scale' => 2]],
            ['char',            ['name' => SQLiteAdapter::PHINX_TYPE_CHAR, 'limit' => null, 'scale' => null]],
            ['boolean',         ['name' => SQLiteAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'scale' => null]],
            ['boolean_integer', ['name' => SQLiteAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'scale' => null]],
            ['int',             ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['integer',         ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['tinyint',         ['name' => SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, 'limit' => null, 'scale' => null]],
            ['tinyint(1)',      ['name' => SQLiteAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'scale' => null]],
            ['smallint',        ['name' => SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, 'limit' => null, 'scale' => null]],
            ['smallinteger',    ['name' => SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, 'limit' => null, 'scale' => null]],
            ['mediumint',       ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['mediuminteger',   ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['bigint',          ['name' => SQLiteAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => null, 'scale' => null]],
            ['biginteger',      ['name' => SQLiteAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => null, 'scale' => null]],
            ['text',            ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['tinytext',        ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['mediumtext',      ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['longtext',        ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['blob',            ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['tinyblob',        ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['mediumblob',      ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['longblob',        ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['float',           ['name' => SQLiteAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'scale' => null]],
            ['real',            ['name' => SQLiteAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'scale' => null]],
            ['numeric',         ['name' => SQLiteAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'scale' => null]],
            ['double',          ['name' => SQLiteAdapter::PHINX_TYPE_DOUBLE, 'limit' => null, 'scale' => null]],
            ['date',            ['name' => SQLiteAdapter::PHINX_TYPE_DATE, 'limit' => null, 'scale' => null]],
            ['date_text',       ['name' => SQLiteAdapter::PHINX_TYPE_DATE, 'limit' => null, 'scale' => null]],
            ['datetime',        ['name' => SQLiteAdapter::PHINX_TYPE_DATETIME, 'limit' => null, 'scale' => null]],
            ['datetime_text',   ['name' => SQLiteAdapter::PHINX_TYPE_DATETIME, 'limit' => null, 'scale' => null]],
            ['time',            ['name' => SQLiteAdapter::PHINX_TYPE_TIME, 'limit' => null, 'scale' => null]],
            ['time_text',       ['name' => SQLiteAdapter::PHINX_TYPE_TIME, 'limit' => null, 'scale' => null]],
            ['timestamp',       ['name' => SQLiteAdapter::PHINX_TYPE_TIMESTAMP, 'limit' => null, 'scale' => null]],
            ['timestamp_text',  ['name' => SQLiteAdapter::PHINX_TYPE_TIMESTAMP, 'limit' => null, 'scale' => null]],
            ['binary',          ['name' => SQLiteAdapter::PHINX_TYPE_BINARY, 'limit' => null, 'scale' => null]],
            ['binary_blob',     ['name' => SQLiteAdapter::PHINX_TYPE_BINARY, 'limit' => null, 'scale' => null]],
            ['varbinary',       ['name' => SQLiteAdapter::PHINX_TYPE_VARBINARY, 'limit' => null, 'scale' => null]],
            ['varbinary_blob',  ['name' => SQLiteAdapter::PHINX_TYPE_VARBINARY, 'limit' => null, 'scale' => null]],
            ['filestream',      ['name' => SQLiteAdapter::PHINX_TYPE_FILESTREAM, 'limit' => null, 'scale' => null]],
            ['filestream_blob', ['name' => SQLiteAdapter::PHINX_TYPE_FILESTREAM, 'limit' => null, 'scale' => null]],
            ['json',            ['name' => SQLiteAdapter::PHINX_TYPE_JSON, 'limit' => null, 'scale' => null]],
            ['json_text',       ['name' => SQLiteAdapter::PHINX_TYPE_JSON, 'limit' => null, 'scale' => null]],
            ['jsonb',           ['name' => SQLiteAdapter::PHINX_TYPE_JSONB, 'limit' => null, 'scale' => null]],
            ['jsonb_text',      ['name' => SQLiteAdapter::PHINX_TYPE_JSONB, 'limit' => null, 'scale' => null]],
            ['uuid',            ['name' => SQLiteAdapter::PHINX_TYPE_UUID, 'limit' => null, 'scale' => null]],
            ['uuid_text',       ['name' => SQLiteAdapter::PHINX_TYPE_UUID, 'limit' => null, 'scale' => null]],
            ['decimal',         ['name' => Literal::from('decimal'), 'limit' => null, 'scale' => null]],
            ['point',           ['name' => Literal::from('point'), 'limit' => null, 'scale' => null]],
            ['polygon',         ['name' => Literal::from('polygon'), 'limit' => null, 'scale' => null]],
            ['linestring',      ['name' => Literal::from('linestring'), 'limit' => null, 'scale' => null]],
            ['geometry',        ['name' => Literal::from('geometry'), 'limit' => null, 'scale' => null]],
            ['bit',             ['name' => Literal::from('bit'), 'limit' => null, 'scale' => null]],
            ['enum',            ['name' => Literal::from('enum'), 'limit' => null, 'scale' => null]],
            ['set',             ['name' => Literal::from('set'), 'limit' => null, 'scale' => null]],
            ['cidr',            ['name' => Literal::from('cidr'), 'limit' => null, 'scale' => null]],
            ['inet',            ['name' => Literal::from('inet'), 'limit' => null, 'scale' => null]],
            ['macaddr',         ['name' => Literal::from('macaddr'), 'limit' => null, 'scale' => null]],
            ['interval',        ['name' => Literal::from('interval'), 'limit' => null, 'scale' => null]],
            ['decimal_text',    ['name' => Literal::from('decimal'), 'limit' => null, 'scale' => null]],
            ['point_text',      ['name' => Literal::from('point'), 'limit' => null, 'scale' => null]],
            ['polygon_text',    ['name' => Literal::from('polygon'), 'limit' => null, 'scale' => null]],
            ['linestring_text', ['name' => Literal::from('linestring'), 'limit' => null, 'scale' => null]],
            ['geometry_text',   ['name' => Literal::from('geometry'), 'limit' => null, 'scale' => null]],
            ['bit_text',        ['name' => Literal::from('bit'), 'limit' => null, 'scale' => null]],
            ['enum_text',       ['name' => Literal::from('enum'), 'limit' => null, 'scale' => null]],
            ['set_text',        ['name' => Literal::from('set'), 'limit' => null, 'scale' => null]],
            ['cidr_text',       ['name' => Literal::from('cidr'), 'limit' => null, 'scale' => null]],
            ['inet_text',       ['name' => Literal::from('inet'), 'limit' => null, 'scale' => null]],
            ['macaddr_text',    ['name' => Literal::from('macaddr'), 'limit' => null, 'scale' => null]],
            ['interval_text',   ['name' => Literal::from('interval'), 'limit' => null, 'scale' => null]],
            ['bit_text(2,12)',  ['name' => Literal::from('bit'), 'limit' => 2, 'scale' => 12]],
            ['VARCHAR',         ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => null, 'scale' => null]],
            ['STRING',          ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => null, 'scale' => null]],
            ['STRING_TEXT',     ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => null, 'scale' => null]],
            ['VARCHAR(5)',      ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => 5, 'scale' => null]],
            ['VARCHAR(55,2)',   ['name' => SQLiteAdapter::PHINX_TYPE_STRING, 'limit' => 55, 'scale' => 2]],
            ['CHAR',            ['name' => SQLiteAdapter::PHINX_TYPE_CHAR, 'limit' => null, 'scale' => null]],
            ['BOOLEAN',         ['name' => SQLiteAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'scale' => null]],
            ['BOOLEAN_INTEGER', ['name' => SQLiteAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'scale' => null]],
            ['INT',             ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['INTEGER',         ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['TINYINT',         ['name' => SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, 'limit' => null, 'scale' => null]],
            ['TINYINT(1)',      ['name' => SQLiteAdapter::PHINX_TYPE_BOOLEAN, 'limit' => null, 'scale' => null]],
            ['SMALLINT',        ['name' => SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, 'limit' => null, 'scale' => null]],
            ['SMALLINTEGER',    ['name' => SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, 'limit' => null, 'scale' => null]],
            ['MEDIUMINT',       ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['MEDIUMINTEGER',   ['name' => SQLiteAdapter::PHINX_TYPE_INTEGER, 'limit' => null, 'scale' => null]],
            ['BIGINT',          ['name' => SQLiteAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => null, 'scale' => null]],
            ['BIGINTEGER',      ['name' => SQLiteAdapter::PHINX_TYPE_BIG_INTEGER, 'limit' => null, 'scale' => null]],
            ['TEXT',            ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['TINYTEXT',        ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['MEDIUMTEXT',      ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['LONGTEXT',        ['name' => SQLiteAdapter::PHINX_TYPE_TEXT, 'limit' => null, 'scale' => null]],
            ['BLOB',            ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['TINYBLOB',        ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['MEDIUMBLOB',      ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['LONGBLOB',        ['name' => SQLiteAdapter::PHINX_TYPE_BLOB, 'limit' => null, 'scale' => null]],
            ['FLOAT',           ['name' => SQLiteAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'scale' => null]],
            ['REAL',            ['name' => SQLiteAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'scale' => null]],
            ['NUMERIC',         ['name' => SQLiteAdapter::PHINX_TYPE_FLOAT, 'limit' => null, 'scale' => null]],
            ['DOUBLE',          ['name' => SQLiteAdapter::PHINX_TYPE_DOUBLE, 'limit' => null, 'scale' => null]],
            ['DATE',            ['name' => SQLiteAdapter::PHINX_TYPE_DATE, 'limit' => null, 'scale' => null]],
            ['DATE_TEXT',       ['name' => SQLiteAdapter::PHINX_TYPE_DATE, 'limit' => null, 'scale' => null]],
            ['DATETIME',        ['name' => SQLiteAdapter::PHINX_TYPE_DATETIME, 'limit' => null, 'scale' => null]],
            ['DATETIME_TEXT',   ['name' => SQLiteAdapter::PHINX_TYPE_DATETIME, 'limit' => null, 'scale' => null]],
            ['TIME',            ['name' => SQLiteAdapter::PHINX_TYPE_TIME, 'limit' => null, 'scale' => null]],
            ['TIME_TEXT',       ['name' => SQLiteAdapter::PHINX_TYPE_TIME, 'limit' => null, 'scale' => null]],
            ['TIMESTAMP',       ['name' => SQLiteAdapter::PHINX_TYPE_TIMESTAMP, 'limit' => null, 'scale' => null]],
            ['TIMESTAMP_TEXT',  ['name' => SQLiteAdapter::PHINX_TYPE_TIMESTAMP, 'limit' => null, 'scale' => null]],
            ['BINARY',          ['name' => SQLiteAdapter::PHINX_TYPE_BINARY, 'limit' => null, 'scale' => null]],
            ['BINARY_BLOB',     ['name' => SQLiteAdapter::PHINX_TYPE_BINARY, 'limit' => null, 'scale' => null]],
            ['VARBINARY',       ['name' => SQLiteAdapter::PHINX_TYPE_VARBINARY, 'limit' => null, 'scale' => null]],
            ['VARBINARY_BLOB',  ['name' => SQLiteAdapter::PHINX_TYPE_VARBINARY, 'limit' => null, 'scale' => null]],
            ['FILESTREAM',      ['name' => SQLiteAdapter::PHINX_TYPE_FILESTREAM, 'limit' => null, 'scale' => null]],
            ['FILESTREAM_BLOB', ['name' => SQLiteAdapter::PHINX_TYPE_FILESTREAM, 'limit' => null, 'scale' => null]],
            ['JSON',            ['name' => SQLiteAdapter::PHINX_TYPE_JSON, 'limit' => null, 'scale' => null]],
            ['JSON_TEXT',       ['name' => SQLiteAdapter::PHINX_TYPE_JSON, 'limit' => null, 'scale' => null]],
            ['JSONB',           ['name' => SQLiteAdapter::PHINX_TYPE_JSONB, 'limit' => null, 'scale' => null]],
            ['JSONB_TEXT',      ['name' => SQLiteAdapter::PHINX_TYPE_JSONB, 'limit' => null, 'scale' => null]],
            ['UUID',            ['name' => SQLiteAdapter::PHINX_TYPE_UUID, 'limit' => null, 'scale' => null]],
            ['UUID_TEXT',       ['name' => SQLiteAdapter::PHINX_TYPE_UUID, 'limit' => null, 'scale' => null]],
            ['DECIMAL',         ['name' => Literal::from('decimal'), 'limit' => null, 'scale' => null]],
            ['POINT',           ['name' => Literal::from('point'), 'limit' => null, 'scale' => null]],
            ['POLYGON',         ['name' => Literal::from('polygon'), 'limit' => null, 'scale' => null]],
            ['LINESTRING',      ['name' => Literal::from('linestring'), 'limit' => null, 'scale' => null]],
            ['GEOMETRY',        ['name' => Literal::from('geometry'), 'limit' => null, 'scale' => null]],
            ['BIT',             ['name' => Literal::from('bit'), 'limit' => null, 'scale' => null]],
            ['ENUM',            ['name' => Literal::from('enum'), 'limit' => null, 'scale' => null]],
            ['SET',             ['name' => Literal::from('set'), 'limit' => null, 'scale' => null]],
            ['CIDR',            ['name' => Literal::from('cidr'), 'limit' => null, 'scale' => null]],
            ['INET',            ['name' => Literal::from('inet'), 'limit' => null, 'scale' => null]],
            ['MACADDR',         ['name' => Literal::from('macaddr'), 'limit' => null, 'scale' => null]],
            ['INTERVAL',        ['name' => Literal::from('interval'), 'limit' => null, 'scale' => null]],
            ['DECIMAL_TEXT',    ['name' => Literal::from('decimal'), 'limit' => null, 'scale' => null]],
            ['POINT_TEXT',      ['name' => Literal::from('point'), 'limit' => null, 'scale' => null]],
            ['POLYGON_TEXT',    ['name' => Literal::from('polygon'), 'limit' => null, 'scale' => null]],
            ['LINESTRING_TEXT', ['name' => Literal::from('linestring'), 'limit' => null, 'scale' => null]],
            ['GEOMETRY_TEXT',   ['name' => Literal::from('geometry'), 'limit' => null, 'scale' => null]],
            ['BIT_TEXT',        ['name' => Literal::from('bit'), 'limit' => null, 'scale' => null]],
            ['ENUM_TEXT',       ['name' => Literal::from('enum'), 'limit' => null, 'scale' => null]],
            ['SET_TEXT',        ['name' => Literal::from('set'), 'limit' => null, 'scale' => null]],
            ['CIDR_TEXT',       ['name' => Literal::from('cidr'), 'limit' => null, 'scale' => null]],
            ['INET_TEXT',       ['name' => Literal::from('inet'), 'limit' => null, 'scale' => null]],
            ['MACADDR_TEXT',    ['name' => Literal::from('macaddr'), 'limit' => null, 'scale' => null]],
            ['INTERVAL_TEXT',   ['name' => Literal::from('interval'), 'limit' => null, 'scale' => null]],
            ['BIT_TEXT(2,12)',  ['name' => Literal::from('bit'), 'limit' => 2, 'scale' => 12]],
            ['not a type',      ['name' => Literal::from('not a type'), 'limit' => null, 'scale' => null]],
            ['NOT A TYPE',      ['name' => Literal::from('NOT A TYPE'), 'limit' => null, 'scale' => null]],
            ['not a type(2)',   ['name' => Literal::from('not a type(2)'), 'limit' => null, 'scale' => null]],
            ['NOT A TYPE(2)',   ['name' => Literal::from('NOT A TYPE(2)'), 'limit' => null, 'scale' => null]],
            ['ack',             ['name' => Literal::from('ack'), 'limit' => null, 'scale' => null]],
            ['ACK',             ['name' => Literal::from('ACK'), 'limit' => null, 'scale' => null]],
            ['ack_text',        ['name' => Literal::from('ack_text'), 'limit' => null, 'scale' => null]],
            ['ACK_TEXT',        ['name' => Literal::from('ACK_TEXT'), 'limit' => null, 'scale' => null]],
            ['ack_text(2,12)',  ['name' => Literal::from('ack_text'), 'limit' => 2, 'scale' => 12]],
            ['ACK_TEXT(12,2)',  ['name' => Literal::from('ACK_TEXT'), 'limit' => 12, 'scale' => 2]],
        ];
    }

    /** @covers \Phinx\Db\Adapter\SQLiteAdapter::getColumnTypes */
    public function testGetColumnTypes()
    {
        $exp = [
            SQLiteAdapter::PHINX_TYPE_BIG_INTEGER,
            SQLiteAdapter::PHINX_TYPE_BINARY,
            SQLiteAdapter::PHINX_TYPE_BLOB,
            SQLiteAdapter::PHINX_TYPE_BOOLEAN,
            SQLiteAdapter::PHINX_TYPE_CHAR,
            SQLiteAdapter::PHINX_TYPE_DATE,
            SQLiteAdapter::PHINX_TYPE_DATETIME,
            SQLiteAdapter::PHINX_TYPE_DOUBLE,
            SQLiteAdapter::PHINX_TYPE_FILESTREAM,
            SQLiteAdapter::PHINX_TYPE_FLOAT,
            SQLiteAdapter::PHINX_TYPE_INTEGER,
            SQLiteAdapter::PHINX_TYPE_JSON,
            SQLiteAdapter::PHINX_TYPE_JSONB,
            SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER,
            SQLiteAdapter::PHINX_TYPE_STRING,
            SQLiteAdapter::PHINX_TYPE_TEXT,
            SQLiteAdapter::PHINX_TYPE_TIME,
            SQLiteAdapter::PHINX_TYPE_UUID,
            SQLiteAdapter::PHINX_TYPE_TIMESTAMP,
            SQLiteAdapter::PHINX_TYPE_VARBINARY
        ];
        $this->assertEquals($exp, $this->adapter->getColumnTypes());
    }

    /** @dataProvider provideColumnTypesForValidation
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::isValidColumnType */
    public function testIsValidColumnType($phinxType, $exp)
    {
        $col = (new Column)->setType($phinxType);
        $this->assertSame($exp, $this->adapter->isValidColumnType($col));
    }

    public function provideColumnTypesForValidation()
    {
        return [
            [SQLiteAdapter::PHINX_TYPE_BIG_INTEGER,   true],
            [SQLiteAdapter::PHINX_TYPE_BINARY,        true],
            [SQLiteAdapter::PHINX_TYPE_BLOB,          true],
            [SQLiteAdapter::PHINX_TYPE_BOOLEAN,       true],
            [SQLiteAdapter::PHINX_TYPE_CHAR,          true],
            [SQLiteAdapter::PHINX_TYPE_DATE,          true],
            [SQLiteAdapter::PHINX_TYPE_DATETIME,      true],
            [SQLiteAdapter::PHINX_TYPE_DOUBLE,        true],
            [SQLiteAdapter::PHINX_TYPE_FILESTREAM,    true],
            [SQLiteAdapter::PHINX_TYPE_FLOAT,         true],
            [SQLiteAdapter::PHINX_TYPE_INTEGER,       true],
            [SQLiteAdapter::PHINX_TYPE_JSON,          true],
            [SQLiteAdapter::PHINX_TYPE_JSONB,         true],
            [SQLiteAdapter::PHINX_TYPE_SMALL_INTEGER, true],
            [SQLiteAdapter::PHINX_TYPE_STRING,        true],
            [SQLiteAdapter::PHINX_TYPE_TEXT,          true],
            [SQLiteAdapter::PHINX_TYPE_TIME,          true],
            [SQLiteAdapter::PHINX_TYPE_UUID,          true],
            [SQLiteAdapter::PHINX_TYPE_TIMESTAMP,     true],
            [SQLiteAdapter::PHINX_TYPE_VARBINARY,     true],
            [SQLiteAdapter::PHINX_TYPE_BIT,           false],
            [SQLiteAdapter::PHINX_TYPE_CIDR,          false],
            [SQLiteAdapter::PHINX_TYPE_DECIMAL,       false],
            [SQLiteAdapter::PHINX_TYPE_ENUM,          false],
            [SQLiteAdapter::PHINX_TYPE_GEOMETRY,      false],
            [SQLiteAdapter::PHINX_TYPE_INET,          false],
            [SQLiteAdapter::PHINX_TYPE_INTERVAL,      false],
            [SQLiteAdapter::PHINX_TYPE_LINESTRING,    false],
            [SQLiteAdapter::PHINX_TYPE_MACADDR,       false],
            [SQLiteAdapter::PHINX_TYPE_POINT,         false],
            [SQLiteAdapter::PHINX_TYPE_POLYGON,       false],
            [SQLiteAdapter::PHINX_TYPE_SET,           false],
            [Literal::from('someType'),               true],
            ['someType',                              false]
        ];
    }

    /** @dataProvider provideColumnNamesToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getTableInfo
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasColumn */
    public function testHasColumn($tableDef, $col, $exp)
    {
        $conn = $this->adapter->getConnection();
        $conn->exec($tableDef);
        $this->assertEquals($exp, $this->adapter->hasColumn('t', $col));
    }

    public function provideColumnNamesToCheck()
    {
        return [
            ['create table t(a text)', 'a', true],
            ['create table t(A text)', 'a', true],
            ['create table t("a" text)', 'a', true],
            ['create table t([a] text)', 'a', true],
            ['create table t(\'a\' text)', 'a', true],
            ['create table t("A" text)', 'a', true],
            ['create table t(a text)', 'A', true],
            ['create table t(b text)', 'a', false],
            ['create table t(b text, a text)', 'a', true],
            ['create table t("0" text)', '0', true],
            ['create table t("0" text)', '0e0', false],
            ['create table t("0e0" text)', '0', false],
            ['create table t("0" text)', 0, true],
            ['create table t(b text); create temp table t(a text)', 'a', true],
            ['create table not_t(a text)', 'a', false],
        ];
    }
}
