<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\RawValue;
use tommyknocker\pdodb\query\interfaces\JoinBuilderInterface;
use tommyknocker\pdodb\query\traits\RawValueResolutionTrait;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class JoinBuilder implements JoinBuilderInterface
{
    use RawValueResolutionTrait;
    use TableManagementTrait;

    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected RawValueResolver $rawValueResolver;

    /** @var string|null table name */
    protected ?string $table = null;

    /** @var array<int, string> */
    protected array $joins = [];

    public function __construct(ConnectionInterface $connection, RawValueResolver $rawValueResolver)
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->rawValueResolver = $rawValueResolver;
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
     * Get all joins.
     *
     * @return array<int, string>
     */
    public function getJoins(): array
    {
        return $this->joins;
    }
}
