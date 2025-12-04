<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

/**
 * Layout manager for calculating pane dimensions and rendering borders.
 */
class Layout
{
    /**
     * Number of panes (8 panes across 2 screens, 2x2 grid per screen).
     */
    public const PANE_COUNT = 8;

    /**
     * Pane indices.
     */
    public const PANE_QUERIES = 0;
    public const PANE_CONNECTIONS = 1;
    public const PANE_CACHE = 2;
    public const PANE_METRICS = 3;
    public const PANE_SCHEMA = 4;
    public const PANE_MIGRATIONS = 5;
    public const PANE_VARIABLES = 6;
    public const PANE_SCRATCHPAD = 7;

    /**
     * Terminal rows.
     *
     * @var int
     */
    protected int $rows;

    /**
     * Terminal columns.
     *
     * @var int
     */
    protected int $cols;

    /**
     * Current screen (0 for panes 0-3, 1 for panes 4-7).
     *
     * @var int
     */
    protected int $currentScreen = 0;

    /**
     * Pane dimensions.
     *
     * @var array<int, array{row: int, col: int, height: int, width: int}>
     */
    protected array $panes = [];

    /**
     * Create layout manager.
     *
     * @param int $rows Terminal rows
     * @param int $cols Terminal columns
     */
    public function __construct(int $rows, int $cols)
    {
        $this->rows = $rows;
        $this->cols = $cols;
        $this->calculatePanes();
    }

    /**
     * Calculate pane dimensions for 2x2 grid (both screens use same layout).
     */
    protected function calculatePanes(): void
    {
        // Reserve space for header and footer
        $headerHeight = 1;
        $footerHeight = 1;
        $availableRows = $this->rows - $headerHeight - $footerHeight;

        // Calculate pane dimensions (2x2 grid)
        $paneHeight = (int)floor($availableRows / 2);
        $paneWidth = (int)floor($this->cols / 2);

        // Left pane width: paneWidth (includes borders)
        // Right pane width: remaining space to fill to $this->cols
        $leftPaneWidth = $paneWidth;
        $rightPaneCol = $paneWidth + 1;
        $rightPaneWidth = $this->cols - $rightPaneCol + 1;

        // Screen 1: Panes 0-3
        // Top-left: Active Queries
        $this->panes[self::PANE_QUERIES] = [
            'row' => $headerHeight + 1,
            'col' => 1,
            'height' => $paneHeight,
            'width' => $leftPaneWidth,
        ];

        // Top-right: Connection Pool
        $this->panes[self::PANE_CONNECTIONS] = [
            'row' => $headerHeight + 1,
            'col' => $rightPaneCol,
            'height' => $paneHeight,
            'width' => $rightPaneWidth,
        ];

        // Bottom-left: Cache Stats
        $this->panes[self::PANE_CACHE] = [
            'row' => $headerHeight + $paneHeight + 1,
            'col' => 1,
            'height' => $availableRows - $paneHeight,
            'width' => $leftPaneWidth,
        ];

        // Bottom-right: Server Metrics
        $this->panes[self::PANE_METRICS] = [
            'row' => $headerHeight + $paneHeight + 1,
            'col' => $rightPaneCol,
            'height' => $availableRows - $paneHeight,
            'width' => $rightPaneWidth,
        ];

        // Screen 2: Panes 4-7 (same layout as screen 1)
        // Top-left: Schema Browser
        $this->panes[self::PANE_SCHEMA] = [
            'row' => $headerHeight + 1,
            'col' => 1,
            'height' => $paneHeight,
            'width' => $leftPaneWidth,
        ];

        // Top-right: Migration Manager
        $this->panes[self::PANE_MIGRATIONS] = [
            'row' => $headerHeight + 1,
            'col' => $rightPaneCol,
            'height' => $paneHeight,
            'width' => $rightPaneWidth,
        ];

        // Bottom-left: Server Variables
        $this->panes[self::PANE_VARIABLES] = [
            'row' => $headerHeight + $paneHeight + 1,
            'col' => 1,
            'height' => $availableRows - $paneHeight,
            'width' => $leftPaneWidth,
        ];

        // Bottom-right: SQL Scratchpad
        $this->panes[self::PANE_SCRATCHPAD] = [
            'row' => $headerHeight + $paneHeight + 1,
            'col' => $rightPaneCol,
            'height' => $availableRows - $paneHeight,
            'width' => $rightPaneWidth,
        ];
    }

    /**
     * Get pane dimensions.
     *
     * @param int $paneIndex Pane index
     *
     * @return array{row: int, col: int, height: int, width: int}
     */
    public function getPane(int $paneIndex): array
    {
        return $this->panes[$paneIndex] ?? [
            'row' => 1,
            'col' => 1,
            'height' => 10,
            'width' => 40,
        ];
    }

    /**
     * Get terminal rows.
     *
     * @return int
     */
    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * Get terminal columns.
     *
     * @return int
     */
    public function getCols(): int
    {
        return $this->cols;
    }

    /**
     * Render pane border.
     *
     * @param int $paneIndex Pane index
     * @param string $title Pane title
     * @param bool $active Whether pane is active (highlighted)
     */
    public function renderBorder(int $paneIndex, string $title, bool $active = false): void
    {
        $pane = $this->getPane($paneIndex);
        $row = $pane['row'];
        $col = $pane['col'];
        $height = $pane['height'];
        $width = $pane['width'];

        // Set color for active pane
        if ($active && Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
            Terminal::bold();
        }

        // Top border with title
        Terminal::moveTo($row, $col);
        echo '┌' . str_repeat('─', $width - 2) . '┐';
        Terminal::moveTo($row + 1, $col);
        // Center the title
        $availableWidth = $width - 2; // Exclude left and right borders
        $titleLength = mb_strlen($title, 'UTF-8');
        $truncatedTitle = mb_substr($title, 0, $availableWidth - 2, 'UTF-8'); // Leave 1 space on each side
        $truncatedLength = mb_strlen($truncatedTitle, 'UTF-8');
        $leftPadding = (int)floor(($availableWidth - $truncatedLength) / 2);
        $rightPadding = $availableWidth - $truncatedLength - $leftPadding;
        echo '│' . str_repeat(' ', $leftPadding) . $truncatedTitle . str_repeat(' ', $rightPadding) . '│';

        // Side borders
        for ($i = 2; $i < $height; $i++) {
            Terminal::moveTo($row + $i, $col);
            echo '│' . str_repeat(' ', $width - 2) . '│';
        }

        // Bottom border
        Terminal::moveTo($row + $height - 1, $col);
        echo '└' . str_repeat('─', $width - 2) . '┘';

        // Reset formatting
        Terminal::reset();
    }

    /**
     * Get content area for pane (inside border).
     *
     * @param int $paneIndex Pane index
     *
     * @return array{row: int, col: int, height: int, width: int}
     */
    public function getContentArea(int $paneIndex): array
    {
        $pane = $this->getPane($paneIndex);

        return [
            'row' => $pane['row'] + 2, // Skip border and title
            'col' => $pane['col'] + 1, // Skip left border
            'height' => $pane['height'] - 3, // Exclude borders and title
            'width' => $pane['width'] - 2, // Exclude left and right borders
        ];
    }

    /**
     * Get current screen (0 for panes 0-3, 1 for panes 4-7).
     *
     * @return int
     */
    public function getCurrentScreen(): int
    {
        return $this->currentScreen;
    }

    /**
     * Set current screen (0 for panes 0-3, 1 for panes 4-7).
     *
     * @param int $screen Screen index (0 or 1)
     */
    public function setCurrentScreen(int $screen): void
    {
        if ($screen < 0 || $screen > 1) {
            return;
        }
        $this->currentScreen = $screen;
    }
}
