<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Window function value.
 * Represents SQL window functions like ROW_NUMBER(), RANK(), LAG(), LEAD(), etc.
 */
class WindowFunctionValue extends RawValue
{
    /** @var string Window function name (ROW_NUMBER, RANK, etc.) */
    protected string $function;

    /** @var array<mixed> Function arguments (for LAG, LEAD, etc.) */
    protected array $args;

    /** @var array<string> PARTITION BY columns */
    protected array $partitionBy;

    /** @var array<array<string, string>> ORDER BY expressions [['column' => 'ASC|DESC']] */
    protected array $orderBy;

    /** @var string|null Frame clause (ROWS BETWEEN, RANGE BETWEEN) */
    protected ?string $frameClause;

    /**
     * Constructor.
     *
     * @param string $function Window function name (ROW_NUMBER, RANK, etc.)
     * @param array<mixed> $args Function arguments (for LAG, LEAD, etc.)
     * @param array<string> $partitionBy PARTITION BY columns
     * @param array<array<string, string>> $orderBy ORDER BY expressions [['column' => 'ASC|DESC']]
     * @param string|null $frameClause Frame clause (ROWS BETWEEN, RANGE BETWEEN)
     */
    public function __construct(
        string $function,
        array $args = [],
        array $partitionBy = [],
        array $orderBy = [],
        ?string $frameClause = null
    ) {
        $this->function = $function;
        $this->args = $args;
        $this->partitionBy = $partitionBy;
        $this->orderBy = $orderBy;
        $this->frameClause = $frameClause;

        // Placeholder - will be replaced by dialect
        parent::__construct('');
    }

    /**
     * Get window function name.
     *
     * @return string Function name.
     */
    public function getFunction(): string
    {
        return $this->function;
    }

    /**
     * Get function arguments.
     *
     * @return array<mixed> Arguments.
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Get PARTITION BY columns.
     *
     * @return array<string> Column names.
     */
    public function getPartitionBy(): array
    {
        return $this->partitionBy;
    }

    /**
     * Get ORDER BY expressions.
     *
     * @return array<array<string, string>> Order expressions.
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * Get frame clause.
     *
     * @return string|null Frame clause.
     */
    public function getFrameClause(): ?string
    {
        return $this->frameClause;
    }

    /**
     * Set PARTITION BY columns.
     *
     * @param string|array<string> $columns Column name(s)
     *
     * @return static
     */
    public function partitionBy(string|array $columns): static
    {
        $this->partitionBy = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Add ORDER BY clause.
     *
     * @param string|array<string, string> $column Column name or array of columns with directions
     * @param string $direction Direction (ASC or DESC) when $column is string
     *
     * @return static
     */
    public function orderBy(string|array $column, string $direction = 'ASC'): static
    {
        if (is_array($column)) {
            foreach ($column as $col => $dir) {
                if (is_int($col)) {
                    // Array like ['col1', 'col2'] - use default direction
                    $this->orderBy[] = [$dir => 'ASC'];
                } else {
                    // Array like ['col1' => 'DESC', 'col2' => 'ASC']
                    $this->orderBy[] = [$col => $dir];
                }
            }
        } else {
            $this->orderBy[] = [$column => strtoupper($direction)];
        }
        return $this;
    }

    /**
     * Set frame clause for windowing.
     *
     * @param string|null $frameClause Frame clause (e.g., 'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')
     *
     * @return static
     */
    public function rows(?string $frameClause): static
    {
        $this->frameClause = $frameClause;
        return $this;
    }
}
