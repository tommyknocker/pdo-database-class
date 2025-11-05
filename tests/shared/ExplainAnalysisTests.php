<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\analysis\Issue;
use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;
use tommyknocker\pdodb\query\analysis\Recommendation;

/**
 * Tests for ExplainAnalysis class.
 */
final class ExplainAnalysisTests extends BaseSharedTestCase
{
    public function testExplainAnalysisHasRecommendations(): void
    {
        $plan = new ParsedExplainPlan();
        $recommendations = [
            new Recommendation('info', 'missing_index', 'Create index on column email'),
        ];

        $analysis = new ExplainAnalysis([], $plan, [], $recommendations);
        $this->assertTrue($analysis->hasRecommendations());
    }

    public function testExplainAnalysisHasNoRecommendations(): void
    {
        $plan = new ParsedExplainPlan();
        $analysis = new ExplainAnalysis([], $plan, [], []);
        $this->assertFalse($analysis->hasRecommendations());
    }

    public function testExplainAnalysisHasCriticalIssues(): void
    {
        $plan = new ParsedExplainPlan();
        $issues = [
            new Issue('critical', 'full_table_scan', 'Full table scan detected', 'users'),
        ];

        $analysis = new ExplainAnalysis([], $plan, $issues, []);
        $this->assertTrue($analysis->hasCriticalIssues());
    }

    public function testExplainAnalysisHasNoCriticalIssues(): void
    {
        $plan = new ParsedExplainPlan();
        $issues = [
            new Issue('warning', 'full_table_scan', 'Full table scan detected', 'users'),
        ];

        $analysis = new ExplainAnalysis([], $plan, $issues, []);
        $this->assertFalse($analysis->hasCriticalIssues());
    }

    public function testExplainAnalysisWithEmptyIssues(): void
    {
        $plan = new ParsedExplainPlan();
        $analysis = new ExplainAnalysis([], $plan, [], []);
        $this->assertFalse($analysis->hasCriticalIssues());
    }
}
