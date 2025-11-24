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
     * @return static The current instance.
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): static
    {
        $type = strtoupper(trim($type));
        $tableSql = $this->normalizeTable($tableAlias);
        if ($condition instanceof RawValue) {
            $onSql = $this->resolveRawValue($condition);
        } else {
            $onSql = $this->dialect->normalizeJoinCondition((string)$condition);
        }
        $this->joins[] = "{$type} JOIN {$tableSql} ON {$onSql}";
        return $this;
    }

    /**
     * Add LEFT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): static
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
     * @return static The current instance.
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): static
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
     * @return static The current instance.
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): static
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
     * @param string|callable(QueryBuilder): void $tableOrSubquery Table name or callable that returns a query builder for subquery
     * @param string|RawValue|null $condition Optional ON condition (not always required for LATERAL)
     * @param string $type JOIN type, e.g. INNER, LEFT (default: LEFT)
     * @param string|null $alias Optional alias for LATERAL subquery/table
     *
     * @return static The current instance.
     * @throws RuntimeException If LATERAL JOIN is not supported by the dialect
     */
    public function lateralJoin(
        string|callable $tableOrSubquery,
        string|RawValue|null $condition = null,
        string $type = 'LEFT',
        ?string $alias = null
    ): static {
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
                    $aliasQuoted = $this->dialect->quoteIdentifier($alias);
                } else {
                    // Extract alias from tableSql if present, or generate one
                    if (preg_match('/\s+AS\s+(\w+)/i', $tableSql, $matches)) {
                        $aliasQuoted = $this->dialect->quoteIdentifier($matches[1]);
                    } else {
                        $alias = 'lateral_' . substr(hash('sha256', $tableSql), 0, 16);
                        $aliasQuoted = $this->dialect->quoteIdentifier($alias);
                    }
                }
                $tableSql = "LATERAL {$tableSql}";
            } else {
                // Simple table name, normalize it
                $tableSql = $this->normalizeTable($tableOrSubquery);
                if ($alias !== null) {
                    $tableSql .= ' AS ' . $this->dialect->quoteIdentifier($alias);
                    $aliasQuoted = $this->dialect->quoteIdentifier($alias);
                } else {
                    // Generate alias for simple table name
                    $alias = 'lateral_' . substr(hash('sha256', $tableSql), 0, 16);
                    $aliasQuoted = $this->dialect->quoteIdentifier($alias);
                }
                $tableSql = "LATERAL {$tableSql}";
            }
        }

        // Use dialect-specific formatting for LATERAL JOIN
        $joinSql = $this->dialect->formatLateralJoin($tableSql, $type, $aliasQuoted, $condition);
        $this->joins[] = $joinSql;

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

    /**
     * Get debug information about joins.
     *
     * @return array<string, mixed> Debug information about JOINs
     */
    public function getDebugInfo(): array
    {
        if (empty($this->joins)) {
            return [];
        }

        return [
            'join_count' => count($this->joins),
            'joins' => $this->joins,
        ];
    }
}
