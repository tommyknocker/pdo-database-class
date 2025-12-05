<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\seeds;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Seed data generator - generates seed files from existing database data.
 *
 * This class extracts data from database tables and generates seed files
 * that can be used to populate test databases with real data.
 */
class SeedDataGenerator
{
    /** @var PdoDb Database instance */
    protected PdoDb $db;

    /**
     * SeedDataGenerator constructor.
     *
     * @param PdoDb $db Database instance
     */
    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Generate seed file content from table data.
     *
     * @param string $tableName Table name
     * @param array<string, mixed> $options Generation options
     * @return array{content: string, rowCount: int, warnings: array<string>}
     */
    public function generate(string $tableName, array $options = []): array
    {
        $limit = null;
        if (isset($options['limit'])) {
            $limitVal = $options['limit'];
            if ($limitVal !== null && is_numeric($limitVal)) {
                $limit = (int)$limitVal;
            }
        }
        $where = null;
        if (isset($options['where'])) {
            $whereVal = $options['where'];
            if ($whereVal !== null && is_string($whereVal)) {
                $where = $whereVal;
            }
        }
        $orderBy = null;
        if (isset($options['order_by'])) {
            $orderByVal = $options['order_by'];
            if ($orderByVal !== null && is_string($orderByVal)) {
                $orderBy = $orderByVal;
            }
        }
        $excludeColumns = isset($options['exclude']) && $options['exclude'] !== null ? $options['exclude'] : [];
        $includeColumns = null;
        if (isset($options['include'])) {
            $includeVal = $options['include'];
            if ($includeVal !== null) {
                $includeColumns = $includeVal;
            }
        }
        $chunkSize = 1000;
        if (isset($options['chunk_size'])) {
            $chunkSizeVal = $options['chunk_size'];
            if (is_numeric($chunkSizeVal)) {
                $chunkSize = (int)$chunkSizeVal;
            }
        }
        $preserveIds = isset($options['preserve_ids']) ? (bool)$options['preserve_ids'] : true;
        $skipTimestamps = isset($options['skip_timestamps']) ? (bool)$options['skip_timestamps'] : false;
        $format = isset($options['format']) && is_string($options['format']) ? $options['format'] : 'pretty';

        $warnings = [];

        // Get table structure
        $columns = $this->db->describe($tableName);
        if (empty($columns)) {
            throw new QueryException("Table '{$tableName}' does not exist or has no columns");
        }

        // Filter columns
        $columnNames = array_column($columns, 'name');
        if ($includeColumns !== null) {
            $includeCols = is_array($includeColumns) ? $includeColumns : explode(',', $includeColumns);
            $includeCols = array_map('trim', $includeCols);
            $columnNames = array_intersect($columnNames, $includeCols);
        }
        if (!empty($excludeColumns)) {
            $excludeCols = is_array($excludeColumns) ? $excludeColumns : explode(',', (string)$excludeColumns);
            $excludeCols = array_map('trim', $excludeCols);
            $columnNames = array_diff($columnNames, $excludeCols);
        }

        if (empty($columnNames)) {
            throw new QueryException("No columns to export after filtering");
        }

        // Get primary key or unique indexes for rollback
        $primaryKey = $this->getPrimaryKey($tableName);
        $uniqueIndexes = $this->getUniqueIndexes($tableName);
        $rollbackColumns = $primaryKey ?? ($uniqueIndexes[0] ?? null);

        // Build query
        $query = $this->db->find()->from($tableName)->select($columnNames);

        if ($where !== null) {
            // Simple WHERE parsing - for complex cases, user should use raw SQL
            if (preg_match('/^(\w+)\s*([=<>!]+)\s*(.+)$/', $where, $matches)) {
                $column = trim($matches[1]);
                $operator = trim($matches[2]);
                $value = trim($matches[3], " '\"");
                if ($operator === '=') {
                    $query->where($column, $value);
                } else {
                    $query->where($column, new \tommyknocker\pdodb\helpers\values\RawValue("{$operator} " . $this->db->connection->getDialect()->quoteValue($value)));
                }
            } else {
                // Use raw WHERE
                $query->where(new \tommyknocker\pdodb\helpers\values\RawValue($where));
            }
        }

        if ($orderBy !== null) {
            $orderByColumns = is_array($orderBy) ? $orderBy : explode(',', $orderBy);
            foreach ($orderByColumns as $col) {
                $col = trim($col);
                if (preg_match('/^(\w+)\s+(ASC|DESC)$/i', $col, $matches)) {
                    $query->orderBy($matches[1], strtoupper($matches[2]));
                } else {
                    $query->orderBy($col);
                }
            }
        } else {
            // Default order by primary key or first column
            if ($primaryKey !== null && count($primaryKey) === 1) {
                $query->orderBy($primaryKey[0]);
            } else {
                $query->orderBy($columnNames[0]);
            }
        }

        if ($limit !== null) {
            $query->limit((int)$limit);
        }

        // Fetch data
        $dataRaw = $query->get();
        // Ensure data is indexed by integers
        $data = [];
        foreach ($dataRaw as $row) {
            $data[] = $row;
        }
        $rowCount = count($data);

        if ($rowCount === 0) {
            throw new QueryException("No data found in table '{$tableName}'");
        }

        // Check for binary data
        foreach ($data as $row) {
            foreach ($row as $column => $value) {
                if (is_resource($value) || (is_string($value) && !mb_check_encoding($value, 'UTF-8'))) {
                    $warnings[] = "Column '{$column}' contains binary data and will be skipped";
                    $columnNames = array_diff($columnNames, [$column]);
                }
            }
        }

        // Filter timestamp columns if needed
        if ($skipTimestamps) {
            $timestampColumns = ['created_at', 'updated_at', 'deleted_at', 'timestamp'];
            $columnNames = array_filter($columnNames, function ($col) use ($timestampColumns) {
                return !in_array(strtolower($col), $timestampColumns, true);
            });
        }

        // Generate class name
        $className = $this->generateClassName($tableName);

        // Generate code
        $content = $this->generateSeedContent(
            $className,
            $tableName,
            $data,
            $columnNames,
            $rollbackColumns,
            (int)$chunkSize,
            (bool)$preserveIds,
            (string)$format
        );

        // Replace placeholder table name with actual table name
        $tableNameEscaped = $this->escapeString($tableName);
        $content = str_replace("'TABLE_PLACEHOLDER'", "'" . $tableNameEscaped . "'", $content);

        return [
            'content' => $content,
            'rowCount' => $rowCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get primary key columns for a table.
     *
     * @param string $tableName Table name
     * @return array<string>|null Primary key columns or null if no primary key
     */
    protected function getPrimaryKey(string $tableName): ?array
    {
        try {
            $indexes = $this->db->schema()->getIndexes($tableName);
            foreach ($indexes as $index) {
                if (($index['primary'] ?? false) || ($index['type'] ?? '') === 'PRIMARY') {
                    $columns = $index['columns'] ?? [];
                    if (!empty($columns)) {
                        $columnArray = is_array($columns) ? $columns : [$columns];
                        // Ensure all values are strings
                        $result = [];
                        foreach ($columnArray as $col) {
                            $result[] = (string)$col;
                        }
                        return $result;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore errors, return null
        }

        return null;
    }

    /**
     * Get unique indexes for a table.
     *
     * @param string $tableName Table name
     * @return array<array<string>> Array of unique index column arrays
     */
    protected function getUniqueIndexes(string $tableName): array
    {
        $uniqueIndexes = [];
        try {
            $indexes = $this->db->schema()->getIndexes($tableName);
            foreach ($indexes as $index) {
                if (($index['unique'] ?? false) && !($index['primary'] ?? false)) {
                    $columns = $index['columns'] ?? [];
                    if (!empty($columns)) {
                        $columnArray = is_array($columns) ? $columns : [$columns];
                        // Ensure all values are strings
                        $result = [];
                        foreach ($columnArray as $col) {
                            $result[] = (string)$col;
                        }
                        $uniqueIndexes[] = $result;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }

        return $uniqueIndexes;
    }

    /**
     * Generate class name from table name.
     *
     * @param string $tableName Table name
     * @return string Class name
     */
    protected function generateClassName(string $tableName): string
    {
        // Remove prefix if exists
        $name = $tableName;
        if (preg_match('/^[a-z_]+_(.+)$/i', $name, $matches)) {
            $name = $matches[1];
        }

        // Convert snake_case to PascalCase
        $className = str_replace('_', '', ucwords($name, '_'));
        return $className . 'Seed';
    }

    /**
     * Generate seed file content.
     *
     * @param string $className Class name
     * @param string $tableName Table name
     * @param array<int, array<string, mixed>> $data Row data
     * @param array<string> $columnNames Column names to include
     * @param array<string>|null $rollbackColumns Columns to use for rollback
     * @param int $chunkSize Chunk size for insertMulti
     * @param bool $preserveIds Whether to preserve IDs
     * @param string $format Code format (pretty/compact)
     * @return string Generated PHP code
     */
    protected function generateSeedContent(
        string $className,
        string $tableName,
        array $data,
        array $columnNames,
        ?array $rollbackColumns,
        int $chunkSize,
        bool $preserveIds,
        string $format
    ): string {
        $indent = $format === 'pretty' ? '        ' : '    ';
        $newline = $format === 'pretty' ? "\n" : '';

        // Generate data array
        $dataCode = $this->generateDataArray($data, $columnNames, $preserveIds, $chunkSize, $indent, $newline);

        // Generate rollback code
        $rollbackCode = $this->generateRollbackCode($data, $rollbackColumns, $columnNames, $indent, $newline);

        // Generate class
        $generatedAt = date('Y-m-d H:i:s');
        $rowCount = count($data);

        return <<<PHP
<?php

declare(strict_types=1);

use tommyknocker\pdodb\seeds\Seed;

/**
 * {$className} - Generated from database data.
 * Generated: {$generatedAt}
 * Source table: {$tableName}
 * Rows: {$rowCount}
 */
class {$className} extends Seed
{
    /**
     * Run the seed.
     */
    public function run(): void
    {
{$dataCode}
    }

    /**
     * Rollback the seed.
     */
    public function rollback(): void
    {
{$rollbackCode}
    }
}
PHP;
    }

    /**
     * Generate data array code.
     *
     * @param array<int, array<string, mixed>> $data Row data
     * @param array<string> $columnNames Column names
     * @param bool $preserveIds Whether to preserve IDs
     * @param string $indent Indentation string
     * @param string $newline Newline string for formatting
     * @return string Generated code
     */
    protected function generateDataArray(
        array $data,
        array $columnNames,
        bool $preserveIds,
        int $chunkSize,
        string $indent,
        string $newline
    ): string {
        if (empty($data)) {
            return $indent . '// No data to insert' . $newline;
        }

        $chunkSizeInt = max(1, (int)$chunkSize);
        $chunks = array_chunk($data, $chunkSizeInt);
        $code = '';

        foreach ($chunks as $chunkIndex => $chunk) {
            if (count($chunks) > 1) {
                $code .= $indent . "// Chunk " . ($chunkIndex + 1) . " of " . count($chunks) . $newline;
            }

            $code .= $indent . '$data = [' . $newline;

            foreach ($chunk as $row) {
                $code .= $indent . '    [' . $newline;
                foreach ($columnNames as $column) {
                    $value = $row[$column] ?? null;
                    $code .= $indent . '        \'' . $this->escapeString($column) . '\' => ' . $this->formatValue($value, $preserveIds) . ',' . $newline;
                }
                $code .= $indent . '    ],' . $newline;
            }

            $code .= $indent . '];' . $newline;
            $code .= $indent . '$this->insertMulti(\'TABLE_PLACEHOLDER\', $data);' . $newline;

            if ($chunkIndex < count($chunks) - 1) {
                $code .= $newline;
            }
        }

        return $code;
    }

    /**
     * Generate rollback code.
     *
     * @param array<int, array<string, mixed>> $data Row data
     * @param array<string>|null $rollbackColumns Columns to use for rollback
     * @param array<string> $allColumns All column names
     * @param string $indent Indentation string
     * @param string $newline Newline string
     * @return string Generated code
     */
    protected function generateRollbackCode(
        array $data,
        ?array $rollbackColumns,
        array $allColumns,
        string $indent,
        string $newline
    ): string {
        if (empty($data)) {
            return $indent . '// No data to rollback' . $newline;
        }

        if ($rollbackColumns !== null && !empty($rollbackColumns)) {
            // Use primary key or unique index
            $code = '';
            foreach ($data as $row) {
                $conditions = [];
                foreach ($rollbackColumns as $col) {
                    $value = $row[$col] ?? null;
                    if ($value !== null) {
                        $conditions[] = '\'' . $this->escapeString($col) . '\' => ' . $this->formatValue($value, true);
                    }
                }
                if (!empty($conditions)) {
                    $code .= $indent . '$this->delete(\'TABLE_PLACEHOLDER\', [' . implode(', ', $conditions) . ']);' . $newline;
                }
            }
            return $code;
        }

        // Fallback: delete by all columns (less efficient but works)
        $code = '';
        foreach ($data as $row) {
            $conditions = [];
            foreach ($allColumns as $col) {
                $value = $row[$col] ?? null;
                if ($value !== null) {
                    $conditions[] = '\'' . $this->escapeString($col) . '\' => ' . $this->formatValue($value, true);
                }
            }
            if (!empty($conditions)) {
                $code .= $indent . '$this->delete(\'TABLE_PLACEHOLDER\', [' . implode(', ', $conditions) . ']);' . $newline;
            }
        }

        return $code;
    }

    /**
     * Format value for PHP code.
     *
     * @param mixed $value Value to format
     * @param bool $preserveIds Whether to preserve IDs
     * @return string Formatted PHP code
     */
    protected function formatValue($value, bool $preserveIds): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            $json = json_encode($value);
            $jsonStr = is_string($json) ? $json : '[]';
            return 'json_decode(\'' . $this->escapeString($jsonStr) . '\', true)';
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return '\'' . $this->escapeString((string)$value) . '\'';
            }
            $json = json_encode($value);
            $jsonStr = is_string($json) ? $json : '{}';
            return 'json_decode(\'' . $this->escapeString($jsonStr) . '\', true)';
        }

        // String value
        $str = is_string($value) ? $value : (string)$value;

        // Check if it's a date/time that should use Db::raw()
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $str)) {
            return '\'' . $this->escapeString($str) . '\'';
        }

        return '\'' . $this->escapeString($str) . '\'';
    }

    /**
     * Escape string for PHP code.
     *
     * @param string $str String to escape
     * @return string Escaped string
     */
    protected function escapeString(string $str): string
    {
        return str_replace(
            ['\\', '\'', "\n", "\r", "\t"],
            ['\\\\', '\\\'', '\\n', '\\r', '\\t'],
            $str
        );
    }

}

