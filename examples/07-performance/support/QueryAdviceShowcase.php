<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\examples;

use Closure;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

/** Reproducible example fixtures, always in a new SQLite :memory: database. */
final class QueryAdviceShowcase
{
    private PdoDb $db;
    private const int USERS = 2000;
    private const int WARMUPS = 2;
    private const int RUNS = 9;

    /** @param array<string, mixed> $ai */
    public function __construct(private int $orderCount = 100000, array $ai = [], private ?Closure $progress = null)
    {
        if ($orderCount < 10000 || $orderCount > 500000) {
            throw new RuntimeException('Use --rows between 10000 and 500000');
        }
        $this->db = new PdoDb('sqlite', ['path' => ':memory:', 'ai' => $ai]);
        $this->seed();
    }

    private function seed(): void
    {
        $pdo = $this->db->connection->getPdo();
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, country TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), status TEXT NOT NULL, created_at TEXT NOT NULL, total_cents INTEGER NOT NULL)');
        $user = $pdo->prepare('INSERT INTO users VALUES (?, ?)');
        $order = $pdo->prepare('INSERT INTO orders VALUES (?, ?, ?, ?, ?)');
        $pdo->beginTransaction();
        for ($id = 1; $id <= self::USERS; ++$id) {
            $user->execute([$id, $id % 100 === 0 ? 'IS' : 'US']);
        }
        for ($id = 1; $id <= $this->orderCount; ++$id) {
            $order->execute([$id, ($id * 73) % self::USERS + 1, $id % 20 === 0 ? 'pending' : 'paid',
                gmdate('Y-m-d H:i:s', 1704067200 + $id * 60), 1000 + ($id * 7919) % 49000]);
        }
        $pdo->commit();
    }

    /** @return array<string, mixed> */
    public function run(bool $withAi = false, int $maxTokens = 512, int $timeout = 45): array
    {
        $report = ['generated_at' => gmdate('c'), 'environment' => ['php' => PHP_VERSION,
            'sqlite' => $this->db->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'fixture' => ['users' => self::USERS, 'orders' => $this->orderCount, 'pending_orders' => intdiv($this->orderCount, 20), 'customers_in_IS' => 20],
            'warmup_runs' => self::WARMUPS, 'measured_runs' => self::RUNS, 'statistic' => 'median of SELECT execution plus fetching all result rows, milliseconds',
            'database' => 'new SQLite :memory:; no configured application database is used'], 'ai_enabled' => $withAi, 'cases' => []];
        $options = ['max_tokens' => max(64, min(512, $maxTokens)), 'timeout' => max(5, min(90, $timeout))];

        // 1. A filtered, ordered page needs an index that serves both operations.
        $query = $this->db->find()->from('orders')->select(['id', 'user_id', 'created_at'])
            ->where('status', 'pending')->orderBy('created_at', 'DESC')->limit(50);
        $case = $this->capture(
            'ordered_page',
            'The first page of pending orders',
            $query,
            $withAi,
            $options,
            'Explain the scan and sorting for this first page. Recommend one candidate index if justified.'
        );
        $case['change_applied'] = 'CREATE INDEX idx_orders_status_created ON orders(status, created_at DESC)';
        $this->db->rawQuery($case['change_applied']);
        $case['after'] = $this->measure($query);
        $this->verifySameRows($case);
        $report['cases'][] = $case;

        // 2. An index on orders.status cannot solve an unrelated join/filter.
        $query = $this->db->find()->from('users u')->join('orders o', 'o.user_id = u.id')
            ->select(['o.id', 'o.total_cents'])->where('u.country', 'IS')->where('o.total_cents', 9000, '>=')->orderBy('o.id');
        $case = $this->capture(
            'join_filter',
            'Orders from customers in a small country group',
            $query,
            $withAi,
            $options,
            'Investigate the JOIN and country filter. In this synthetic fixture IS represents 1% of customers. Recommend candidate indexes using both schemas.'
        );
        $case['change_applied'] = 'CREATE INDEX idx_users_country ON users(country); CREATE INDEX idx_orders_user_total ON orders(user_id, total_cents)';
        // These fixed statements are reviewed example code, never model-generated SQL.
        $this->db->rawQuery('CREATE INDEX idx_users_country ON users(country)');
        $this->db->rawQuery('CREATE INDEX idx_orders_user_total ON orders(user_id, total_cents)');
        $case['after'] = $this->measure($query);
        $this->verifySameRows($case);
        $report['cases'][] = $case;

        // 3. Prepare a fixture state in which a suitable index already exists.
        $this->db->rawQuery('CREATE INDEX idx_orders_user_created ON orders(user_id, created_at DESC)');
        $query = $this->db->find()->from('orders')->select(['id', 'created_at', 'total_cents'])
            ->where('user_id', 42)->orderBy('created_at', 'DESC')->limit(20);
        $case = $this->capture(
            'existing_index',
            'Recent orders for one customer',
            $query,
            $withAi,
            $options,
            'Review this query and existing indexes. Is another index actually necessary? Explain evidence and tradeoffs; do not add an index merely because one was requested.'
        );
        $case['fixture_setup'] = 'CREATE INDEX idx_orders_user_created ON orders(user_id, created_at DESC)';
        $case['change_applied'] = null;
        $case['verification'] = 'The existing idx_orders_user_created is used; no temporary sorting B-tree is needed. No optimization change was applied for this case.';
        $report['cases'][] = $case;
        return $report;
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function capture(string $id, string $title, QueryBuilder $query, bool $withAi, array $options, string $goal): array
    {
        $options['context'] = ['goal' => $goal, 'language' => 'English', 'constraints' => [
            'Answer in at most 120 words. Include observation, supplied evidence, candidate change or why no change, and a verification step.',
            'Do not claim a measured speedup. Index changes require developer review and are not applied by explainAiAdvice().',
        ]];
        $before = $this->measure($query);
        $context = $query->explainAiContext(options: $options);
        $analysis = null;
        if ($withAi) {
            if ($this->progress !== null) {
                ($this->progress)($title);
            }
            $advice = $query->explainAiAdvice(options: $options);
            if ($advice->status !== 'ok') {
                throw new RuntimeException('AI request failed; check the private provider configuration and token budget. No raw provider response was logged.');
            }
            $analysis = ['provider' => $advice->provider, 'model' => $advice->model, 'text' => $advice->aiAnalysis];
            $context = $advice->context;
        }
        return ['id' => $id, 'title' => $title, 'sql' => $query->toSQL()['sql'], 'before' => $before,
            'context_tables' => array_keys($context['schema']['tables']),
            'context_indexes' => array_map(static fn ($table) => array_column($table['indexes'], 'name'), $context['schema']['tables']),
            'context_complete' => $context['collection']['complete'], 'ai' => $analysis];
    }

    /** Measurements execute the SELECT deliberately; explainAiAdvice() itself does not.
     * @return array<string, mixed>
     */
    private function measure(QueryBuilder $query): array
    {
        $compiled = $query->toSQL();
        $pdo = $this->db->connection->getPdo();
        $explain = $pdo->prepare('EXPLAIN QUERY PLAN ' . $compiled['sql']);
        $explain->execute($compiled['params']);
        $plan = array_column($explain->fetchAll(PDO::FETCH_ASSOC), 'detail');
        $explain->closeCursor();
        $statement = $pdo->prepare($compiled['sql']);
        $times = [];
        $rows = [];
        for ($run = 0; $run < self::WARMUPS + self::RUNS; ++$run) {
            $start = hrtime(true);
            $statement->execute($compiled['params']);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            if ($run >= self::WARMUPS) {
                $times[] = (hrtime(true) - $start) / 1000000;
            }
        }
        sort($times);
        return ['plan' => $plan, 'median_ms' => round($times[intdiv(count($times), 2)], 4),
            'rows_returned' => count($rows), 'result_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, mixed> $case */
    private function verifySameRows(array &$case): void
    {
        $case['same_rows'] = $case['before']['result_sha256'] === $case['after']['result_sha256'];
        if (!$case['same_rows']) {
            throw new RuntimeException('Verification failed: the candidate change altered the result rows');
        }
    }
}
