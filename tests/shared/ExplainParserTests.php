<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\parsers\MySQLExplainParser;
use tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser;
use tommyknocker\pdodb\query\analysis\parsers\SqliteExplainParser;

/**
 * Tests for ExplainParser classes.
 */
final class ExplainParserTests extends BaseSharedTestCase
{
    public function testMySQLExplainParserFullTableScan(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ALL',
                'key' => null,
                'possible_keys' => null,
                'rows' => 1000,
                'Extra' => '',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertContains('users', $plan->tableScans);
        $this->assertEquals('ALL', $plan->accessType);
        $this->assertEquals(1000, $plan->estimatedRows);
    }

    public function testMySQLExplainParserIndexScan(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email,idx_username',
                'rows' => 10,
                'Extra' => '',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals('idx_email', $plan->usedIndex);
        $this->assertEquals('ref', $plan->accessType);
        $this->assertEquals(10, $plan->estimatedRows);
        $this->assertContains('idx_email', $plan->possibleKeys);
        $this->assertContains('idx_username', $plan->possibleKeys);
    }

    public function testMySQLExplainParserMissingIndex(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ALL',
                'key' => null,
                'possible_keys' => 'idx_email,idx_username',
                'rows' => 1000,
                'Extra' => '',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('possible keys', $plan->warnings[0]);
    }

    public function testMySQLExplainParserUsingFilesort(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 10,
                'Extra' => 'Using filesort',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('filesort', $plan->warnings[0]);
    }

    public function testMySQLExplainParserUsingTemporary(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 10,
                'Extra' => 'Using temporary',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('temporary table', $plan->warnings[0]);
    }

    public function testMySQLExplainParserWithRef(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 10,
                'Extra' => '',
                'ref' => 'email,status',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertContains('email', $plan->usedColumns);
        $this->assertContains('status', $plan->usedColumns);
    }

    public function testMySQLExplainParserMultipleRows(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 10,
                'Extra' => '',
            ],
            [
                'table' => 'orders',
                'type' => 'ref',
                'key' => 'idx_user_id',
                'possible_keys' => 'idx_user_id',
                'rows' => 5,
                'Extra' => '',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals(10, $plan->estimatedRows);
    }

    public function testMySQLExplainParserWithFiltered(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 1000,
                'filtered' => 5.5,
                'Extra' => '',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals(5.5, $plan->filtered);
    }

    public function testMySQLExplainParserWithDependentSubquery(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 10,
                'Extra' => 'Using where; dependent subquery',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('Dependent subquery', $plan->warnings[0]);
    }

    public function testMySQLExplainParserWithGroupByWithoutIndex(): void
    {
        $parser = new MySQLExplainParser();
        $explainResults = [
            [
                'table' => 'users',
                'type' => 'ref',
                'key' => 'idx_email',
                'possible_keys' => 'idx_email',
                'rows' => 10,
                'Extra' => 'Using temporary; Using filesort',
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $hasGroupByWarning = false;
        foreach ($plan->warnings as $warning) {
            if (str_contains($warning, 'GROUP BY requires temporary table and filesort')) {
                $hasGroupByWarning = true;
                break;
            }
        }
        $this->assertTrue($hasGroupByWarning);
    }

    public function testPostgreSQLExplainParserWithCost(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Seq Scan on users  (cost=0.00..150000.00 rows=1000 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals(150000.0, $plan->totalCost);
    }

    public function testPostgreSQLExplainParserWithExecutionTime(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Seq Scan on users  (cost=0.00..100.00 rows=1000 width=4) (actual time=0.123..45.678 rows=1000 loops=1)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals(45.678, $plan->executionTime);
    }

    public function testPostgreSQLExplainParserWithJoinTypes(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Nested Loop Join  (cost=0.00..100.00 rows=1000 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertContains('Nested Loop', $plan->joinTypes);
    }

    public function testPostgreSQLExplainParserWithDependentSubquery(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Seq Scan on users  (cost=0.00..100.00 rows=1000 width=4)'],
            ['QUERY PLAN' => 'SubPlan 1'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $hasSubqueryWarning = false;
        foreach ($plan->warnings as $warning) {
            if (str_contains($warning, 'Dependent subquery')) {
                $hasSubqueryWarning = true;
                break;
            }
        }
        $this->assertTrue($hasSubqueryWarning);
    }

    public function testMySQLExplainParserEmptyResults(): void
    {
        $parser = new MySQLExplainParser();
        $plan = $parser->parse([]);
        $this->assertEmpty($plan->tableScans);
        $this->assertNull($plan->accessType);
        $this->assertEquals(0, $plan->estimatedRows);
    }

    public function testPostgreSQLExplainParserSequentialScan(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Seq Scan on users  (cost=0.00..100.00 rows=1000 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertContains('users', $plan->tableScans);
        $this->assertEquals('Seq Scan', $plan->accessType);
        $this->assertEquals(1000, $plan->estimatedRows);
    }

    public function testPostgreSQLExplainParserIndexScan(): void
    {
        $parser = new PostgreSQLExplainParser();
        // Parser expects format: "Index Scan on users using idx_email"
        $explainResults = [
            ['QUERY PLAN' => 'Index Scan on users using idx_email  (cost=0.00..10.00 rows=10 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals('idx_email', $plan->usedIndex);
        $this->assertEquals('Index Scan', $plan->accessType);
        $this->assertEquals(10, $plan->estimatedRows);
    }

    public function testPostgreSQLExplainParserIndexOnlyScan(): void
    {
        $parser = new PostgreSQLExplainParser();
        // Parser expects format: "Index Only Scan on users using idx_email"
        $explainResults = [
            ['QUERY PLAN' => 'Index Only Scan on users using idx_email  (cost=0.00..10.00 rows=10 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals('idx_email', $plan->usedIndex);
        $this->assertEquals('Index Only Scan', $plan->accessType);
    }

    public function testPostgreSQLExplainParserBitmapIndexScan(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Bitmap Index Scan on idx_email  (cost=0.00..10.00 rows=10 width=0)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals('idx_email', $plan->usedIndex);
        $this->assertEquals('Bitmap Index Scan', $plan->accessType);
    }

    public function testPostgreSQLExplainParserWarning(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Seq Scan on users  (cost=0.00..100.00 rows=1000 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('Sequential scan', $plan->warnings[0]);
    }

    public function testPostgreSQLExplainParserPossibleKeys(): void
    {
        $parser = new PostgreSQLExplainParser();
        $explainResults = [
            ['QUERY PLAN' => 'Index Scan using idx_email on users  (cost=0.00..10.00 rows=10 width=4)'],
            ['QUERY PLAN' => 'Index Scan using idx_username on users  (cost=0.00..10.00 rows=10 width=4)'],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertContains('idx_email', $plan->possibleKeys);
        $this->assertContains('idx_username', $plan->possibleKeys);
    }

    public function testPostgreSQLExplainParserEmptyResults(): void
    {
        $parser = new PostgreSQLExplainParser();
        $plan = $parser->parse([]);
        $this->assertEmpty($plan->tableScans);
        $this->assertNull($plan->accessType);
    }

    public function testSqliteExplainParserScanTable(): void
    {
        $parser = new SqliteExplainParser();
        $explainResults = [
            [
                'opcode' => 'ScanTable',
                'p4' => 'users',
                'p2' => 1000,
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertContains('users', $plan->tableScans);
        $this->assertEquals('Table Scan', $plan->accessType);
        $this->assertEquals(1000, $plan->estimatedRows);
    }

    public function testSqliteExplainParserIndexScan(): void
    {
        $parser = new SqliteExplainParser();
        $explainResults = [
            [
                'opcode' => 'OpenRead',
                'p4' => 'users',
                'p5' => 1,
                'comment' => 'INDEX users:idx_email',
            ],
        ];

        $plan = $parser->parse($explainResults);
        // SQLite parser extracts index from comment, which may include table name
        $this->assertNotNull($plan->usedIndex);
        $this->assertStringContainsString('idx_email', $plan->usedIndex);
        $this->assertEquals('Index Scan', $plan->accessType);
    }

    public function testSqliteExplainParserIdxScan(): void
    {
        $parser = new SqliteExplainParser();
        $explainResults = [
            [
                'opcode' => 'IdxScan',
                'p4' => 'idx_email',
                'p2' => 10,
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertEquals('idx_email', $plan->usedIndex);
        $this->assertEquals('Index Scan', $plan->accessType);
        $this->assertEquals(10, $plan->estimatedRows);
    }

    public function testSqliteExplainParserWarning(): void
    {
        $parser = new SqliteExplainParser();
        $explainResults = [
            [
                'opcode' => 'ScanTable',
                'p4' => 'users',
                'p2' => 1000,
            ],
        ];

        $plan = $parser->parse($explainResults);
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('Full table scan', $plan->warnings[0]);
    }

    public function testSqliteExplainParserEmptyResults(): void
    {
        $parser = new SqliteExplainParser();
        $plan = $parser->parse([]);
        $this->assertEmpty($plan->tableScans);
        $this->assertNull($plan->accessType);
    }
}
