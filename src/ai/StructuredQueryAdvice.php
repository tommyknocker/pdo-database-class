<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/** Validates optional model output without requiring provider-specific JSON modes. */
final class StructuredQueryAdvice
{
    /** @param array<string, mixed> $context
     * @return array{summary: string, findings: list<array<string, mixed>>}|null
     */
    public static function parse(string $text, array $context): ?array
    {
        if (strlen($text) > 262144) {
            return null;
        }
        $data = json_decode($text, true, 32);
        if (!is_array($data) || !is_string($data['summary'] ?? null) || strlen($data['summary']) > 16000 || !is_array($data['findings'] ?? null) || !array_is_list($data['findings']) || count($data['findings']) > 50) {
            return null;
        }
        foreach ($data['findings'] as $finding) {
            if (!is_array($finding) || !in_array($finding['severity'] ?? null, ['info', 'warning', 'critical'], true)) {
                return null;
            }
            foreach (['type', 'message'] as $key) {
                if (!is_string($finding[$key] ?? null) || $finding[$key] === '' || strlen($finding[$key]) > 16000) {
                    return null;
                }
            }
            if (isset($finding['suggestion']) && (!is_string($finding['suggestion']) || strlen($finding['suggestion']) > 16000)) {
                return null;
            }
            foreach (['evidence', 'limitations'] as $key) {
                if (!is_array($finding[$key] ?? null) || !array_is_list($finding[$key]) || count($finding[$key]) > 20) {
                    return null;
                }
                foreach ($finding[$key] as $item) {
                    if (!is_string($item) || strlen($item) > 4000 || ($key === 'evidence' && !self::evidenceExists($item, $context))) {
                        return null;
                    }
                }
            }
            if ($finding['evidence'] === []) {
                return null;
            }
        }
        return ['summary' => $data['summary'], 'findings' => $data['findings']];
    }

    /** @param array<string, mixed> $context */
    private static function evidenceExists(string $pointer, array $context): bool
    {
        if (!preg_match('~^/(?:explain_plan/(?:facts|nodes)|schema/tables)/~', $pointer)) {
            return false;
        }
        $value = $context;
        foreach (explode('/', substr($pointer, 1)) as $key) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $key);
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        return $value !== null && $value !== [] && $value !== '';
    }
}
