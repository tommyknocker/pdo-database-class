<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\cte;

use Closure;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Manages Common Table Expressions (CTEs) for a query.
 */
class CteManager
{
    /** @var array<CteDefinition> */
    protected array $ctes = [];

    /** @var array<string, mixed> Collected parameters from all CTEs */
    protected array $cteParams = [];

    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;

    /**
     * Constructor.
     *
     * @param ConnectionInterface $connection Database connection.
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
    }

    /**
     * Add a CTE definition.
     *
     * @param CteDefinition $cte CTE definition to add.
     */
    public function add(CteDefinition $cte): void
    {
        $this->ctes[] = $cte;
    }

    /**
     * Check if any CTEs are recursive.
     *
     * @return bool True if at least one CTE is recursive.
     */
    public function hasRecursive(): bool
    {
        foreach ($this->ctes as $cte) {
            if ($cte->isRecursive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all CTEs.
     *
     * @return array<CteDefinition> All CTE definitions.
     */
    public function getAll(): array
    {
        return $this->ctes;
    }

    /**
     * Check if there are any CTEs.
     *
     * @return bool True if no CTEs exist.
     */
    public function isEmpty(): bool
    {
        return empty($this->ctes);
    }

    /**
     * Build the WITH clause SQL.
     *
     * @return string WITH clause SQL or empty string if no CTEs.
     */
    public function buildSql(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        // Clear parameters before rebuilding
        $this->cteParams = [];

        $keyword = $this->hasRecursive() ? 'WITH RECURSIVE' : 'WITH';
        $cteParts = [];

        foreach ($this->ctes as $index => $cte) {
            $cteParts[] = $this->buildCteSql($cte, $index);
        }

        return $keyword . ' ' . implode(', ', $cteParts);
    }

    /**
     * Build SQL for a single CTE.
     *
     * @param CteDefinition $cte CTE definition.
     * @param int $index CTE index for unique parameter naming.
     *
     * @return string CTE SQL.
     */
    protected function buildCteSql(CteDefinition $cte, int $index): string
    {
        $name = $this->dialect->quoteIdentifier($cte->getName());

        // Add column list if specified
        if ($cte->hasColumns()) {
            $columns = array_map(
                fn ($col) => $this->dialect->quoteIdentifier($col),
                $cte->getColumns()
            );
            $name .= ' (' . implode(', ', $columns) . ')';
        }

        // Build query
        $query = $cte->getQuery();

        if ($query instanceof QueryBuilder) {
            $sqlData = $query->toSQL();
            $sql = $sqlData['sql'];
            // Rename parameters to avoid conflicts between CTEs
            $renamedParams = $this->renameParameters($sqlData['params'], $index, $sql);
            $this->cteParams = array_merge($this->cteParams, $renamedParams['params']);
            $sql = $renamedParams['sql'];
        } elseif ($query instanceof Closure) {
            $qb = new QueryBuilder($this->connection);
            $query($qb);
            $sqlData = $qb->toSQL();
            $sql = $sqlData['sql'];
            // Rename parameters to avoid conflicts between CTEs
            $renamedParams = $this->renameParameters($sqlData['params'], $index, $sql);
            $this->cteParams = array_merge($this->cteParams, $renamedParams['params']);
            $sql = $renamedParams['sql'];
        } else {
            // Raw SQL string
            $sql = $query;
        }

        return $name . ' AS (' . $sql . ')';
    }

    /**
     * Rename parameters to make them unique for this CTE.
     *
     * @param array<string, mixed> $params Original parameters.
     * @param int $cteIndex CTE index.
     * @param string $sql SQL string with placeholders.
     *
     * @return array{params: array<string, mixed>, sql: string} Renamed parameters and updated SQL.
     */
    protected function renameParameters(array $params, int $cteIndex, string $sql): array
    {
        $renamedParams = [];

        foreach ($params as $paramName => $paramValue) {
            // Create new unique parameter name with CTE index prefix
            $newParamName = ':cte' . $cteIndex . '_' . ltrim($paramName, ':');
            $renamedParams[$newParamName] = $paramValue;

            // Replace old parameter name with new one in SQL
            $sql = str_replace($paramName, $newParamName, $sql);
        }

        return ['params' => $renamedParams, 'sql' => $sql];
    }

    /**
     * Get collected parameters from all CTEs.
     *
     * @return array<string, mixed> Parameters.
     */
    public function getParams(): array
    {
        return $this->cteParams;
    }

    /**
     * Clear all CTEs.
     */
    public function clear(): void
    {
        $this->ctes = [];
        $this->cteParams = [];
    }
}
