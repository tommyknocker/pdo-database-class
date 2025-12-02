<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\ui\Dashboard;

/**
 * UI command for TUI Dashboard.
 */
class UiCommand extends Command
{
    /**
     * Create UI command.
     */
    public function __construct()
    {
        parent::__construct('ui', 'Interactive TUI Dashboard for database monitoring');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        // Show help if explicitly requested
        if ($this->getOption('help', false)) {
            $this->showHelp();
            return 0;
        }

        $db = $this->getDb();

        // Get refresh interval
        $refreshVal = $this->getOption('refresh', 2);
        $refresh = is_int($refreshVal) ? $refreshVal : (is_string($refreshVal) ? (int)$refreshVal : 2);
        if ($refresh < 1) {
            $refresh = 1;
        }

        // Create and run dashboard
        $dashboard = new Dashboard($db);
        $dashboard->run();

        return 0;
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "TUI Dashboard\n\n";
        echo "Usage: pdodb ui [options]\n\n";
        echo "Options:\n";
        echo "  --refresh=<seconds>  Refresh interval in seconds (default: 2)\n";
        echo "  --help              Show this help message\n\n";
        echo "Description:\n";
        echo "  Launches an interactive full-screen terminal dashboard for monitoring\n";
        echo "  database queries, connections, cache statistics, and server metrics.\n\n";
        echo "Navigation:\n";
        echo "  1-4        Switch between panes\n";
        echo "  Tab/Arrows Navigate between panes\n";
        echo "  q/Esc      Quit dashboard\n\n";
        echo "Panes:\n";
        echo "  1. Active Queries    - Currently executing queries\n";
        echo "  2. Connection Pool   - Active connections and pool statistics\n";
        echo "  3. Cache Stats       - Query cache statistics\n";
        echo "  4. Server Metrics    - Database server performance metrics\n\n";
        echo "Examples:\n";
        echo "  pdodb ui\n";
        echo "  pdodb ui --refresh=5\n";
        return 0;
    }
}
