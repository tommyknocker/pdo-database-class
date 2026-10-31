<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Fiber;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryProfiler;

class DiagnosticUser extends Model
{
    public static function tableName(): string { return 'diag_users'; }
    public static function globalScopes(): array
    {
        return ['kind' => static fn ($query) => $query->where('kind', 'sensitive-secret')];
    }
}
class DiagnosticOrder extends Model
{
    public static function tableName(): string { return 'diag_orders'; }
    public static function relations(): array
    {
        return ['user' => ['belongsTo', 'modelClass' => DiagnosticUser::class, 'foreignKey' => 'user_id', 'ownerKey' => 'id']];
    }
}

final class RelationDiagnosticsTests extends TestCase
{
    public function testLazyAndEagerLoadingAreCountedWithoutExposingParameters(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE diag_users (id INTEGER PRIMARY KEY, kind TEXT)');
        $db->rawQuery('CREATE TABLE diag_orders (id INTEGER PRIMARY KEY, user_id INTEGER)');
        for ($i = 1; $i <= 12; $i++) {
            $db->find()->table('diag_users')->insert(['id' => $i, 'kind' => 'sensitive-secret']);
            $db->find()->table('diag_orders')->insert(['id' => $i, 'user_id' => $i]);
        }
        DiagnosticOrder::setDb($db);
        DiagnosticUser::setDb($db);
        $db->enableProfiling();
        foreach (DiagnosticOrder::find()->all() as $order) {
            self::assertInstanceOf(DiagnosticUser::class, $order->user);
        }
        $summary = $db->profileReport()->summary();
        $relation = $summary['relations'][0];
        self::assertSame(12, $relation['lazy_loads']);
        self::assertSame(12, $relation['lazy_queries']);
        self::assertTrue($relation['possible_n_plus_one']);
        self::assertStringNotContainsString('sensitive-secret', json_encode($summary, JSON_THROW_ON_ERROR));

        $db->getProfiler()->reset()->setMaxTrackedQueries(1);
        foreach (DiagnosticOrder::find()->with('user')->all() as $order) {
            self::assertInstanceOf(DiagnosticUser::class, $order->user);
        }
        $relation = $db->profileReport()->summary()['relations'][0];
        self::assertSame(1, $relation['eager_loads']);
        self::assertSame(1, $relation['eager_queries']);
        self::assertSame(0, $relation['lazy_queries']);
        self::assertFalse($relation['possible_n_plus_one']);
        $db->getProfiler()->reset();
        self::assertSame([], $db->profileReport()->summary()['relations']);
    }

    public function testRelationScopesAreIsolatedBetweenFibersAndBounded(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $fiber = new Fiber(static function () use ($profiler): void {
            $profiler->beginRelation('A', 'foo', 'lazy');
            Fiber::suspend();
            $id = $profiler->startQuery("SELECT 'secret'");
            $profiler->endQuery($id);
            $profiler->endRelation();
        });
        $fiber->start();
        $profiler->beginRelation('B', 'bar', 'lazy');
        $id = $profiler->startQuery('SELECT 1');
        $profiler->endQuery($id);
        $profiler->endRelation();
        $fiber->resume();
        $stats = $profiler->getRelationStats();
        self::assertSame(1, $stats[0]['lazy_queries']);
        self::assertSame(1, $stats[1]['lazy_queries']);
        self::assertStringNotContainsString('secret', json_encode($stats, JSON_THROW_ON_ERROR));
        for ($i = 0; $i < 100; $i++) {
            $profiler->beginRelation('C', (string)$i, 'eager');
            $profiler->endRelation();
        }
        self::assertCount(100, $profiler->getRelationStats());
        self::assertTrue($profiler->areRelationStatsTruncated());
    }
}
