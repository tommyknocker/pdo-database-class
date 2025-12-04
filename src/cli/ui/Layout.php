<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

/**
 * Layout manager for calculating pane dimensions and rendering borders.
 */
class Layout
{
    /**
     * Number of panes (2x2 grid).
     */
    public const PANE_COUNT = 4;

    /**
     * Pane indices.
     */
    public const PANE_QUERIES = 0;
    public const PANE_CONNECTIONS = 1;
    public const PANE_CACHE = 2;
    public const PANE_METRICS = 3;

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
     * Calculate pane dimensions for 2x2 grid.
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
        $titleText = ' ' . substr($title, 0, $width - 4) . ' ';
        $titlePadding = str_repeat(' ', max(0, $width - 2 - strlen($titleText)));
        echo '│' . $titleText . $titlePadding . '│';

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
}
