<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\cli\ui\panes\ActiveQueriesPane;
use tommyknocker\pdodb\cli\ui\panes\ConnectionPoolPane;
use tommyknocker\pdodb\cli\ui\panes\MigrationManagerPane;
use tommyknocker\pdodb\cli\ui\panes\SchemaBrowserPane;
use tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane;
use tommyknocker\pdodb\cli\ui\panes\ServerVariablesPane;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * The fullscreen and detail screens of the TUI dashboard. {@see Dashboard} runs the loop and the overview; this class
 * draws what a pane shows when it takes the whole terminal, or when one of its items is opened.
 */
class DashboardViews
{
    public function __construct(
        protected PdoDb $db,
        protected DashboardState $state,
        protected SqlScratchpad $scratchpad,
    ) {
    }

    /**
     * Render fullscreen pane.
     */
    public function renderFullscreen(): void
    {
        [$rows, $cols] = Terminal::getSize();

        if ($this->state->fullscreenPane === Layout::PANE_QUERIES) {
            // Don't clear entire screen for queries pane - only clear header and footer
            // Content area is cleared by ActiveQueriesPane::render() to avoid flickering

            // Calculate scroll offset for fullscreen
            $queries = $this->state->cachedQueries;
            // Header: row 1, Table header: row 2, Footer: row $rows
            // Content area: row 2, height = $rows - 2
            // Visible height for queries list = $rows - 2 - 1 (table header)
            $visibleHeight = $rows - 3;
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_QUERIES];

            if ($selectedIdx >= 0 && count($queries) > 0) {
                // Clamp selected index
                if ($selectedIdx >= count($queries)) {
                    $selectedIdx = count($queries) - 1;
                    $this->state->selectedIndex[Layout::PANE_QUERIES] = $selectedIdx;
                }

                // Only adjust scrollOffset if selected item is not visible
                $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_QUERIES];
                if ($relativeIndex < 0) {
                    // Selected item is above visible area - scroll up
                    $this->state->scrollOffset[Layout::PANE_QUERIES] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    // Selected item is below visible area - scroll down
                    $this->state->scrollOffset[Layout::PANE_QUERIES] = max(0, $selectedIdx - $visibleHeight + 1);
                }
                // If relativeIndex is in range [0, visibleHeight), keep current scrollOffset
            }

            // Render header (clear only header line)
            Terminal::moveTo(1, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            Stdout::write('Active Queries (Fullscreen)');
            Terminal::reset();

            // Render pane in fullscreen
            $dummyLayout = new Layout($rows, $cols);
            ActiveQueriesPane::render(
                $this->db,
                $dummyLayout,
                Layout::PANE_QUERIES,
                true,
                $this->state->selectedIndex[Layout::PANE_QUERIES],
                $this->state->scrollOffset[Layout::PANE_QUERIES],
                true,
                $this->state->cachedQueries
            );

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->state->fullscreenPane === Layout::PANE_CONNECTIONS) {
            // Calculate scroll offset for fullscreen
            $connectionsData = $this->state->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            // Header: row 1
            // Summary: rows 2-4 (3 rows)
            // Spacing: row 5
            // "Connections:" label: row 6
            // Table header: row 7
            // Connections list starts: row 8
            // Footer: row $rows
            // Visible height for connections list = $rows - 1 (footer) - 7 (header + summary + spacing + label + table header) = $rows - 8
            $summaryHeight = 3;
            $spacingHeight = 2; // Spacing before "Connections:" label
            $labelHeight = 1;
            $tableHeaderHeight = 1;
            $visibleHeight = $rows - 1 - ($summaryHeight + $spacingHeight + $labelHeight + $tableHeaderHeight);
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_CONNECTIONS];

            if ($selectedIdx >= 0 && count($connections) > 0) {
                // Clamp selected index
                if ($selectedIdx >= count($connections)) {
                    $selectedIdx = count($connections) - 1;
                    $this->state->selectedIndex[Layout::PANE_CONNECTIONS] = $selectedIdx;
                }

                // Only adjust scrollOffset if selected item is not visible
                $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_CONNECTIONS];
                if ($relativeIndex < 0) {
                    // Selected item is above visible area - scroll up
                    $this->state->scrollOffset[Layout::PANE_CONNECTIONS] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    // Selected item is below visible area - scroll down
                    $this->state->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $selectedIdx - $visibleHeight + 1);
                }
                // If relativeIndex is in range [0, visibleHeight), keep current scrollOffset
            }

            // Render header
            Terminal::moveTo(1, 1);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            Stdout::write('Connection Pool (Fullscreen)');
            Terminal::reset();

            // Render pane in fullscreen
            $dummyLayout = new Layout($rows, $cols);
            ConnectionPoolPane::render(
                $this->db,
                $dummyLayout,
                Layout::PANE_CONNECTIONS,
                true,
                $this->state->selectedIndex[Layout::PANE_CONNECTIONS],
                $this->state->scrollOffset[Layout::PANE_CONNECTIONS],
                true,
                $this->state->cachedConnections
            );

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->state->fullscreenPane === Layout::PANE_CACHE) {
            // Render header
            Terminal::moveTo(1, 1);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            Stdout::write('Cache Stats (Fullscreen)');
            Terminal::reset();

            // Render cache stats in fullscreen
            $this->renderCacheStatsFullscreen();

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->state->fullscreenPane === Layout::PANE_METRICS) {
            // Render header
            Terminal::moveTo(1, 1);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            Stdout::write('Server Metrics (Fullscreen)');
            Terminal::reset();

            // Render server metrics in fullscreen
            $this->renderServerMetricsFullscreen();

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->state->fullscreenPane === Layout::PANE_SCHEMA) {
            $this->renderSchemaBrowserFullscreen();
        } elseif ($this->state->fullscreenPane === Layout::PANE_MIGRATIONS) {
            $this->renderMigrationsFullscreen();
        } elseif ($this->state->fullscreenPane === Layout::PANE_VARIABLES) {
            $this->renderVariablesFullscreen();
        } elseif ($this->state->fullscreenPane === Layout::PANE_SCRATCHPAD) {
            $this->scratchpad->renderFullscreen();
        }

        flush();
    }

    /**
     * Render detail view for selected query or connection.
     */
    public function renderDetailView(): void
    {
        [$rows, $cols] = Terminal::getSize();
        Terminal::clear();

        if ($this->state->detailViewPane === Layout::PANE_QUERIES) {
            $queries = $this->state->cachedQueries;
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_QUERIES];

            if ($selectedIdx >= 0 && $selectedIdx < count($queries)) {
                $query = $queries[$selectedIdx];

                // Render header
                Terminal::moveTo(1, 1);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                    Terminal::color(Terminal::COLOR_CYAN);
                }
                Stdout::write('Query Details (Press any key to close)');
                Terminal::reset();

                // Render query details
                $row = 3;
                $col = 1;
                $width = $cols - 2;

                // ID
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write('ID: ');
                Terminal::reset();
                Stdout::write($query['id'] ?? $query['pid'] ?? $query['session_id'] ?? 'N/A');
                $row++;

                // User
                if (isset($query['user']) || isset($query['usename']) || isset($query['login'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('User: ');
                    Terminal::reset();
                    Stdout::write($query['user'] ?? $query['usename'] ?? $query['login'] ?? 'N/A');
                    $row++;
                }

                // Database
                if (isset($query['db']) || isset($query['database']) || isset($query['datname'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('Database: ');
                    Terminal::reset();
                    Stdout::write($query['db'] ?? $query['database'] ?? $query['datname'] ?? 'N/A');
                    $row++;
                }

                // Time/Duration
                if (isset($query['time']) || isset($query['duration'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('Time: ');
                    Terminal::reset();
                    Stdout::write($query['time'] ?? $query['duration'] ?? 'N/A');
                    $row++;
                }

                // State (if available)
                if (isset($query['state']) || isset($query['status'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('State: ');
                    Terminal::reset();
                    Stdout::write($query['state'] ?? $query['status'] ?? 'N/A');
                    $row++;
                }

                $row++;

                // Full query text
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write('Query:');
                Terminal::reset();
                $row++;

                $queryText = (string)($query['query'] ?? '');
                if (!empty($queryText)) {
                    // Word wrap query text
                    $lines = explode("\n", wordwrap($queryText, $width, "\n", true));
                    foreach ($lines as $line) {
                        if ($row >= $rows - 1) {
                            break; // Don't overwrite footer
                        }
                        Terminal::moveTo($row, $col);
                        Stdout::write($line);
                        $row++;
                    }
                } else {
                    Terminal::moveTo($row, $col);
                    Stdout::write('(No query text available)');
                }
            }
        } elseif ($this->state->detailViewPane === Layout::PANE_CONNECTIONS) {
            $connectionsData = $this->state->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_CONNECTIONS];

            if ($selectedIdx >= 0 && $selectedIdx < count($connections)) {
                $conn = $connections[$selectedIdx];

                // Render header
                Terminal::moveTo(1, 1);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                    Terminal::color(Terminal::COLOR_CYAN);
                }
                Stdout::write('Connection Details (Press any key to close)');
                Terminal::reset();

                // Render connection details
                $row = 3;
                $col = 1;
                $width = $cols - 2;

                // ID
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write('ID: ');
                Terminal::reset();
                Stdout::write($conn['id'] ?? $conn['pid'] ?? $conn['session_id'] ?? 'N/A');
                $row++;

                // User
                if (isset($conn['user']) || isset($conn['usename']) || isset($conn['login'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('User: ');
                    Terminal::reset();
                    Stdout::write($conn['user'] ?? $conn['usename'] ?? $conn['login'] ?? 'N/A');
                    $row++;
                }

                // Database
                if (isset($conn['db']) || isset($conn['database']) || isset($conn['datname'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('Database: ');
                    Terminal::reset();
                    Stdout::write($conn['db'] ?? $conn['database'] ?? $conn['datname'] ?? 'N/A');
                    $row++;
                }

                // Host (if available)
                if (isset($conn['host']) || isset($conn['client_addr'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('Host: ');
                    Terminal::reset();
                    Stdout::write($conn['host'] ?? $conn['client_addr'] ?? 'N/A');
                    $row++;
                }

                // State (if available)
                if (isset($conn['state']) || isset($conn['status'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('State: ');
                    Terminal::reset();
                    Stdout::write($conn['state'] ?? $conn['status'] ?? 'N/A');
                    $row++;
                }

                // Application name (if available, e.g., PostgreSQL)
                if (isset($conn['application_name'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('Application: ');
                    Terminal::reset();
                    Stdout::write($conn['application_name']);
                    $row++;
                }

                // Backend start (if available, e.g., PostgreSQL)
                if (isset($conn['backend_start'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    Stdout::write('Backend Start: ');
                    Terminal::reset();
                    Stdout::write($conn['backend_start']);
                    $row++;
                }

                // Show all other available fields
                $shownFields = ['id', 'pid', 'session_id', 'user', 'usename', 'login', 'db', 'database', 'datname', 'host', 'client_addr', 'state', 'status', 'application_name', 'backend_start'];
                foreach ($conn as $key => $value) {
                    if (!in_array($key, $shownFields, true) && $value !== null && $value !== '') {
                        if ($row >= $rows - 1) {
                            break; // Don't overwrite footer
                        }
                        Terminal::moveTo($row, $col);
                        if (Terminal::supportsColors()) {
                            Terminal::bold();
                        }
                        Stdout::write(ucfirst(str_replace('_', ' ', $key)) . ': ');
                        Terminal::reset();
                        Stdout::write(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string)$value);
                        $row++;
                    }
                }
            }
        } elseif ($this->state->detailViewPane === Layout::PANE_MIGRATIONS) {
            $this->renderMigrationDetailView();
        } elseif ($this->state->detailViewPane === Layout::PANE_SCHEMA) {
            $this->renderTableDetailView();
        }

        // Render footer
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }
        if ($this->state->detailViewPane === Layout::PANE_MIGRATIONS) {
            $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
            $runner = new MigrationRunner($this->db, $migrationPath);
            $newMigrations = $runner->getNewMigrations();
            $history = $runner->getMigrationHistory();
            $allMigrations = [];
            foreach ($newMigrations as $version) {
                $allMigrations[] = ['version' => $version, 'status' => 'pending'];
            }
            foreach ($history as $record) {
                $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
            }
            usort($allMigrations, function ($a, $b) {
                return strcmp($a['version'], $b['version']);
            });
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_MIGRATIONS];
            if ($selectedIdx >= 0 && $selectedIdx < count($allMigrations)) {
                $migration = $allMigrations[$selectedIdx];
                $status = $migration['status'];
                if ($status === 'pending') {
                    Stdout::write('a:Apply | Any key:Close');
                } else {
                    Stdout::write('r:Rollback | Any key:Close');
                }
            } else {
                Stdout::write('Press any key to close');
            }
        } else {
            Stdout::write('Press any key to close');
        }
        Terminal::reset();

        flush();
    }

    /**
     * Render footer for fullscreen mode.
     */
    protected function renderFullscreenFooter(): void
    {
        [$rows, $cols] = Terminal::getSize();
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();

        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }

        // Build fullscreen footer help text
        $pane = $this->state->fullscreenPane;
        $help = '';

        if ($pane === Layout::PANE_QUERIES) {
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:Details | x:Kill | Esc:Exit';
        } elseif ($pane === Layout::PANE_CONNECTIONS) {
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:Details | x:Kill | Esc:Exit';
        } elseif ($pane === Layout::PANE_CACHE || $pane === Layout::PANE_METRICS) {
            $help = 'Esc:Exit';
        } elseif ($pane === Layout::PANE_SCHEMA) {
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:Table Details | /:Search | Esc:Exit';
        } elseif ($pane === Layout::PANE_MIGRATIONS) {
            // Get current migration status to show appropriate commands
            $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
            $runner = new MigrationRunner($this->db, $migrationPath);
            $newMigrations = $runner->getNewMigrations();
            $history = $runner->getMigrationHistory();
            $allMigrations = [];
            foreach ($newMigrations as $version) {
                $allMigrations[] = ['version' => $version, 'status' => 'pending'];
            }
            foreach ($history as $record) {
                $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
            }
            usort($allMigrations, function ($a, $b) {
                return strcmp($a['version'], $b['version']);
            });
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_MIGRATIONS];
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:View';
            if ($selectedIdx >= 0 && $selectedIdx < count($allMigrations)) {
                $migration = $allMigrations[$selectedIdx];
                if ($migration['status'] === 'pending') {
                    $help .= ' | a:Apply';
                } else {
                    $help .= ' | r:Rollback';
                }
            }
            $help .= ' | Esc:Exit';
        } elseif ($pane === Layout::PANE_VARIABLES) {
            $help = '↑↓:Select | PgUp/PgDn:Page | /:Search | Esc:Exit';
        } elseif ($pane === Layout::PANE_SCRATCHPAD) {
            $help = 'F5:Execute | Tab:Switch | F2:TX | F3:Save | F4:Export | Esc:Exit';
        } else {
            $help = 'Esc:Exit';
        }

        // Truncate if too long to fit in one line
        if (mb_strlen($help, 'UTF-8') > $cols) {
            $help = mb_substr($help, 0, $cols, 'UTF-8');
        }

        Stdout::write($help);
        Terminal::reset();
    }

    /**
     * Render cache stats in fullscreen mode with additional details.
     */
    protected function renderCacheStatsFullscreen(): void
    {
        [$rows, $cols] = Terminal::getSize();

        // Clear content area (skip header row 1 and footer row $rows)
        for ($i = 2; $i < $rows; $i++) {
            Terminal::moveTo($i, 1);
            Terminal::clearLine();
        }

        $cacheManager = $this->db->getCacheManager();

        if ($cacheManager === null) {
            Terminal::moveTo(3, 1);
            Stdout::write('Cache not enabled');
            return;
        }

        $stats = $cacheManager->getStats();
        $row = 3;
        $col = 1;
        $width = $cols - 2;

        // Status
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Status: ');
        Terminal::reset();
        Stdout::write(($stats['enabled'] ?? false) ? 'Enabled' : 'Disabled');
        $row++;

        // Type
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Type: ');
        Terminal::reset();
        Stdout::write($stats['type'] ?? 'unknown');
        $row++;

        // Hits
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Hits: ');
        Terminal::reset();
        $hits = (int)($stats['hits'] ?? 0);
        Stdout::write(number_format($hits));
        $row++;

        // Misses
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Misses: ');
        Terminal::reset();
        $misses = (int)($stats['misses'] ?? 0);
        Stdout::write(number_format($misses));
        $row++;

        // Hit Rate
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Hit Rate: ');
        Terminal::reset();
        $hitRate = (float)($stats['hit_rate'] ?? 0);

        // Color code hit rate
        if (Terminal::supportsColors()) {
            if ($hitRate >= 80) {
                Terminal::color(Terminal::COLOR_GREEN);
            } elseif ($hitRate >= 50) {
                Terminal::color(Terminal::COLOR_YELLOW);
            } else {
                Terminal::color(Terminal::COLOR_RED);
            }
        }

        Stdout::write(number_format($hitRate, 2) . '%');
        Terminal::reset();
        $row++;

        // Total Requests
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Total Requests: ');
        Terminal::reset();
        $total = (int)($stats['total_requests'] ?? 0);
        Stdout::write(number_format($total));
        $row++;

        // Sets
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Sets: ');
        Terminal::reset();
        $sets = (int)($stats['sets'] ?? 0);
        Stdout::write(number_format($sets));
        $row++;

        // Deletes
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Deletes: ');
        Terminal::reset();
        $deletes = (int)($stats['deletes'] ?? 0);
        Stdout::write(number_format($deletes));
        $row++;

        // TTL
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        Stdout::write('Default TTL: ');
        Terminal::reset();
        $ttl = (int)($stats['default_ttl'] ?? 0);
        Stdout::write($ttl . 's');
        $row++;

        // Additional details if available
        $row++;
        if (isset($stats['size']) || isset($stats['memory_usage']) || isset($stats['keys_count'])) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            Stdout::write('Additional Information:');
            Terminal::reset();
            $row++;

            // Size/Memory Usage
            if (isset($stats['size']) || isset($stats['memory_usage'])) {
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write('Size/Memory: ');
                Terminal::reset();
                $size = $stats['size'] ?? $stats['memory_usage'] ?? 0;
                if (is_numeric($size)) {
                    Stdout::write(ServerMetricsPane::formatBytes((int)$size));
                } else {
                    Stdout::write((string)$size);
                }
                $row++;
            }

            // Keys Count
            if (isset($stats['keys_count'])) {
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write('Keys Count: ');
                Terminal::reset();
                Stdout::write(number_format((int)$stats['keys_count']));
                $row++;
            }
        }

        // Show all other available stats
        $shownFields = ['enabled', 'type', 'hits', 'misses', 'hit_rate', 'total_requests', 'sets', 'deletes', 'default_ttl', 'size', 'memory_usage', 'keys_count'];
        foreach ($stats as $key => $value) {
            if (!in_array($key, $shownFields, true) && $value !== null && $value !== '') {
                if ($row >= $rows - 1) {
                    break; // Don't overwrite footer
                }
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write(ucfirst(str_replace('_', ' ', $key)) . ': ');
                Terminal::reset();
                Stdout::write(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string)$value);
                $row++;
            }
        }
    }

    /**
     * Render server metrics in fullscreen mode with additional details.
     */
    protected function renderServerMetricsFullscreen(): void
    {
        [$rows, $cols] = Terminal::getSize();

        // Clear content area (skip header row 1 and footer row $rows)
        for ($i = 2; $i < $rows; $i++) {
            Terminal::moveTo($i, 1);
            Terminal::clearLine();
        }

        $metrics = $this->state->cachedServerMetrics;

        if (empty($metrics)) {
            Terminal::moveTo(3, 1);
            Stdout::write('No metrics available');
            return;
        }

        $row = 3;
        $col = 1;
        $width = $cols - 2;

        // Version
        if (isset($metrics['version'])) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            Stdout::write('Version: ');
            Terminal::reset();
            $version = (string)$metrics['version'];
            Stdout::write($version);
            $row++;
        }

        // Uptime
        if (isset($metrics['uptime_seconds'])) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            Stdout::write('Uptime: ');
            Terminal::reset();
            $uptime = (int)$metrics['uptime_seconds'];
            Stdout::write(ServerMetricsPane::formatUptime($uptime));
            $row++;
        }

        $row++;

        // Key metrics section
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        Stdout::write('Key Metrics:');
        Terminal::reset();
        $row++;

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
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write($label . ': ');
                Terminal::reset();
                $value = (int)$metrics[$key];
                Stdout::write(number_format($value));
                $row++;

                if ($row >= $rows - 2) {
                    break; // Don't overwrite footer
                }
            }
        }

        // File size for SQLite
        if (isset($metrics['file_size'])) {
            $row++;
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            Stdout::write('File Size: ');
            Terminal::reset();
            $size = (int)$metrics['file_size'];
            Stdout::write(ServerMetricsPane::formatBytes($size));
            $row++;
        }

        // Show all other available metrics
        $row++;
        $shownFields = ['version', 'uptime_seconds', 'file_size'];
        $shownFields = array_merge($shownFields, array_keys($keyMetrics));

        $hasAdditional = false;
        foreach ($metrics as $key => $value) {
            if (!in_array($key, $shownFields, true) && $key !== 'error' && $value !== null && $value !== '') {
                if (!$hasAdditional) {
                    $row++;
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                        Terminal::color(Terminal::COLOR_CYAN);
                    }
                    Stdout::write('Additional Metrics:');
                    Terminal::reset();
                    $row++;
                    $hasAdditional = true;
                }

                if ($row >= $rows - 2) {
                    break; // Don't overwrite footer
                }

                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                Stdout::write(ucfirst(str_replace('_', ' ', $key)) . ': ');
                Terminal::reset();
                Stdout::write(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_numeric($value) ? number_format((float)$value) : (string)$value));
                $row++;
            }
        }

        // Error message if present
        if (isset($metrics['error'])) {
            $row++;
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
            }
            Stdout::write('Error: ' . (string)$metrics['error']);
            Terminal::reset();
        }

        flush();
    }

    /**
     * Render schema browser fullscreen.
     */
    protected function renderSchemaBrowserFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Get tables and apply filter
        $tables = TableManager::listTables($this->db);
        $searchFilter = $this->state->searchFilter[Layout::PANE_SCHEMA];
        $searchFilter = $searchFilter !== '' ? $searchFilter : null;
        if ($searchFilter !== null) {
            $tables = array_filter($tables, function ($table) use ($searchFilter) {
                return stripos($table, $searchFilter) !== false;
            });
            $tables = array_values($tables); // Re-index
        }

        // Calculate scroll offset for fullscreen
        $visibleHeight = $rows - 3;
        $selectedIdx = $this->state->selectedIndex[Layout::PANE_SCHEMA];

        if ($selectedIdx >= 0 && count($tables) > 0) {
            if ($selectedIdx >= count($tables)) {
                $selectedIdx = count($tables) - 1;
                $this->state->selectedIndex[Layout::PANE_SCHEMA] = $selectedIdx;
            }

            $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_SCHEMA];
            if ($relativeIndex < 0) {
                $this->state->scrollOffset[Layout::PANE_SCHEMA] = $selectedIdx;
            } elseif ($relativeIndex >= $visibleHeight) {
                $this->state->scrollOffset[Layout::PANE_SCHEMA] = max(0, $selectedIdx - $visibleHeight + 1);
            }
        }

        // Render pane in fullscreen
        $dummyLayout = new Layout($rows, $cols);
        SchemaBrowserPane::render(
            $this->db,
            $dummyLayout,
            Layout::PANE_SCHEMA,
            true,
            $this->state->selectedIndex[Layout::PANE_SCHEMA],
            $this->state->scrollOffset[Layout::PANE_SCHEMA],
            true,
            $searchFilter
        );

        // Render search prompt if in search mode, otherwise render footer
        if ($this->state->searchMode[Layout::PANE_SCHEMA] ?? false) {
            Terminal::moveTo($rows, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
                Terminal::bold();
            }
            Stdout::write('/');
            if (Terminal::supportsColors()) {
                Terminal::reset();
            }
            Stdout::write($this->state->searchFilter[Layout::PANE_SCHEMA]);
            Stdout::write('_');
            Terminal::reset();
        } else {
            $this->renderFullscreenFooter();
        }
    }

    /**
     * Render migrations fullscreen.
     */
    protected function renderMigrationsFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Get migration path
        $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
        $runner = new MigrationRunner($this->db, $migrationPath);
        $newMigrations = $runner->getNewMigrations();
        $history = $runner->getMigrationHistory();

        // Combine all migrations
        $allMigrations = [];
        foreach ($newMigrations as $version) {
            $allMigrations[] = ['version' => $version, 'status' => 'pending'];
        }
        foreach ($history as $record) {
            $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
        }
        usort($allMigrations, function ($a, $b) {
            return strcmp($a['version'], $b['version']);
        });

        // Calculate scroll offset
        $visibleHeight = $rows - 3;
        $selectedIdx = $this->state->selectedIndex[Layout::PANE_MIGRATIONS];

        if ($selectedIdx >= 0 && count($allMigrations) > 0) {
            if ($selectedIdx >= count($allMigrations)) {
                $selectedIdx = count($allMigrations) - 1;
                $this->state->selectedIndex[Layout::PANE_MIGRATIONS] = $selectedIdx;
            }

            $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_MIGRATIONS];
            if ($relativeIndex < 0) {
                $this->state->scrollOffset[Layout::PANE_MIGRATIONS] = $selectedIdx;
            } elseif ($relativeIndex >= $visibleHeight) {
                $this->state->scrollOffset[Layout::PANE_MIGRATIONS] = max(0, $selectedIdx - $visibleHeight + 1);
            }
        }

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        Stdout::write('Migration Manager (Fullscreen)');
        Terminal::reset();

        // Render pane in fullscreen
        $dummyLayout = new Layout($rows, $cols);
        MigrationManagerPane::render(
            $this->db,
            $dummyLayout,
            Layout::PANE_MIGRATIONS,
            true,
            $this->state->selectedIndex[Layout::PANE_MIGRATIONS],
            $this->state->scrollOffset[Layout::PANE_MIGRATIONS],
            true,
            $migrationPath
        );

        // Render footer
        $this->renderFullscreenFooter();
    }

    /**
     * Render server variables fullscreen.
     */
    protected function renderVariablesFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Get variables (filtering is handled in ServerVariablesPane)
        $dialect = $this->db->schema()->getDialect();
        $searchFilter = $this->state->searchFilter[Layout::PANE_VARIABLES];
        $searchFilter = $searchFilter !== '' ? $searchFilter : null;

        // Calculate scroll offset
        $variables = $dialect->getServerVariables($this->db);
        // Apply filter for scroll calculation
        if ($searchFilter !== null) {
            $variables = array_filter($variables, function ($var) use ($searchFilter) {
                $name = $var['name'] ?? '';
                return stripos($name, $searchFilter) !== false;
            });
            $variables = array_values($variables); // Re-index
        }

        $visibleHeight = $rows - 3;
        $selectedIdx = $this->state->selectedIndex[Layout::PANE_VARIABLES];

        if ($selectedIdx >= 0 && count($variables) > 0) {
            if ($selectedIdx >= count($variables)) {
                $selectedIdx = count($variables) - 1;
                $this->state->selectedIndex[Layout::PANE_VARIABLES] = $selectedIdx;
            }

            $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_VARIABLES];
            if ($relativeIndex < 0) {
                $this->state->scrollOffset[Layout::PANE_VARIABLES] = $selectedIdx;
            } elseif ($relativeIndex >= $visibleHeight) {
                $this->state->scrollOffset[Layout::PANE_VARIABLES] = max(0, $selectedIdx - $visibleHeight + 1);
            }
        }

        // Render pane in fullscreen
        $dummyLayout = new Layout($rows, $cols);
        ServerVariablesPane::render(
            $this->db,
            $dummyLayout,
            Layout::PANE_VARIABLES,
            true,
            $this->state->selectedIndex[Layout::PANE_VARIABLES],
            $this->state->scrollOffset[Layout::PANE_VARIABLES],
            true,
            $searchFilter
        );

        // Render search prompt if in search mode, otherwise render footer
        if ($this->state->searchMode[Layout::PANE_VARIABLES] ?? false) {
            Terminal::moveTo($rows, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
                Terminal::bold();
            }
            Stdout::write('/');
            if (Terminal::supportsColors()) {
                Terminal::reset();
            }
            Stdout::write($this->state->searchFilter[Layout::PANE_VARIABLES]);
            Stdout::write('_');
            Terminal::reset();
        } else {
            $this->renderFullscreenFooter();
        }
    }

    /**
     * Render migration file content with syntax highlighting.
     */
    protected function renderMigrationDetailView(): void
    {
        [$rows, $cols] = Terminal::getSize();
        $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
        $runner = new MigrationRunner($this->db, $migrationPath);
        $newMigrations = $runner->getNewMigrations();
        $history = $runner->getMigrationHistory();

        // Combine all migrations
        $allMigrations = [];
        foreach ($newMigrations as $version) {
            $allMigrations[] = ['version' => $version, 'status' => 'pending'];
        }
        foreach ($history as $record) {
            $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
        }
        usort($allMigrations, function ($a, $b) {
            return strcmp($a['version'], $b['version']);
        });

        $selectedIdx = $this->state->selectedIndex[Layout::PANE_MIGRATIONS];
        if ($selectedIdx < 0 || $selectedIdx >= count($allMigrations)) {
            return;
        }

        $migration = $allMigrations[$selectedIdx];
        $version = $migration['version'];

        // Find migration file
        $files = glob($migrationPath . '/m*.php');
        if ($files === false) {
            $files = [];
        }
        $migrationFile = null;
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if (str_starts_with($basename, 'm')) {
                $versionPart = substr($basename, 1);
                if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6}|\d{14})_[a-z0-9_]+$/i', $versionPart) === 1 && $versionPart === $version) {
                    $migrationFile = $file;
                    break;
                }
            }
        }

        if ($migrationFile === null || !file_exists($migrationFile)) {
            Terminal::moveTo(1, 1);
            Stdout::write('Migration file not found');
            return;
        }

        // Read migration file
        $content = file_get_contents($migrationFile);
        if ($content === false) {
            Terminal::moveTo(1, 1);
            Stdout::write('Failed to read migration file');
            return;
        }
        $lines = explode("\n", $content);

        // Extract migration name from class name or comment
        $migrationName = $version;
        foreach ($lines as $line) {
            // Try to find class name
            if (preg_match('/class\s+(\w+)\s+extends/', $line, $matches)) {
                $migrationName = $matches[1];
                break;
            }
            // Try to find comment with migration name
            if (preg_match('/Migration:\s*(.+)/', $line, $matches)) {
                $migrationName = trim($matches[1]);
                break;
            }
        }

        // Parse up() and down() methods
        $upMethod = self::extractMethod($content, 'up');
        $downMethod = self::extractMethod($content, 'down');

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        Stdout::write('Migration: ' . $migrationName . ' (Press any key to close)');
        Terminal::reset();

        $row = 3;
        $col = 1;
        $width = $cols - 2;
        $maxRows = $rows - 2;

        // Display migration name
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_MAGENTA);
        }
        Stdout::write('Name: ' . $migrationName);
        Terminal::reset();
        $row += 2;

        // Display up() method
        if ($row > $maxRows) {
            return;
        }
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_GREEN);
        }
        Stdout::write('up() method:');
        Terminal::reset();
        $row++;

        if (!empty($upMethod)) {
            $upLines = explode("\n", $upMethod);
            foreach ($upLines as $line) {
                if ($row > $maxRows) {
                    break;
                }
                Terminal::moveTo($row, $col + 2);
                $displayLine = mb_substr($line, 0, $width - 2, 'UTF-8');
                self::renderHighlightedLine($displayLine);
                $row++;
            }
        } else {
            Terminal::moveTo($row, $col + 2);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            Stdout::write('(empty)');
            Terminal::reset();
            $row++;
        }

        $row++;

        // Display down() method
        if ($row > $maxRows) {
            return;
        }
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_RED);
        }
        Stdout::write('down() method:');
        Terminal::reset();
        $row++;

        if (!empty($downMethod)) {
            $downLines = explode("\n", $downMethod);
            foreach ($downLines as $line) {
                if ($row > $maxRows) {
                    break;
                }
                Terminal::moveTo($row, $col + 2);
                $displayLine = mb_substr($line, 0, $width - 2, 'UTF-8');
                self::renderHighlightedLine($displayLine);
                $row++;
            }
        } else {
            Terminal::moveTo($row, $col + 2);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            Stdout::write('(empty)');
            Terminal::reset();
            $row++;
        }
    }

    /**
     * Render table details (Columns/Indexes/Foreign Keys).
     */
    protected function renderTableDetailView(): void
    {
        [$rows, $cols] = Terminal::getSize();
        $tables = TableManager::listTables($this->db);
        $selectedIdx = $this->state->selectedIndex[Layout::PANE_SCHEMA];

        if ($selectedIdx < 0 || $selectedIdx >= count($tables)) {
            return;
        }

        $tableName = $tables[$selectedIdx];

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        Stdout::write('Table: ' . $tableName . ' (Press any key to close)');
        Terminal::reset();

        $row = 3;
        $col = 1;
        $width = $cols - 2;

        try {
            // Get columns
            $columns = TableManager::describe($this->db, $tableName);
            $dialect = $this->db->schema()->getDialect();
            $indexSql = $dialect->buildShowIndexesSql($tableName);
            $indexes = $this->db->rawQuery($indexSql);
            $foreignKeys = $this->db->schema()->getForeignKeys($tableName);

            // Columns section
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            Stdout::write('Columns:');
            Terminal::reset();
            $row++;

            foreach ($columns as $column) {
                if ($row > $rows - 10) {
                    break;
                }
                $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? 'N/A';
                $colType = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? 'N/A';
                $colNull = ($column['Null'] ?? $column['is_nullable'] ?? '') === 'YES' ? 'NULL' : 'NOT NULL';
                Terminal::moveTo($row, $col + 2);
                Stdout::write($colName . ' (' . $colType . ', ' . $colNull . ')');
                $row++;
            }

            $row++;

            // Indexes section
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            Stdout::write('Indexes:');
            Terminal::reset();
            $row++;

            foreach ($indexes as $index) {
                if ($row > $rows - 5) {
                    break;
                }
                $idxName = $index['Key_name'] ?? $index['indexname'] ?? $index['name'] ?? 'N/A';
                $idxColumns = $index['Column_name'] ?? $index['column_name'] ?? 'N/A';
                Terminal::moveTo($row, $col + 2);
                Stdout::write($idxName . ' (' . $idxColumns . ')');
                $row++;
            }

            $row++;

            // Foreign Keys section
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            Stdout::write('Foreign Keys:');
            Terminal::reset();
            $row++;

            foreach ($foreignKeys as $fk) {
                if ($row > $rows - 2) {
                    break;
                }
                $fkName = $fk['constraint_name'] ?? $fk['name'] ?? 'N/A';
                $fkColumn = $fk['column_name'] ?? 'N/A';
                $fkRefTable = $fk['referenced_table_name'] ?? $fk['referenced_table'] ?? 'N/A';
                $fkRefColumn = $fk['referenced_column_name'] ?? $fk['referenced_column'] ?? 'N/A';
                Terminal::moveTo($row, $col + 2);
                Stdout::write($fkName . ': ' . $fkColumn . ' -> ' . $fkRefTable . '.' . $fkRefColumn);
                $row++;
            }
        } catch (\Throwable $e) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
            }
            Stdout::write('Error: ' . $e->getMessage());
            Terminal::reset();
        }
    }

    /**
     * Extract method body from PHP code.
     *
     * @param string $content PHP file content
     * @param string $methodName Method name (e.g., 'up', 'down')
     *
     * @return string Method body (without method signature)
     */
    protected static function extractMethod(string $content, string $methodName): string
    {
        // Find method signature - more flexible pattern
        // Matches: public function up(): void { or public function up() { or public function up()\n{
        $pattern = '/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*void)?\s*\{/';
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $startPos = $matches[0][1] + strlen($matches[0][0]);
        $braceCount = 1;
        $pos = $startPos;
        $len = strlen($content);
        $inString = false;
        $stringChar = null;

        // Find matching closing brace, handling strings and comments
        while ($pos < $len && $braceCount > 0) {
            $char = $content[$pos];
            $nextChar = $pos + 1 < $len ? $content[$pos + 1] : '';

            // Handle strings
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                $inString = false;
                $stringChar = null;
            }

            // Skip string contents
            if ($inString) {
                $pos++;
                continue;
            }

            // Handle comments
            if ($char === '/' && $nextChar === '/') {
                // Single-line comment - skip to end of line
                while ($pos < $len && $content[$pos] !== "\n") {
                    $pos++;
                }
                continue;
            }
            if ($char === '/' && $nextChar === '*') {
                // Multi-line comment - skip until */
                $pos += 2;
                while ($pos < $len - 1) {
                    if ($content[$pos] === '*' && $content[$pos + 1] === '/') {
                        $pos += 2;
                        break;
                    }
                    $pos++;
                }
                continue;
            }

            // Count braces
            if ($char === '{') {
                $braceCount++;
            } elseif ($char === '}') {
                $braceCount--;
            }
            $pos++;
        }

        if ($braceCount === 0) {
            // Extract method body (without the closing brace)
            $body = substr($content, $startPos, $pos - $startPos - 1);
            // Remove leading/trailing whitespace and trim each line
            $lines = explode("\n", $body);
            $trimmedLines = array_map('trim', $lines);
            // Remove empty lines at start and end
            while (!empty($trimmedLines) && trim($trimmedLines[0]) === '') {
                array_shift($trimmedLines);
            }
            while (!empty($trimmedLines) && trim($trimmedLines[count($trimmedLines) - 1]) === '') {
                array_pop($trimmedLines);
            }
            return implode("\n", $trimmedLines);
        }

        return '';
    }

    /**
     * Render a line with syntax highlighting.
     *
     * @param string $line Line to render
     */
    protected static function renderHighlightedLine(string $line): void
    {
        if (Terminal::supportsColors()) {
            // PHP keywords for highlighting
            $phpKeywords = [
                'abstract', 'and', 'array', 'as', 'break', 'case', 'catch', 'class', 'clone', 'const',
                'continue', 'declare', 'default', 'do', 'else', 'elseif', 'enddeclare', 'endfor',
                'endforeach', 'endif', 'endswitch', 'endwhile', 'extends', 'final', 'for', 'foreach',
                'function', 'global', 'goto', 'if', 'implements', 'interface', 'instanceof', 'namespace',
                'new', 'or', 'private', 'protected', 'public', 'static', 'switch', 'throw', 'trait',
                'try', 'use', 'var', 'while', 'xor', 'return', 'void', 'string', 'int', 'bool', 'float',
                'null', 'true', 'false', 'self', 'parent', 'static', 'void',
            ];

            $result = '';
            $pos = 0;
            $len = mb_strlen($line, 'UTF-8');

            while ($pos < $len) {
                // Check for comments first
                if (mb_substr($line, $pos, 2, 'UTF-8') === '//') {
                    Terminal::color(Terminal::COLOR_YELLOW);
                    $result .= mb_substr($line, $pos, null, 'UTF-8');
                    Terminal::reset();
                    break;
                }

                // Check for strings
                $char = mb_substr($line, $pos, 1, 'UTF-8');
                if ($char === '"' || $char === "'") {
                    $endPos = mb_strpos($line, $char, $pos + 1, 'UTF-8');
                    if ($endPos !== false) {
                        Terminal::color(Terminal::COLOR_GREEN);
                        $result .= mb_substr($line, $pos, $endPos - $pos + 1, 'UTF-8');
                        Terminal::reset();
                        $pos = $endPos + 1;
                        continue;
                    }
                }

                // Check for keywords (word boundaries)
                $matched = false;
                foreach ($phpKeywords as $keyword) {
                    $keywordLen = mb_strlen($keyword, 'UTF-8');
                    if (mb_substr($line, $pos, $keywordLen, 'UTF-8') === $keyword) {
                        // Check word boundaries
                        $before = $pos > 0 ? mb_substr($line, $pos - 1, 1, 'UTF-8') : ' ';
                        $after = mb_substr($line, $pos + $keywordLen, 1, 'UTF-8');
                        if (!ctype_alnum($before) && $before !== '_' && !ctype_alnum($after) && $after !== '_') {
                            Terminal::color(Terminal::COLOR_BLUE);
                            Terminal::bold();
                            $result .= $keyword;
                            Terminal::reset();
                            $pos += $keywordLen;
                            $matched = true;
                            break;
                        }
                    }
                }

                if (!$matched) {
                    $result .= $char;
                    $pos++;
                }
            }
            Stdout::write($result);
        } else {
            Stdout::write($line);
        }
    }
}
