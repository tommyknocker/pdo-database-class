<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\SchemaInspector;

/**
 * Schema command for inspecting database schema.
 */
class SchemaCommand extends Command
{
    /**
     * Create schema command.
     */
    public function __construct()
    {
        parent::__construct('schema', 'Inspect database schema');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        $subcommand = $this->getArgument(0);

        if ($subcommand === null || $subcommand === '--help' || $subcommand === 'help') {
            $this->showHelp();
            return 0;
        }

        return match ($subcommand) {
            'inspect' => $this->inspect(),
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Inspect schema.
     *
     * @return int Exit code
     */
    protected function inspect(): int
    {
        $tableName = $this->getArgument(1);
        $format = $this->getOption('format');

        try {
            SchemaInspector::inspect($tableName, $format, $this->getDb());
            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
            // @phpstan-ignore-next-line
            return 1;
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Schema Inspection\n\n";
        echo "Usage: pdodb schema inspect [table_name] [--format=table|json|yaml]\n\n";
        echo "Arguments:\n";
        echo "  table_name        Table name to inspect (optional, shows all tables if not provided)\n\n";
        echo "Options:\n";
        echo "  --format=FORMAT   Output format: table, json, or yaml (default: table)\n\n";
        echo "Examples:\n";
        echo "  pdodb schema inspect\n";
        echo "  pdodb schema inspect users\n";
        echo "  pdodb schema inspect users --format=json\n";
        return 0;
    }
}
