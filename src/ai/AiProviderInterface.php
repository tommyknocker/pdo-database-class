<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/**
 * Interface for AI providers.
 */
interface AiProviderInterface
{
    /**
     * Analyze SQL query and provide optimization suggestions.
     *
     * @param string $sql SQL query to analyze
     * @param array<string, mixed> $context Additional context (schema, explain plan, etc.)
     *
     * @return string AI-generated analysis and recommendations
     */
    public function analyzeQuery(string $sql, array $context = []): string;

    /**
     * Analyze database schema and provide optimization suggestions.
     *
     * @param array<string, mixed> $schema Schema information
     * @param array<string, mixed> $context Additional context
     *
     * @return string AI-generated analysis and recommendations
     */
    public function analyzeSchema(array $schema, array $context = []): string;

    /**
     * Get optimization suggestions based on existing analysis.
     *
     * @param array<string, mixed> $analysis Existing analysis results
     * @param array<string, mixed> $context Additional context
     *
     * @return string AI-generated optimization suggestions
     */
    public function suggestOptimizations(array $analysis, array $context = []): string;

    /**
     * Get provider name/identifier.
     *
     * @return string Provider name
     */
    public function getProviderName(): string;

    /**
     * Check if provider is available/configured.
     *
     * @return bool True if provider can be used
     */
    public function isAvailable(): bool;

    /**
     * Get model name.
     *
     * @return string Model name
     */
    public function getModel(): string;

    /**
     * Set model name.
     *
     * @param string $model Model name
     */
    public function setModel(string $model): void;

    /**
     * Get temperature.
     *
     * @return float Temperature value
     */
    public function getTemperature(): float;

    /**
     * Set temperature.
     *
     * @param float $temperature Temperature value (0.0-2.0)
     */
    public function setTemperature(float $temperature): void;

    /**
     * Get max tokens.
     *
     * @return int Maximum tokens
     */
    public function getMaxTokens(): int;

    /**
     * Set max tokens.
     *
     * @param int $maxTokens Maximum tokens
     */
    public function setMaxTokens(int $maxTokens): void;

    /**
     * Get timeout.
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int;

    /**
     * Set timeout.
     *
     * @param int $timeout Timeout in seconds
     */
    public function setTimeout(int $timeout): void;
}
