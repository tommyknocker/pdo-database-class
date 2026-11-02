<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * Query analysis on Oracle: EXPLAIN PLAN, execution statistics and column description.
 */
final class AnalysisTests extends BaseOracleTestCase
{
    private function seed(): void
    {
        self::$db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        self::$db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);
    }

    public function testExplainReturnsAPlanForTheQuery(): void
    {
        $this->seed();

        $plan = self::$db->find()->table('users')->where('age', 25, '>')->explain();

        $this->assertIsArray($plan);
        $this->assertNotEmpty($plan);
        $text = strtoupper(json_encode($plan, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('USERS', $text, 'the plan names the table it reads');
    }

    public function testExplainAnalyzeRunsTheQueryAndReturnsAPlan(): void
    {
        $this->seed();

        $plan = self::$db->find()->table('users')->where('age', 25, '>')->explainAnalyze();

        $this->assertIsArray($plan);
        $this->assertNotEmpty($plan);
        $this->assertStringContainsString('USERS', strtoupper(json_encode($plan, JSON_THROW_ON_ERROR)));
    }

    public function testExplainOfRawSqlWithBoundValues(): void
    {
        $this->seed();

        $plan = self::$db->explain('SELECT * FROM "USERS" WHERE "AGE" > ?', [20]);

        $this->assertIsArray($plan);
        $this->assertNotEmpty($plan);
    }

    public function testDescribeListsTheColumnsOfTheTable(): void
    {
        $description = self::$db->find()->table('users')->describe();

        $this->assertIsArray($description);
        $this->assertNotEmpty($description);
        $names = array_map('strtoupper', array_map('strval', array_column($description, array_key_first($description[0]))));
        foreach (['ID', 'NAME', 'AGE'] as $column) {
            $this->assertContains($column, $names);
        }
    }
}
