<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\cli\CliExitException;
use tommyknocker\pdodb\cli\Command;

/**
 * Cache command for managing query cache.
 */
class CacheCommand extends Command
{
    /**
     * Create cache command.
     */
    public function __construct()
    {
        parent::__construct('cache', 'Manage query result cache');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        // Show help if explicitly requested
        if ($this->getOption('help', false)) {
            $this->showHelp();
            return 0;
        }

        $sub = $this->getArgument(0);
        if ($sub === null || $sub === '--help' || $sub === 'help') {
            return $this->showHelp();
        }

        $db = $this->getDb();

        // Check if cache is enabled
        $cacheManager = $db->getCacheManager();
        if ($cacheManager === null) {
            $this->showError('Cache is not enabled. Enable it in your configuration.');
        }

        // At this point, cacheManager is guaranteed to be non-null
        // (showError() terminates execution if it was null)
        /** @var CacheManager $cacheManager */
        $subStr = is_string($sub) ? $sub : '';
        return match ($subStr) {
            'clear' => $this->clearCache($cacheManager),
            'invalidate' => $this->invalidateCache($cacheManager),
            'stats' => $this->showStats($cacheManager),
            default => $this->fail("Unknown subcommand: {$subStr}"),
        };
    }

    /**
     * Clear all cached query results.
     *
     * @param CacheManager $cacheManager
     *
     * @return int Exit code
     */
    protected function clearCache(CacheManager $cacheManager): int
    {
        $force = (bool)$this->getOption('force', false);

        if (!$force) {
            $confirmed = static::readConfirmation(
                'Are you sure you want to clear all cache? This cannot be undone.',
                false
            );
            if (!$confirmed) {
                static::info('Cache clear cancelled.');
                return 0;
            }
        }

        if ($cacheManager->clear()) {
            static::success('Cache cleared successfully.');
            return 0;
        }

        $this->showError('Failed to clear cache.');
    }

    /**
     * Invalidate cache entries matching pattern.
     *
     * @param CacheManager $cacheManager
     *
     * @return int Exit code
     */
    protected function invalidateCache(CacheManager $cacheManager): int
    {
        $pattern = $this->getArgument(1);
        if (!is_string($pattern) || $pattern === '') {
            $this->showError('Pattern is required. Usage: pdodb cache invalidate <pattern>');
        }

        $force = (bool)$this->getOption('force', false);

        if (!$force) {
            $confirmed = static::readConfirmation(
                "Are you sure you want to invalidate cache entries matching pattern '{$pattern}'?",
                false
            );
            if (!$confirmed) {
                static::info('Cache invalidation cancelled.');
                return 0;
            }
        }

        $deletedCount = $cacheManager->invalidateByPattern($pattern);

        if ($deletedCount > 0) {
            static::success("Invalidated {$deletedCount} cache entries matching pattern '{$pattern}'.");
            return 0;
        }

        static::info("No cache entries found matching pattern '{$pattern}'.");
        return 0;
    }

    /**
     * Show cache statistics.
     *
     * @param CacheManager $cacheManager
     *
     * @return int Exit code
     */
    protected function showStats(CacheManager $cacheManager): int
    {
        $formatVal = $this->getOption('format', 'table');
        $format = is_string($formatVal) ? $formatVal : 'table';

        try {
            $stats = $cacheManager->getStats();

            if ($format === 'json') {
                $this->writeln(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return 0;
            }

            // Table format
            $this->writeln('Cache Statistics');
            $this->writeln("================\n");
            $this->writeln('Enabled:        ' . ($stats['enabled'] ? 'Yes' : 'No'));
            $this->writeln("Type:           {$stats['type']}");
            $this->writeln("Prefix:         {$stats['prefix']}");
            $this->writeln("Default TTL:    {$stats['default_ttl']} seconds");
            $this->writeln("Hits:           {$stats['hits']}");
            $this->writeln("Misses:         {$stats['misses']}");
            $this->writeln("Hit Rate:       {$stats['hit_rate']}%");
            $this->writeln("Sets:           {$stats['sets']}");
            $this->writeln("Deletes:        {$stats['deletes']}");
            $this->writeln("Total Requests: {$stats['total_requests']}");

            return 0;
        } catch (CliExitException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->showError('Failed to get cache statistics: ' . $e->getMessage());
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        $this->writeln("Cache Management\n");
        $this->writeln("Usage: pdodb cache <subcommand> [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  clear                    Clear all cached query results');
        $this->writeln('  invalidate <pattern>     Invalidate cache entries matching pattern');
        $this->writeln("  stats                    Show cache usage statistics\n");
        $this->writeln('Options:');
        $this->writeln('  --force                  Skip confirmation prompts (for clear/invalidate)');
        $this->writeln("  --format=table|json      Output format (default: table, for stats)\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb cache clear');
        $this->writeln('  pdodb cache clear --force');
        $this->writeln('  pdodb cache invalidate users');
        $this->writeln('  pdodb cache invalidate "table:users"');
        $this->writeln('  pdodb cache invalidate "table:users_*" --force');
        $this->writeln('  pdodb cache invalidate "pdodb_table_users_*"');
        $this->writeln('  pdodb cache stats');
        $this->writeln('  pdodb cache stats --format=json');
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'force',
            'format',
        ];
    }
}
