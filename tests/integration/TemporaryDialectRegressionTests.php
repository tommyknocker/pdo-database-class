<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

/** Uses only session-private tables; all objects disappear when the connection closes. */
final class TemporaryDialectRegressionTests extends TestCase
{
    private PdoDb $db;
    private string $table;
    private string $other;

    protected function setUp(): void
    {
        $driver = getenv('PDODB_TEST_DRIVER');
        $dsn = getenv('PDODB_TEST_DSN');
        if (!in_array($driver, ['oci', 'sqlsrv'], true) || $dsn === false) {
            self::markTestSkipped('Requires PDODB_TEST_DRIVER=oci or sqlsrv and PDODB_TEST_DSN.');
        }
        $pdo = new PDO($dsn, getenv('PDODB_TEST_USER') ?: '', getenv('PDODB_TEST_PASSWORD') ?: '');
        $this->db = new PdoDb($driver, ['pdo' => $pdo], cache: new Psr16Cache(new ArrayAdapter()));
        if ($driver === 'oci') {
            $this->table = 'ORA$PTT_PDO_AUDIT';
            $this->other = 'ORA$PTT_PDO_AUDIT_OTHER';
            $this->db->rawQuery('CREATE PRIVATE TEMPORARY TABLE ORA$PTT_PDO_AUDIT (ID NUMBER, TENANT_ID NUMBER) ON COMMIT PRESERVE DEFINITION');
            $this->db->rawQuery('CREATE PRIVATE TEMPORARY TABLE ORA$PTT_PDO_AUDIT_OTHER (ID NUMBER, TENANT_ID NUMBER) ON COMMIT PRESERVE DEFINITION');
        } else {
            $this->table = '#pdo_audit';
            $this->other = '#pdo_audit_other';
            $pdo->exec('CREATE TABLE #pdo_audit (id INTEGER, tenant_id INTEGER)');
            $pdo->exec('CREATE TABLE #pdo_audit_other (id INTEGER, tenant_id INTEGER)');
        }
        foreach ([[1, 1], [2, 2], [3, 1]] as [$id, $tenant]) {
            $this->db->rawQuery("INSERT INTO {$this->table} (id, tenant_id) VALUES ($id, $tenant)");
        }
        $this->db->rawQuery("INSERT INTO {$this->other} (id, tenant_id) VALUES (2, 2)");
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, int> */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int)(array_change_key_case($row, CASE_LOWER)['id']), $rows);
    }

    public function testParameterizedCtePagination(): void
    {
        $db = $this->db;
        $table = $this->table;
        $query = static fn (int $tenant): QueryBuilder => $db->find()
            ->with('filtered', $db->find()->from($table)->where('tenant_id', $tenant))
            ->from('filtered')->orderBy('id');
        $page = $query(1)->paginate(10, 1);
        self::assertSame(2, $page->total());
        self::assertSame([1, 3], $this->ids($page->items()));
        $page = $query(2)->paginate(10, 1);
        self::assertSame(1, $page->total());
        self::assertSame([2], $this->ids($page->items()));
    }

    public function testJoinedAndGroupedPagination(): void
    {
        $db = $this->db;
        $table = $this->table;
        $other = $this->other;
        $base = static fn (): QueryBuilder => $db->find()->from($table . ' AS a')
            ->select(['a.id', 'b.id'])->join($other . ' AS b', 'a.id = b.id');
        self::assertSame(1, $base()->paginate(10, 1)->total());
        self::assertSame(1, $base()->distinct()->paginate(10, 1)->total());
        self::assertSame(1, $base()->groupBy(['a.id', 'b.id'])->paginate(10, 1)->total());
    }

    public function testCachedReadAndScopedPagination(): void
    {
        $query = $this->db->find()->from($this->table)->select('id')->orderBy('id')->cache();
        self::assertSame(1, (int)$query->getValue());
        self::assertSame([1, 2, 3], $this->ids($query->get()));
        self::assertSame(1, (int)$this->db->find()->from($this->table)->select('id')->orderBy('id')->cache()->getValue());
        $scoped = $this->db->find()->from($this->table)
            ->setGlobalScopes(['tenant' => static fn (QueryBuilder $q): QueryBuilder => $q->where('tenant_id', 1)])
            ->orderBy('id')->paginate(10, 1);
        self::assertSame([1, 3], $this->ids($scoped->items()));
    }
}
