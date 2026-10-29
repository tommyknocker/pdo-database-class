<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\PdoDb;

/** Opt-in real catalog/EXPLAIN checks. Creates and removes only random fixture tables. */
final class AiQueryAdviceDatabaseTests extends TestCase
{
    public static function databases(): array
    {
        return array_map(static fn ($name) => [$name], ['sqlite', 'mysql', 'mariadb', 'postgresql', 'mssql', 'oracle']);
    }

    private function database(string $name): PdoDb
    {
        if ($name === 'sqlite') {
            return new PdoDb('sqlite', ['path' => ':memory:']);
        }
        $classes = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL', 'mssql' => 'MSSQL', 'oracle' => 'Oracle'];
        $base = new ReflectionClass('tommyknocker\\pdodb\\tests\\' . $name . '\\Base' . $classes[$name] . 'TestCase');
        $config = ['enable_regexp' => false];
        foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_USER' => 'username', 'DB_PASSWORD' => 'password', 'DB_NAME' => 'dbname', 'DB_CHARSET' => 'charset', 'DB_SERVICE_NAME' => 'service_name'] as $constant => $key) {
            if ($base->hasConstant($constant)) {
                $config[$key] = $base->getConstant($constant);
            }
        }
        $config += ['encrypt' => true, 'trust_server_certificate' => true];
        return new PdoDb(['postgresql' => 'pgsql', 'mssql' => 'sqlsrv', 'oracle' => 'oci'][$name] ?? $name, $config);
    }

    #[DataProvider('databases')]
    public function testJoinedCatalogsIndexesStatisticsAndHonestPlan(string $name): void
    {
        $db = $this->database($name);
        $pdo = $db->connection->getPdo();
        $dialect = $db->connection->getDialect();
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $orders = 'AI_O_' . $suffix;
        $users = 'AI_U_' . $suffix;
        $index = 'AI_I_' . $suffix;
        $o = $dialect->quoteIdentifier($orders);
        $u = $dialect->quoteIdentifier($users);

        try {
            $pdo->exec('CREATE TABLE ' . $u . ' (id INTEGER PRIMARY KEY, email VARCHAR(80))');
            $pdo->exec('CREATE TABLE ' . $o . ' (id INTEGER PRIMARY KEY, user_id INTEGER, status VARCHAR(30), total INTEGER, FOREIGN KEY (user_id) REFERENCES ' . $u . '(id))');
            $pdo->exec('CREATE INDEX ' . $dialect->quoteIdentifier($index) . ' ON ' . $o . '(status, total DESC)');
            $query = $db->find()->from($orders . ' o')->join($users . ' u', 'o.user_id = u.id')->where('o.status', 'private-status');
            $context = $query->explainAiContext(options: ['context' => ['include_statistics' => true, 'max_collection_ms' => 10000]]);
            self::assertCount(2, $context['schema']['tables'], json_encode($context['collection']));
            self::assertTrue($context['collection']['complete'], json_encode($context['schema']));
            $tables = array_values($context['schema']['tables']);
            self::assertCount(4, $tables[0]['columns']);
            self::assertSame($users, $tables[0]['foreign_keys'][0]['referenced_table']);
            $indexes = array_values(array_filter($tables[0]['indexes'], static fn ($i) => $i['name'] === $index));
            self::assertCount(1, $indexes);
            self::assertCount(2, $indexes[0]['columns']);
            self::assertSame([1, 2], array_column($indexes[0]['columns'], 'position'));
            self::assertSame($name === 'oracle' ? ['STATUS', 'TOTAL'] : ['status', 'total'], array_column($indexes[0]['columns'], 'name'));
            if ($name !== 'mariadb') {
                // MariaDB versions before descending indexes may ignore DESC in DDL.
                self::assertTrue($indexes[0]['columns'][1]['descending']);
            }
            self::assertFalse($tables[0]['statistics']['exact']);
            self::assertStringNotContainsString('private-status', json_encode($context));
            self::assertSame(in_array($name, ['mssql', 'oracle'], true) ? 'unavailable' : 'database', $context['explain_plan']['source']);
            self::assertNotEmpty($context['database']['version']);
            // Explicit schema resolution is checked independently of builder quoting conventions.
            $qualified = $dialect->quoteIdentifier($tables[0]['schema']) . '.' . $o;
            $explicit = (new AiAnalysisService($db))->previewQueryContext('SELECT * FROM ' . $qualified, options: ['context' => ['max_collection_ms' => 10000]]);
            self::assertCount(4, array_values($explicit['schema']['tables'])[0]['columns']);
        } finally {
            foreach ([$o, $u] as $table) {
                try {
                    $pdo->exec('DROP TABLE ' . $table);
                } catch (PDOException) {
                }
            }
        }
    }
}
