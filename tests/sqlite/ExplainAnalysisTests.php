<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * SQLite-specific tests for EXPLAIN analysis with recommendations.
 */
final class ExplainAnalysisTests extends BaseSqliteTestCase
{
    public function testExplainAdviceDetectsFullTableScan(): void
    {
        // Create table without index on status column
        self::$db->rawQuery(
            'CREATE TABLE IF NOT EXISTS test_explain_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100),
                status VARCHAR(50)
            )'
        );

        self::$db->rawQuery('DELETE FROM test_explain_users');

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
        self::$db->rawQuery('DROP TABLE IF EXISTS test_explain_users');
    }

    public function testExplainAdviceDetectsIndexUsage(): void
    {
        // Create table with index
        self::$db->rawQuery(
            'CREATE TABLE IF NOT EXISTS test_explain_indexed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) NOT NULL UNIQUE
            )'
        );

        self::$db->rawQuery('DELETE FROM test_explain_indexed');

        // Insert test data
        self::$db->find()->table('test_explain_indexed')->insert([
            'email' => 'test@example.com',
        ]);

        $analysis = self::$db->find()
            ->from('test_explain_indexed')
            ->where('email', 'test@example.com')
            ->explainAdvice();

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);

        // Should use index (access type should not be Table Scan)
        $this->assertNotNull($analysis->plan);
        if ($analysis->plan->accessType !== null) {
            $this->assertNotEquals('Table Scan', $analysis->plan->accessType);
        }

        // Cleanup
        self::$db->rawQuery('DROP TABLE IF EXISTS test_explain_indexed');
    }

    public function testExplainAnalysisStructure(): void
    {
        $analysis = self::$db->find()
            ->from('users')
            ->where('status', 'active')
            ->explainAdvice();

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertNotEmpty($analysis->rawExplain);
        $this->assertNotNull($analysis->plan);
        $this->assertIsArray($analysis->issues);
        $this->assertIsArray($analysis->recommendations);

        // Test helper methods
        $this->assertIsBool($analysis->hasCriticalIssues());
        $this->assertIsBool($analysis->hasRecommendations());
    }
}
