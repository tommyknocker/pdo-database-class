<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * Cache Stats pane.
 */
class CacheStatsPane
{
    /**
     * Render cache stats pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active): void
    {
        $content = $layout->getContentArea($paneIndex);
        $cacheManager = $db->getCacheManager();

        // Clear content area first (before border to avoid clearing border)
        for ($i = 0; $i < $content['height']; $i++) {
            Terminal::moveTo($content['row'] + $i, $content['col']);
            Terminal::clearLine();
        }

        // Render border after clearing content
        $layout->renderBorder($paneIndex, 'Cache Stats', $active);

        if ($cacheManager === null) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'Cache not enabled';
            return;
        }

        $stats = $cacheManager->getStats();
        $row = $content['row'];

        // Status / Type (combined in one line)
        Terminal::moveTo($row, $content['col']);
        $statusText = ($stats['enabled'] ?? false) ? 'Enabled' : 'Disabled';
        $typeText = $stats['type'] ?? 'unknown';
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Status: ';
        Terminal::reset();
        echo $statusText;
        echo ' / ';
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Type: ';
        Terminal::reset();
        echo $typeText;

        // Hits
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Hits: ';
        Terminal::reset();
        $hits = (int)($stats['hits'] ?? 0);
        $hitsText = number_format($hits);
        echo $hitsText;

        // Misses
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Misses: ';
        Terminal::reset();
        $misses = (int)($stats['misses'] ?? 0);
        echo number_format($misses);

        // Hit Rate
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Hit Rate: ';
        Terminal::reset();
        $hitRate = (float)($stats['hit_rate'] ?? 0);

        // Color code hit rate
        if (Terminal::supportsColors()) {
            if ($hitRate >= 80) {
                Terminal::color(Terminal::COLOR_GREEN);
            } elseif ($hitRate >= 50) {
                Terminal::color(Terminal::COLOR_YELLOW);
            } else {
                Terminal::color(Terminal::COLOR_RED);
            }
        }

        echo number_format($hitRate, 2) . '%';
        Terminal::reset();

        // Total Requests
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Total Requests: ';
        Terminal::reset();
        $total = (int)($stats['total_requests'] ?? 0);
        echo number_format($total);

        // Sets
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Sets: ';
        Terminal::reset();
        $sets = (int)($stats['sets'] ?? 0);
        echo number_format($sets);

        // Deletes
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Deletes: ';
        Terminal::reset();
        $deletes = (int)($stats['deletes'] ?? 0);
        echo number_format($deletes);

        // TTL
        $row++;
        Terminal::moveTo($row, $content['col']);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Default TTL: ';
        Terminal::reset();
        $ttl = (int)($stats['default_ttl'] ?? 0);
        echo $ttl . 's';
    }
}
