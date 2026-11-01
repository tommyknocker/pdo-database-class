<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\GenerationCancelledException;
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
            default => $this->fail("Unknown subcommand: {$subcommand}"),
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
        } catch (GenerationCancelledException) {
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
        $this->writeln("Repository Generation\n");
        $this->writeln("Usage: pdodb repository make <RepositoryName> [ModelName] [output_path]\n");
        $this->writeln('Arguments:');
        $this->writeln('  RepositoryName    Repository class name (required, e.g. UserRepository)');
        $this->writeln('  ModelName         Model class name (optional, auto-detected from repository name if not provided)');
        $this->writeln("  output_path       Output directory path (optional)\n");
        $this->writeln('Options:');
        $this->writeln('  --force              Overwrite existing file without confirmation');
        $this->writeln('  --namespace=NS       Set the PHP namespace for the generated repository (default: app\\\\repositories)');
        $this->writeln('  --model-namespace=NS Set the PHP namespace for the model class (default: app\\\\models)');
        $this->writeln("  --connection=NAME    Use a named connection from config/db.php\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb repository make UserRepository');
        $this->writeln('  pdodb repository make UserRepository User');
        $this->writeln('  pdodb repository make UserRepository User app/Repositories');
        $this->writeln('  pdodb repository make UserRepository User app/Repositories --namespace=app\\\\repositories');
        $this->writeln('  pdodb repository make UserRepository User app/Repositories --model-namespace=app\\\\entities --force');
        return 0;
    }
}
