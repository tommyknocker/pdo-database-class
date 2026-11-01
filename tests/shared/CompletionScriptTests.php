<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

/**
 * The bash completion script is written by hand, so it must not fall behind the registered commands.
 */
final class CompletionScriptTests extends TestCase
{
    public function testEveryRegisteredCommandIsCompleted(): void
    {
        $script = file_get_contents(__DIR__ . '/../../scripts/pdodb-completion.bash');
        $this->assertIsString($script);
        $this->assertSame(1, preg_match('/local commands="([^"]+)"/', $script, $matches));
        $completed = explode(' ', $matches[1]);

        foreach (array_keys((new Application())->getCommands()) as $name) {
            $this->assertContains($name, $completed, "Command '{$name}' is missing from scripts/pdodb-completion.bash");
        }
    }
}
