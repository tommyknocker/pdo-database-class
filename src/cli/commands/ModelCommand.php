<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\ModelGenerator;

/**
 * Model command for generating ActiveRecord models.
 */
class ModelCommand extends Command
{
    /**
     * Create model command.
     */
    public function __construct()
    {
        parent::__construct('model', 'Generate ActiveRecord models');
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
            'make' => $this->make(),
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Make model.
     *
     * @return int Exit code
     */
    protected function make(): int
    {
        $modelName = $this->getArgument(1);
        $tableName = $this->getArgument(2);
        $outputPath = $this->getArgument(3);

        if ($modelName === null) {
            $this->showError('Model name is required');
            // @phpstan-ignore-next-line
            return 1;
        }

        try {
            ob_start();
            ModelGenerator::generate($modelName, $tableName, $outputPath, $this->getDb());
            ob_end_clean();
            return 0;
        } catch (\Exception $e) {
            ob_end_clean();
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
        echo "Model Generation\n\n";
        echo "Usage: pdodb model make <ModelName> [table_name] [output_path]\n\n";
        echo "Arguments:\n";
        echo "  ModelName         Model class name (required)\n";
        echo "  table_name       Table name (optional, auto-detected from model name if not provided)\n";
        echo "  output_path      Output directory path (optional)\n\n";
        echo "Examples:\n";
        echo "  pdodb model make User\n";
        echo "  pdodb model make User users\n";
        echo "  pdodb model make User users app/Models\n";
        return 0;
    }
}
