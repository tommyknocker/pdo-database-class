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
        $this->writeln("TUI Dashboard\n");
        $this->writeln("Usage: pdodb ui [options]\n");
        $this->writeln('Options:');
        $this->writeln('  --refresh=<seconds>  Refresh interval in seconds (default: 2)');
        $this->writeln("  --help              Show this help message\n");
        $this->writeln('Description:');
        $this->writeln('  Launches an interactive full-screen terminal dashboard for monitoring');
        $this->writeln("  database queries, connections, cache statistics, and server metrics.\n");
        $this->writeln('Navigation:');
        $this->writeln('  1-4        Switch between panes');
        $this->writeln('  Tab/Arrows Navigate between panes');
        $this->writeln("  q/Esc      Quit dashboard\n");
        $this->writeln('Panes:');
        $this->writeln('  1. Active Queries    - Currently executing queries');
        $this->writeln('  2. Connection Pool   - Active connections and pool statistics');
        $this->writeln('  3. Cache Stats       - Query cache statistics');
        $this->writeln("  4. Server Metrics    - Database server performance metrics\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb ui');
        $this->writeln('  pdodb ui --refresh=5');
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'refresh',
        ];
    }
}
