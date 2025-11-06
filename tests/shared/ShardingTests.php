<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use RuntimeException;
use tommyknocker\pdodb\connection\sharding\ShardConfig;
use tommyknocker\pdodb\connection\sharding\ShardRouter;

/**
 * Tests for sharding functionality.
 */
final class ShardingTests extends BaseSharedTestCase
{
    protected ShardRouter $shardRouter;

    protected function setUp(): void
    {
        // Don't call parent::setUp() as it tries to delete test_coverage table
        // which doesn't exist in shard connections
        $this->shardRouter = new ShardRouter();
    }

    protected function createShardConnection(string $name): void
    {
        self::$db->addConnection($name, [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);
    }

    protected function setupShardTables(): void
    {
        foreach (['shard1', 'shard2', 'shard3'] as $shard) {
            $this->createShardConnection($shard);
            self::$db->connection($shard);
            self::$db->rawQuery('
                CREATE TABLE users (
                    user_id INTEGER PRIMARY KEY,
                    name TEXT,
                    email TEXT
                )
            ');
        }
    }

    public function testShardConfigCreation(): void
    {
        $config = new ShardConfig('users');
        $config->setShardKey('user_id')
            ->setStrategy('range')
            ->setRanges([
                'shard1' => [0, 1000],
                'shard2' => [1001, 2000],
            ])
            ->setShardCount(2);

        $this->assertEquals('users', $config->getTable());
        $this->assertEquals('user_id', $config->getShardKey());
        $this->assertEquals('range', $config->getStrategy());
        $this->assertEquals(2, $config->getShardCount());
    }

    public function testShardConfigValidation(): void
    {
        $config = new ShardConfig('users');
        $config->setShardKey('user_id')
            ->setStrategy('range')
            ->setRanges([
                'shard1' => [0, 1000],
            ])
            ->setShardCount(1);

        // Should not throw
        $config->validate();

        // Missing shard key
        $config2 = new ShardConfig('users');
        $config2->setStrategy('range');
        $this->expectException(InvalidArgumentException::class);
        $config2->validate();
    }

    public function testShardConfigValidationForRangeStrategy(): void
    {
        $config = new ShardConfig('users');
        $config->setShardKey('user_id')
            ->setStrategy('range')
            ->setShardCount(1);

        // Missing ranges
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Range strategy requires ranges');
        $config->validate();
    }

    public function testShardRouterRegisterShard(): void
    {
        $config = new ShardConfig('users');
        $config->setShardKey('user_id')
            ->setStrategy('range')
            ->setRanges([
                'shard1' => [0, 1000],
            ])
            ->setShardCount(1);

        $this->shardRouter->registerShard($config);
        $this->assertTrue($this->shardRouter->hasSharding('users'));
    }

    public function testShardRouterResolveShard(): void
    {
        $this->setupShardTables();

        // Configure sharding
        // Add connections to pool first
        foreach (['shard1', 'shard2', 'shard3'] as $shard) {
            self::$db->addConnection($shard, ['driver' => 'sqlite', 'path' => ':memory:']);
        }

        $config = new ShardConfig('users');
        $config->setShardKey('user_id')
            ->setStrategy('range')
            ->setRanges([
                'shard1' => [0, 1000],
                'shard2' => [1001, 2000],
                'shard3' => [2001, 3000],
            ])
            ->setShardCount(3);

        // Register connections manually for this test
        foreach (['shard1', 'shard2', 'shard3'] as $shard) {
            $connection = self::$db->getConnection($shard);
            $this->shardRouter->addShardConnection('users', $shard, $connection);
        }

        $this->shardRouter->registerShard($config);

        // Create query builder
        $query = self::$db->find()
            ->from('users')
            ->where('user_id', 500);

        $shardName = $this->shardRouter->resolveShard($query);
        $this->assertEquals('shard1', $shardName);
    }

    public function testShardRouterResolveShardReturnsNullWhenNoSharding(): void
    {
        $query = self::$db->find()
            ->from('products')
            ->where('id', 1);

        $shardName = $this->shardRouter->resolveShard($query);
        $this->assertNull($shardName);
    }

    public function testShardRouterResolveShardReturnsNullWhenShardKeyNotInWhere(): void
    {
        $this->setupShardTables();

        // Add connection to pool first
        self::$db->addConnection('shard1', ['driver' => 'sqlite', 'path' => ':memory:']);

        $config = new ShardConfig('users');
        $config->setShardKey('user_id')
            ->setStrategy('range')
            ->setRanges([
                'shard1' => [0, 1000],
            ])
            ->setShardCount(1);

        // Register connection manually for this test
        $connection = self::$db->getConnection('shard1');
        $this->shardRouter->addShardConnection('users', 'shard1', $connection);

        $this->shardRouter->registerShard($config);

        // Query without shard key
        $query = self::$db->find()
            ->from('users')
            ->where('name', 'test');

        $shardName = $this->shardRouter->resolveShard($query);
        $this->assertNull($shardName);
    }

    public function testShardRouterGetShardConnection(): void
    {
        $this->setupShardTables();

        $connection = self::$db->getConnection('shard1');

        $this->shardRouter->addShardConnection('users', 'shard1', $connection);
        $retrieved = $this->shardRouter->getShardConnection('users', 'shard1');

        $this->assertSame($connection, $retrieved);
    }

    public function testShardRouterGetShardConnectionThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shard connection not found');

        $this->shardRouter->getShardConnection('users', 'nonexistent');
    }

    public function testShardingIntegrationWithRangeStrategy(): void
    {
        $this->setupShardTables();

        // Configure sharding with existing connections
        self::$db->shard('users')
            ->shardKey('user_id')
            ->strategy('range')
            ->ranges([
                'shard1' => [0, 1000],
                'shard2' => [1001, 2000],
                'shard3' => [2001, 3000],
            ])
            ->useConnections(['shard1', 'shard2', 'shard3'])
            ->register();

        // Insert test data using sharding
        self::$db->find()
            ->table('users')
            ->insert([
                'user_id' => 500,
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ]);

        // Insert test data in shard2
        self::$db->find()
            ->table('users')
            ->insert([
                'user_id' => 1500,
                'name' => 'Bob',
                'email' => 'bob@example.com',
            ]);

        // Query with shard key - should route to shard1
        $user = self::$db->find()
            ->from('users')
            ->where('user_id', 500)
            ->getOne();

        $this->assertNotNull($user);
        $this->assertIsArray($user);
        $this->assertEquals('Alice', $user['name']);

        // Query with different shard key - should route to shard2
        $user2 = self::$db->find()
            ->from('users')
            ->where('user_id', 1500)
            ->getOne();

        $this->assertNotNull($user2);
        $this->assertIsArray($user2);
        $this->assertEquals('Bob', $user2['name']);
    }

    public function testShardingIntegrationWithHashStrategy(): void
    {
        $this->setupShardTables();

        // Configure hash sharding
        self::$db->shard('users')
            ->shardKey('user_id')
            ->strategy('hash')
            ->useConnections(['shard1', 'shard2', 'shard3'])
            ->register();

        // Insert data - it will be distributed across shards
        $userIds = [1, 2, 3, 10, 20, 50, 100];
        foreach ($userIds as $userId) {
            self::$db->find()
                ->from('users')
                ->insert([
                    'user_id' => $userId,
                    'name' => "User {$userId}",
                    'email' => "user{$userId}@example.com",
                ]);
        }

        // Verify data can be retrieved
        $user = self::$db->find()
            ->from('users')
            ->where('user_id', 1)
            ->getOne();

        $this->assertNotNull($user);
        $this->assertEquals('User 1', $user['name']);
    }

    public function testShardingIntegrationWithModuloStrategy(): void
    {
        $this->setupShardTables();

        // Configure modulo sharding
        self::$db->shard('users')
            ->shardKey('user_id')
            ->strategy('modulo')
            ->useConnections(['shard1', 'shard2', 'shard3'])
            ->register();

        // Insert data
        self::$db->find()
            ->from('users')
            ->insert([
                'user_id' => 5,
                'name' => 'User 5',
                'email' => 'user5@example.com',
            ]);

        // Query - user_id 5 % 3 = 2, so should go to shard3 (index 2 + 1)
        $user = self::$db->find()
            ->from('users')
            ->where('user_id', 5)
            ->getOne();

        $this->assertNotNull($user);
        $this->assertEquals('User 5', $user['name']);
    }

    public function testShardingInsert(): void
    {
        $this->setupShardTables();

        // Configure sharding with existing connections
        self::$db->shard('users')
            ->shardKey('user_id')
            ->strategy('range')
            ->ranges([
                'shard1' => [0, 1000],
                'shard2' => [1001, 2000],
            ])
            ->useConnections(['shard1', 'shard2'])
            ->register();

        // Insert - should route to shard1
        $affected = self::$db->find()
            ->table('users')
            ->insert([
                'user_id' => 500,
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ]);

        $this->assertGreaterThan(0, $affected);

        // Verify data can be retrieved through sharding
        $user = self::$db->find()
            ->from('users')
            ->where('user_id', 500)
            ->getOne();

        $this->assertIsArray($user);
        $this->assertEquals('Alice', $user['name']);

        // Insert another record in shard2
        $affected2 = self::$db->find()
            ->table('users')
            ->insert([
                'user_id' => 1500,
                'name' => 'Bob',
                'email' => 'bob@example.com',
            ]);

        $this->assertGreaterThan(0, $affected2);

        // Verify both records can be retrieved
        $user1 = self::$db->find()
            ->from('users')
            ->where('user_id', 500)
            ->getOne();
        $this->assertEquals('Alice', $user1['name']);

        $user2 = self::$db->find()
            ->from('users')
            ->where('user_id', 1500)
            ->getOne();
        $this->assertEquals('Bob', $user2['name']);
    }

    public function testShardingUpdate(): void
    {
        $this->setupShardTables();

        // Configure sharding
        self::$db->shard('users')
            ->shardKey('user_id')
            ->strategy('range')
            ->ranges([
                'shard1' => [0, 1000],
                'shard2' => [1001, 2000],
            ])
            ->useConnections(['shard1', 'shard2'])
            ->register();

        // Insert data in shard1
        self::$db->connection('shard1');
        self::$db->find()->table('users')->insert([
            'user_id' => 500,
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        // Update via sharding
        $affected = self::$db->find()
            ->table('users')
            ->where('user_id', 500)
            ->update(['name' => 'Alice Updated']);

        $this->assertEquals(1, $affected);

        // Verify update
        $user = self::$db->find()
            ->from('users')
            ->where('user_id', 500)
            ->getOne();

        $this->assertEquals('Alice Updated', $user['name']);
    }

    public function testShardingDelete(): void
    {
        $this->setupShardTables();

        // Configure sharding
        self::$db->shard('users')
            ->shardKey('user_id')
            ->strategy('range')
            ->ranges([
                'shard1' => [0, 1000],
                'shard2' => [1001, 2000],
            ])
            ->useConnections(['shard1', 'shard2'])
            ->register();

        // Insert data in shard1
        self::$db->connection('shard1');
        self::$db->find()->table('users')->insert([
            'user_id' => 500,
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        // Delete via sharding
        $affected = self::$db->find()
            ->table('users')
            ->where('user_id', 500)
            ->delete();

        $this->assertEquals(1, $affected);

        // Verify deletion
        $user = self::$db->find()
            ->from('users')
            ->where('user_id', 500)
            ->getOne();

        $this->assertFalse($user);
    }
}
