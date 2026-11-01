<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\InitWizard;

/**
 * Init command for project initialization.
 */
class InitCommand extends Command
{
    /**
     * Create init command.
     */
    public function __construct()
    {
        parent::__construct('init', 'Initialize PDOdb project configuration');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        // Check for help option
        if ($this->getOption('help', false)) {
            $this->showHelp();
            return 0;
        }

        $skipConnectionTest = (bool)$this->getOption('skip-connection-test', false);
        $force = (bool)$this->getOption('force', false);
        $envOnly = (bool)$this->getOption('env-only', false);
        $configOnly = (bool)$this->getOption('config-only', false);
        $noStructure = (bool)$this->getOption('no-structure', false);

        // Determine format from options
        $format = 'interactive';
        if ($envOnly) {
            $format = 'env';
        } elseif ($configOnly) {
            $format = 'config';
        }

        $wizard = new InitWizard($this, $skipConnectionTest, $force, $format, $noStructure);
        return $wizard->run();
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        $this->writeln("PDOdb Project Initialization\n");
        $this->writeln("Usage: pdodb init [options]\n");
        $this->writeln('This command guides you through setting up PDOdb configuration');
        $this->writeln("for your project with an interactive wizard.\n");
        $this->writeln('Options:');
        $this->writeln('  --skip-connection-test    Skip database connection test');
        $this->writeln('  --force                   Overwrite existing files without confirmation');
        $this->writeln('  --env-only                Create .env file only');
        $this->writeln('  --config-only             Create config/db.php only');
        $this->writeln("  --no-structure            Don't create directory structure\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb init                                    Interactive wizard');
        $this->writeln('  pdodb init --env-only                         Create .env only');
        $this->writeln('  pdodb init --config-only --skip-connection-test  Create config/db.php without testing connection');
        $this->writeln('  pdodb init --force                            Overwrite existing files');
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'config-only',
            'env-only',
            'force',
            'no-structure',
            'skip-connection-test',
        ];
    }
}
