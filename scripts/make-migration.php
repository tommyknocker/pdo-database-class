#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migration generator CLI tool.
 *
 * Usage:
 *   php scripts/make-migration.php [name]
 *   composer pdodb:make:migration [name]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\cli\MigrationGenerator;

$name = $argv[1] ?? null;

try {
    MigrationGenerator::generate($name);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

