<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

class MaintenanceScopedUser extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }

    public static function globalScopes(): array
    {
        return ['tenant' => static fn (QueryBuilder $q): QueryBuilder => $q->where('tenant_id', 1)];
    }
}

final class MaintenanceRegressionTests extends TestCase
{
    private function database(?Psr16Cache $cache = null): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:'], cache: $cache);
        $db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        return $db;
    }

    public function testCacheSeparatesDatabasesAndResultShapes(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $first = $this->database($cache);
        $second = $this->database($cache);
        $first->rawQuery("INSERT INTO users VALUES (1, 1, 'Alice'), (2, 2, 'Bob')");
        $second->rawQuery("INSERT INTO users VALUES (99, 3, 'Carol')");

        self::assertCount(2, $first->find()->from('users')->cache()->get());
        self::assertSame(1, $first->find()->from('users')->cache()->getOne()['id']);
        self::assertSame(99, $second->find()->from('users')->cache()->getOne()['id']);
        self::assertSame(99, $second->find()->from('users')->cache()->get()[0]['id']);
        self::assertSame([1, 2], $first->find()->from('users')->select(['id', 'name'])->cache()->getColumn('id'));
        self::assertSame(['Alice', 'Bob'], $first->find()->from('users')->select(['id', 'name'])->cache()->getColumn('name'));
        self::assertSame(1, $first->find()->from('users')->select('id')->cache()->getValue());
        self::assertCount(2, $first->find()->from('users')->select('id')->cache()->get());
    }

    public function testCachedValueDoesNotLeakIntoOtherResultShapesWhenBuilderIsReused(): void
    {
        $db = $this->database(new Psr16Cache(new ArrayAdapter()));
        $db->rawQuery("INSERT INTO users VALUES (1, 1, 'Alice'), (2, 1, 'Bob')");
        $query = $db->find()->from('users')->select('id')->cache();

        self::assertSame(1, $query->getValue());
        self::assertSame([['id' => 1], ['id' => 2]], $query->get());
        self::assertSame(1, $db->find()->from('users')->select('id')->cache()->getValue());
        self::assertSame(['id' => 1], $query->getOne());
        self::assertSame(1, $query->getValue());
    }

    public function testParameterizedCtePaginationAndCompilationCache(): void
    {
        $db = $this->database(new Psr16Cache(new ArrayAdapter()));
        $db->rawQuery("INSERT INTO users VALUES (1, 1, 'Alice'), (2, 2, 'Bob'), (3, 1, 'Carol')");
        $query = static fn (int $tenant): QueryBuilder => $db->find()
            ->with('filtered', $db->find()->from('users')->where('tenant_id', $tenant))
            ->from('filtered')->orderBy('id');

        $first = $query(1)->paginate(10, 1);
        self::assertSame(2, $first->total());
        self::assertSame([1, 3], array_column($first->items(), 'id'));
        $second = $query(2)->paginate(10, 1);
        self::assertSame(1, $second->total());
        self::assertSame([2], array_column($second->items(), 'id'));

        // These CTEs share the outer structure but select from different tables.
        $db->rawQuery('CREATE TABLE others (id INTEGER)');
        $db->rawQuery('INSERT INTO others VALUES (99)');
        $fromUsers = $db->find()->with('source', 'SELECT id FROM users')->from('source')->get();
        $fromOthers = $db->find()->with('source', 'SELECT id FROM others')->from('source')->get();
        self::assertSame([1, 2, 3], array_column($fromUsers, 'id'));
        self::assertSame([99], array_column($fromOthers, 'id'));
    }

    public function testPaginationAppliesScopesAndCountsGroups(): void
    {
        $db = $this->database();
        $db->rawQuery("INSERT INTO users VALUES (1, 1, 'Alice'), (2, 2, 'Bob'), (3, 1, 'Carol')");
        $scoped = static fn (): QueryBuilder => $db->find()->from('users')
            ->setGlobalScopes(['tenant' => static fn (QueryBuilder $q): QueryBuilder => $q->where('tenant_id', 1)]);
        self::assertSame([1, 3], array_column($scoped()->orderBy('id')->paginate(10, 1)->items(), 'id'));
        self::assertSame([1, 3], array_column($scoped()->orderBy('id')->simplePaginate(10, 1)->items(), 'id'));
        self::assertSame([1, 3], array_column($scoped()->orderBy('id')->cursorPaginate(10)->items(), 'id'));
        $page = $db->find()->from('users')->select('tenant_id')->groupBy('tenant_id')->paginate(10, 1);
        self::assertCount(2, $page->items());
        self::assertSame(2, $page->total());
        $distinct = $db->find()->from('users')->select('tenant_id')->distinct()->paginate(10, 1);
        self::assertSame(2, $distinct->total());
        $having = $db->find()->from('users')->select('tenant_id')->groupBy('tenant_id')
            ->havingRaw('COUNT(*) > 1')->paginate(10, 1);
        self::assertSame(1, $having->total());
        self::assertSame(1, $having->items()[0]['tenant_id']);
        $cte = $db->find()->with('filtered', 'SELECT id, tenant_id FROM users WHERE tenant_id = 1')
            ->from('filtered')->paginate(10, 1);
        self::assertSame(2, $cte->total());
        self::assertCount(2, $cte->items());
        MaintenanceScopedUser::setDb($db);
        self::assertSame([1, 3], array_column(MaintenanceScopedUser::find()->orderBy('id')->paginate(10, 1)->items(), 'id'));
        MaintenanceScopedUser::setDb(null);
    }

    public function testUnionIsAppliedToGetOne(): void
    {
        $db = $this->database();
        $db->rawQuery("INSERT INTO users VALUES (1, 1, 'Alice')");
        $row = $db->find()->from('users')->whereRaw('id = 999')
            ->union($db->find()->from('users')->whereRaw('id = 1'))->getOne();
        self::assertSame(1, $row['id']);
        $query = static fn (): QueryBuilder => $db->find()->from('users')->where('id', 999)
            ->union($db->find()->from('users')->where('id', 1));
        self::assertSame(1, $query()->getOne()['id']);
        self::assertSame(1, $query()->get()[0]['id']);
        self::assertSame(1, $query()->paginate(10, 1)->total());
        $cachedDb = $this->database(new Psr16Cache(new ArrayAdapter()));
        $cachedDb->rawQuery("INSERT INTO users VALUES (1, 1, 'Alice')");
        $cachedUnion = static fn (): QueryBuilder => $cachedDb->find()->from('users')->where('id', 999)
            ->union($cachedDb->find()->from('users')->where('id', 1));
        self::assertSame(1, $cachedUnion()->cache()->getOne()['id']);
        self::assertSame(1, $cachedUnion()->cache()->get()[0]['id']);
        self::assertFalse($db->find()->from('users')->whereRaw('id = 999')->getOne());
    }

    public function testSafeExceptionArrayExcludesSecrets(): void
    {
        $db = $this->database();

        try {
            $db->find()->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'secret']);
            $db->find()->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'secret']);
            self::fail('Expected database exception');
        } catch (DatabaseException $e) {
            self::assertStringNotContainsString('secret', json_encode($e->toSafeArray(), JSON_THROW_ON_ERROR));
            self::assertArrayHasKey('category', $e->toSafeArray());
        }
    }
}
