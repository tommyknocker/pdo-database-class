<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

/**
 * Complete EXPLAIN analysis result with recommendations.
 */
class ExplainAnalysis
{
    /**
     * @param array<int, array<string, mixed>> $rawExplain Original EXPLAIN output
     * @param ParsedExplainPlan $plan Parsed and structured plan
     * @param array<Issue> $issues Detected issues
     * @param array<Recommendation> $recommendations Optimization recommendations
     */
    public function __construct(
        public array $rawExplain,
        public ParsedExplainPlan $plan,
        public array $issues = [],
        public array $recommendations = []
    ) {
    }

    /**
     * Check if analysis has critical issues.
     */
    public function hasCriticalIssues(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'critical') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if analysis has any recommendations.
     */
    public function hasRecommendations(): bool
    {
        return !empty($this->recommendations);
    }
}
