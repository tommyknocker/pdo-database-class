<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

/**
 * The global scopes of one query: which are registered, which are switched off, and whether they were applied yet.
 */
final class GlobalScopes
{
    /** @var array<string, callable(QueryBuilder, mixed...): QueryBuilder> */
    private array $scopes = [];

    /** @var array<string> */
    private array $disabled = [];

    private bool $applied = false;

    /**
     * @param array<string, callable(QueryBuilder, mixed...): QueryBuilder> $scopes
     */
    public function set(array $scopes): void
    {
        $this->scopes = $scopes;
    }

    public function disable(string $name): void
    {
        $this->disabled[] = $name;
    }

    /**
     * @param array<string> $names
     */
    public function disableMany(array $names): void
    {
        $this->disabled = array_merge($this->disabled, $names);
    }

    public function isDisabled(string $name): bool
    {
        return in_array($name, $this->disabled, true);
    }

    /**
     * @return array<string>
     */
    public function disabled(): array
    {
        return $this->disabled;
    }

    /**
     * Run each enabled scope once, however often the query is executed.
     *
     * @param callable(callable(QueryBuilder, mixed...): QueryBuilder): mixed $apply Applies one scope to the query
     */
    public function applyOnce(callable $apply): void
    {
        if ($this->applied || $this->scopes === []) {
            return;
        }

        foreach ($this->scopes as $name => $scope) {
            if (!$this->isDisabled($name)) {
                $apply($scope);
            }
        }

        $this->applied = true;
    }
}
