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
     * @param string $status ok, ai_unavailable or invalid_response
     * @param array{summary: string, findings: list<array<string, mixed>>}|null $structuredAdvice Validated optional model output
     * @param list<string> $warnings Plan availability or response warnings
     * @param array<string, mixed> $context Exact bounded context sent to the model
     * @param string $planSource database or unavailable
     */
    public function __construct(
        public ExplainAnalysis $baseAnalysis,
        public string $aiAnalysis,
        public string $provider,
        public ?string $model = null,
        public string $status = 'ok',
        public ?array $structuredAdvice = null,
        public array $warnings = [],
        public array $context = [],
        public string $planSource = 'database'
    ) {
    }

    /**
     * Check if analysis has critical issues.
     */
    public function hasCriticalIssues(): bool
    {
        foreach ($this->structuredAdvice['findings'] ?? [] as $finding) {
            if ($finding['severity'] === 'critical') {
                return true;
            }
        }
        return $this->baseAnalysis->hasCriticalIssues();
    }

    /**
     * Check if analysis has any recommendations.
     */
    public function hasRecommendations(): bool
    {
        return $this->baseAnalysis->hasRecommendations() || !empty($this->structuredAdvice['findings']);
    }

    /**
     * Get all recommendations (base + AI).
     *
     * @return array<Recommendation> All recommendations
     */
    public function getAllRecommendations(): array
    {
        return array_merge($this->baseAnalysis->recommendations, $this->getAiRecommendations());
    }

    /** Validated model recommendations remain advisory, never executable actions.
     * @return list<Recommendation>
     */
    public function getAiRecommendations(): array
    {
        return array_map(static fn ($finding) => new Recommendation(
            $finding['severity'],
            $finding['type'],
            $finding['message'],
            $finding['suggestion'] ?? null
        ), $this->structuredAdvice['findings'] ?? []);
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
