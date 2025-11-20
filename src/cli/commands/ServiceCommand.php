<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\ServiceGenerator;

/**
 * Service command for generating service classes.
 */
class ServiceCommand extends Command
{
    /**
     * Create service command.
     */
    public function __construct()
    {
        parent::__construct('service', 'Generate service classes');
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
     * Make service.
     *
     * @return int Exit code
     */
    protected function make(): int
    {
        $serviceName = $this->getArgument(1);
        $repositoryName = $this->getArgument(2);
        $outputPath = $this->getArgument(3);
        $namespace = $this->getOption('namespace');
        $repositoryNamespace = $this->getOption('repository-namespace');
        $force = (bool)$this->getOption('force', false);

        if ($serviceName === null) {
            $this->showError('Service name is required');
            // @phpstan-ignore-next-line
            return 1;
        }

        try {
            ServiceGenerator::generate(
                $serviceName,
                $repositoryName,
                $outputPath,
                $this->getDb(),
                is_string($namespace) ? $namespace : null,
                is_string($repositoryNamespace) ? $repositoryNamespace : null,
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
        echo "Service Generation\n\n";
        echo "Usage: pdodb service make <ServiceName> [RepositoryName] [output_path]\n\n";
        echo "Arguments:\n";
        echo "  ServiceName         Service class name (required, e.g. UserService)\n";
        echo "  RepositoryName      Repository class name (optional, auto-detected from service name if not provided)\n";
        echo "  output_path         Output directory path (optional)\n\n";
        echo "Options:\n";
        echo "  --force                  Overwrite existing file without confirmation\n";
        echo "  --namespace=NS           Set the PHP namespace for the generated service (default: App\\\\Services)\n";
        echo "  --repository-namespace=NS Set the PHP namespace for the repository class (default: App\\\\Repositories)\n";
        echo "  --connection=NAME        Use a named connection from config/db.php\n\n";
        echo "Examples:\n";
        echo "  pdodb service make UserService\n";
        echo "  pdodb service make UserService UserRepository\n";
        echo "  pdodb service make UserService UserRepository app/Services\n";
        echo "  pdodb service make UserService UserRepository app/Services --namespace=App\\\\Services\n";
        echo "  pdodb service make UserService UserRepository app/Services --repository-namespace=App\\\\Repositories --force\n";
        return 0;
    }
}
