<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Base command class for CLI commands.
 *
 * All CLI commands should extend this class and implement the execute() method.
 */
abstract class Command extends BaseCliCommand
{
    /**
     * Command name.
     *
     * @var string
     */
    protected string $name;

    /**
     * Command description.
     *
     * @var string
     */
    protected string $description;

    /**
     * Command options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Command arguments.
     *
     * @var array<string>
     */
    protected array $arguments = [];

    /**
     * Database instance.
     *
     * @var PdoDb|null
     */
    protected ?PdoDb $db = null;

    /**
     * Create command instance.
     *
     * @param string $name Command name
     * @param string $description Command description
     */
    public function __construct(string $name, string $description)
    {
        $this->name = $name;
        $this->description = $description;
    }

    /**
     * Get command name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get command description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set command options.
     *
     * @param array<string, mixed> $options Options
     *
     * @return static
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;
        // Support global --connection option by exporting to environment
        if (isset($options['connection']) && is_string($options['connection']) && $options['connection'] !== '') {
            $conn = $options['connection'];
            putenv('PDODB_CONNECTION=' . $conn);
            $_ENV['PDODB_CONNECTION'] = $conn;
        }
        // Support global --config option by exporting to environment
        if (isset($options['config']) && is_string($options['config']) && $options['config'] !== '') {
            $path = $options['config'];
            putenv('PDODB_CONFIG_PATH=' . $path);
            $_ENV['PDODB_CONFIG_PATH'] = $path;
        }
        // Support global --env option by exporting to environment
        if (isset($options['env']) && is_string($options['env']) && $options['env'] !== '') {
            $path = $options['env'];
            putenv('PDODB_ENV_PATH=' . $path);
            $_ENV['PDODB_ENV_PATH'] = $path;
        }
        return $this;
    }

    /**
     * Set command arguments.
     *
     * @param array<string> $arguments Arguments
     *
     * @return static
     */
    public function setArguments(array $arguments): static
    {
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * Set database instance.
     *
     * @param PdoDb|null $db Database instance
     *
     * @return static
     */
    public function setDb(?PdoDb $db): static
    {
        $this->db = $db;
        return $this;
    }

    /**
     * Get option value.
     *
     * @param string $name Option name
     * @param mixed $default Default value
     *
     * @return mixed
     */
    protected function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Get argument value.
     *
     * @param int $index Argument index
     * @param mixed $default Default value
     *
     * @return mixed
     */
    protected function getArgument(int $index, mixed $default = null): mixed
    {
        return $this->arguments[$index] ?? $default;
    }

    /**
     * Get database instance.
     *
     * @return PdoDb
     */
    protected function getDb(): PdoDb
    {
        if ($this->db === null) {
            $this->db = static::createDatabase();
        }
        return $this->db;
    }

    /**
     * Execute command.
     *
     * @return int Exit code (0 for success, non-zero for error)
     */
    abstract public function execute(): int;

    /**
     * Get command help text.
     *
     * @return string
     */
    public function getHelp(): string
    {
        return $this->description;
    }

    /**
     * Show error message.
     *
     * @param string $message Error message
     *
     * @return never
     */
    protected function showError(string $message): never
    {
        BaseCliCommand::error($message);
    }
}
