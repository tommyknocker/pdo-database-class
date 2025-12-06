<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

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
        /** @var \tommyknocker\pdodb\cache\CacheManager $cacheManager */
        $subStr = is_string($sub) ? $sub : '';
        return match ($subStr) {
            'clear' => $this->clearCache($cacheManager),
            'invalidate' => $this->invalidateCache($cacheManager),
            'stats' => $this->showStats($cacheManager),
            default => $this->showError("Unknown subcommand: {$subStr}"),
        };
    }

    /**
     * Clear all cached query results.
     *
     * @param \tommyknocker\pdodb\cache\CacheManager $cacheManager
     *
     * @return int Exit code
     */
    protected function clearCache(\tommyknocker\pdodb\cache\CacheManager $cacheManager): int
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
     * @param \tommyknocker\pdodb\cache\CacheManager $cacheManager
     *
     * @return int Exit code
     */
    protected function invalidateCache(\tommyknocker\pdodb\cache\CacheManager $cacheManager): int
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
     * @param \tommyknocker\pdodb\cache\CacheManager $cacheManager
     *
     * @return int Exit code
     */
    protected function showStats(\tommyknocker\pdodb\cache\CacheManager $cacheManager): int
    {
        $formatVal = $this->getOption('format', 'table');
        $format = is_string($formatVal) ? $formatVal : 'table';

        try {
            $stats = $cacheManager->getStats();

            if ($format === 'json') {
                echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            // Table format
            echo "Cache Statistics\n";
            echo "================\n\n";
            echo 'Enabled:        ' . ($stats['enabled'] ? 'Yes' : 'No') . "\n";
            echo "Type:           {$stats['type']}\n";
            echo "Prefix:         {$stats['prefix']}\n";
            echo "Default TTL:    {$stats['default_ttl']} seconds\n";
            echo "Hits:           {$stats['hits']}\n";
            echo "Misses:         {$stats['misses']}\n";
            echo "Hit Rate:       {$stats['hit_rate']}%\n";
            echo "Sets:           {$stats['sets']}\n";
            echo "Deletes:        {$stats['deletes']}\n";
            echo "Total Requests: {$stats['total_requests']}\n";

            return 0;
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
        echo "Cache Management\n\n";
        echo "Usage: pdodb cache <subcommand> [options]\n\n";
        echo "Subcommands:\n";
        echo "  clear                    Clear all cached query results\n";
        echo "  invalidate <pattern>     Invalidate cache entries matching pattern\n";
        echo "  stats                    Show cache usage statistics\n\n";
        echo "Options:\n";
        echo "  --force                  Skip confirmation prompts (for clear/invalidate)\n";
        echo "  --format=table|json      Output format (default: table, for stats)\n\n";
        echo "Examples:\n";
        echo "  pdodb cache clear\n";
        echo "  pdodb cache clear --force\n";
        echo "  pdodb cache invalidate users\n";
        echo "  pdodb cache invalidate \"table:users\"\n";
        echo "  pdodb cache invalidate \"table:users_*\" --force\n";
        echo "  pdodb cache invalidate \"pdodb_table_users_*\"\n";
        echo "  pdodb cache stats\n";
        echo "  pdodb cache stats --format=json\n";
        return 0;
    }
}
