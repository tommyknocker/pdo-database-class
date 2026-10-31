<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\query\ProfileReport;

/**
 * Inspect exported query profiles and diagnose caller-supplied SQL.
 */
class ProfileCommand extends Command
{
    public function __construct()
    {
        parent::__construct('profile', 'Inspect an exported query profile or diagnose SQL');
    }

    public function execute(): int
    {
        if ($this->getOption('help', false)) {
            $this->showHelp();

            return 0;
        }

        $sub = $this->getArgument(0);
        if ($sub === null || $sub === '--help' || $sub === 'help') {
            return $this->showHelp();
        }

        $subStr = is_string($sub) ? $sub : '';

        return match ($subStr) {
            'summary', 'import' => $this->import(),
            'export' => $this->fail('Export from the application with $db->profileReport()->export(); the CLI runs in a separate process.'),
            'diagnose' => $this->diagnose(),
            default => $this->fail('Unknown subcommand: ' . $subStr),
        };
    }

    protected function import(): int
    {
        $path = $this->getArgument(1);
        if (!is_string($path) || $path === '') {
            return $this->fail('Path to exported JSON is required.');
        }

        if (!is_readable($path)) {
            return $this->fail("Cannot read file: {$path}");
        }

        try {
            $json = file_get_contents($path);
            if (!is_string($json)) {
                return $this->fail('Failed to read export file.');
            }
            $data = ProfileReport::parseExport($json);
            $this->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    protected function diagnose(): int
    {
        if ($this->getOption('rank') !== null && $this->getOption('rank') !== false) {
            return $this->fail('Rank diagnosis requires the original in-process profiler. Use $db->profileReport()->diagnoseRankedQuery() in your application, or pass SQL to this command.');
        }

        $sql = $this->getArgument(1);
        if (!is_string($sql) || trim($sql) === '') {
            return $this->fail('Pass SQL to diagnose.');
        }

        try {
            $db = $this->getDb();
            $report = new ProfileReport($db);
            $params = $this->parseParams();
            $result = $report->diagnose($sql, $params);
            $this->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function parseParams(): array
    {
        $raw = $this->getOption('param');
        if ($raw === false || $raw === null || $raw === true) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [$raw];
    }

    protected function showHelp(): int
    {
        $this->writeln('Query profiling and diagnosis');
        $this->writeln();
        $this->writeln('Usage:');
        $this->writeln('  pdodb profile summary <report.json>       Show an exported profile');
        $this->writeln('  pdodb profile import <report.json>        Alias for summary');
        $this->writeln('  pdodb profile diagnose "SQL" [--param=json]');
        $this->writeln();
        $this->writeln('Export a profile in your application with $db->profileReport()->export().');
        $this->writeln('Profiles are in-memory and cannot be read directly by a separate CLI process.');
        $this->writeln('Exports contain redacted SQL; provide the original SQL and parameters for diagnosis.');

        return 0;
    }
}
