<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use InvalidArgumentException;

/** Validated, bounded context collection settings; independent of provider options. */
final class AiQueryOptions
{
    /** @var array<string, mixed> */
    public readonly array $context;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $context = $options['context'] ?? [];
        if (!is_array($context) || array_diff(array_keys($context), ['tables', 'max_tables', 'max_columns', 'max_indexes', 'max_plan_rows', 'max_bytes', 'max_collection_ms', 'max_catalog_queries', 'privacy', 'include_raw_plan', 'include_statistics', 'goal', 'constraints', 'language']) !== []) {
            throw new InvalidArgumentException('Invalid AI context settings');
        }
        $defaults = ['tables' => null, 'max_tables' => 8, 'max_columns' => 100, 'max_indexes' => 50, 'max_plan_rows' => 100,
            'max_bytes' => 32768, 'max_collection_ms' => 1000, 'max_catalog_queries' => 64, 'privacy' => 'redacted', 'include_raw_plan' => false,
            'include_statistics' => false, 'goal' => '', 'constraints' => [], 'language' => ''];
        $context += $defaults;
        foreach (['max_tables' => [0, 50], 'max_columns' => [1, 1000], 'max_indexes' => [1, 200], 'max_plan_rows' => [1, 500], 'max_bytes' => [2048, 262144], 'max_collection_ms' => [1, 30000], 'max_catalog_queries' => [1, 512]] as $key => [$min, $max]) {
            if (!is_int($context[$key]) || $context[$key] < $min || $context[$key] > $max) {
                throw new InvalidArgumentException("Invalid AI context {$key}");
            }
        }
        if (!in_array($context['privacy'], ['redacted', 'raw'], true)) {
            throw new InvalidArgumentException('AI context privacy must be redacted or raw');
        }
        foreach (['include_raw_plan', 'include_statistics'] as $key) {
            if (!is_bool($context[$key])) {
                throw new InvalidArgumentException("AI context {$key} must be boolean");
            }
        }
        if ($context['include_raw_plan'] && $context['privacy'] !== 'raw') {
            throw new InvalidArgumentException('Raw plan text requires context privacy=raw; plan text may contain literal values');
        }
        foreach (['goal', 'language'] as $key) {
            if (!is_string($context[$key]) || strlen($context[$key]) > ($key === 'goal' ? 2000 : 100)) {
                throw new InvalidArgumentException("Invalid AI context {$key}");
            }
        }
        foreach (['tables', 'constraints'] as $key) {
            $list = $context[$key];
            if ($key === 'tables' && $list === null) {
                continue;
            }
            if (!is_array($list) || !array_is_list($list) || count($list) > 50) {
                throw new InvalidArgumentException("AI context {$key} must be a list of at most 50 strings");
            }
            foreach ($list as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 1000) {
                    throw new InvalidArgumentException("Invalid AI context {$key} entry");
                }
            }
        }
        $this->context = $context;
    }
}
