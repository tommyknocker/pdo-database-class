<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\PdoDb;

/**
 * Read/write splitting and sharding must change where a query runs, not only which connection the builder reports.
 */
final class ConnectionRoutingTests extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function file(string $name): string
    {
        $path = sys_get_temp_dir() . '/pdodb_routing_' . bin2hex(random_bytes(4)) . '_' . $name . '.sqlite';
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function seed(PdoDb $db, string $connection, string $ddl, string $insert, array $rows): void
    {
        $db->connection($connection);
        $db->rawQuery($ddl);
        foreach ($rows as $row) {
            $db->rawQuery($insert, $row);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function peek(string $path, string $sql): array
    {
        $pdo = new PDO('sqlite:' . $path);

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function readWriteDb(string &$master, string &$replica): PdoDb
    {
        $master = $this->file('master');
        $replica = $this->file('replica');
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->addConnection('write', ['driver' => 'sqlite', 'path' => $master]);
        $db->addConnection('read', ['driver' => 'sqlite', 'path' => $replica, 'type' => 'read']);
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, src TEXT)';
        $this->seed($db, 'write', $ddl, 'INSERT INTO t (id, src) VALUES (1, :src)', [['src' => 'master']]);
        $this->seed($db, 'read', $ddl, 'INSERT INTO t (id, src) VALUES (1, :src)', [['src' => 'replica']]);
        $db->connection('write');

        return $db;
    }

    public function testReadsRunOnTheReplicaAndWritesOnTheMaster(): void
    {
        $master = $replica = '';
        $db = $this->readWriteDb($master, $replica);

        $this->assertSame('replica', $db->find()->from('t')->where('id', 1)->getOne()['src']);

        $db->find()->table('t')->insert(['id' => 2, 'src' => 'written']);
        $db->find()->table('t')->where('id', 1)->update(['src' => 'updated']);
        $db->find()->table('t')->where('id', 2)->delete();

        $this->assertSame([['id' => 1, 'src' => 'updated']], $this->peek($master, 'SELECT * FROM t ORDER BY id'));
        $this->assertSame([['id' => 1, 'src' => 'replica']], $this->peek($replica, 'SELECT * FROM t ORDER BY id'));
    }

    public function testForceWriteReadsFromTheMaster(): void
    {
        $master = $replica = '';
        $db = $this->readWriteDb($master, $replica);

        $this->assertSame('master', $db->find()->from('t')->where('id', 1)->forceWrite()->getOne()['src']);
        $this->assertSame('replica', $db->find()->from('t')->where('id', 1)->getOne()['src']);
    }

    public function testBuilderGoesBackToItsConnectionAfterAReplicaRead(): void
    {
        $master = $replica = '';
        $db = $this->readWriteDb($master, $replica);
        $writeConnection = $db->getConnection('write');

        $query = $db->find()->from('t')->where('id', 1);
        $this->assertSame('replica', $query->getOne()['src']);
        $this->assertSame($writeConnection, $query->getConnection());
    }

    public function testShardedQueriesRunOnTheirShard(): void
    {
        $default = $this->file('default');
        $shard1 = $this->file('shard1');
        $shard2 = $this->file('shard2');
        $db = new PdoDb('sqlite', ['path' => $default]);
        $ddl = 'CREATE TABLE users (user_id INTEGER PRIMARY KEY, name TEXT)';
        $db->addConnection('shard1', ['driver' => 'sqlite', 'path' => $shard1]);
        $db->addConnection('shard2', ['driver' => 'sqlite', 'path' => $shard2]);
        foreach (['shard1', 'shard2', 'default'] as $name) {
            $this->seed($db, $name, $ddl, 'SELECT 1', []);
        }
        $db->shard('users')
            ->shardKey('user_id')
            ->strategy('range')
            ->ranges(['shard1' => [0, 1000], 'shard2' => [1001, 2000]])
            ->useConnections(['shard1', 'shard2'])
            ->register();

        $db->find()->table('users')->insert(['user_id' => 500, 'name' => 'Alice']);
        $db->find()->table('users')->insert(['user_id' => 1500, 'name' => 'Bob']);
        $db->find()->table('users')->where('user_id', 500)->update(['name' => 'Alicia']);

        $this->assertSame([['user_id' => 500, 'name' => 'Alicia']], $this->peek($shard1, 'SELECT * FROM users'));
        $this->assertSame([['user_id' => 1500, 'name' => 'Bob']], $this->peek($shard2, 'SELECT * FROM users'));
        $this->assertSame([], $this->peek($default, 'SELECT * FROM users'));
        $this->assertSame('Bob', $db->find()->from('users')->where('user_id', 1500)->getOne()['name']);

        $db->find()->table('users')->where('user_id', 1500)->delete();
        $this->assertSame([], $this->peek($shard2, 'SELECT * FROM users'));
    }

    public function testShardedBuilderGoesBackToItsConnectionWithoutAReadWriteRouter(): void
    {
        $default = $this->file('default');
        $shard1 = $this->file('shard1');
        $db = new PdoDb('sqlite', ['path' => $default]);
        $db->addConnection('shard1', ['driver' => 'sqlite', 'path' => $shard1]);
        foreach (['shard1', 'default'] as $name) {
            $this->seed($db, $name, 'CREATE TABLE users (user_id INTEGER PRIMARY KEY, name TEXT)', 'SELECT 1', []);
        }
        $db->shard('users')->shardKey('user_id')->strategy('range')->ranges(['shard1' => [0, 1000]])->useConnections(['shard1'])->register();
        $original = $db->find()->getConnection();

        $query = $db->find()->from('users')->where('user_id', 500);
        $query->get();

        $this->assertSame($original, $query->getConnection());
    }

    public function testProfilerSurvivesAConnectionSwitch(): void
    {
        $master = $replica = '';
        $db = $this->readWriteDb($master, $replica);
        $db->enableProfiling();

        $db->find()->from('t')->where('id', 1)->get();
        $db->find()->from('t')->where('id', 1)->get();

        $stats = $db->getProfilerStats(true);
        $this->assertGreaterThanOrEqual(2, (int) ($stats['total_queries'] ?? 0));
    }

    public function testBulkInsertRoutesToTheShardOfItsRows(): void
    {
        $default = $this->file('default');
        $shard1 = $this->file('shard1');
        $shard2 = $this->file('shard2');
        $db = new PdoDb('sqlite', ['path' => $default]);
        $db->addConnection('shard1', ['driver' => 'sqlite', 'path' => $shard1]);
        $db->addConnection('shard2', ['driver' => 'sqlite', 'path' => $shard2]);
        foreach (['shard1', 'shard2', 'default'] as $name) {
            $this->seed($db, $name, 'CREATE TABLE users (user_id INTEGER PRIMARY KEY, name TEXT)', 'SELECT 1', []);
        }
        $db->shard('users')
            ->shardKey('user_id')
            ->strategy('range')
            ->ranges(['shard1' => [0, 1000], 'shard2' => [1001, 2000]])
            ->useConnections(['shard1', 'shard2'])
            ->register();

        $db->find()->table('users')->insertMulti([
            ['user_id' => 1, 'name' => 'A'],
            ['user_id' => 2, 'name' => 'B'],
        ]);
        $this->assertCount(2, $this->peek($shard1, 'SELECT * FROM users'));
        $this->assertSame([], $this->peek($default, 'SELECT * FROM users'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('belong to different shards');
        $db->find()->table('users')->insertMulti([
            ['user_id' => 3, 'name' => 'C'],
            ['user_id' => 1500, 'name' => 'D'],
        ]);
    }

    public function testRoutingToAnotherDialectIsRejected(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $query = $db->find()->from('t');
        $other = $this->createMock(ConnectionInterface::class);
        $other->method('getDialect')->willReturn(new MySQLDialect());

        $route = new ReflectionMethod($query, 'routeTo');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('same SQL dialect');
        $route->invoke($query, $other);
    }
}
