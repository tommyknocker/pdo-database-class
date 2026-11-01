<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\BaseCliCommand;

/**
 * Application rejects options a command does not declare, so every option the code reads or documents has to be
 * declared, and a mistyped one has to be reported instead of ignored.
 */
final class CommandOptionDeclarationTests extends TestCase
{
    private const GLOBAL_OPTIONS = ['connection', 'config', 'env', 'help'];

    public function testEveryOptionACommandReadsOrDocumentsIsDeclared(): void
    {
        foreach ((new Application())->getCommands() as $name => $command) {
            $declared = $command->knownOptions();
            $this->assertNotNull($declared, "Command '{$name}' does not declare its options");

            $file = (new ReflectionClass($command))->getFileName();
            $this->assertIsString($file);
            $source = (string) file_get_contents($file);
            preg_match_all("/(?:getOption|hasOption)\\('([a-z0-9-]+)'/", $source, $read);
            preg_match_all('/--([a-z][a-z0-9-]+)/', $source, $documented);
            preg_match_all('/Option\\(\\[([^\\]]+)\\]/', $source, $lists);
            preg_match_all("/'([a-z][a-z0-9-]+)'/", implode(',', $lists[1]), $listed);
            $read[1] = [...$read[1], ...$listed[1]];

            $missing = array_diff(array_unique([...$read[1], ...$documented[1]]), $declared, self::GLOBAL_OPTIONS);
            $this->assertSame([], array_values($missing), "Command '{$name}' uses undeclared options");
        }
    }

    public function testMistypedOptionIsRejectedWithASuggestion(): void
    {
        $app = new Application();
        $result = CliTestCapture::runApplication($app, ['pdodb', 'inbox', 'purge', '--older-then=30d']);

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('unknown option --older-then', $result['stderr']);
        $this->assertStringContainsString('Did you mean --older-than?', $result['stderr']);
    }

    public function testUnrelatedOptionIsRejectedWithAPointerToHelp(): void
    {
        $result = CliTestCapture::runApplication(new Application(), ['pdodb', 'db', 'list', '--frobnicate']);

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString("Run 'pdodb db help'", $result['stderr']);
    }

    public function testGlobalOptionsAreAcceptedByEveryCommand(): void
    {
        $result = CliTestCapture::runApplication(new Application(), ['pdodb', 'inbox', 'help', '--connection=x', '--config=y']);

        $this->assertSame(0, $result['code']);
        $this->assertFalse(getenv('PDODB_CONNECTION'), 'a global option must not leak into the process environment');
        $this->assertFalse(getenv('PDODB_CONFIG_PATH'));
    }

    protected function setUp(): void
    {
        BaseCliCommand::resetIo();
    }
}
