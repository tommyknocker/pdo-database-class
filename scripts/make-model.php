#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Model generator CLI tool.
 *
 * Usage:
 *   php scripts/make-model.php <ModelName> [table_name] [output_path]
 *   composer pdodb:make:model <ModelName> [table_name] [output_path]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\cli\ModelGenerator;

$modelName = $argv[1] ?? null;
$tableName = $argv[2] ?? null;
$outputPath = $argv[3] ?? null;

if ($modelName === null) {
    echo "PDOdb Model Generator\n\n";
    echo "Usage:\n";
    echo "  php scripts/make-model.php <ModelName> [table_name] [output_path]\n";
    echo "  composer pdodb:make:model <ModelName> [table_name] [output_path]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php scripts/make-model.php User\n";
    echo "  php scripts/make-model.php User users\n";
    echo "  php scripts/make-model.php User users app/Models\n";
    exit(1);
}

try {
    ModelGenerator::generate($modelName, $tableName, $outputPath);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

