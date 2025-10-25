<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\utils;

class ParameterManager
{
    /** @var array<int, string> */
    private array $usedNames = [];

    /**
     * Generate unique parameter name.
     */
    public function generateUniqueName(string $prefix, string $context = ''): string
    {
        $baseName = ':' . $prefix . '_' . substr(md5($context), 0, 8);
        $name = $baseName;
        $counter = 1;
        
        while (in_array($name, $this->usedNames, true)) {
            $name = $baseName . '_' . $counter++;
        }
        
        $this->usedNames[] = $name;
        return $name;
    }

    /**
     * Reset used names.
     */
    public function reset(): void
    {
        $this->usedNames = [];
    }

    /**
     * Get all used names.
     *
     * @return array<int, string>
     */
    public function getUsedNames(): array
    {
        return $this->usedNames;
    }

    /**
     * Check if name is already used.
     */
    public function isNameUsed(string $name): bool
    {
        return in_array($name, $this->usedNames, true);
    }
}
