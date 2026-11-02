<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

/**
 * What the command line does when it is used wrongly: a non-zero exit code and a message that says what is wrong,
 * with nothing written to the database or the project.
 */
final class CliFailureModeTests extends TestCase
{
    private string $dbPath;

    private string $workDir;

    private string $previousCwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_failure_' . uniqid() . '.sqlite';
        $this->workDir = sys_get_temp_dir() . '/pdodb_failure_dir_' . uniqid();
        mkdir($this->workDir);
        $this->previousCwd = (string)getcwd();
        chdir($this->workDir);
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        @unlink($this->dbPath);
        $this->removeDirectory($this->workDir);
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }
        @rmdir($directory);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function misuse(): array
    {
        return [
            'db create without a name' => [['db', 'create'], 'Database name is required'],
            'db unknown subcommand' => [['db', 'bogus'], 'Unknown subcommand: bogus'],
            'dump with both only-flags' => [['dump', '--schema-only', '--data-only'], 'Cannot use --schema-only and --data-only together'],
            'dump of a missing table' => [['dump', 'no_such_table'], "Table 'no_such_table' does not exist"],
            'restore of a missing file' => [['dump', 'restore', '/no/such/file.sql', '--force'], 'Dump file not found: /no/such/file.sql'],
            'migrate create without a name' => [['migrate', 'create'], 'Migration name is required'],
            'migrate unknown subcommand' => [['migrate', 'bogus'], 'Unknown subcommand: bogus'],
            'model make without a name' => [['model', 'make'], 'Model name is required'],
            'model unknown subcommand' => [['model', 'bogus'], 'Unknown subcommand: bogus'],
            'monitor unknown subcommand' => [['monitor', 'bogus'], 'Unknown subcommand: bogus'],
            'repository make without a name' => [['repository', 'make'], 'Repository name is required'],
            'repository unknown subcommand' => [['repository', 'bogus'], 'Unknown subcommand: bogus'],
            'service make without a name' => [['service', 'make'], 'Service name is required'],
            'service unknown subcommand' => [['service', 'bogus'], 'Unknown subcommand: bogus'],
            'seed create without a name' => [['seed', 'create'], 'Seed name is required'],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('misuse')]
    public function testMisuseExitsNonZeroWithAnExplanation(array $arguments, string $message): void
    {
        $result = CliTestCapture::runApplication(new Application(), array_merge(['pdodb'], $arguments));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Error: ' . $message, $result['output']);
        $this->assertStringNotContainsString('Error: Error:', $result['output'], 'a message is prefixed once');
    }

    public function testRestoreWithoutForceAsksFirstAndCancelsWhenNobodyAnswers(): void
    {
        $result = CliTestCapture::runApplication(new Application(), ['pdodb', 'dump', 'restore', '/no/such/file.sql']);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Operation cancelled', $result['output']);
    }

    public function testGeneratorsNeedingNoSubcommandPrintTheirUsage(): void
    {
        foreach ([['repository', 'Usage: pdodb repository make'], ['service', 'Usage: pdodb service make']] as [$command, $usage]) {
            $result = CliTestCapture::runApplication(new Application(), ['pdodb', $command]);

            $this->assertSame(0, $result['code'], $command);
            $this->assertStringContainsString($usage, $result['output']);
        }
    }

    public function testFailedGenerationLeavesNoFilesBehind(): void
    {
        CliTestCapture::runApplication(new Application(), ['pdodb', 'seed', 'create']);
        CliTestCapture::runApplication(new Application(), ['pdodb', 'repository', 'make']);

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }

        $this->assertSame([], $files);
    }
}
