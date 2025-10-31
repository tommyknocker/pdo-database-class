<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;

/**
 * IntrospectionTests tests for shared.
 */
final class IntrospectionTests extends BaseSharedTestCase
{
    public function testDescribeTable(): void
    {
        $structure = self::$db->describe('test_coverage');
        $this->assertIsArray($structure);
        $this->assertNotEmpty($structure);

        // Should have columns
        $this->assertGreaterThan(0, count($structure));
    }

    public function testExplainAnalyze(): void
    {
        $result = self::$db->explainAnalyze('SELECT * FROM test_coverage WHERE id = 1');
        $this->assertIsArray($result);
    }

    public function testExplainDescribeAndIndexes(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INT)');
        $db->rawQuery('CREATE INDEX idx_t_name ON t(name)');

        $report = $db->find()->from('t')->where('name', 'Alice')->explain();
        $this->assertIsArray($report);

        $reportAnalyze = $db->find()->from('t')->where('name', 'Alice')->explainAnalyze();
        $this->assertIsArray($reportAnalyze);

        $desc = $db->find()->from('t')->describe();
        $this->assertIsArray($desc);

        $idx = $db->find()->from('t')->indexes();
        $this->assertIsArray($idx);

        $keys = $db->find()->from('t')->keys();
        $this->assertIsArray($keys);

        $constraints = $db->find()->from('t')->constraints();
        $this->assertIsArray($constraints);
    }
}
