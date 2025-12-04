<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * Server Variables pane.
 */
class ServerVariablesPane
{
    /**
     * Important performance variables to highlight.
     *
     * @var array<int, string>
     */
    protected static array $importantVars = [
        'max_connections',
        'innodb_buffer_pool_size',
        'query_cache_size',
        'tmp_table_size',
        'max_heap_table_size',
        'shared_buffers',
        'work_mem',
        'maintenance_work_mem',
        'effective_cache_size',
    ];

    /**
     * Render server variables pane.
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
            $layout->renderBorder($paneIndex, 'Server Variables', $active);
        }

        try {
            $dialect = $db->schema()->getDialect();
            $variables = $dialect->getServerVariables($db);
        } catch (\Throwable $e) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'Error: ' . $e->getMessage();
            return;
        }

        if (empty($variables)) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'No variables found';
            return;
        }

        // Filter by search if provided
        if ($searchFilter !== null && $searchFilter !== '') {
            $variables = array_filter($variables, function ($var) use ($searchFilter) {
                $name = $var['name'] ?? '';
                return stripos($name, $searchFilter) !== false;
            });
            $variables = array_values($variables); // Re-index
        }

        if ($fullscreen) {
            self::renderFullscreen($content, $variables, $selectedIndex, $scrollOffset, $active, $searchFilter);
        } else {
            self::renderPreview($content, $variables);
        }
    }

    /**
     * Render preview mode (important variables count).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param array<int, array<string, mixed>> $variables List of variables
     */
    protected static function renderPreview(array $content, array $variables): void
    {
        Terminal::moveTo($content['row'], $content['col']);
        $importantCount = 0;
        $varNames = array_column($variables, 'name');
        foreach (self::$importantVars as $importantVar) {
            if (in_array($importantVar, $varNames, true)) {
                $importantCount++;
            }
        }
        $totalCount = count($variables);
        echo "Variables: {$totalCount} (Important: {$importantCount})";
    }

    /**
     * Render fullscreen mode (variable list with search).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param array<int, array<string, mixed>> $variables List of variables
     * @param int $selectedIndex Selected item index
     * @param int $scrollOffset Scroll offset
     * @param bool $active Whether pane is active
     * @param string|null $searchFilter Search filter
     */
    protected static function renderFullscreen(array $content, array $variables, int $selectedIndex, int $scrollOffset, bool $active, ?string $searchFilter): void
    {
        // Header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        $headerText = 'Server Variables (Fullscreen)';
        if ($searchFilter !== null && $searchFilter !== '') {
            $headerText .= ' [Search: ' . $searchFilter . ']';
        }
        echo $headerText;
        Terminal::reset();

        // Clamp selected index
        if ($selectedIndex >= count($variables)) {
            $selectedIndex = count($variables) - 1;
        }
        if ($selectedIndex < 0) {
            $selectedIndex = 0;
        }

        $visibleHeight = $content['height'] - 1;
        $startIdx = $scrollOffset;
        $endIdx = min($startIdx + $visibleHeight, count($variables));

        // Display variables
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $variable = $variables[$i];
            $displayRow = $content['row'] + ($i - $startIdx);

            if ($displayRow > $content['row'] + $content['height'] - 1) {
                break;
            }

            Terminal::moveTo($displayRow, $content['col']);

            $isSelected = $active && $selectedIndex === $i;
            $isImportant = in_array($variable['name'] ?? '', self::$importantVars, true);

            if ($isSelected && Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_CYAN);
                Terminal::color(Terminal::COLOR_BLACK);
            } elseif ($isImportant && Terminal::supportsColors() && !$isSelected) {
                Terminal::color(Terminal::COLOR_YELLOW);
                Terminal::bold();
            }

            $marker = $isSelected ? '> ' : '  ';
            $name = $variable['name'] ?? '';
            $value = $variable['value'] ?? '';
            $varText = $marker . $name . ': ' . $value;
            if (mb_strlen($varText, 'UTF-8') > $content['width']) {
                $varText = mb_substr($varText, 0, $content['width'] - 3, 'UTF-8') . '...';
            }
            echo $varText;
            Terminal::reset();
        }

        // Show scroll indicator
        if ($scrollOffset > 0 || $endIdx < count($variables)) {
            Terminal::moveTo($content['row'] + $content['height'] - 1, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            $info = '';
            if ($scrollOffset > 0) {
                $info .= '↑ ';
            }
            if ($endIdx < count($variables)) {
                $info .= '↓ ';
            }
            if (count($variables) > $visibleHeight) {
                $info .= '(' . ($scrollOffset + 1) . '-' . $endIdx . '/' . count($variables) . ')';
            }
            echo $info;
            Terminal::reset();
        }
    }
}
