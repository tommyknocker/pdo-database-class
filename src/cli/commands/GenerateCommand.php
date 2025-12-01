<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use Exception;
use tommyknocker\pdodb\cli\ApiGenerator;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DocsGenerator;
use tommyknocker\pdodb\cli\DtoGenerator;
use tommyknocker\pdodb\cli\EnumGenerator;
use tommyknocker\pdodb\cli\TestGenerator;

/**
 * Generate command for extended code generation.
 */
class GenerateCommand extends Command
{
    /**
     * Create generate command.
     */
    public function __construct()
    {
        parent::__construct('generate', 'Extended code generation');
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
            'api' => $this->generateApi(),
            'tests' => $this->generateTests(),
            'dto' => $this->generateDto(),
            'enum' => $this->generateEnum(),
            'docs' => $this->generateDocs(),
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Generate API endpoints.
     *
     * @return int Exit code
     */
    protected function generateApi(): int
    {
        $table = $this->getOption('table');
        $model = $this->getOption('model');
        $format = $this->getOption('format', 'rest');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        if ($table === null && $model === null) {
            $this->showError('Either --table or --model option is required');
        }

        try {
            ApiGenerator::generate(
                $table,
                $model,
                is_string($format) ? $format : 'rest',
                is_string($namespace) ? $namespace : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                $force
            );
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate tests.
     *
     * @return int Exit code
     */
    protected function generateTests(): int
    {
        $model = $this->getOption('model');
        $table = $this->getOption('table');
        $repository = $this->getOption('repository');
        $type = $this->getOption('type', 'unit');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        if ($model === null && $table === null && $repository === null) {
            $this->showError('One of --model, --table, or --repository option is required');
        }

        try {
            TestGenerator::generate(
                $model,
                $table,
                $repository,
                is_string($type) ? $type : 'unit',
                is_string($namespace) ? $namespace : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                $force
            );
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate DTO.
     *
     * @return int Exit code
     */
    protected function generateDto(): int
    {
        $table = $this->getOption('table');
        $model = $this->getOption('model');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        if ($table === null && $model === null) {
            $this->showError('Either --table or --model option is required');
        }

        try {
            DtoGenerator::generate(
                $table,
                $model,
                is_string($namespace) ? $namespace : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                $force
            );
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate Enum class.
     *
     * @return int Exit code
     */
    protected function generateEnum(): int
    {
        $table = $this->getOption('table');
        $column = $this->getOption('column');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        if ($table === null) {
            $this->showError('--table option is required');
        }

        if ($column === null) {
            $this->showError('--column option is required');
        }

        try {
            EnumGenerator::generate(
                $table,
                is_string($column) ? $column : '',
                is_string($namespace) ? $namespace : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                $force
            );
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate documentation.
     *
     * @return int Exit code
     */
    protected function generateDocs(): int
    {
        $table = $this->getOption('table');
        $model = $this->getOption('model');
        $format = $this->getOption('format', 'openapi');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        if ($table === null && $model === null) {
            $this->showError('Either --table or --model option is required');
        }

        try {
            DocsGenerator::generate(
                $table,
                $model,
                is_string($format) ? $format : 'openapi',
                is_string($output) ? $output : null,
                $this->getDb(),
                $force
            );
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Extended Code Generation\n\n";
        echo "Usage: pdodb generate <subcommand> [options]\n\n";
        echo "Subcommands:\n";
        echo "  api              Generate REST API endpoints/controllers\n";
        echo "  tests            Generate unit/integration tests\n";
        echo "  dto              Generate Data Transfer Objects\n";
        echo "  enum             Generate Enum classes from database ENUM columns\n";
        echo "  docs             Generate API documentation (OpenAPI)\n\n";
        echo "Common Options:\n";
        echo "  --table=<name>       Table name\n";
        echo "  --model=<name>       Model class name\n";
        echo "  --namespace=<ns>     PHP namespace (default varies by generator)\n";
        echo "  --output=<path>      Output directory path\n";
        echo "  --force              Overwrite existing files without confirmation\n";
        echo "  --connection=<name>  Use specific database connection\n\n";
        echo "Subcommand-specific Options:\n";
        echo "  generate api:\n";
        echo "    --format=rest      Output format (default: rest)\n";
        echo "    --table=<name> or --model=<name>  Required\n\n";
        echo "  generate tests:\n";
        echo "    --type=unit|integration  Test type (default: unit)\n";
        echo "    --model=<name> or --table=<name> or --repository=<name>  Required\n\n";
        echo "  generate dto:\n";
        echo "    --table=<name> or --model=<name>  Required\n\n";
        echo "  generate enum:\n";
        echo "    --table=<name>     Required\n";
        echo "    --column=<name>    Required\n\n";
        echo "  generate docs:\n";
        echo "    --format=openapi   Output format (default: openapi)\n";
        echo "    --table=<name> or --model=<name>  Required\n\n";
        echo "Examples:\n";
        echo "  pdodb generate api --table=users --format=rest\n";
        echo "  pdodb generate api --model=User --format=rest\n";
        echo "  pdodb generate tests --model=User --type=unit\n";
        echo "  pdodb generate tests --table=users --type=integration\n";
        echo "  pdodb generate tests --repository=UserRepository --type=unit\n";
        echo "  pdodb generate dto --table=users\n";
        echo "  pdodb generate dto --model=User\n";
        echo "  pdodb generate enum --table=users --column=status\n";
        echo "  pdodb generate docs --table=users --format=openapi\n";
        echo "  pdodb generate docs --model=User --format=openapi\n";
        return 0;
    }
}
