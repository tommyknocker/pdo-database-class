<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\MonitorManager;
use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * Connection Pool pane.
 */
class ConnectionPoolPane
{
    /**
     * Render connection pool pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     * @param int $selectedIndex Selected item index (-1 if none)
     * @param int $scrollOffset Scroll offset
     * @param bool $fullscreen Whether in fullscreen mode
     * @param array<string, mixed>|null $connectionsData Cached connections data (null to fetch fresh)
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active, int $selectedIndex = -1, int $scrollOffset = 0, bool $fullscreen = false, ?array $connectionsData = null): void
    {
        if ($fullscreen) {
            [$rows, $cols] = Terminal::getSize();
            // Header: row 1
            // Summary: rows 2-4 (3 rows)
            // "Connections:" label: row 5
            // Table header: row 6
            // Footer: row $rows
            // Content area starts at row 2 (includes summary, label, table header)
            // Height = $rows - 2 (header + footer)
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
        if ($connectionsData === null) {
            $connectionsData = MonitorManager::getActiveConnections($db);
        }

        // Clear content area first (before border to avoid clearing border)
        for ($i = 0; $i < $content['height']; $i++) {
            Terminal::moveTo($content['row'] + $i, $content['col']);
            Terminal::clearLine();
        }

        // Render border after clearing content
        if (!$fullscreen) {
            $layout->renderBorder($paneIndex, 'Connection Pool', $active);
        }

        $connections = $connectionsData['connections'] ?? [];
        $summary = $connectionsData['summary'] ?? [];

        // Display summary
        $row = $content['row'];
        Terminal::moveTo($row, $content['col']);

        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Summary:';
        Terminal::reset();

        $row++;
        Terminal::moveTo($row, $content['col']);
        $current = (int)($summary['current'] ?? 0);
        $max = (int)($summary['max'] ?? 0);
        $usage = (float)($summary['usage_percent'] ?? 0);

        $text = "Current: {$current}";
        if ($max > 0) {
            $text .= " / Max: {$max}";
        }
        // Truncate to fit pane width
        $maxLen = $content['width'];
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, 0, $maxLen, 'UTF-8');
        }
        echo $text;

        $row++;
        Terminal::moveTo($row, $content['col']);
        $usageText = 'Usage: ' . number_format($usage, 1) . '%';
        // Truncate to fit pane width
        if (mb_strlen($usageText, 'UTF-8') > $maxLen) {
            $usageText = mb_substr($usageText, 0, $maxLen, 'UTF-8');
        }
        echo $usageText;

        // Color code usage
        if (Terminal::supportsColors()) {
            if ($usage > 80) {
                Terminal::color(Terminal::COLOR_RED);
            } elseif ($usage > 60) {
                Terminal::color(Terminal::COLOR_YELLOW);
            } else {
                Terminal::color(Terminal::COLOR_GREEN);
            }
            Terminal::moveTo($row, $content['col'] + 7);
            echo number_format($usage, 1) . '%';
            Terminal::reset();
        }

        // Display connections list
        if (!empty($connections)) {
            $summaryHeight = 3; // Summary takes 3 rows
            $row += 2;
            Terminal::moveTo($row, $content['col']);

            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'Connections:';
            Terminal::reset();

            // Clamp selected index
            if ($selectedIndex >= count($connections)) {
                $selectedIndex = count($connections) - 1;
            }
            if ($selectedIndex < 0) {
                $selectedIndex = 0;
            }

            // Calculate visible height for connections list
            // Content area includes: summary (3 rows) + label (1 row) + table header (1 row) + connections list
            // In fullscreen: content['height'] = $rows - 2 (header + footer)
            // Connections list starts at row 8 (after summary 3 rows + spacing 2 rows + label 1 row + table header 1 row)
            // Footer is at row $rows, so last connection row = $rows - 1
            // Visible height = ($rows - 1) - 8 + 1 = $rows - 8
            $summaryHeight = 3;
            $labelHeight = 1;
            $tableHeaderHeight = 1;
            if ($fullscreen) {
                // In fullscreen: header (row 1) + summary (rows 2-4) + spacing (row 5) + label (row 6) + table header (row 7) + list (starts row 8) + footer (row $rows)
                // Visible height = $rows - 1 (footer) - 7 (header + summary + spacing + label + table header) = $rows - 8
                $visibleHeight = $rows - 1 - ($summaryHeight + 2 + $labelHeight + $tableHeaderHeight); // +2 for spacing before label
            } else {
                $visibleHeight = $content['height'] - $summaryHeight - 2; // -2 for header and spacing
            }

            $colWidth = (int)floor($content['width'] / 3);

            // Header
            $row++;
            Terminal::moveTo($row, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            $headerText = str_pad('ID', $colWidth) . str_pad('User', $colWidth) . 'Database';
            // Truncate if too long
            if (mb_strlen($headerText, 'UTF-8') > $content['width']) {
                $headerText = mb_substr($headerText, 0, $content['width'], 'UTF-8');
            }
            echo $headerText;
            Terminal::reset();

            // Display connections (with scrolling)
            $startIdx = $scrollOffset;
            $endIdx = min($startIdx + $visibleHeight, count($connections));

            for ($i = $startIdx; $i < $endIdx; $i++) {
                $conn = $connections[$i];
                $displayRow = $row + 1 + ($i - $startIdx);

                // In fullscreen, footer is at row $rows, so last connection row = $rows - 1
                // In normal mode, check against content area
                if ($fullscreen) {
                    [$totalRows] = Terminal::getSize();
                    // Footer is at row $totalRows, so last connection row = $totalRows - 1
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

                $id = self::truncate((string)($conn['id'] ?? $conn['pid'] ?? $conn['session_id'] ?? ''), $colWidth);
                $user = self::truncate((string)($conn['user'] ?? $conn['usename'] ?? $conn['login'] ?? ''), $colWidth);
                $dbName = self::truncate((string)($conn['db'] ?? $conn['database'] ?? $conn['datname'] ?? ''), $content['width'] - ($colWidth * 2));

                $marker = $isSelected ? '> ' : '  ';
                $rowText = $marker . str_pad($id, $colWidth - 2) . str_pad($user, $colWidth) . $dbName;
                // Truncate if too long
                if (mb_strlen($rowText, 'UTF-8') > $content['width']) {
                    $rowText = mb_substr($rowText, 0, $content['width'], 'UTF-8');
                }
                echo $rowText;
                Terminal::reset();
            }

            // Show scroll indicator
            if ($scrollOffset > 0 || $endIdx < count($connections)) {
                Terminal::moveTo($content['row'] + $content['height'] - 1, $content['col']);
                if (Terminal::supportsColors()) {
                    Terminal::color(Terminal::COLOR_YELLOW);
                }
                $info = '';
                if ($scrollOffset > 0) {
                    $info .= '↑ ';
                }
                if ($endIdx < count($connections)) {
                    $info .= '↓ ';
                }
                if (count($connections) > $visibleHeight) {
                    $info .= '(' . ($scrollOffset + 1) . '-' . $endIdx . '/' . count($connections) . ')';
                }
                echo $info;
                Terminal::reset();
            }
        } else {
            $row += 2;
            Terminal::moveTo($row, $content['col']);
            echo 'No connections';
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
