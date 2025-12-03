<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Search for values across table columns.
 */
class TableSearcher
{
    protected PdoDb $db;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Search for value in table.
     *
     * @param string $table Table name
     * @param string $searchTerm Search term
     * @param array<string, mixed> $options Search options
     *                                      - column: string|null - Search only in specific column
     *                                      - limit: int - Maximum number of results (default: 100)
     *                                      - searchInJson: bool - Search in JSON/array columns (default: true)
     *
     * @return array<int, array<string, mixed>> Found rows
     * @throws QueryException
     */
    public function search(string $table, string $searchTerm, array $options = []): array
    {
        $column = $options['column'] ?? null;
        $limit = (int)($options['limit'] ?? 100);
        $searchInJson = (bool)($options['searchInJson'] ?? true);

        // Get table columns
        $columns = TableManager::describe($this->db, $table);
        if (empty($columns)) {
            return [];
        }

        // Filter columns if specific column is requested
        $searchColumns = $this->filterColumns($columns, $column);

        if (empty($searchColumns)) {
            return [];
        }

        // Build WHERE conditions using dialect
        $dialect = $this->db->schema()->getDialect();
        $quotedTable = $dialect->quoteTable($table);
        $conditions = [];
        $params = [];

        foreach ($searchColumns as $col) {
            $colName = $dialect->getColumnNameFromMetadata($col);
            if (!is_string($colName)) {
                continue;
            }

            $quotedCol = $dialect->quoteIdentifier($colName);
            $condition = $dialect->buildColumnSearchCondition(
                $quotedCol,
                $searchTerm,
                $col,
                $searchInJson,
                $params
            );

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        if (empty($conditions)) {
            return [];
        }

        // Build query
        $whereClause = '(' . implode(' OR ', $conditions) . ')';
        $sql = "SELECT * FROM {$quotedTable} WHERE {$whereClause} LIMIT " . (int)$limit;

        return $this->db->rawQuery($sql, $params);
    }

    /**
     * Filter columns based on column name.
     *
     * @param array<int, array<string, mixed>> $columns All columns
     * @param string|null $columnName Specific column name to search
     *
     * @return array<int, array<string, mixed>> Filtered columns
     */
    protected function filterColumns(array $columns, ?string $columnName): array
    {
        if ($columnName === null || $columnName === '') {
            return $columns;
        }

        $dialect = $this->db->schema()->getDialect();
        foreach ($columns as $col) {
            $colName = $dialect->getColumnNameFromMetadata($col);
            if (is_string($colName) && strtolower($colName) === strtolower($columnName)) {
                return [$col];
            }
        }

        return [];
    }
}
