<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\MonitorManager;
use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * Active Queries pane.
 */
class ActiveQueriesPane
{
    /**
     * Render active queries pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     * @param int $selectedIndex Selected item index (-1 if none)
     * @param int $scrollOffset Scroll offset
     * @param bool $fullscreen Whether in fullscreen mode
     * @param array<int, array<string, mixed>>|null $queries Cached queries data (null to fetch fresh)
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active, int $selectedIndex = -1, int $scrollOffset = 0, bool $fullscreen = false, ?array $queries = null): void
    {
        if ($fullscreen) {
            [$rows, $cols] = Terminal::getSize();
            // Header: row 1, Table header: row 2, Footer: row $rows
            // Content area for queries starts at row 2 (includes table header)
            // Height = $rows - 2 (header + footer), table header is part of content
            $content = [
                'row' => 2,
                'col' => 1,
                'height' => $rows - 2,
                'width' => $cols,
            ];
        } else {
            $content = $layout->getContentArea($paneIndex);
        }

        // Use cached data if provided, otherwise fetch fresh
        if ($queries === null) {
            $queries = MonitorManager::getActiveQueries($db);
        }

        // Clear content area first (before border to avoid clearing border)
        for ($i = 0; $i < $content['height']; $i++) {
            Terminal::moveTo($content['row'] + $i, $content['col']);
            Terminal::clearLine();
        }

        // Render border after clearing content
        if (!$fullscreen) {
            $layout->renderBorder($paneIndex, 'Active Queries', $active);
        }

        if (empty($queries)) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'No active queries';
            return;
        }

        // Clamp selected index
        if ($selectedIndex >= count($queries)) {
            $selectedIndex = count($queries) - 1;
        }
        if ($selectedIndex < 0) {
            $selectedIndex = 0;
        }

        $visibleHeight = $content['height'] - 1; // -1 for header
        $colWidth = (int)floor($content['width'] / 4);

        // Header
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Terminal::moveTo($content['row'], $content['col']);
        $headerText = str_pad('ID', $colWidth) . str_pad('Time', $colWidth) . str_pad('DB', $colWidth) . 'Query';
        // Truncate if too long
        if (mb_strlen($headerText, 'UTF-8') > $content['width']) {
            $headerText = mb_substr($headerText, 0, $content['width'], 'UTF-8');
        }
        echo $headerText;
        Terminal::reset();

        // Display queries (with scrolling)
        $startIdx = $scrollOffset;
        $endIdx = min($startIdx + $visibleHeight, count($queries));

        for ($i = $startIdx; $i < $endIdx; $i++) {
            $query = $queries[$i];
            $displayRow = $content['row'] + 1 + ($i - $startIdx);

            // In fullscreen, footer is at row $rows, so last query row = $rows - 1
            // In normal mode, check against content area
            if ($fullscreen) {
                [$totalRows] = Terminal::getSize();
                // Footer is at row $totalRows, so last query row = $totalRows - 1
                if ($displayRow > $totalRows - 1) {
                    break;
                }
            } else {
                if ($displayRow >= $content['row'] + $content['height']) {
                    break;
                }
            }

            Terminal::moveTo($displayRow, $content['col']);

            $isSelected = $active && $selectedIndex === $i;
            if ($isSelected && Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_CYAN);
                Terminal::color(Terminal::COLOR_BLACK);
            }

            $id = self::truncate((string)($query['id'] ?? $query['pid'] ?? $query['session_id'] ?? ''), $colWidth);
            $time = self::truncate((string)($query['time'] ?? $query['duration'] ?? ''), $colWidth);
            $dbName = self::truncate((string)($query['db'] ?? $query['database'] ?? $query['datname'] ?? ''), $colWidth);
            $queryText = self::truncate((string)($query['query'] ?? ''), $content['width'] - ($colWidth * 3));

            // Highlight slow queries (> 5 seconds) if not selected
            if (!$isSelected) {
                $timeVal = (float)($query['time'] ?? $query['duration'] ?? 0);
                if ($timeVal > 5 && Terminal::supportsColors()) {
                    Terminal::color(Terminal::COLOR_RED);
                    Terminal::bold();
                }
            }

            $marker = $isSelected ? '> ' : '  ';
            $rowText = $marker . str_pad($id, $colWidth - 2) . str_pad($time, $colWidth) . str_pad($dbName, $colWidth) . $queryText;
            // Truncate if too long
            if (mb_strlen($rowText, 'UTF-8') > $content['width']) {
                $rowText = mb_substr($rowText, 0, $content['width'], 'UTF-8');
            }
            echo $rowText;
            Terminal::reset();
        }

        // Show scroll indicator
        if ($scrollOffset > 0 || $endIdx < count($queries)) {
            Terminal::moveTo($content['row'] + $content['height'] - 1, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            $info = '';
            if ($scrollOffset > 0) {
                $info .= '↑ ';
            }
            if ($endIdx < count($queries)) {
                $info .= '↓ ';
            }
            if (count($queries) > $visibleHeight) {
                $info .= '(' . ($scrollOffset + 1) . '-' . $endIdx . '/' . count($queries) . ')';
            }
            echo $info;
            Terminal::reset();
        }
    }

    /**
     * Truncate string to fit width.
     *
     * @param string $text Text to truncate
     * @param int $width Maximum width
     *
     * @return string Truncated text
     */
    public static function truncate(string $text, int $width): string
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
