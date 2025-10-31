<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

/**
 * Single optimization recommendation.
 */
class Recommendation
{
    /**
     * @param string $severity Severity level: 'critical', 'warning', 'info'
     * @param string $type Recommendation type: 'missing_index', 'full_scan', 'filesort', etc.
     * @param string $message Human-readable recommendation message
     * @param string|null $suggestion SQL suggestion if applicable
     * @param array<string>|null $affectedTables List of affected table names
     */
    public function __construct(
        public string $severity,
        public string $type,
        public string $message,
        public ?string $suggestion = null,
        public ?array $affectedTables = null
    ) {
    }
}
