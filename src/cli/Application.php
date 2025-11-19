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
     * @var string
     */
    protected string $version = '1.0.0';

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
        $this->registerDefaultCommands();
    }

    /**
     * Register default commands.
     */
    protected function registerDefaultCommands(): void
    {
        // Migrate command with subcommands
        $this->addCommand(new commands\MigrateCommand());

        // Schema command with subcommands
        $this->addCommand(new commands\SchemaCommand());

        // Query command with subcommands
        $this->addCommand(new commands\QueryCommand());

        // Model command with subcommands
        $this->addCommand(new commands\ModelCommand());

        // Database management command with subcommands
        $this->addCommand(new commands\DbCommand());

        // User management command with subcommands
        $this->addCommand(new commands\UserCommand());

        // Table management command with subcommands
        $this->addCommand(new commands\TableCommand());

        // Dump and restore command
        $this->addCommand(new commands\DumpCommand());

        // Monitor command
        $this->addCommand(new commands\MonitorCommand());
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
     * Show help message.
     */
    protected function showHelp(): void
    {
        echo "{$this->name} v{$this->version}\n\n";
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
