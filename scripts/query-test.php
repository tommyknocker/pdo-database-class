#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Query tester CLI tool (REPL).
 *
 * Usage:
 *   php scripts/query-test.php [query]
 *   composer pdodb:query:test [query]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\cli\QueryTester;

$query = $argv[1] ?? null;

try {
    QueryTester::test($query);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

