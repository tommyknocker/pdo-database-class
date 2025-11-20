<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\RepositoryGenerator;

/**
 * Repository command for generating repository classes.
 */
class RepositoryCommand extends Command
{
    /**
     * Create repository command.
     */
    public function __construct()
    {
        parent::__construct('repository', 'Generate repository classes');
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
     * Make repository.
     *
     * @return int Exit code
     */
    protected function make(): int
    {
        $repositoryName = $this->getArgument(1);
        $modelName = $this->getArgument(2);
        $outputPath = $this->getArgument(3);
        $namespace = $this->getOption('namespace');
        $modelNamespace = $this->getOption('model-namespace');
        $force = (bool)$this->getOption('force', false);

        if ($repositoryName === null) {
            $this->showError('Repository name is required');
            // @phpstan-ignore-next-line
            return 1;
        }

        try {
            RepositoryGenerator::generate(
                $repositoryName,
                $modelName,
                $outputPath,
                $this->getDb(),
                is_string($namespace) ? $namespace : null,
                is_string($modelNamespace) ? $modelNamespace : null,
                $force
            );
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
        echo "Repository Generation\n\n";
        echo "Usage: pdodb repository make <RepositoryName> [ModelName] [output_path]\n\n";
        echo "Arguments:\n";
        echo "  RepositoryName    Repository class name (required, e.g. UserRepository)\n";
        echo "  ModelName         Model class name (optional, auto-detected from repository name if not provided)\n";
        echo "  output_path       Output directory path (optional)\n\n";
        echo "Options:\n";
        echo "  --force              Overwrite existing file without confirmation\n";
        echo "  --namespace=NS       Set the PHP namespace for the generated repository (default: App\\\\Repositories)\n";
        echo "  --model-namespace=NS Set the PHP namespace for the model class (default: App\\\\Models)\n";
        echo "  --connection=NAME    Use a named connection from config/db.php\n\n";
        echo "Examples:\n";
        echo "  pdodb repository make UserRepository\n";
        echo "  pdodb repository make UserRepository User\n";
        echo "  pdodb repository make UserRepository User app/Repositories\n";
        echo "  pdodb repository make UserRepository User app/Repositories --namespace=App\\\\Repositories\n";
        echo "  pdodb repository make UserRepository User app/Repositories --model-namespace=App\\\\Entities --force\n";
        return 0;
    }
}
