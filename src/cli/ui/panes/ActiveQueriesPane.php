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

        // Render border first (before clearing content to avoid clearing border)
        if (!$fullscreen) {
            $layout->renderBorder($paneIndex, 'Active Queries', $active);
        }

        if (empty($queries)) {
            // Clear only first line and show message
            Terminal::moveTo($content['row'], $content['col']);
            Terminal::clearLine();
            echo 'No active queries';
            // Clear remaining lines
            for ($i = 1; $i < $content['height']; $i++) {
                Terminal::moveTo($content['row'] + $i, $content['col']);
                Terminal::clearLine();
            }
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
        // Use full available width - getContentArea already accounts for borders
        $availableWidth = $content['width'];
        $colWidth = (int)floor($availableWidth / 4);

        // Header
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Terminal::moveTo($content['row'], $content['col']);
        // Clear only the content area, not the entire line (to preserve right border)
        echo str_repeat(' ', $availableWidth);
        Terminal::moveTo($content['row'], $content['col']);
        
        // Build header with UTF-8 aware padding
        $headerId = 'ID';
        $headerTime = 'Time';
        $headerDb = 'DB';
        $headerQuery = 'Query';
        
        $idLen = mb_strlen($headerId, 'UTF-8');
        $timeLen = mb_strlen($headerTime, 'UTF-8');
        $dbLen = mb_strlen($headerDb, 'UTF-8');
        
        $headerIdPadded = $headerId . str_repeat(' ', max(0, $colWidth - $idLen));
        $headerTimePadded = $headerTime . str_repeat(' ', max(0, $colWidth - $timeLen));
        $headerDbPadded = $headerDb . str_repeat(' ', max(0, $colWidth - $dbLen));
        
        // Calculate available width for Query header
        $usedWidth = ($colWidth * 3);
        $queryHeaderWidth = max(1, $availableWidth - $usedWidth);
        $headerQueryTruncated = mb_strlen($headerQuery, 'UTF-8') > $queryHeaderWidth 
            ? mb_substr($headerQuery, 0, $queryHeaderWidth, 'UTF-8')
            : $headerQuery;
        
        $headerText = $headerIdPadded . $headerTimePadded . $headerDbPadded . $headerQueryTruncated;
        
        // Final safety check
        if (mb_strlen($headerText, 'UTF-8') > $availableWidth) {
            $headerText = mb_substr($headerText, 0, $availableWidth, 'UTF-8');
        }
        
        echo $headerText;
        Terminal::reset();

        // Display queries (with scrolling)
        $startIdx = $scrollOffset;
        $endIdx = min($startIdx + $visibleHeight, count($queries));
        $lastDisplayRow = $content['row']; // Track last row that was actually displayed

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
            // Clear only the content area, not the entire line (to preserve right border)
            echo str_repeat(' ', $availableWidth);
            Terminal::moveTo($displayRow, $content['col']);

            $isSelected = $active && $selectedIndex === $i;
            if ($isSelected && Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_CYAN);
                Terminal::color(Terminal::COLOR_BLACK);
            }

            $id = self::truncate((string)($query['id'] ?? $query['pid'] ?? $query['session_id'] ?? ''), $colWidth);
            $time = self::truncate((string)($query['time'] ?? $query['duration'] ?? ''), $colWidth);
            $dbName = self::truncate((string)($query['db'] ?? $query['database'] ?? $query['datname'] ?? ''), $colWidth);

            // Highlight slow queries (> 5 seconds) if not selected
            if (!$isSelected) {
                $timeVal = (float)($query['time'] ?? $query['duration'] ?? 0);
                if ($timeVal > 5 && Terminal::supportsColors()) {
                    Terminal::color(Terminal::COLOR_RED);
                    Terminal::bold();
                }
            }

            $marker = $isSelected ? '> ' : '  ';
            
            // Build row text with proper padding (using UTF-8 aware length)
            $idPadded = $id;
            $timePadded = $time;
            $dbNamePadded = $dbName;
            
            // Pad using UTF-8 aware method
            $idLen = mb_strlen($id, 'UTF-8');
            if ($idLen < $colWidth) {
                $idPadded = $id . str_repeat(' ', $colWidth - $idLen);
            }
            
            $timeLen = mb_strlen($time, 'UTF-8');
            if ($timeLen < $colWidth) {
                $timePadded = $time . str_repeat(' ', $colWidth - $timeLen);
            }
            
            $dbNameLen = mb_strlen($dbName, 'UTF-8');
            if ($dbNameLen < $colWidth) {
                $dbNamePadded = $dbName . str_repeat(' ', $colWidth - $dbNameLen);
            }
            
            // Calculate actual used width after padding
            $markerWidth = mb_strlen($marker, 'UTF-8');
            $idPaddedWidth = mb_strlen($idPadded, 'UTF-8');
            $timePaddedWidth = mb_strlen($timePadded, 'UTF-8');
            $dbNamePaddedWidth = mb_strlen($dbNamePadded, 'UTF-8');
            $usedWidth = $markerWidth + $idPaddedWidth + $timePaddedWidth + $dbNamePaddedWidth;
            
            // Calculate available width for query text
            $queryTextWidth = max(1, $availableWidth - $usedWidth);
            $queryText = self::truncate((string)($query['query'] ?? ''), $queryTextWidth);
            
            $rowText = $marker . $idPadded . $timePadded . $dbNamePadded . $queryText;
            
            // Final safety check - ensure total width doesn't exceed available width
            $totalWidth = mb_strlen($rowText, 'UTF-8');
            if ($totalWidth > $availableWidth) {
                // Truncate queryText further if needed
                $queryTextWidth = max(0, $queryTextWidth - ($totalWidth - $availableWidth));
                $queryText = self::truncate((string)($query['query'] ?? ''), $queryTextWidth);
                $rowText = $marker . $idPadded . $timePadded . $dbNamePadded . $queryText;
                // Final truncation as last resort
                if (mb_strlen($rowText, 'UTF-8') > $availableWidth) {
                    $rowText = mb_substr($rowText, 0, $availableWidth, 'UTF-8');
                }
            }
            
            // Ensure we don't output more than available width (strict check)
            $finalWidth = mb_strlen($rowText, 'UTF-8');
            if ($finalWidth > $availableWidth) {
                // Truncate to exact available width
                $rowText = mb_substr($rowText, 0, $availableWidth, 'UTF-8');
            }
            
            // Output text and pad to exact width to ensure we don't overwrite anything
            echo $rowText;
            $actualWidth = mb_strlen($rowText, 'UTF-8');
            if ($actualWidth < $availableWidth) {
                // Pad with spaces to fill exactly availableWidth (prevents any overflow)
                echo str_repeat(' ', $availableWidth - $actualWidth);
            }
            Terminal::reset();
            
            $lastDisplayRow = $displayRow; // Update last displayed row
        }

        // Clear remaining rows after last displayed query (to remove old content)
        // In fullscreen, footer is at $rows, so clear up to $rows - 1
        // In normal mode, clear up to scroll indicator row
        if ($fullscreen) {
            [$totalRows] = Terminal::getSize();
            $maxRow = $totalRows - 1; // Footer is at $totalRows
        } else {
            $maxRow = $content['row'] + $content['height'] - 1; // Scroll indicator row
        }
        
        for ($row = $lastDisplayRow + 1; $row < $maxRow; $row++) {
            Terminal::moveTo($row, $content['col']);
            // Clear only the content area, not the entire line (to preserve right border)
            echo str_repeat(' ', $availableWidth);
        }

        // Show scroll indicator
        // In fullscreen, footer is at $rows, so scroll indicator is at $rows - 1
        // In normal mode, scroll indicator is at content['row'] + content['height'] - 1
        if ($fullscreen) {
            [$totalRows] = Terminal::getSize();
            $scrollIndicatorRow = $totalRows - 1;
        } else {
            $scrollIndicatorRow = $content['row'] + $content['height'] - 1;
        }
        
        if ($scrollOffset > 0 || $endIdx < count($queries)) {
            Terminal::moveTo($scrollIndicatorRow, $content['col']);
            // Clear only the content area, not the entire line (to preserve right border)
            echo str_repeat(' ', $availableWidth);
            Terminal::moveTo($scrollIndicatorRow, $content['col']);
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
        } else {
            // Clear scroll indicator row if no scrolling needed
            Terminal::moveTo($scrollIndicatorRow, $content['col']);
            // Clear only the content area, not the entire line (to preserve right border)
            echo str_repeat(' ', $availableWidth);
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
