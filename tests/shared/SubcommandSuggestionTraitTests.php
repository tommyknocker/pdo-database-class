<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;

/**
 * Test class that uses SubcommandSuggestionTrait.
 */
class TestCommandWithSubcommandSuggestion
{
    use SubcommandSuggestionTrait;

    protected function getAvailableSubcommands(): array
    {
        return ['create', 'drop', 'migrate', 'rollback', 'status'];
    }
}

/**
 * Tests for SubcommandSuggestionTrait.
 */
final class SubcommandSuggestionTraitTests extends BaseSharedTestCase
{
    public function testShowSubcommandErrorWithExactMatch(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');
        $method->setAccessible(true);

        ob_start();
        $exitCode = $method->invoke($command, 'create');
        $output = ob_get_clean();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Error: Unknown subcommand: create', $output);
    }

    public function testShowSubcommandErrorWithSuggestion(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');
        $method->setAccessible(true);

        ob_start();
        $exitCode = $method->invoke($command, 'creat');
        $output = ob_get_clean();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Error: Unknown subcommand: creat', $output);
        $this->assertStringContainsString('Did you mean', $output);
        $this->assertStringContainsString('create', $output);
    }

    public function testShowSubcommandErrorWithMultipleSuggestions(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');
        $method->setAccessible(true);

        ob_start();
        $exitCode = $method->invoke($command, 'migrat');
        $output = ob_get_clean();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Error: Unknown subcommand: migrat', $output);
        $this->assertStringContainsString('Did you mean', $output);
    }

    public function testShowSubcommandErrorWithNoSuggestions(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');
        $method->setAccessible(true);

        ob_start();
        $exitCode = $method->invoke($command, 'xyzabc');
        $output = ob_get_clean();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Error: Unknown subcommand: xyzabc', $output);
        $this->assertStringNotContainsString('Did you mean', $output);
    }

    public function testShowSubcommandErrorWithTypo(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');
        $method->setAccessible(true);

        ob_start();
        $exitCode = $method->invoke($command, 'rolback');
        $output = ob_get_clean();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Error: Unknown subcommand: rolback', $output);
        $this->assertStringContainsString('rollback', $output);
    }
}
