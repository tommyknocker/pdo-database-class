<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\PdoDb;

/**
 * SQL Scratchpad pane.
 */
class SqlScratchpadPane
{
    /**
     * Render SQL scratchpad pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     * @param string|null $lastQuery Last executed query (optional)
     * @param array<int, string>|null $queryHistory Query history (optional)
     * @param bool $fullscreen Whether in fullscreen mode
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active, ?string $lastQuery = null, ?array $queryHistory = null, bool $fullscreen = false): void
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
            $layout->renderBorder($paneIndex, 'SQL Scratchpad', $active);
        }

        if ($fullscreen) {
            self::renderFullscreen($content, $lastQuery, $queryHistory);
        } else {
            self::renderPreview($content, $lastQuery, $queryHistory);
        }
    }

    /**
     * Render preview mode (last query or history count).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param string|null $lastQuery Last executed query
     * @param array<int, string>|null $queryHistory Query history
     */
    protected static function renderPreview(array $content, ?string $lastQuery, ?array $queryHistory): void
    {
        Terminal::moveTo($content['row'], $content['col']);
        if ($lastQuery !== null) {
            $preview = mb_substr($lastQuery, 0, min(50, mb_strlen($lastQuery, 'UTF-8')), 'UTF-8');
            if (mb_strlen($lastQuery, 'UTF-8') > 50) {
                $preview .= '...';
            }
            echo 'Last: ' . $preview;
        } elseif ($queryHistory !== null) {
            $count = count($queryHistory);
            echo "History: {$count} queries";
        } else {
            echo 'No queries executed';
        }
    }

    /**
     * Render fullscreen mode (SQL editor and results).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param string|null $lastQuery Last executed query
     * @param array<int, string>|null $queryHistory Query history
     */
    protected static function renderFullscreen(array $content, ?string $lastQuery, ?array $queryHistory): void
    {
        // Header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        echo 'SQL Scratchpad (Fullscreen)';
        Terminal::reset();

        Terminal::moveTo($content['row'], $content['col']);
        echo 'SQL Editor - Enter query and press Ctrl+Enter or F5 to execute';
        Terminal::moveTo($content['row'] + 1, $content['col']);
        if ($lastQuery !== null) {
            $queryPreview = mb_substr($lastQuery, 0, min($content['width'] - 2, mb_strlen($lastQuery, 'UTF-8')), 'UTF-8');
            if (mb_strlen($lastQuery, 'UTF-8') > $content['width'] - 2) {
                $queryPreview .= '...';
            }
            echo 'Last query: ' . $queryPreview;
        } else {
            echo 'No query executed yet';
        }
    }
}

