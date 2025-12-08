<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

/**
 * AI-enhanced EXPLAIN analysis result.
 */
class AiExplainAnalysis
{
    /**
     * @param ExplainAnalysis $baseAnalysis Base explain analysis
     * @param string $aiAnalysis AI-generated analysis text
     * @param string $provider AI provider name
     * @param string|null $model AI model used
     */
    public function __construct(
        public ExplainAnalysis $baseAnalysis,
        public string $aiAnalysis,
        public string $provider,
        public ?string $model = null
    ) {
    }

    /**
     * Check if analysis has critical issues.
     */
    public function hasCriticalIssues(): bool
    {
        return $this->baseAnalysis->hasCriticalIssues();
    }

    /**
     * Check if analysis has any recommendations.
     */
    public function hasRecommendations(): bool
    {
        return $this->baseAnalysis->hasRecommendations();
    }

    /**
     * Get all recommendations (base + AI).
     *
     * @return array<Recommendation> All recommendations
     */
    public function getAllRecommendations(): array
    {
        return $this->baseAnalysis->recommendations;
    }

    /**
     * Get AI analysis text.
     */
    public function getAiAnalysis(): string
    {
        return $this->aiAnalysis;
    }

    /**
     * Get provider name.
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Get model name.
     */
    public function getModel(): ?string
    {
        return $this->model;
    }
}
