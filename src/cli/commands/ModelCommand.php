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
            default => $this->fail("Unknown subcommand: {$subcommand}"),
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
        $namespace = $this->getOption('namespace');
        $force = (bool)$this->getOption('force', false);

        if ($modelName === null) {
            $this->showError('Model name is required');
            // @phpstan-ignore-next-line
            return 1;
        }

        try {
            ModelGenerator::generate($modelName, $tableName, $outputPath, $this->getDb(), is_string($namespace) ? $namespace : null, $force, ModelGenerator::parseRelatedModels($this->getOption('relation-models')));
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
        $this->writeln("Model Generation\n");
        $this->writeln("Usage: pdodb model make <ModelName> [table_name] [output_path]\n");
        $this->writeln('Arguments:');
        $this->writeln('  ModelName         Model class name (required)');
        $this->writeln('  table_name       Table name (optional, auto-detected from model name if not provided)');
        $this->writeln("  output_path      Output directory path (optional)\n");
        $this->writeln('Options:');
        $this->writeln('  --force          Overwrite existing file without confirmation');
        $this->writeln('  --namespace=NS   Set the PHP namespace for the generated model (default: app\\\\models)');
        $this->writeln('  --connection=NAME  Use a named connection from config/db.php');
        $this->writeln("  --relation-models=JSON  Map referenced tables to model class names\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb model make User');
        $this->writeln('  pdodb model make User users');
        $this->writeln('  pdodb model make User users app/Models');
        $this->writeln('  pdodb model make User users app/Models --namespace=app\\\\entities');
        $this->writeln('  pdodb model make User users app/Models --force');
        return 0;
    }
}
