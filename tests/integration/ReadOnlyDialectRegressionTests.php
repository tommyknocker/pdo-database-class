<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

/** Integration checks that never create, modify, or delete database objects. */
final class ReadOnlyDialectRegressionTests extends TestCase
{
    private PdoDb $db;
    private string $fixture;

    protected function setUp(): void
    {
        $driver = getenv('PDODB_TEST_DRIVER');
        $dsn = getenv('PDODB_TEST_DSN');
        if ($driver === false || $dsn === false) {
            self::markTestSkipped('Set PDODB_TEST_DRIVER and PDODB_TEST_DSN to run integration tests.');
        }
        $pdo = new PDO($dsn, getenv('PDODB_TEST_USER') ?: '', getenv('PDODB_TEST_PASSWORD') ?: '');
        $this->db = new PdoDb($driver, ['pdo' => $pdo], cache: new Psr16Cache(new ArrayAdapter()));
        $from = $driver === 'oci' ? ' FROM DUAL' : '';
        $this->fixture = 'SELECT 1 AS id, 1 AS tenant_id' . $from
            . ' UNION ALL SELECT 2 AS id, 2 AS tenant_id' . $from
            . ' UNION ALL SELECT 3 AS id, 1 AS tenant_id' . $from;
    }

    private function query(): QueryBuilder
    {
        return $this->db->find()->with('audit_rows', $this->fixture)->from('audit_rows');
    }

    public function testScopedPagination(): void
    {
        $query = $this->query()->setGlobalScopes([
            'tenant' => static fn (QueryBuilder $q): QueryBuilder => $q->where('tenant_id', 1),
        ])->orderBy('id');
        $page = $query->paginate(10, 1);
        self::assertSame(2, $page->total());
        self::assertCount(2, $page->items());
    }

    public function testGroupedPaginationAndCachedResultShapes(): void
    {
        $page = $this->query()->select('tenant_id')->groupBy('tenant_id')->paginate(10, 1);
        self::assertSame(2, $page->total());
        self::assertCount(2, $page->items());
        $distinct = $this->query()->select('tenant_id')->distinct()->paginate(10, 1);
        self::assertSame(2, $distinct->total());
        self::assertCount(2, $distinct->items());

        $query = $this->query()->select('id')->orderBy('id')->cache();
        self::assertSame(1, (int)$query->getValue());
        self::assertCount(3, $query->get());
        self::assertSame(1, (int)$this->query()->select('id')->orderBy('id')->cache()->getValue());
    }
}
