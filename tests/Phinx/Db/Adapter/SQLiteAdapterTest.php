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
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getSchemaName */
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
            $this->assertTrue($this->adapter->hasTable($tableName), sprintf('Adapter claims table %s does not exist when it should', $tableName));
        } else {
            $this->assertFalse($this->adapter->hasTable($tableName), sprintf('Adapter claims table %s exists when it should not', $tableName));
        }
    }

    public function provideTableNamesForPresenceCheck()
    {
        return [
            'Ordinary table' => ['t', 't', true],
            'Ordinary table with schema' => ['t', 'main.t', true],
            'Temporary table' => ['temp.t', 't', false],
            'Temporary table with schema' => ['temp.t', 'temp.t', true],
            'Attached table' => ['etc.t', 't', false],
            'Attached table with schema' => ['etc.t', 'etc.t', true],
            'Attached table with unusual schema' => ['"main.db".t', 'main.db.t', true],
            'Wrong schema' => ['t', 'etc.t', false],
            'Missing schema' => ['t', 'not_attached.t', false],
            'Malicious table' => ['"\'"', '\'', true],
            'Malicious missing table' => ['t', '\'', false]
        ];
    }

    /** @dataProvider providePrimaryKeysToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasPrimaryKey
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getPrimaryKey */
    public function testHasPrimaryKey($tableDef, $key, $exp)
    {
        $this->assertFalse($this->adapter->hasTable('t'), 'Dirty test fixture');
        $conn = $this->adapter->getConnection();
        $conn->exec($tableDef);
        $this->assertEquals($exp, $this->adapter->hasPrimaryKey('t', $key));
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
            ['create table t(`a` integer PRIMARY KEY)', ['a'], true],
            ['create table t(`a` integer PRIMARY KEY)', ['a', 'b'], false],
            ['create table t(`a` integer, PRIMARY KEY(a))', 'a', true],
            ['create table t(`a` integer, PRIMARY KEY("a"))', 'a', true],
            ['create table t(`a` integer, PRIMARY KEY([a]))', 'a', true],
            ['create table t(`a` integer, PRIMARY KEY(`a`))', 'a', true],
            ['create table t(`a` integer, `b` integer PRIMARY KEY)', 'a', false],
            ['create table t(`a` integer, `b` text PRIMARY KEY)', 'b', true],
            ['create table t(`a` integer, `b` integer default 2112 PRIMARY KEY)', ['a'], false],
            ['create table t(`a` integer, `b` integer PRIMARY KEY)', ['b'], true],
            ['create table t(`a` integer, `b` integer, PRIMARY KEY(`a`,`b`))', ['b', 'a'], true],
            ['create table t(`a` integer, `b` integer, PRIMARY KEY(`a`,`b`))', ['a', 'b'], true],
            ['create table t(`a` integer, `b` integer, PRIMARY KEY(`a`,`b`))', 'a', false],
            ['create table t(`a` integer, `b` integer, PRIMARY KEY(`a`,`b`))', ['a'], false],
            ['create table t(`a` integer, `b` integer, PRIMARY KEY(`a`,`b`))', ['a', 'b', 'c'], false],
            ['create table t(`a` integer, `b` integer, PRIMARY KEY(`a`,`b`))', ['a', 'B'], true],
            ['create table t(`a` integer, `B` integer, PRIMARY KEY(`a`,`b`))', ['a', 'b'], true],
            ['create table t(`a` integer, `b` integer, constraint t_pk PRIMARY KEY(`a`,`b`))', ['a', 'b'], true],
            ['create table not_t(a integer)', 'a', false] // test checks table t which does not exist
        ];
    }

    /** @dataProvider provideForeignKeysToCheck
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::hasForeignKey
     *  @covers \Phinx\Db\Adapter\SQLiteAdapter::getForeignKeys */
    public function testHasForeignKey($tableDef, $key, $exp)
    {
        $conn = $this->adapter->getConnection();
        $conn->exec('CREATE TABLE other(a integer, b integer, c integer)');
        $conn->exec($tableDef);
        $this->assertEquals($exp, $this->adapter->hasForeignKey('t', $key));
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
            ['create table t(a integer); create temp table t(a integer references other(a))', ['a'], false],
        ];
    }
}
