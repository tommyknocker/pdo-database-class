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
        echo "PDOdb Project Initialization\n\n";
        echo "Usage: pdodb init [options]\n\n";
        echo "This command guides you through setting up PDOdb configuration\n";
        echo "for your project with an interactive wizard.\n\n";
        echo "Options:\n";
        echo "  --skip-connection-test    Skip database connection test\n";
        echo "  --force                   Overwrite existing files without confirmation\n";
        echo "  --env-only                Create .env file only\n";
        echo "  --config-only             Create config/db.php only\n";
        echo "  --no-structure            Don't create directory structure\n\n";
        echo "Examples:\n";
        echo "  pdodb init                                    Interactive wizard\n";
        echo "  pdodb init --env-only                         Create .env only\n";
        echo "  pdodb init --config-only --skip-connection-test  Create config/db.php without testing connection\n";
        echo "  pdodb init --force                            Overwrite existing files\n";
        return 0;
    }
}
