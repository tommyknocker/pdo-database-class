<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * Dump and restore command.
 */
class DumpCommand extends Command
{
    /**
     * Create dump command.
     */
    public function __construct()
    {
        parent::__construct('dump', 'Dump and restore database');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        // Show help if explicitly requested via option or argument
        if ($this->getOption('help', false)) {
            $this->showHelp();
            return 0;
        }

        $firstArg = $this->getArgument(0);

        if ($firstArg === '--help' || $firstArg === 'help') {
            $this->showHelp();
            return 0;
        }

        if ($firstArg === 'restore') {
            return $this->restore();
        }

        // Check for common typos in 'restore' command
        if ($firstArg !== null && (str_starts_with($firstArg, 'restor') || str_starts_with($firstArg, 'retor'))) {
            return $this->showError("Unknown command: '{$firstArg}'. Did you mean 'restore'?");
        }

        // If no arguments, check if dump-specific options are provided
        // If yes, dump entire database; if no, show help
        if ($firstArg === null) {
            $hasDumpOptions = $this->getOption('schema-only', false) !== false
                || $this->getOption('data-only', false) !== false
                || $this->getOption('output') !== null
                || $this->getOption('auto-name', false) !== false;
            if (!$hasDumpOptions) {
                $this->showHelp();
                return 0;
            }
        }

        // First arg is table name (dump specific table), or null for entire database
        return $this->dump();
    }

    /**
     * Dump database or table.
     *
     * @return int Exit code
     */
    protected function dump(): int
    {
        $table = $this->getArgument(0);
        $schemaOnly = (bool)$this->getOption('schema-only', false);
        $dataOnly = (bool)$this->getOption('data-only', false);
        $output = $this->getOption('output');
        $dropTables = !(bool)$this->getOption('no-drop-tables', false);
        $compress = $this->getOption('compress');
        $autoName = (bool)$this->getOption('auto-name', false);
        $dateFormat = $this->getOption('date-format');
        $rotate = $this->getOption('rotate');

        if ($schemaOnly && $dataOnly) {
            return $this->showError('Cannot use --schema-only and --data-only together');
        }

        try {
            $db = $this->getDb();

            // Check if table exists when table name is provided
            if (is_string($table) && $table !== '') {
                $schema = $db->schema();
                if (!$schema->tableExists($table)) {
                    return $this->showError("Table '{$table}' does not exist");
                }
            }

            $sql = DumpManager::dump($db, is_string($table) ? $table : null, $schemaOnly, $dataOnly, $dropTables);

            // Determine output file path
            $outputPath = $this->determineOutputPath($output, $autoName, $dateFormat, $compress);

            if ($outputPath !== null) {
                // Write dump to file
                $content = $sql;
                $finalPath = $outputPath;

                // Apply compression if requested
                if ($compress !== null && $compress !== false) {
                    $compressed = $this->compressContent($content, $compress);
                    if ($compressed === null) {
                        return $this->showError("Invalid compression format: {$compress}. Supported: gzip, bzip2");
                    }
                    $content = $compressed;
                    $finalPath = $this->addCompressionExtension($outputPath, $compress);
                }

                $written = file_put_contents($finalPath, $content);
                if ($written === false) {
                    return $this->showError("Failed to write dump to: {$finalPath}");
                }

                // Apply rotation if requested
                if ($rotate !== null && $rotate !== false) {
                    $this->rotateBackups($finalPath, (int)$rotate);
                }

                static::success("Dump written to: {$finalPath}");
                return 0;
            }

            // Output to stdout (no compression for stdout)
            echo $sql;
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        } catch (QueryException $e) {
            // Check if error is about table not found
            $message = $e->getMessage();
            if (is_string($table) && $table !== '' && (str_contains($message, "doesn't exist") || str_contains($message, 'not found'))) {
                return $this->showError("Table '{$table}' does not exist");
            }
            return $this->showError($message);
        } catch (\Exception $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Restore database from dump file.
     *
     * @return int Exit code
     */
    protected function restore(): int
    {
        $file = $this->getArgument(1);
        if (!is_string($file) || $file === '') {
            return $this->showError('Dump file path is required');
        }

        $force = (bool)$this->getOption('force', false);

        if (!$force) {
            $confirmed = static::readConfirmation(
                "Are you sure you want to restore from '{$file}'? This will modify your database",
                false
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        try {
            $db = $this->getDb();
            DumpManager::restore($db, $file, $force);
            static::success("Database restored from: {$file}");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Determine output file path based on options.
     *
     * @param string|null $output Explicit output path
     * @param bool $autoName Use automatic naming with date/time
     * @param string|null $dateFormat Custom date format for auto-naming
     * @param string|false|null $compress Compression format (affects extension)
     *
     * @return string|null Output file path or null for stdout
     */
    protected function determineOutputPath(?string $output, bool $autoName, ?string $dateFormat, $compress): ?string
    {
        if ($output !== null && $output !== '') {
            return $output;
        }

        if (!$autoName) {
            return null;
        }

        // Generate automatic filename with date/time
        $format = $dateFormat ?? 'Y-m-d_H-i-s';
        $timestamp = date($format);
        $baseName = 'backup_' . $timestamp;
        $extension = '.sql';

        // If compression is enabled, extension will be added later
        if ($compress === null || $compress === false) {
            return $baseName . $extension;
        }

        return $baseName . $extension;
    }

    /**
     * Compress content using specified format.
     *
     * @param string $content Content to compress
     * @param string $format Compression format (gzip, bzip2)
     *
     * @return string|null Compressed content or null if format is invalid
     */
    protected function compressContent(string $content, string $format): ?string
    {
        return match (strtolower($format)) {
            'gzip' => $this->compressGzip($content),
            'bzip2', 'bz2' => $this->compressBzip2($content),
            default => null,
        };
    }

    /**
     * Compress content using gzip.
     *
     * @param string $content Content to compress
     *
     * @return string|null Compressed content or null if compression fails
     */
    protected function compressGzip(string $content): ?string
    {
        $compressed = gzencode($content, 9);
        return $compressed !== false ? $compressed : null;
    }

    /**
     * Compress content using bzip2.
     *
     * @param string $content Content to compress
     *
     * @return string|null Compressed content or null if compression fails
     */
    protected function compressBzip2(string $content): ?string
    {
        $compressed = bzcompress($content, 9);
        if (!is_string($compressed)) {
            return null;
        }

        return $compressed;
    }

    /**
     * Add compression extension to file path.
     *
     * @param string $path Original file path
     * @param string $format Compression format
     *
     * @return string File path with compression extension
     */
    protected function addCompressionExtension(string $path, string $format): string
    {
        $extension = match (strtolower($format)) {
            'gzip' => '.gz',
            'bzip2', 'bz2' => '.bz2',
            default => '',
        };

        if ($extension === '') {
            return $path;
        }

        // Remove existing compression extensions to avoid duplicates
        $path = preg_replace('/\.(gz|bz2)$/', '', $path) ?? $path;

        return $path . $extension;
    }

    /**
     * Rotate backup files, keeping only N most recent.
     *
     * @param string $currentPath Current backup file path
     * @param int $keep Number of backups to keep
     */
    protected function rotateBackups(string $currentPath, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        $directory = dirname($currentPath);
        $basename = basename($currentPath);

        // Determine pattern based on filename
        // For auto-named files starting with "backup_", match all backup_*.sql files
        // For custom named files, match files with same base name
        if (preg_match('/^backup_/', $basename)) {
            // Auto-named file: match all backup_*.sql files in same directory
            // Pattern matches: backup_<any-date-format>.<ext>
            $pattern = '/^backup_.*\.(sql|sql\.gz|sql\.bz2)$/';
        } else {
            // Custom named file: match files with same base name
            // Extract base name without extension
            $baseWithoutExt = preg_replace('/\.(sql|gz|bz2)$/', '', $basename) ?? $basename;
            $escapedBase = preg_quote($baseWithoutExt, '/');
            $pattern = "/^{$escapedBase}.*\.(sql|sql\.gz|sql\.bz2)$/";
        }

        // Find all matching backup files
        $files = [];
        if (is_dir($directory)) {
            $handle = opendir($directory);
            if ($handle !== false) {
                while (($file = readdir($handle)) !== false) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    $filePath = $directory . DIRECTORY_SEPARATOR . $file;
                    if (is_file($filePath) && preg_match($pattern, $file)) {
                        $files[] = [
                            'path' => $filePath,
                            'mtime' => filemtime($filePath),
                        ];
                    }
                }
                closedir($handle);
            }
        }

        // Sort by modification time (newest first)
        usort($files, static function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });

        // Remove old backups beyond the limit
        if (count($files) > $keep) {
            $toRemove = array_slice($files, $keep);
            foreach ($toRemove as $file) {
                @unlink($file['path']);
            }
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Database Dump and Restore\n\n";
        echo "Usage:\n";
        echo "  pdodb dump [table] [--schema-only] [--data-only] [--output=file.sql]\n";
        echo "  pdodb dump restore <file> [--force]\n\n";
        echo "Commands:\n";
        echo "  dump [table]        Dump database or specific table to SQL (default)\n";
        echo "  restore <file>      Restore database from SQL dump file\n\n";
        echo "Options:\n";
        echo "  --schema-only       Dump only schema (CREATE TABLE, indexes, etc.)\n";
        echo "  --data-only         Dump only data (INSERT statements)\n";
        echo "  --output=<file>     Write dump to file instead of stdout\n";
        echo "  --no-drop-tables   Do not add DROP TABLE IF EXISTS before CREATE TABLE\n";
        echo "  --compress=<format> Compress output (gzip, bzip2)\n";
        echo "  --auto-name         Automatically name backup file with timestamp\n";
        echo "  --date-format=<fmt> Custom date format for auto-naming (default: Y-m-d_H-i-s)\n";
        echo "  --rotate=<N>        Keep only N most recent backups (delete older ones)\n";
        echo "  --force             Skip confirmation (for restore)\n\n";
        echo "Examples:\n";
        echo "  pdodb dump --output=backup.sql\n";
        echo "  pdodb dump users --data-only --output=users_data.sql\n";
        echo "  pdodb dump --schema-only --output=schema.sql\n";
        echo "  pdodb dump --auto-name --compress=gzip\n";
        echo "  pdodb dump --output=backup.sql --compress=bzip2 --rotate=7\n";
        echo "  pdodb dump --auto-name --date-format=Y-m-d --rotate=30\n";
        echo "  pdodb dump restore backup.sql\n";
        echo "  pdodb dump restore backup.sql --force\n";
        return 0;
    }
}
