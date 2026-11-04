<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\connection\Connection;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

final class InsertIdHookTests extends TestCase
{
    public function testCustomOverridesKeepZeroKeysAndTheConnectionFallback(): void
    {
        foreach ([0 => 0, 123 => 123] as $legacy => $expected) {
            $dialect = new class ($legacy) extends MySQLDialect {
                public function __construct(protected int $key)
                {
                    parent::__construct();
                }

                public function extractInsertId(PDOStatement $stmt, string $sql, array $params): int|string|null
                {
                    return $this->key;
                }
            };
            $this->assertSame($expected, $this->insertWith($dialect));
        }
        $fallback = new class () extends MySQLDialect {
            public function extractInsertId(PDOStatement $stmt, string $sql, array $params): int|string|null
            {
                return null;
            }
        };
        $this->assertSame(7, $this->insertWith($fallback));
    }

    public function testAnExplicitAbsenceDoesNotFallBackToAnEarlierConnectionId(): void
    {
        $dialect = new class () extends MySQLDialect {
            public function extractInsertId(PDOStatement $stmt, string $sql, array $params): int|string|false|null
            {
                return false;
            }
        };
        $this->assertNull($this->insertWith($dialect));
    }

    protected function insertWith(MySQLDialect $dialect): int|string|null
    {
        // The custom override must bypass MySQL's metadata SQL, which this SQLite test connection cannot execute.
        $pdo = PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE ids (id INTEGER PRIMARY KEY)');
        $connection = new Connection($pdo, $dialect);
        $parameters = new ParameterManager();
        $engine = new ExecutionEngine($connection, new RawValueResolver($connection, $parameters), $parameters);

        return $engine->executeInsert('INSERT INTO ids (id) VALUES (7)', []);
    }
}
