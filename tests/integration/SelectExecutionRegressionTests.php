<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

/** Exercises real database servers using session-local temporary tables only. */
final class SelectExecutionRegressionTests extends TestCase
{
    private PdoDb $db;

    protected function setUp(): void
    {
        $driver = getenv('PDODB_TEST_DRIVER');
        $dsn = getenv('PDODB_TEST_DSN');
        if ($driver === false || $dsn === false) {
            self::markTestSkipped('Set PDODB_TEST_DRIVER and PDODB_TEST_DSN to run integration tests.');
        }

        $pdo = new PDO($dsn, getenv('PDODB_TEST_USER') ?: '', getenv('PDODB_TEST_PASSWORD') ?: '', [
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = new PdoDb($driver, ['pdo' => $pdo], cache: new Psr16Cache(new ArrayAdapter()));
        $this->db->rawQuery('CREATE TEMPORARY TABLE pdodb_release_check (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        $this->db->rawQuery('INSERT INTO pdodb_release_check (id, tenant_id) VALUES (1, 1), (2, 2), (3, 1)');
        $this->db->rawQuery('CREATE TEMPORARY TABLE pdodb_release_check_other (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        $this->db->rawQuery('INSERT INTO pdodb_release_check_other (id, tenant_id) VALUES (2, 2)');
    }

    public function testReusingCachedBuilderKeepsResultShapesSeparate(): void
    {
        $query = $this->db->find()->from('pdodb_release_check')->select('id')->orderBy('id')->cache();
        self::assertSame(1, (int)$query->getValue());
        self::assertSame([1, 2, 3], array_map('intval', array_column($query->get(), 'id')));
        self::assertSame(1, (int)$this->db->find()->from('pdodb_release_check')->select('id')->orderBy('id')->cache()->getValue());
    }

    public function testParameterizedCtePaginationAndScopes(): void
    {
        $db = $this->db;
        $query = static fn (int $tenant): QueryBuilder => $db->find()
            ->with('filtered', $db->find()->from('pdodb_release_check')->where('tenant_id', $tenant))
            ->from('filtered')->orderBy('id');
        $page = $query(1)->paginate(10, 1);
        self::assertSame(2, $page->total());
        self::assertSame([1, 3], array_map('intval', array_column($page->items(), 'id')));
        $page = $query(2)->paginate(10, 1);
        self::assertSame(1, $page->total());
        self::assertSame([2], array_map('intval', array_column($page->items(), 'id')));
        $firstSource = $db->find()->with('source', 'SELECT id FROM pdodb_release_check')->from('source')->get();
        $secondSource = $db->find()->with('source', 'SELECT id FROM pdodb_release_check_other')->from('source')->get();
        self::assertCount(3, $firstSource);
        self::assertSame([2], array_map('intval', array_column($secondSource, 'id')));

        $scoped = static fn (): QueryBuilder => $db->find()->from('pdodb_release_check')
            ->setGlobalScopes(['tenant' => static fn (QueryBuilder $q): QueryBuilder => $q->where('tenant_id', 1)]);
        self::assertSame([1, 3], array_map('intval', array_column($scoped()->orderBy('id')->paginate(10, 1)->items(), 'id')));
        self::assertSame([1, 3], array_map('intval', array_column($scoped()->orderBy('id')->simplePaginate(10, 1)->items(), 'id')));
        self::assertSame([1, 3], array_map('intval', array_column($scoped()->orderBy('id')->cursorPaginate(10)->items(), 'id')));
    }

    public function testGroupedCountAndParameterizedUnion(): void
    {
        $db = $this->db;
        $page = $db->find()->from('pdodb_release_check')->select('tenant_id')->groupBy('tenant_id')->paginate(10, 1);
        self::assertSame(2, $page->total());
        self::assertCount(2, $page->items());
        $query = static fn (): QueryBuilder => $db->find()->from('pdodb_release_check')->where('id', 99)
            ->union($db->find()->from('pdodb_release_check_other')->where('id', 2));
        self::assertSame(2, (int)$query()->getOne()['id']);
        self::assertSame(1, $query()->paginate(10, 1)->total());

        $joined = $db->find()->from('pdodb_release_check AS a')
            ->select(['a.id', 'b.id'])->join('pdodb_release_check_other AS b', 'a.id = b.id')
            ->paginate(10, 1);
        self::assertSame(1, $joined->total());
        $distinctJoined = $db->find()->from('pdodb_release_check AS a')
            ->select(['a.id', 'b.id'])->join('pdodb_release_check_other AS b', 'a.id = b.id')
            ->distinct()->paginate(10, 1);
        self::assertSame(1, $distinctJoined->total());
        $groupedJoined = $db->find()->from('pdodb_release_check AS a')
            ->select(['a.id', 'b.id'])->join('pdodb_release_check_other AS b', 'a.id = b.id')
            ->groupBy(['a.id', 'b.id'])->paginate(10, 1);
        self::assertSame(1, $groupedJoined->total());
    }
}
