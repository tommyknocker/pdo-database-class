<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Utility class for suggesting similar commands.
 */
class CommandSuggestion
{
    /**
     * Find similar command names using Levenshtein distance.
     *
     * @param string $input Input command name
     * @param array<string> $availableCommands Available command names
     * @param int $maxDistance Maximum Levenshtein distance (default: 3)
     * @param int $maxResults Maximum number of suggestions (default: 3)
     *
     * @return array<string> Array of suggested command names
     */
    public static function findSimilar(
        string $input,
        array $availableCommands,
        int $maxDistance = 3,
        int $maxResults = 3
    ): array {
        if (empty($availableCommands)) {
            return [];
        }

        $suggestions = [];
        $inputLower = mb_strtolower($input, 'UTF-8');

        foreach ($availableCommands as $command) {
            $commandLower = mb_strtolower($command, 'UTF-8');

            // Exact match (case-insensitive) - highest priority
            if ($commandLower === $inputLower) {
                return [$command];
            }

            // Check if input is a prefix of command (e.g., "migrat" -> "migrate")
            if (str_starts_with($commandLower, $inputLower) && strlen($inputLower) >= 3) {
                $suggestions[] = [
                    'command' => $command,
                    'distance' => 0,
                    'type' => 'prefix',
                ];
                continue;
            }

            // Check if command is a prefix of input (e.g., "migrate" -> "migrat")
            if (str_starts_with($inputLower, $commandLower) && strlen($commandLower) >= 3) {
                $suggestions[] = [
                    'command' => $command,
                    'distance' => 0,
                    'type' => 'contains',
                ];
                continue;
            }

            // Calculate Levenshtein distance
            $distance = levenshtein($inputLower, $commandLower);

            if ($distance <= $maxDistance) {
                $suggestions[] = [
                    'command' => $command,
                    'distance' => $distance,
                    'type' => 'levenshtein',
                ];
            }
        }

        // Sort by priority: prefix > contains > levenshtein (by distance)
        usort($suggestions, static function (array $a, array $b): int {
            $typeOrder = ['prefix' => 0, 'contains' => 1, 'levenshtein' => 2];
            $aType = $a['type'];
            $bType = $b['type'];
            // Type is always one of: 'prefix', 'contains', 'levenshtein'
            // @phpstan-ignore-next-line
            $aOrder = $typeOrder[$aType] ?? 3;
            // @phpstan-ignore-next-line
            $bOrder = $typeOrder[$bType] ?? 3;
            $typeDiff = $aOrder <=> $bOrder;
            if ($typeDiff !== 0) {
                return $typeDiff;
            }
            return $a['distance'] <=> $b['distance'];
        });

        // Extract command names and limit results
        $result = [];
        foreach (array_slice($suggestions, 0, $maxResults) as $suggestion) {
            $result[] = $suggestion['command'];
        }

        return array_unique($result);
    }

    /**
     * Format suggestion message.
     *
     * @param string $input Input command
     * @param array<string> $suggestions Suggested commands
     *
     * @return string Formatted message
     */
    public static function formatMessage(string $input, array $suggestions): string
    {
        if (empty($suggestions)) {
            return '';
        }

        if (count($suggestions) === 1) {
            return "Did you mean: {$suggestions[0]}?";
        }

        $last = array_pop($suggestions);
        $others = implode(', ', $suggestions);
        return "Did you mean: {$others} or {$last}?";
    }
}
