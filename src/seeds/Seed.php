<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\seeds;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Base class for database seeds.
 *
 * This class provides common methods for seeds, similar to Migration class.
 * All seeds should extend this class and implement run() and rollback() methods.
 */
abstract class Seed implements SeedInterface
{
    /** @var PdoDb Database instance */
    protected PdoDb $db;

    /**
     * Seed constructor.
     *
     * @param PdoDb $db Database instance
     */
    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Run the seed.
     */
    abstract public function run(): void;

    /**
     * Rollback the seed.
     */
    abstract public function rollback(): void;

    /**
     * Get DDL Query Builder for schema operations.
     *
     * @return DdlQueryBuilder
     */
    protected function schema(): DdlQueryBuilder
    {
        return $this->db->schema();
    }

    /**
     * Get Query Builder for data operations.
     *
     * @return QueryBuilder
     */
    protected function find(): QueryBuilder
    {
        return $this->db->find();
    }

    /**
     * Execute raw SQL.
     *
     * @param string $sql SQL statement
     * @param array<int|string, mixed> $params Parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function execute(string $sql, array $params = []): array
    {
        return $this->db->rawQuery($sql, $params);
    }

    /**
     * Insert a single row.
     *
     * @param string $table Table name
     * @param array<string, mixed> $columns Column data
     *
     * @return int Inserted row ID
     */
    protected function insert(string $table, array $columns): int
    {
        return $this->db->find()->table($table)->insert($columns);
    }

    /**
     * Insert multiple rows.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rows Row data
     *
     * @return int Number of inserted rows
     */
    protected function insertMulti(string $table, array $rows): int
    {
        return $this->db->find()->table($table)->insertMulti($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function insertBatch(string $table, array $rows): int
    {
        return $this->insertMulti($table, $rows);
    }

    /**
     * Update rows.
     *
     * @param string $table Table name
     * @param array<string, mixed> $columns Column data
     * @param array<string, mixed> $where Where conditions
     *
     * @return int Number of affected rows
     */
    protected function update(string $table, array $columns, array $where = []): int
    {
        $query = $this->db->find()->table($table);

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->update($columns);
    }

    /**
     * Delete rows.
     *
     * @param string $table Table name
     * @param array<string, mixed> $where Where conditions
     *
     * @return int Number of affected rows
     */
    protected function delete(string $table, array $where = []): int
    {
        $query = $this->db->find()->table($table);

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->delete();
    }

    /**
     * Create a raw value.
     *
     * @param string $value Raw SQL value
     *
     * @return RawValue
     */
    protected function raw(string $value): RawValue
    {
        return new RawValue($value);
    }
}
