<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\SchemaInspector;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;

/**
 * Schema command for inspecting database schema.
 */
class SchemaCommand extends Command
{
    use SubcommandSuggestionTrait;

    /**
     * Create schema command.
     */
    public function __construct()
    {
        parent::__construct('schema', 'Inspect database schema');
    }

    /**
     * Get available subcommands.
     *
     * @return array<string>
     */
    protected function getAvailableSubcommands(): array
    {
        return ['inspect', 'help'];
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
            default => $this->showSubcommandError($subcommand),
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
        $this->writeln("Schema Inspection\n");
        $this->writeln("Usage: pdodb schema inspect [table_name] [--format=table|json|yaml]\n");
        $this->writeln('Arguments:');
        $this->writeln("  table_name        Table name to inspect (optional, shows all tables if not provided)\n");
        $this->writeln('Options:');
        $this->writeln("  --format=FORMAT   Output format: table, json, or yaml (default: table)\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb schema inspect');
        $this->writeln('  pdodb schema inspect users');
        $this->writeln('  pdodb schema inspect users --format=json');
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'format',
        ];
    }
}
