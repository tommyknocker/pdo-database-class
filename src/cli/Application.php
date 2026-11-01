<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PackageVersion;

/**
 * CLI application for managing commands.
 */
class Application
{
    /** Flags that make a command only preview what it would do. */
    protected const SAFETY_FLAGS = ['dry-run'];

    /** Options every command accepts. */
    protected const GLOBAL_OPTIONS = ['connection', 'config', 'env', 'help'];

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
        $this->addCommand(new commands\OutboxCommand());
        $this->addCommand(new commands\InboxCommand());

        // Code generation
        $this->addCommand(new commands\ModelCommand());
        $this->addCommand(new commands\RepositoryCommand());
        $this->addCommand(new commands\ServiceCommand());
        $this->addCommand(new commands\GenerateCommand());

        // Database management
        $this->addCommand(new commands\DbCommand());
        $this->addCommand(new commands\UserCommand());
        $this->addCommand(new commands\TableCommand());
        $this->addCommand(new commands\ConnectionCommand());

        // Development tools
        $this->addCommand(new commands\SchemaCommand());
        $this->addCommand(new commands\QueryCommand());

        // Monitoring and performance
        $this->addCommand(new commands\MonitorCommand());
        $this->addCommand(new commands\ProfileCommand());
        $this->addCommand(new commands\UiCommand());
        $this->addCommand(new commands\CacheCommand());
        $this->addCommand(new commands\BenchmarkCommand());
        $this->addCommand(new commands\OptimizeCommand());
        $this->addCommand(new commands\AiCommand());
        $this->addCommand(new commands\McpCommand());

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

        // Check for global --version option
        if ($argv[0] === '--version' || $argv[0] === '-v') {
            $this->showVersion();
            return 0;
        }

        // Check for version command
        if ($argv[0] === 'version') {
            $this->showVersion();
            return 0;
        }

        $commandName = $argv[0];
        $command = $this->getCommand($commandName);

        if ($command === null) {
            BaseCliCommand::io()->writeErrorLine("Error: Unknown command: {$commandName}");

            // Suggest similar commands
            $availableCommands = array_keys($this->commands);
            $suggestions = CommandSuggestion::findSimilar($commandName, $availableCommands);

            if (!empty($suggestions)) {
                $message = CommandSuggestion::formatMessage($commandName, $suggestions);
                BaseCliCommand::io()->writeErrorLine('');
                BaseCliCommand::io()->writeErrorLine($message);
            }

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
                    $currentOption = null;
                } else {
                    $options[$optionName] = true;
                    $currentOption = $optionName;
                }
            } elseif ($currentOption !== null) {
                // Value for previous option (replace true with actual value)
                $options[$currentOption] = $arg;
                $currentOption = null;
            } else {
                // Argument
                $args[] = $arg;
            }
        }

        $misspelled = $this->findMisspelledSafetyFlag(array_keys($options));
        if ($misspelled !== null) {
            BaseCliCommand::io()->writeErrorLine("Error: unknown option --{$misspelled[0]}. Did you mean --{$misspelled[1]}?");

            return 2;
        }

        $unknown = $this->findUnknownOption($command, array_keys($options));
        if ($unknown !== null) {
            BaseCliCommand::io()->writeErrorLine("Error: unknown option --{$unknown[0]} for '{$commandName}'." . ($unknown[1] !== null ? " Did you mean --{$unknown[1]}?" : " Run 'pdodb {$commandName} help' for the options."));

            return 2;
        }

        $command->setArguments($args);
        $command->setOptions($options);

        try {
            return $command->execute();
        } catch (CliExitException $e) {
            return $e->getCode() > 0 ? $e->getCode() : 1;
        } finally {
            $command->restoreEnvironment();
        }
    }

    /**
     * The first option the command does not declare, with the declared option it most resembles.
     *
     * @param array<int|string> $names Option names as typed
     *
     * @return array{0: string, 1: string|null}|null
     */
    protected function findUnknownOption(Command $command, array $names): ?array
    {
        $known = $command->knownOptions();
        if ($known === null) {
            return null;
        }
        $known = [...$known, ...self::GLOBAL_OPTIONS];
        foreach ($names as $name) {
            $name = (string)$name;
            if (in_array($name, $known, true)) {
                continue;
            }
            $closest = null;
            $distance = 3;
            foreach ($known as $candidate) {
                $d = levenshtein($name, $candidate);
                if ($d < $distance) {
                    $distance = $d;
                    $closest = $candidate;
                }
            }

            return [$name, $closest];
        }

        return null;
    }

    /**
     * Options are read by name and an unrecognized one is ignored, so a mistyped safety flag such as --dryrun would
     * silently turn a preview into the real run. Those flags are checked here, before anything executes.
     *
     * @param array<int|string> $names Option names as typed
     *
     * @return array{0: string, 1: string}|null The typed name and the flag it resembles, or null
     */
    protected function findMisspelledSafetyFlag(array $names): ?array
    {
        foreach ($names as $name) {
            $name = (string)$name;
            foreach (self::SAFETY_FLAGS as $flag) {
                if ($name !== $flag && levenshtein($name, $flag) <= 2) {
                    return [$name, $flag];
                }
            }
        }

        return null;
    }

    /**
     * Detect application version.
     *
     * @return string
     */
    protected function detectVersion(): string
    {
        return PackageVersion::detect();
    }

    /**
     * Get application version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version ?? 'dev';
    }

    protected function cliWriteln(string $message = ''): void
    {
        BaseCliCommand::io()->writeln($message);
    }

    protected function cliWrite(string $message): void
    {
        BaseCliCommand::io()->write($message);
    }

    /**
     * Show help message.
     */
    protected function showHelp(): void
    {
        $this->cliWriteln("{$this->name} v{$this->getVersion()}\n");
        $this->cliWriteln("Usage: pdodb <command> [arguments] [options]\n");
        $this->cliWriteln("Available commands:\n");

        $maxLength = 0;
        foreach ($this->commands as $command) {
            $length = strlen($command->getName());
            if ($length > $maxLength) {
                $maxLength = $length;
            }
        }

        foreach ($this->commands as $command) {
            $name = str_pad($command->getName(), $maxLength + 2);
            $this->cliWriteln("  {$name}{$command->getDescription()}");
        }

        $this->cliWriteln("\nGlobal options:");
        $this->cliWriteln('  --help               Show help message');
        $this->cliWriteln('  --version, -v         Show version information');
        $this->cliWriteln('  --connection=<name>  Use a named connection from config/db.php');
        $this->cliWriteln('  --config=<path>      Path to db.php configuration file');
        $this->cliWriteln('  --env=<path>         Path to .env file');
        $this->cliWriteln("\nUse 'pdodb <command> --help' for more information about a command.");
    }

    /**
     * Show version information.
     */
    protected function showVersion(): void
    {
        $this->cliWriteln("{$this->name} v{$this->getVersion()}");
    }
}
