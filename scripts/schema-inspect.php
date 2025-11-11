#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Schema inspector CLI tool.
 *
 * Usage:
 *   php scripts/schema-inspect.php [table_name] [--format=table|json|yaml]
 *   composer pdodb:schema:inspect [table_name] [--format=table|json|yaml]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\cli\SchemaInspector;

$tableName = null;
$format = null;

// Parse arguments
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $tableName = $arg;
    }
}

// Validate format
if ($format !== null && !in_array($format, ['table', 'json', 'yaml'], true)) {
    echo "Error: Invalid format '{$format}'. Valid formats: table, json, yaml\n";
    exit(1);
}

try {
    SchemaInspector::inspect($tableName, $format);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

