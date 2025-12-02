<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\traits;

use tommyknocker\pdodb\cli\CommandSuggestion;

/**
 * Trait for commands that support subcommands with suggestions.
 */
trait SubcommandSuggestionTrait
{
    /**
     * Get available subcommands.
     *
     * @return array<string> Array of subcommand names
     */
    abstract protected function getAvailableSubcommands(): array;

    /**
     * Show error with subcommand suggestion.
     *
     * @param string $subcommand Unknown subcommand
     *
     * @return int Exit code
     */
    protected function showSubcommandError(string $subcommand): int
    {
        echo "Error: Unknown subcommand: {$subcommand}\n";

        $availableSubcommands = $this->getAvailableSubcommands();
        $suggestions = CommandSuggestion::findSimilar($subcommand, $availableSubcommands);

        if (!empty($suggestions)) {
            $message = CommandSuggestion::formatMessage($subcommand, $suggestions);
            echo "\n" . $message . "\n";
        }

        return 1;
    }
}
