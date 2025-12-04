<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\panes;

use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\Terminal;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * Migration Manager pane.
 */
class MigrationManagerPane
{
    /**
     * Render migration manager pane.
     *
     * @param PdoDb $db Database instance
     * @param Layout $layout Layout manager
     * @param int $paneIndex Pane index
     * @param bool $active Whether pane is active
     * @param int $selectedIndex Selected item index (-1 if none)
     * @param int $scrollOffset Scroll offset
     * @param bool $fullscreen Whether in fullscreen mode
     * @param string|null $migrationPath Migration path (optional)
     */
    public static function render(PdoDb $db, Layout $layout, int $paneIndex, bool $active, int $selectedIndex = -1, int $scrollOffset = 0, bool $fullscreen = false, ?string $migrationPath = null): void
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
            $layout->renderBorder($paneIndex, 'Migration Manager', $active);
        }

        // Get migration path from environment or use default
        if ($migrationPath === null) {
            $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
        }

        try {
            $runner = new MigrationRunner($db, $migrationPath);
            $newMigrations = $runner->getNewMigrations();
            $history = $runner->getMigrationHistory();
        } catch (\Throwable $e) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'Error: ' . $e->getMessage();
            return;
        }

        if ($fullscreen) {
            self::renderFullscreen($content, $newMigrations, $history, $selectedIndex, $scrollOffset, $active);
        } else {
            self::renderPreview($content, $newMigrations, $history);
        }
    }

    /**
     * Render preview mode (migration counts).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param array<int, string> $newMigrations New migrations
     * @param array<int, array<string, mixed>> $history Migration history
     */
    protected static function renderPreview(array $content, array $newMigrations, array $history): void
    {
        Terminal::moveTo($content['row'], $content['col']);
        $pendingCount = count($newMigrations);
        $appliedCount = count($history);
        echo "Pending: {$pendingCount} | Applied: {$appliedCount}";
    }

    /**
     * Render fullscreen mode (migration list).
     *
     * @param array{row: int, col: int, height: int, width: int} $content Content area
     * @param array<int, string> $newMigrations New migrations
     * @param array<int, array<string, mixed>> $history Migration history
     * @param int $selectedIndex Selected item index
     * @param int $scrollOffset Scroll offset
     * @param bool $active Whether pane is active
     */
    protected static function renderFullscreen(array $content, array $newMigrations, array $history, int $selectedIndex, int $scrollOffset, bool $active): void
    {
        // Header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        echo 'Migration Manager (Fullscreen)';
        Terminal::reset();

        // Combine all migrations with status
        $allMigrations = [];
        $appliedVersions = array_column($history, 'version');
        foreach ($newMigrations as $version) {
            $allMigrations[] = ['version' => $version, 'status' => 'pending'];
        }
        foreach ($history as $record) {
            $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
        }

        // Sort by version
        usort($allMigrations, function ($a, $b) {
            return strcmp($a['version'], $b['version']);
        });

        if (empty($allMigrations)) {
            Terminal::moveTo($content['row'], $content['col']);
            echo 'No migrations found';
            return;
        }

        // Clamp selected index
        if ($selectedIndex >= count($allMigrations)) {
            $selectedIndex = count($allMigrations) - 1;
        }
        if ($selectedIndex < 0) {
            $selectedIndex = 0;
        }

        $visibleHeight = $content['height'] - 1;
        $startIdx = $scrollOffset;
        $endIdx = min($startIdx + $visibleHeight, count($allMigrations));

        // Display migrations
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $migration = $allMigrations[$i];
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

            $status = $migration['status'] === 'applied' ? '✓' : '⏳';
            if (Terminal::supportsColors() && !$isSelected) {
                if ($migration['status'] === 'applied') {
                    Terminal::color(Terminal::COLOR_GREEN);
                } else {
                    Terminal::color(Terminal::COLOR_YELLOW);
                }
            }

            $marker = $isSelected ? '> ' : '  ';
            $versionText = $marker . $status . ' ' . $migration['version'];
            if (mb_strlen($versionText, 'UTF-8') > $content['width']) {
                $versionText = mb_substr($versionText, 0, $content['width'] - 3, 'UTF-8') . '...';
            }
            echo $versionText;
            Terminal::reset();
        }

        // Show scroll indicator
        if ($scrollOffset > 0 || $endIdx < count($allMigrations)) {
            Terminal::moveTo($content['row'] + $content['height'] - 1, $content['col']);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            $info = '';
            if ($scrollOffset > 0) {
                $info .= '↑ ';
            }
            if ($endIdx < count($allMigrations)) {
                $info .= '↓ ';
            }
            if (count($allMigrations) > $visibleHeight) {
                $info .= '(' . ($scrollOffset + 1) . '-' . $endIdx . '/' . count($allMigrations) . ')';
            }
            echo $info;
            Terminal::reset();
        }
    }
}
