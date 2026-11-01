<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use Exception;
use tommyknocker\pdodb\cli\ApiGenerator;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DocsGenerator;
use tommyknocker\pdodb\cli\DtoGenerator;
use tommyknocker\pdodb\cli\EnumGenerator;
use tommyknocker\pdodb\cli\GenerationCancelledException;
use tommyknocker\pdodb\cli\ModelGenerator;
use tommyknocker\pdodb\cli\RepositoryGenerator;
use tommyknocker\pdodb\cli\ServiceGenerator;
use tommyknocker\pdodb\cli\TestGenerator;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;

/**
 * Generate command for extended code generation.
 */
class GenerateCommand extends Command
{
    use SubcommandSuggestionTrait;

    /**
     * Create generate command.
     */
    public function __construct()
    {
        parent::__construct('generate', 'Extended code generation');
    }

    /**
     * Get available subcommands.
     *
     * @return array<string>
     */
    protected function getAvailableSubcommands(): array
    {
        return ['api', 'tests', 'dto', 'enum', 'docs', 'model', 'repository', 'service', 'help'];
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
            'model' => $this->generateModel(),
            'repository' => $this->generateRepository(),
            'service' => $this->generateService(),
            default => $this->showSubcommandError($subcommand),
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

        // Show help if required parameters are missing
        if ($table === null && $model === null) {
            return $this->showSubcommandHelp('api');
        }

        $format = $this->getOption('format', 'rest');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

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
        } catch (GenerationCancelledException) {
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

        // Show help if required parameters are missing
        if ($model === null && $table === null && $repository === null) {
            return $this->showSubcommandHelp('tests');
        }

        $type = $this->getOption('type', 'unit');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

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
        } catch (GenerationCancelledException) {
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

        // Show help if required parameters are missing
        if ($table === null && $model === null) {
            return $this->showSubcommandHelp('dto');
        }

        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

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
        } catch (GenerationCancelledException) {
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

        // Show help if required parameters are missing
        if ($table === null || $column === null) {
            return $this->showSubcommandHelp('enum');
        }

        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

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
        } catch (GenerationCancelledException) {
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

        // Show help if required parameters are missing
        if ($table === null && $model === null) {
            return $this->showSubcommandHelp('docs');
        }

        $format = $this->getOption('format', 'openapi');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

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
        } catch (GenerationCancelledException) {
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate model.
     *
     * @return int Exit code
     */
    protected function generateModel(): int
    {
        $model = $this->getOption('model');

        // Show help if required parameters are missing
        if ($model === null) {
            return $this->showSubcommandHelp('model');
        }

        $table = $this->getOption('table');
        $namespace = $this->getOption('namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        try {
            ModelGenerator::generate(
                $model,
                is_string($table) ? $table : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                is_string($namespace) ? $namespace : null,
                $force,
                ModelGenerator::parseRelatedModels($this->getOption('relation-models'))
            );
            return 0;
        } catch (GenerationCancelledException) {
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate repository.
     *
     * @return int Exit code
     */
    protected function generateRepository(): int
    {
        $repository = $this->getOption('repository');

        // Show help if required parameters are missing
        if ($repository === null) {
            return $this->showSubcommandHelp('repository');
        }

        $model = $this->getOption('model');
        $table = $this->getOption('table');
        $namespace = $this->getOption('namespace');
        $modelNamespace = $this->getOption('model-namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        try {
            RepositoryGenerator::generate(
                $repository,
                is_string($model) ? $model : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                is_string($namespace) ? $namespace : null,
                is_string($modelNamespace) ? $modelNamespace : null,
                $force
            );
            return 0;
        } catch (GenerationCancelledException) {
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Generate service.
     *
     * @return int Exit code
     */
    protected function generateService(): int
    {
        $service = $this->getOption('service');

        // Show help if required parameters are missing
        if ($service === null) {
            return $this->showSubcommandHelp('service');
        }

        $repository = $this->getOption('repository');
        $namespace = $this->getOption('namespace');
        $repositoryNamespace = $this->getOption('repository-namespace');
        $output = $this->getOption('output');
        $force = (bool)$this->getOption('force', false);

        try {
            ServiceGenerator::generate(
                $service,
                is_string($repository) ? $repository : null,
                is_string($output) ? $output : null,
                $this->getDb(),
                is_string($namespace) ? $namespace : null,
                is_string($repositoryNamespace) ? $repositoryNamespace : null,
                $force
            );
            return 0;
        } catch (GenerationCancelledException) {
            return 0;
        } catch (Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Show help message for specific subcommand.
     *
     * @param string $subcommand Subcommand name
     *
     * @return int Exit code
     */
    protected function showSubcommandHelp(string $subcommand): int
    {
        $this->writeln("Generate {$subcommand}\n");
        $this->writeln("Usage: pdodb generate {$subcommand} [options]\n");

        match ($subcommand) {
            'api' => $this->showApiHelp(),
            'tests' => $this->showTestsHelp(),
            'dto' => $this->showDtoHelp(),
            'enum' => $this->showEnumHelp(),
            'docs' => $this->showDocsHelp(),
            'model' => $this->showModelHelp(),
            'repository' => $this->showRepositoryHelp(),
            'service' => $this->showServiceHelp(),
            default => null,
        };

        return 0;
    }

    /**
     * Show help for api subcommand.
     */
    protected function showApiHelp(): void
    {
        $this->writeln("Generate REST API endpoints/controllers with CRUD operations.\n");
        $this->writeln('Required Options:');
        $this->writeln("  --table=<name> or --model=<name>  One of these is required\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --format=rest      Output format (default: rest)');
        $this->writeln('  --namespace=<ns>   Controller namespace (default: app\\controllers)');
        $this->writeln('  --output=<path>    Output directory path');
        $this->writeln('  --force            Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate api --table=users --format=rest');
        $this->writeln('  pdodb generate api --model=User --format=rest');
        $this->writeln('  pdodb generate api --table=users --output=app/Controllers --namespace=app\\controllers');
    }

    /**
     * Show help for tests subcommand.
     */
    protected function showTestsHelp(): void
    {
        $this->writeln("Generate unit/integration tests for models, tables, or repositories.\n");
        $this->writeln('Required Options:');
        $this->writeln("  --model=<name> or --table=<name> or --repository=<name>  One of these is required\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --type=unit|integration  Test type (default: unit)');
        $this->writeln('  --namespace=<ns>         Test namespace (default: tests\\unit or tests\\integration)');
        $this->writeln('  --output=<path>          Output directory path');
        $this->writeln('  --force                 Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>      Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate tests --model=User --type=unit');
        $this->writeln('  pdodb generate tests --table=users --type=integration');
        $this->writeln('  pdodb generate tests --repository=UserRepository --type=unit');
    }

    /**
     * Show help for dto subcommand.
     */
    protected function showDtoHelp(): void
    {
        $this->writeln("Generate Data Transfer Object (DTO) classes.\n");
        $this->writeln('Required Options:');
        $this->writeln("  --table=<name> or --model=<name>  One of these is required\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --namespace=<ns>   DTO namespace (default: app\\dtos)');
        $this->writeln('  --output=<path>    Output directory path');
        $this->writeln('  --force           Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate dto --table=users');
        $this->writeln('  pdodb generate dto --model=User');
    }

    /**
     * Show help for enum subcommand.
     */
    protected function showEnumHelp(): void
    {
        $this->writeln("Generate PHP Enum classes from database ENUM columns.\n");
        $this->writeln('Required Options:');
        $this->writeln('  --table=<name>     Table name');
        $this->writeln("  --column=<name>    Column name with ENUM type\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --namespace=<ns>   Enum namespace (default: app\\enums)');
        $this->writeln('  --output=<path>    Output directory path');
        $this->writeln('  --force           Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate enum --table=users --column=status');
    }

    /**
     * Show help for docs subcommand.
     */
    protected function showDocsHelp(): void
    {
        $this->writeln("Generate API documentation (OpenAPI/Swagger).\n");
        $this->writeln('Required Options:');
        $this->writeln("  --table=<name> or --model=<name>  One of these is required\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --format=openapi|json  Output format (default: openapi)');
        $this->writeln('  --output=<path>       Output directory path');
        $this->writeln('  --force              Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate docs --table=users --format=openapi');
        $this->writeln('  pdodb generate docs --model=User --format=json');
    }

    /**
     * Show help for model subcommand.
     */
    protected function showModelHelp(): void
    {
        $this->writeln("Generate ActiveRecord model classes from existing database tables.\n");
        $this->writeln('Required Options:');
        $this->writeln("  --model=<name>     Model class name\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --table=<name>     Table name (optional, auto-detected from model name)');
        $this->writeln('  --namespace=<ns>   Model namespace (default: app\\models)');
        $this->writeln('  --relation-models=JSON  Map referenced tables to model class names');
        $this->writeln('  --output=<path>    Output directory path');
        $this->writeln('  --force           Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate model --model=User --table=users');
        $this->writeln('  pdodb generate model --model=User');
    }

    /**
     * Show help for repository subcommand.
     */
    protected function showRepositoryHelp(): void
    {
        $this->writeln("Generate repository classes with CRUD operations for models.\n");
        $this->writeln('Required Options:');
        $this->writeln("  --repository=<name>  Repository class name\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --model=<name>       Model class name (optional, auto-detected from repository name)');
        $this->writeln('  --table=<name>       Table name (optional, auto-detected from model name)');
        $this->writeln('  --namespace=<ns>     Repository namespace (default: app\\repositories)');
        $this->writeln('  --model-namespace=<ns>  Model namespace (default: app\\models)');
        $this->writeln('  --output=<path>      Output directory path');
        $this->writeln('  --force             Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate repository --repository=UserRepository --model=User');
        $this->writeln('  pdodb generate repository --repository=UserRepository --model=User --table=users');
    }

    /**
     * Show help for service subcommand.
     */
    protected function showServiceHelp(): void
    {
        $this->writeln("Generate service classes with business logic methods.\n");
        $this->writeln('Required Options:');
        $this->writeln("  --service=<name>      Service class name\n");
        $this->writeln('Optional Options:');
        $this->writeln('  --repository=<name>   Repository class name (optional, auto-detected from service name)');
        $this->writeln('  --namespace=<ns>      Service namespace (default: app\\services)');
        $this->writeln('  --repository-namespace=<ns>  Repository namespace (default: app\\repositories)');
        $this->writeln('  --output=<path>       Output directory path');
        $this->writeln('  --force              Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>   Use specific database connection\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate service --service=UserService --repository=UserRepository');
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        $this->writeln("Extended Code Generation\n");
        $this->writeln("Usage: pdodb generate <subcommand> [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  model            Generate ActiveRecord models');
        $this->writeln('  repository       Generate repository classes');
        $this->writeln('  service          Generate service classes');
        $this->writeln('  api              Generate REST API endpoints/controllers');
        $this->writeln('  tests            Generate unit/integration tests');
        $this->writeln('  dto              Generate Data Transfer Objects');
        $this->writeln('  enum             Generate Enum classes from database ENUM columns');
        $this->writeln("  docs             Generate API documentation (OpenAPI)\n");
        $this->writeln('Common Options:');
        $this->writeln('  --table=<name>       Table name');
        $this->writeln('  --model=<name>       Model class name');
        $this->writeln('  --namespace=<ns>     PHP namespace (default varies by generator)');
        $this->writeln('  --output=<path>      Output directory path');
        $this->writeln('  --force              Overwrite existing files without confirmation');
        $this->writeln("  --connection=<name>  Use specific database connection\n");
        $this->writeln('Subcommand-specific Options:');
        $this->writeln('  generate model:');
        $this->writeln('    --model=<name>     Model class name (required)');
        $this->writeln("    --table=<name>     Table name (optional, auto-detected from model name)\n");
        $this->writeln('  generate repository:');
        $this->writeln('    --repository=<name>  Repository class name (required)');
        $this->writeln('    --model=<name>       Model class name (optional, auto-detected from repository name)');
        $this->writeln('    --table=<name>       Table name (optional, auto-detected from model name)');
        $this->writeln("    --model-namespace=<ns>  Model namespace (default: app\\\\models)\n");
        $this->writeln('  generate service:');
        $this->writeln('    --service=<name>      Service class name (required)');
        $this->writeln('    --repository=<name>   Repository class name (optional, auto-detected from service name)');
        $this->writeln("    --repository-namespace=<ns>  Repository namespace (default: app\\\\repositories)\n");
        $this->writeln('  generate api:');
        $this->writeln('    --format=rest      Output format (default: rest)');
        $this->writeln("    --table=<name> or --model=<name>  Required\n");
        $this->writeln('  generate tests:');
        $this->writeln('    --type=unit|integration  Test type (default: unit)');
        $this->writeln("    --model=<name> or --table=<name> or --repository=<name>  Required\n");
        $this->writeln('  generate dto:');
        $this->writeln("    --table=<name> or --model=<name>  Required\n");
        $this->writeln('  generate enum:');
        $this->writeln('    --table=<name>     Required');
        $this->writeln("    --column=<name>    Required\n");
        $this->writeln('  generate docs:');
        $this->writeln('    --format=openapi   Output format (default: openapi)');
        $this->writeln("    --table=<name> or --model=<name>  Required\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb generate model --model=User --table=users');
        $this->writeln('  pdodb generate repository --repository=UserRepository --model=User');
        $this->writeln('  pdodb generate service --service=UserService --repository=UserRepository');
        $this->writeln('  pdodb generate api --table=users --format=rest');
        $this->writeln('  pdodb generate api --model=User --format=rest');
        $this->writeln('  pdodb generate tests --model=User --type=unit');
        $this->writeln('  pdodb generate tests --table=users --type=integration');
        $this->writeln('  pdodb generate tests --repository=UserRepository --type=unit');
        $this->writeln('  pdodb generate dto --table=users');
        $this->writeln('  pdodb generate dto --model=User');
        $this->writeln('  pdodb generate enum --table=users --column=status');
        $this->writeln('  pdodb generate docs --table=users --format=openapi');
        $this->writeln('  pdodb generate docs --model=User --format=openapi');
        return 0;
    }
}
