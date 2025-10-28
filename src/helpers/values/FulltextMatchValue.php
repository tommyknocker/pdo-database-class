<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Full-text search value.
 */
class FulltextMatchValue extends RawValue
{
    /** @var string|array<string> Column names to search in */
    protected string|array $columns;

    /** @var string Search term */
    protected string $searchTerm;

    /** @var string|null Search mode: natural, boolean, expansion (MySQL only) */
    protected ?string $mode;

    /** @var bool Enable query expansion (MySQL only) */
    protected bool $withQueryExpansion;

    /**
     * Constructor.
     *
     * @param string|array<string> $columns Column name(s) to search in.
     * @param string $searchTerm The search term.
     * @param string|null $mode Search mode: 'natural', 'boolean', 'expansion' (MySQL only).
     * @param bool $withQueryExpansion Enable query expansion (MySQL only).
     */
    public function __construct(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false)
    {
        $this->columns = $columns;
        $this->searchTerm = $searchTerm;
        $this->mode = $mode;
        $this->withQueryExpansion = $withQueryExpansion;

        // Placeholder that will be replaced by dialect
        parent::__construct('', ['search_term' => $searchTerm]);
    }

    /**
     * Get column names.
     *
     * @return string|array<string> Column names.
     */
    public function getColumns(): string|array
    {
        return $this->columns;
    }

    /**
     * Get search term.
     *
     * @return string Search term.
     */
    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    /**
     * Get search mode.
     *
     * @return string|null Search mode.
     */
    public function getMode(): ?string
    {
        return $this->mode;
    }

    /**
     * Get query expansion flag.
     *
     * @return bool Whether query expansion is enabled.
     */
    public function isWithQueryExpansion(): bool
    {
        return $this->withQueryExpansion;
    }
}

