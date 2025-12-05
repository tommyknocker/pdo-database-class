<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * Schema Browser pane.
 */
class SchemaBrowserPane
{
    /**
     * Render schema browser pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     * @param int $selectedIndex Selected item index (-1 if none)
     * @param int $scrollOffset Scroll offset
     * @param bool $fullscreen Whether in fullscreen mode
     * @param string|null $searchFilter Search filter (optional)
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active, int $selectedIndex = -1, int $scrollOffset = 0, bool $fullscreen = false, ?string $searchFilter = null): void
    {
        if ($fullscreen) {
            [$rows, $cols] = Terminal::getSize();
            $content = [
                'row' => 2,
                'col' => 1,
                'height' => $rows - 2,
                'width' => $cols,
            ];
        } else {
            $content = $layout->getContentArea($paneIndex);
        }

        // Clear content area first
        for ($i = 0; $i < $content['height']; $i++) {
            Terminal::moveTo($content['row'] + $i, $content['col']);
            Terminal::clearLine();
        }

        // Render border after clearing content
        if (!$fullscreen) {
            $layout->renderBorder($paneIndex, 'Schema Browser', $active);
        }

        try {
            $tables = TableManager::listTables($db);
        } catch (\Throwable $e) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'Error: ' . $e->getMessage();
            return;
        }

        // Filter tables by search if provided
        if ($searchFilter !== null && $searchFilter !== '') {
            $tables = array_filter($tables, function ($table) use ($searchFilter) {
                return stripos($table, $searchFilter) !== false;
            });
            $tables = array_values($tables); // Re-index
        }

        if (empty($tables)) {
            Terminal::moveTo($content['row'], $content['col']);
            if ($searchFilter !== null && $searchFilter !== '') {
                echo 'No tables found matching: ' . $searchFilter;
            } else {
                echo 'No tables found';
            }
            return;
        }

        if ($fullscreen) {
            self::renderFullscreen($db, $content, $tables, $selectedIndex, $scrollOffset, $active, $searchFilter);
        } else {
            self::renderPreview($content, $tables);
        }
    }

    /**
     * Render preview mode (table count).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param array<int, string> $tables List of tables
     */
    protected static function renderPreview(array $content, array $tables): void
    {
        Terminal::moveTo($content['row'], $content['col']);
        $count = count($tables);
        echo "Tables: {$count}";
    }

    /**
     * Render fullscreen mode (tree navigation).
     *
     * @param PdoDb $db Database instance
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param array<int, string> $tables List of tables
     * @param int $selectedIndex Selected item index
     * @param int $scrollOffset Scroll offset
     * @param bool $active Whether pane is active
     * @param string|null $searchFilter Search filter
     */
    protected static function renderFullscreen(PdoDb $db, array $content, array $tables, int $selectedIndex, int $scrollOffset, bool $active, ?string $searchFilter = null): void
    {
        // Header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        $headerText = 'Schema Browser (Fullscreen)';
        if ($searchFilter !== null && $searchFilter !== '') {
            $headerText .= ' [Search: ' . $searchFilter . ']';
        }
        echo $headerText;
        Terminal::reset();

        // Clamp selected index
        if ($selectedIndex >= count($tables)) {
            $selectedIndex = count($tables) - 1;
        }
        if ($selectedIndex < 0) {
            $selectedIndex = 0;
        }

        $visibleHeight = $content['height'] - 1;
        $startIdx = $scrollOffset;
        $endIdx = min($startIdx + $visibleHeight, count($tables));

        // Display tables
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $table = $tables[$i];
            $displayRow = $content['row'] + ($i - $startIdx);

            if ($displayRow > $content['row'] + $content['height'] - 1) {
                break;
            }

            Terminal::moveTo($displayRow, $content['col']);

            $isSelected = $active && $selectedIndex === $i;
            if ($isSelected && Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_CYAN);
                Terminal::color(Terminal::COLOR_BLACK);
            }

            $marker = $isSelected ? '> ' : '  ';
            $tableText = $marker . $table;
            if (mb_strlen($tableText, 'UTF-8') > $content['width']) {
                $tableText = mb_substr($tableText, 0, $content['width'] - 3, 'UTF-8') . '...';
            }
            echo $tableText;
            Terminal::reset();
        }

        // Show scroll indicator
        if ($scrollOffset > 0 || $endIdx < count($tables)) {
            Terminal::moveTo($content['row'] + $content['height'] - 1, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            $info = '';
            if ($scrollOffset > 0) {
                $info .= '↑ ';
            }
            if ($endIdx < count($tables)) {
                $info .= '↓ ';
            }
            if (count($tables) > $visibleHeight) {
                $info .= '(' . ($scrollOffset + 1) . '-' . $endIdx . '/' . count($tables) . ')';
            }
            echo $info;
            Terminal::reset();
        }
    }
}
