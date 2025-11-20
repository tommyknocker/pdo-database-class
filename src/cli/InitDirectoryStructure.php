<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Directory structure creator for init command.
 */
class InitDirectoryStructure
{
    /**
     * Create directory structure.
     *
     * @param array<string, mixed> $structure Structure configuration
     */
    public static function create(array $structure): void
    {
        if (empty($structure)) {
            return;
        }

        echo "\n";
        echo "Creating directory structure...\n";

        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = __DIR__ . '/../../..';
        }

        $paths = [
            'migrations' => $structure['migrations'] ?? null,
            'models' => $structure['models'] ?? null,
            'repositories' => $structure['repositories'] ?? null,
            'services' => $structure['services'] ?? null,
            'seeds' => $structure['seeds'] ?? null,
        ];

        $created = [];

        foreach ($paths as $type => $path) {
            if ($path === null || $path === '') {
                continue;
            }

            // Make path relative to current directory if not absolute
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Z]:/', $path)) {
                $fullPath = $cwd . '/' . ltrim($path, './');
            } else {
                $fullPath = $path;
            }

            // Normalize path
            $fullPath = rtrim($fullPath, '/\\');

            if (!is_dir($fullPath)) {
                if (mkdir($fullPath, 0755, true)) {
                    $created[] = $type;
                    echo "  ✓ Created directory: {$path}\n";
                } else {
                    echo "  ✗ Failed to create directory: {$path}\n";
                }
            } else {
                echo "  - Directory already exists: {$path}\n";
            }
        }

        // Create .gitkeep files in empty directories
        foreach ($created as $type) {
            $path = $paths[$type];
            if ($path === null || $path === '') {
                continue;
            }

            // Make path relative to current directory if not absolute
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Z]:/', $path)) {
                $fullPath = $cwd . '/' . ltrim($path, './');
            } else {
                $fullPath = $path;
            }

            $fullPath = rtrim($fullPath, '/\\');
            $gitkeep = $fullPath . '/.gitkeep';

            if (is_dir($fullPath) && !file_exists($gitkeep)) {
                file_put_contents($gitkeep, '');
            }
        }

        if (!empty($created)) {
            echo "✓ Directory structure created\n";
        }
    }
}
