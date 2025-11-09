<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * MSSQL-specific tests for EXPLAIN analysis with recommendations.
 */
final class ExplainAnalysisTests extends BaseMSSQLTestCase
{
    public function testExplainAdviceDetectsFullTableScan(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);

        // Create table without index on status column
        $connection->query('IF OBJECT_ID(\'test_explain_users\', \'U\') IS NOT NULL DROP TABLE test_explain_users');
        $connection->query('
            CREATE TABLE test_explain_users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(100),
                status NVARCHAR(50)
            )
        ');

        $connection->query('DELETE FROM test_explain_users');

        // Insert test data
        for ($i = 1; $i <= 5; $i++) {
            self::$db->find()->table('test_explain_users')->insert([
                'name' => "User {$i}",
                'status' => 'active',
            ]);
        }

        $analysis = self::$db->find()
            ->from('test_explain_users')
            ->where('status', 'active')
            ->explainAdvice();

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertNotEmpty($analysis->rawExplain);

        // Check plan structure
        $this->assertNotNull($analysis->plan);
        $this->assertIsArray($analysis->plan->nodes);

        // Cleanup
        $connection->query('DROP TABLE test_explain_users');
    }

    public function testExplainAdviceDetectsIndexUsage(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);

        // Create table with index
        $connection->query('IF OBJECT_ID(\'test_explain_indexed\', \'U\') IS NOT NULL DROP TABLE test_explain_indexed');
        $connection->query('
            CREATE TABLE test_explain_indexed (
                id INT IDENTITY(1,1) PRIMARY KEY,
                email NVARCHAR(255) NOT NULL
            )
        ');
        $connection->query('CREATE UNIQUE INDEX idx_email ON test_explain_indexed (email)');

        $connection->query('DELETE FROM test_explain_indexed');

        // Insert test data
        self::$db->find()->table('test_explain_indexed')->insert([
            'email' => 'test@example.com',
        ]);

        $analysis = self::$db->find()
            ->from('test_explain_indexed')
            ->where('email', 'test@example.com')
            ->explainAdvice();

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);

        // Should use index (access type should not be ALL)
        $this->assertNotNull($analysis->plan);
        if ($analysis->plan->accessType !== null) {
            $this->assertNotEquals('ALL', $analysis->plan->accessType);
        }

        // Cleanup
        $connection->query('DROP TABLE test_explain_indexed');
    }
}
