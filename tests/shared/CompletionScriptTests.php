<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

/**
 * The bash completion script lists the commands by hand; a command added to the application must not be missing
 * from it, and a removed one must not linger.
 */
final class CompletionScriptTests extends TestCase
{
    public function testTheScriptOffersExactlyTheCommandsOfTheApplication(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../../scripts/pdodb-completion.bash');
        $this->assertSame(1, preg_match('/local commands="([^"]+)"/', $script, $match), 'the command list is in the script');

        // `version` is answered by the entry point before any command is looked up
        $offered = array_values(array_diff(explode(' ', trim($match[1])), ['version']));
        $registered = array_keys((new Application())->getCommands());
        sort($offered);
        sort($registered);

        $this->assertSame($registered, $offered);
    }

    public function testEveryCommandHasABranchInTheScript(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../../scripts/pdodb-completion.bash');

        foreach (array_keys((new Application())->getCommands()) as $name) {
            if ($name === 'version') {
                continue;
            }
            $this->assertStringContainsString(
                '"${cmd}" == "' . $name . '"',
                $script,
                "completion has no branch for `{$name}`, so its subcommands and options are not offered"
            );
        }
    }
}
