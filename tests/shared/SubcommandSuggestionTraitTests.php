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

        $captured = CliTestCapture::capture(static fn (): int => (int) $method->invoke($command, 'create'));

        $this->assertSame(1, $captured['result']);
        $this->assertStringContainsString('Error: Unknown subcommand: create', $captured['output']);
    }

    public function testShowSubcommandErrorWithSuggestion(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');

        $captured = CliTestCapture::capture(static fn (): int => (int) $method->invoke($command, 'creat'));

        $this->assertSame(1, $captured['result']);
        $this->assertStringContainsString('Error: Unknown subcommand: creat', $captured['output']);
        $this->assertStringContainsString('Did you mean', $captured['output']);
        $this->assertStringContainsString('create', $captured['output']);
    }

    public function testShowSubcommandErrorWithMultipleSuggestions(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');

        $captured = CliTestCapture::capture(static fn (): int => (int) $method->invoke($command, 'migrat'));

        $this->assertSame(1, $captured['result']);
        $this->assertStringContainsString('Error: Unknown subcommand: migrat', $captured['output']);
        $this->assertStringContainsString('Did you mean', $captured['output']);
    }

    public function testShowSubcommandErrorWithNoSuggestions(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');

        $captured = CliTestCapture::capture(static fn (): int => (int) $method->invoke($command, 'xyzabc'));

        $this->assertSame(1, $captured['result']);
        $this->assertStringContainsString('Error: Unknown subcommand: xyzabc', $captured['output']);
        $this->assertStringNotContainsString('Did you mean', $captured['output']);
    }

    public function testShowSubcommandErrorWithTypo(): void
    {
        $command = new TestCommandWithSubcommandSuggestion();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showSubcommandError');

        $captured = CliTestCapture::capture(static fn (): int => (int) $method->invoke($command, 'rolback'));

        $this->assertSame(1, $captured['result']);
        $this->assertStringContainsString('Error: Unknown subcommand: rolback', $captured['output']);
        $this->assertStringContainsString('rollback', $captured['output']);
    }
}
