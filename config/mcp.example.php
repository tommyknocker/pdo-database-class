<?php

declare(strict_types=1);

// Copy to your project's config/mcp.php, after configuring this alias in config/db.php.
return [
    'connection' => 'diagnostics',
    // Use the actual names returned by search_schema. null = unrestricted, [] = deny all.
    'allowed_tables' => ['users', 'orders'],
    'allowed_schemas' => null,
    // Visibility restrictions force metadata-only mode even if these flags are true.
    'query_analysis_enabled' => false,
    'external_ai_enabled' => false,
    // PostgreSQL/MariaDB can use e.g. 5000. Other adapters currently require 0.
    'statement_timeout_ms' => 0,
];
