<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\exceptions\ProfileReportException;
use tommyknocker\pdodb\query\ProfileReport;

/**
 * In-process query profile summary, export, import, and local diagnosis.
 */
class ProfileCommand extends Command
{
    public function __construct()
    {
        parent::__construct('profile', 'Inspect the current connection query profile');
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
            'summary' => $this->summary(),
            'export' => $this->export(),
            'import' => $this->import(),
            'diagnose' => $this->diagnose(),
            default => $this->showError('Unknown subcommand: ' . $subStr),
        };
    }

    protected function summary(): int
    {
        try {
            $db = $this->getDb();
            $report = new ProfileReport($db);
            echo json_encode($report->summary(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError($e->getMessage());
        }
    }

    protected function export(): int
    {
        try {
            $db = $this->getDb();
            $report = new ProfileReport($db);
            if (!$report->summary()['enabled']) {
                return $this->showError('No active profile in this process. Export it from the application using $db->profileReport()->export().');
            }
            $json = $report->export();
            $out = $this->getOption('out');
            if (is_string($out) && $out !== '') {
                if (@file_put_contents($out, $json) === false) {
                    return $this->showError("Cannot write profile export: {$out}");
                }

                return 0;
            }

            echo $json . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError($e->getMessage());
        }
    }

    protected function import(): int
    {
        $path = $this->getArgument(1);
        if (!is_string($path) || $path === '') {
            return $this->showError('Path to exported JSON is required.');
        }

        if (!is_readable($path)) {
            return $this->showError("Cannot read file: {$path}");
        }

        try {
            $json = file_get_contents($path);
            if (!is_string($json)) {
                return $this->showError('Failed to read export file.');
            }
            $data = ProfileReport::parseExport($json);
            echo json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError($e->getMessage());
        }
    }

    protected function diagnose(): int
    {
        $rankOpt = $this->getOption('rank');
        if ($rankOpt !== false && $rankOpt !== null && $rankOpt !== true) {
            $rank = is_numeric($rankOpt) ? (int)$rankOpt : 0;
            if ($rank < 1) {
                return $this->showError('--rank must be a positive integer.');
            }

            try {
                $db = $this->getDb();
                $report = new ProfileReport($db);
                $params = $this->parseParams();
                $result = $report->diagnoseRankedQuery($rank, $params);
                echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";

                return 0;
            } catch (ProfileReportException $e) {
                return $this->showError($e->getMessage());
            } catch (\Throwable $e) {
                return $this->showError($e->getMessage());
            }
        }

        $sql = $this->getArgument(1);
        if (!is_string($sql) || trim($sql) === '') {
            return $this->showError('Pass SQL or use --rank with an enabled in-process profiler.');
        }

        try {
            $db = $this->getDb();
            $report = new ProfileReport($db);
            $params = $this->parseParams();
            $result = $report->diagnose($sql, $params);
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";

            return 0;
        } catch (\Throwable $e) {
            return $this->showError($e->getMessage());
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
        echo "Query profile (this PHP process only)\n\n";
        echo "Usage:\n";
        echo "  pdodb profile summary                 Summary when a profiled connection is injected\n";
        echo "  pdodb profile export [--out=path]     Export an injected connection profile\n";
        echo "  pdodb profile import <file.json>        Pretty-print a previous export\n";
        echo "  pdodb profile diagnose --rank=N       Diagnose a query on an injected profiled connection\n";
        echo "  pdodb profile diagnose \"SQL\" [--param=json]\n\n";
        echo "Notes:\n";
        echo "  CLI starts a separate process and cannot read your application's in-memory profile.\n";
        echo "  Export from the application with \$db->profileReport()->export(), then use import.\n";
        echo "  Rank diagnosis requires a profiled connection injected in the same process.\n";
        echo "  Exported reports contain redacted SQL only; pass SQL manually for CLI diagnosis.\n";

        return 0;
    }
}
