<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\JoinBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
use tommyknocker\pdodb\query\traits\RawValueResolutionTrait;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class JoinBuilder implements JoinBuilderInterface
{
    use RawValueResolutionTrait;
    use TableManagementTrait;

    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected RawValueResolver $rawValueResolver;
    protected ?ParameterManagerInterface $parameterManager = null;

    /** @var string|null table name */
    protected ?string $table = null;

    /** @var array<int, string> */
    protected array $joins = [];

    public function __construct(
        ConnectionInterface $connection,
        RawValueResolver $rawValueResolver,
        ?ParameterManagerInterface $parameterManager = null
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->rawValueResolver = $rawValueResolver;
        $this->parameterManager = $parameterManager;
    }

    /**
     * Add JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     * @param string $type JOIN type, e.g. INNER, LEFT, RIGHT
     *
     * @return self The current instance.
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self
    {
        $type = strtoupper(trim($type));
        $tableSql = $this->normalizeTable($tableAlias);
        $onSql = $condition instanceof RawValue ? $this->resolveRawValue($condition) : (string)$condition;
        $this->joins[] = "{$type} JOIN {$tableSql} ON {$onSql}";
        return $this;
    }

    /**
     * Add LEFT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return self The current instance.
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->join($tableAlias, $condition, 'LEFT');
        return $this;
    }

    /**
     * Add RIGHT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return self The current instance.
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->join($tableAlias, $condition, 'RIGHT');
        return $this;
    }

    /**
     * Add INNER JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return self The current instance.
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->join($tableAlias, $condition);
        return $this;
    }

    /**
     * Add LATERAL JOIN clause.
     *
     * LATERAL JOINs allow correlated subqueries in FROM clause,
     * where the subquery can reference columns from preceding tables.
     *
     * @param string|callable(\tommyknocker\pdodb\query\QueryBuilder): void $tableOrSubquery Table name or callable that returns a query builder for subquery
     * @param string|RawValue|null $condition Optional ON condition (not always required for LATERAL)
     * @param string $type JOIN type, e.g. INNER, LEFT (default: LEFT)
     * @param string|null $alias Optional alias for LATERAL subquery/table
     *
     * @return self The current instance.
     * @throws RuntimeException If LATERAL JOIN is not supported by the dialect
     */
    public function lateralJoin(
        string|callable $tableOrSubquery,
        string|RawValue|null $condition = null,
        string $type = 'LEFT',
        ?string $alias = null
    ): self {
        if (!$this->dialect->supportsLateralJoin()) {
            throw new RuntimeException(
                'LATERAL JOIN is not supported by ' . $this->dialect->getDriverName() . ' dialect'
            );
        }

        $type = strtoupper(trim($type));

        // Handle subquery via callable
        if (is_callable($tableOrSubquery)) {
            if ($this->parameterManager === null) {
                throw new RuntimeException(
                    'ParameterManager is required for LATERAL JOIN with subquery'
                );
            }

            // Create a temporary QueryBuilder for the subquery
            // Prefix is not critical for subqueries in LATERAL JOIN
            $subqueryBuilder = new QueryBuilder(
                $this->connection,
                ''
            );
            $tableOrSubquery($subqueryBuilder);
            $sub = $subqueryBuilder->toSQL();

            // Merge subquery parameters
            $map = $this->parameterManager->mergeSubParams($sub['params'], 'lat');
            $subquerySql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
            $subquerySql = rtrim(trim($subquerySql), ';');

            // Use provided alias or generate one
            if ($alias === null) {
                $alias = 'lateral_' . substr(hash('sha256', $subquerySql), 0, 16);
            }
            $aliasQuoted = $this->dialect->quoteIdentifier($alias);
            $tableSql = "LATERAL ({$subquerySql}) AS {$aliasQuoted}";
        } else {
            // Handle table name (simple case, e.g., table-valued function)
            // For string input, use as-is (may contain function calls like generate_series)
            // Only normalize if it looks like a simple table name
            if (preg_match('/\s*\(/', $tableOrSubquery) === 1 || str_contains($tableOrSubquery, '(')) {
                // Contains parentheses - likely a function call, use as-is
                $tableSql = $tableOrSubquery;
                if ($alias !== null) {
                    // Alias may already be in the string, check
                    if (!preg_match('/\s+AS\s+/i', $tableSql)) {
                        $tableSql .= ' AS ' . $this->dialect->quoteIdentifier($alias);
                    }
                }
                $tableSql = "LATERAL {$tableSql}";
            } else {
                // Simple table name, normalize it
                $tableSql = $this->normalizeTable($tableOrSubquery);
                if ($alias !== null) {
                    $tableSql .= ' AS ' . $this->dialect->quoteIdentifier($alias);
                }
                $tableSql = "LATERAL {$tableSql}";
            }
        }

        // Build ON condition if provided
        // For LATERAL JOIN, syntax is: [type] JOIN LATERAL ... (not [type] LATERAL ...)
        // CROSS JOIN LATERAL doesn't need ON clause
        // For MySQL, LEFT/INNER JOIN LATERAL may need explicit ON condition
        $isCross = ($type === 'CROSS' || $type === 'CROSS JOIN');
        if ($condition !== null) {
            $onSql = $condition instanceof RawValue ? $this->resolveRawValue($condition) : (string)$condition;
            if ($isCross) {
                $this->joins[] = "CROSS JOIN {$tableSql} ON {$onSql}";
            } else {
                $this->joins[] = "{$type} JOIN {$tableSql} ON {$onSql}";
            }
        } else {
            // For CROSS JOIN LATERAL, no ON clause needed
            if ($isCross) {
                $this->joins[] = "CROSS JOIN {$tableSql}";
            } else {
                // For LEFT/INNER JOIN LATERAL, MySQL may require explicit ON condition
                // PostgreSQL supports LATERAL without ON, but for compatibility add ON true
                $this->joins[] = "{$type} JOIN {$tableSql} ON true";
            }
        }

        return $this;
    }

    /**
     * Get all joins.
     *
     * @return array<int, string>
     */
    public function getJoins(): array
    {
        return $this->joins;
    }
}
