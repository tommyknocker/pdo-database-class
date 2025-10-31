<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * Shared tests for EXPLAIN analysis with recommendations.
 */
final class ExplainAnalysisTests extends \tommyknocker\pdodb\tests\shared\BaseSharedTestCase
{
    public function testExplainAdviceBasic(): void
    {
        $analysis = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->explainAdvice();

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertNotEmpty($analysis->rawExplain);
        $this->assertNotNull($analysis->plan);
    }

    public function testExplainAdviceReturnsIssues(): void
    {
        // Query without index should potentially show warnings
        $analysis = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'test', 'LIKE')
            ->explainAdvice();

        $this->assertIsArray($analysis->issues);
        $this->assertIsArray($analysis->recommendations);
    }

    public function testExplainAdviceWithTableName(): void
    {
        $analysis = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->explainAdvice('test_coverage');

        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
    }

    public function testExplainAnalysisHasCriticalIssues(): void
    {
        $analysis = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'test', 'LIKE')
            ->explainAdvice();

        // Method should exist and return boolean
        $this->assertIsBool($analysis->hasCriticalIssues());
        $this->assertIsBool($analysis->hasRecommendations());
    }
}
