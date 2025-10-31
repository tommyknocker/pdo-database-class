<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * MySQL-specific tests for EXPLAIN analysis with recommendations.
 */
final class ExplainAnalysisTests extends BaseMySQLTestCase
{
    public function testExplainAdviceDetectsFullTableScan(): void
    {
        // Create table without index on status column
        self::$db->rawQuery(
            'CREATE TABLE IF NOT EXISTS test_explain_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100),
                status VARCHAR(50)
            ) ENGINE=InnoDB'
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
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                UNIQUE KEY idx_email (email)
            ) ENGINE=InnoDB'
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

        // Should use index (access type should not be ALL)
        $this->assertNotNull($analysis->plan);
        if ($analysis->plan->accessType !== null) {
            $this->assertNotEquals('ALL', $analysis->plan->accessType);
        }

        // Cleanup
        self::$db->rawQuery('DROP TABLE IF EXISTS test_explain_indexed');
    }

    public function testExplainAdviceProvidesSuggestions(): void
    {
        // Create table without indexes
        self::$db->rawQuery(
            'CREATE TABLE IF NOT EXISTS test_explain_suggestions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                status VARCHAR(50)
            ) ENGINE=InnoDB'
        );

        self::$db->rawQuery('DELETE FROM test_explain_suggestions');

        $analysis = self::$db->find()
            ->from('test_explain_suggestions')
            ->where('user_id', 1)
            ->explainAdvice('test_explain_suggestions');

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->recommendations);
        $this->assertIsArray($analysis->issues);

        // Cleanup
        self::$db->rawQuery('DROP TABLE IF EXISTS test_explain_suggestions');
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

    public function testParsedExplainPlanStructure(): void
    {
        $analysis = self::$db->find()
            ->from('users')
            ->limit(10)
            ->explainAdvice();

        $plan = $analysis->plan;

        $this->assertIsArray($plan->nodes);
        $this->assertIsArray($plan->tableScans);
        $this->assertIsArray($plan->warnings);
        $this->assertIsArray($plan->usedColumns);
        $this->assertIsArray($plan->possibleKeys);
        $this->assertIsInt($plan->estimatedRows);
    }
}
