<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * CLI application for managing commands.
 */
class Application
{
    /**
     * Application name.
     *
     * @var string
     */
    protected string $name = 'PDOdb CLI';

    /**
     * Application version.
     *
     * @var string|null
     */
    protected ?string $version = null;

    /**
     * Registered commands.
     *
     * @var array<string, Command>
     */
    protected array $commands = [];

    /**
     * Create application instance.
     */
    public function __construct()
    {
        $this->version = $this->detectVersion();
        $this->registerDefaultCommands();
    }

    /**
     * Register default commands.
     */
    protected function registerDefaultCommands(): void
    {
        // Project initialization
        $this->addCommand(new commands\InitCommand());

        // Migrations
        $this->addCommand(new commands\MigrateCommand());
        $this->addCommand(new commands\SeedCommand());

        // Code generation
        $this->addCommand(new commands\ModelCommand());
        $this->addCommand(new commands\RepositoryCommand());
        $this->addCommand(new commands\ServiceCommand());

        // Database management
        $this->addCommand(new commands\DbCommand());
        $this->addCommand(new commands\UserCommand());
        $this->addCommand(new commands\TableCommand());

        // Development tools
        $this->addCommand(new commands\SchemaCommand());
        $this->addCommand(new commands\QueryCommand());

        // Monitoring and performance
        $this->addCommand(new commands\MonitorCommand());
        $this->addCommand(new commands\CacheCommand());

        // Utilities
        $this->addCommand(new commands\DumpCommand());
    }

    /**
     * Add command to application.
     *
     * @param Command $command Command instance
     */
    public function addCommand(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Get command by name.
     *
     * @param string $name Command name
     *
     * @return Command|null
     */
    public function getCommand(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /**
     * Get all commands.
     *
     * @return array<string, Command>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Run application.
     *
     * @param array<string> $argv Command line arguments
     *
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        // Remove script name
        array_shift($argv);

        if (empty($argv)) {
            $this->showHelp();
            return 0;
        }

        // Check for global --help option
        if ($argv[0] === '--help') {
            $this->showHelp();
            return 0;
        }

        $commandName = $argv[0];
        $command = $this->getCommand($commandName);

        if ($command === null) {
            BaseCliCommand::error("Unknown command: {$commandName}");
            // @phpstan-ignore-next-line
            return 1;
        }

        // Parse arguments and options
        $args = [];
        $options = [];
        $currentOption = null;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                // Option
                $optionName = substr($arg, 2);
                $equalsPos = strpos($optionName, '=');
                if ($equalsPos !== false) {
                    $name = substr($optionName, 0, $equalsPos);
                    $value = substr($optionName, $equalsPos + 1);
                    $options[$name] = $value;
                } else {
                    $options[$optionName] = true;
                    $currentOption = $optionName;
                }
            } elseif ($currentOption !== null && !isset($options[$currentOption])) {
                // Value for previous option
                $options[$currentOption] = $arg;
                $currentOption = null;
            } else {
                // Argument
                $args[] = $arg;
            }
        }

        $command->setArguments($args);
        $command->setOptions($options);

        return $command->execute();
    }

    /**
     * Detect application version.
     *
     * @return string
     */
    protected function detectVersion(): string
    {
        // Try to get version from Composer\InstalledVersions
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getVersion('tommyknocker/pdo-database-class');
                if (is_string($version) && $version !== '') {
                    // Remove 'dev-' prefix and commit hash for dev versions
                    $version = preg_replace('/^dev-[^-]+-/', '', $version);
                    $version = is_string($version) ? preg_replace('/^dev-/', '', $version) : '';
                    // If it's a valid version (not just 'master'), return it
                    if (is_string($version) && preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
                        return $version;
                    }
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Try to get version from git
        $gitDir = __DIR__ . '/../../.git';
        if (is_dir($gitDir)) {
            // First try to get exact tag
            $gitDescribe = @shell_exec('git describe --tags --exact-match 2>/dev/null');
            if (is_string($gitDescribe) && $gitDescribe !== '') {
                $version = trim($gitDescribe);
                $version = ltrim($version, 'v');
                if (preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
                    return $version;
                }
            }

            // If not on exact tag, get the latest tag
            $gitDescribe = @shell_exec('git describe --tags --always 2>/dev/null');
            if (is_string($gitDescribe) && $gitDescribe !== '') {
                $version = trim($gitDescribe);
                $version = ltrim($version, 'v');
                // Extract just the version number from "2.10.2-12-gabc123" -> "2.10.2"
                $matches = [];
                if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches) === 1 && count($matches) > 1) {
                    return (string)$matches[1];
                }
            }
        }

        // Fallback: try to read from composer.json in parent directory
        $composerPath = __DIR__ . '/../../composer.json';
        if (file_exists($composerPath)) {
            $composerContent = @file_get_contents($composerPath);
            if (is_string($composerContent)) {
                $composer = @json_decode($composerContent, true);
                if (is_array($composer) && isset($composer['version']) && is_string($composer['version'])) {
                    return $composer['version'];
                }
            }
        }

        // Ultimate fallback
        return '2.10.2';
    }

    /**
     * Get application version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version ?? '2.10.2';
    }

    /**
     * Show help message.
     */
    protected function showHelp(): void
    {
        echo "{$this->name} v{$this->getVersion()}\n\n";
        echo "Usage: pdodb <command> [arguments] [options]\n\n";
        echo "Available commands:\n\n";

        $maxLength = 0;
        foreach ($this->commands as $command) {
            $length = strlen($command->getName());
            if ($length > $maxLength) {
                $maxLength = $length;
            }
        }

        foreach ($this->commands as $command) {
            $name = str_pad($command->getName(), $maxLength + 2);
            echo "  {$name}{$command->getDescription()}\n";
        }

        echo "\nGlobal options:\n";
        echo "  --help               Show help message\n";
        echo "  --connection=<name>  Use a named connection from config/db.php\n";
        echo "  --config=<path>      Path to db.php configuration file\n";
        echo "  --env=<path>         Path to .env file\n";
        echo "\nUse 'pdodb <command> --help' for more information about a command.\n";
    }
}
