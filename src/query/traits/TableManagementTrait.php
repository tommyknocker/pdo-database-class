<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\traits;

trait TableManagementTrait
{
    /** @var string|null Table prefix */
    protected ?string $prefix = null;

    /**
     * Set table name.
     *
     * @param string $table
     *
     * @return static
     */
    public function setTable(string $table): static
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Set table prefix.
     *
     * @param string|null $prefix
     *
     * @return static
     */
    public function setPrefix(?string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Normalizes a table name by prefixing it with the database prefix if it is set.
     *
     * @param string|null $table
     *
     * @return string The normalized table name.
     */
    protected function normalizeTable(?string $table = null): string
    {
        $table = $table ?: $this->table;
        return $this->dialect->quoteTable($this->prefix . $table);
    }
}
