<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

/**
 * Detected issue in query execution plan.
 */
class Issue
{
    /**
     * @param string $severity Severity level: 'critical', 'warning', 'info'
     * @param string $type Issue type: 'full_table_scan', 'missing_index', etc.
     * @param string $description Issue description
     * @param string|null $table Affected table name
     */
    public function __construct(
        public string $severity,
        public string $type,
        public string $description,
        public ?string $table = null
    ) {
    }
}
