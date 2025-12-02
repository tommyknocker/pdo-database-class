<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * Server Metrics pane.
 */
class ServerMetricsPane
{
    /**
     * Render server metrics pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     * @param array<string, mixed>|null $metrics Cached metrics data (null to fetch fresh)
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active, ?array $metrics = null): void
    {
        $content = $layout->getContentArea($paneIndex);

        // Use cached data if provided, otherwise fetch fresh
        if ($metrics === null) {
            $dialect = $db->schema()->getDialect();
            $metrics = $dialect->getServerMetrics($db);
        }

        // Render border
        $layout->renderBorder($paneIndex, 'Server Metrics', $active);

        // Clear content area
        for ($i = 0; $i < $content['height']; $i++) {
            Terminal::moveTo($content['row'] + $i, $content['col']);
            Terminal::clearLine();
        }

        if (empty($metrics)) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'No metrics available';
            return;
        }

        $row = $content['row'];

        // Version
        if (isset($metrics['version'])) {
            Terminal::moveTo($row, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'Version: ';
            Terminal::reset();
            $version = (string)$metrics['version'];
            echo self::truncate($version, $content['width'] - 9);
            $row++;
        }

        // Uptime
        if (isset($metrics['uptime_seconds'])) {
            Terminal::moveTo($row, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'Uptime: ';
            Terminal::reset();
            $uptime = (int)$metrics['uptime_seconds'];
            echo self::formatUptime($uptime);
            $row++;
        }

        // Display key metrics (dialect-specific)
        // Note: Questions and Queries are cumulative counters since server start
        $keyMetrics = [
            'threads_connected' => 'Connections',
            'threads_running' => 'Running',
            'questions' => 'Questions (total)',
            'queries' => 'Queries (total)',
            'slow_queries' => 'Slow Queries',
            'connections' => 'Connections',
            'commits' => 'Commits',
            'rollbacks' => 'Rollbacks',
            'sessions' => 'Sessions',
            'user_connections' => 'User Connections',
        ];

        foreach ($keyMetrics as $key => $label) {
            if (isset($metrics[$key])) {
                Terminal::moveTo($row, $content['col']);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo $label . ': ';
                Terminal::reset();
                $value = (int)$metrics[$key];
                echo number_format($value);
                $row++;

                if ($row >= $content['row'] + $content['height']) {
                    break;
                }
            }
        }

        // File size for SQLite
        if (isset($metrics['file_size'])) {
            Terminal::moveTo($row, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'File Size: ';
            Terminal::reset();
            $size = (int)$metrics['file_size'];
            echo self::formatBytes($size);
            $row++;
        }

        // Error message if present
        if (isset($metrics['error'])) {
            Terminal::moveTo($row, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
            }
            echo 'Error: ' . self::truncate((string)$metrics['error'], $content['width'] - 7);
            Terminal::reset();
        }
    }

    /**
     * Format uptime in human-readable format.
     *
     * @param int $seconds Uptime in seconds
     *
     * @return string Formatted uptime
     */
    public static function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int)($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }

        if ($seconds < 86400) {
            $hours = (int)($seconds / 3600);
            $minutes = (int)(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }

        $days = (int)($seconds / 86400);
        $hours = (int)(($seconds % 86400) / 3600);
        return $days . 'd ' . $hours . 'h';
    }

    /**
     * Format bytes in human-readable format.
     *
     * @param int $bytes Bytes
     *
     * @return string Formatted size
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Truncate string to fit width.
     *
     * @param string $text Text to truncate
     * @param int $width Maximum width
     *
     * @return string Truncated text
     */
    protected static function truncate(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }

        if (strlen($text) <= $width) {
            return $text;
        }

        return substr($text, 0, max(0, $width - 3)) . '...';
    }
}
