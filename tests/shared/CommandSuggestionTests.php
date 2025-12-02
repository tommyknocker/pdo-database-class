<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\CommandSuggestion;

/**
 * Tests for CommandSuggestion class.
 */
final class CommandSuggestionTests extends BaseSharedTestCase
{
    public function testFindSimilarExactMatch(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result = CommandSuggestion::findSimilar('migrate', $commands);

        $this->assertCount(1, $result);
        $this->assertEquals('migrate', $result[0]);
    }

    public function testFindSimilarCaseInsensitive(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result = CommandSuggestion::findSimilar('MIGRATE', $commands);

        $this->assertCount(1, $result);
        $this->assertEquals('migrate', $result[0]);
    }

    public function testFindSimilarPrefixMatch(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result = CommandSuggestion::findSimilar('migrat', $commands);

        $this->assertCount(1, $result);
        $this->assertEquals('migrate', $result[0]);
    }

    public function testFindSimilarShortPrefix(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result = CommandSuggestion::findSimilar('mi', $commands);

        // Prefix must be at least 3 characters
        $this->assertCount(0, $result);
    }

    public function testFindSimilarLevenshteinDistance(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result = CommandSuggestion::findSimilar('migrat', $commands, 3);

        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertContains('migrate', $result);
    }

    public function testFindSimilarMultipleSuggestions(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate', 'migration'];
        $result = CommandSuggestion::findSimilar('migrat', $commands, 3, 3);

        $this->assertLessThanOrEqual(3, count($result));
        $this->assertContains('migrate', $result);
    }

    public function testFindSimilarNoMatch(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result = CommandSuggestion::findSimilar('xyzabc', $commands, 1);

        $this->assertCount(0, $result);
    }

    public function testFindSimilarEmptyCommands(): void
    {
        $result = CommandSuggestion::findSimilar('migrate', []);

        $this->assertCount(0, $result);
    }

    public function testFindSimilarMaxDistance(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate'];
        $result1 = CommandSuggestion::findSimilar('migrat', $commands, 1);
        $result2 = CommandSuggestion::findSimilar('migrat', $commands, 3);

        // With maxDistance=1, should find fewer matches
        $this->assertLessThanOrEqual(count($result2), count($result1));
    }

    public function testFindSimilarMaxResults(): void
    {
        $commands = ['migrate', 'migration', 'migrator', 'seed', 'schema'];
        $result = CommandSuggestion::findSimilar('migrat', $commands, 3, 2);

        $this->assertLessThanOrEqual(2, count($result));
    }

    public function testFindSimilarPriorityPrefixOverLevenshtein(): void
    {
        $commands = ['migrate', 'migration'];
        $result = CommandSuggestion::findSimilar('migrat', $commands);

        // Prefix match should come first
        $this->assertGreaterThanOrEqual(1, count($result));
        if (count($result) > 0) {
            $this->assertEquals('migrate', $result[0]);
        }
    }

    public function testFormatMessageSingleSuggestion(): void
    {
        $message = CommandSuggestion::formatMessage('migrat', ['migrate']);

        $this->assertEquals('Did you mean: migrate?', $message);
    }

    public function testFormatMessageMultipleSuggestions(): void
    {
        $message = CommandSuggestion::formatMessage('migrat', ['migrate', 'migration']);

        $this->assertEquals('Did you mean: migrate or migration?', $message);
    }

    public function testFormatMessageThreeSuggestions(): void
    {
        $message = CommandSuggestion::formatMessage('migrat', ['migrate', 'migration', 'migrator']);

        $this->assertEquals('Did you mean: migrate, migration or migrator?', $message);
    }

    public function testFormatMessageEmptySuggestions(): void
    {
        $message = CommandSuggestion::formatMessage('migrat', []);

        $this->assertEquals('', $message);
    }

    public function testFindSimilarSubcommands(): void
    {
        $subcommands = ['create', 'up', 'migrate', 'down', 'rollback', 'history', 'new'];
        $result = CommandSuggestion::findSimilar('creat', $subcommands);

        $this->assertCount(1, $result);
        $this->assertEquals('create', $result[0]);
    }

    public function testFindSimilarSubcommandsLevenshtein(): void
    {
        $subcommands = ['create', 'up', 'migrate', 'down', 'rollback', 'history', 'new'];
        $result = CommandSuggestion::findSimilar('histroy', $subcommands, 2);

        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertContains('history', $result);
    }

    public function testFindSimilarSubcommandsMultiple(): void
    {
        $subcommands = ['create', 'up', 'migrate', 'down', 'rollback', 'history', 'new'];
        $result = CommandSuggestion::findSimilar('rolback', $subcommands, 2);

        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertContains('rollback', $result);
    }

    public function testFindSimilarRealWorldScenarios(): void
    {
        $commands = ['migrate', 'seed', 'schema', 'generate', 'model', 'repository', 'service'];

        // Common typos
        $testCases = [
            'migrat' => ['migrate'],
            'migrate' => ['migrate'], // exact
            'seeds' => ['seed'],
            'scheme' => ['schema'],
            'generat' => ['generate'],
            'modle' => ['model'],
            'repositor' => ['repository'],
        ];

        foreach ($testCases as $input => $expected) {
            $result = CommandSuggestion::findSimilar($input, $commands, 3);
            $this->assertGreaterThanOrEqual(1, count($result), "Failed for input: {$input}");
            if (!empty($expected)) {
                $this->assertContains($expected[0], $result, "Failed for input: {$input}");
            }
        }
    }

    public function testFindSimilarUnicodeSupport(): void
    {
        $commands = ['migrate', 'seed', 'schema'];
        $result = CommandSuggestion::findSimilar('migraté', $commands, 3);

        // Should handle unicode gracefully
        $this->assertIsArray($result);
    }
}
