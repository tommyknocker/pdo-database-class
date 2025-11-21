<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

use Psr\EventDispatcher\ListenerProviderInterface;
use tommyknocker\pdodb\events\QueryExecutedEvent;

/**
 * SQL Query Collector for dry-run mode.
 *
 * Collects SQL queries executed during migration execution.
 */
class SqlQueryCollector implements ListenerProviderInterface
{
    /** @var array<int, string> Collected SQL queries */
    protected array $queries = [];

    /**
     * Get collected SQL queries.
     *
     * @return array<int, string>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Clear collected queries.
     */
    public function clear(): void
    {
        $this->queries = [];
    }

    /**
     * Handle query executed event.
     *
     * @param QueryExecutedEvent $event
     */
    public function handleQueryExecuted(QueryExecutedEvent $event): void
    {
        $sql = $event->getSql();
        $params = $event->getParams();

        // Format SQL with parameters
        $formattedSql = $this->formatSqlWithParams($sql, $params);
        $this->queries[] = $formattedSql;
    }

    /**
     * Format SQL query with parameters.
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     *
     * @return string Formatted SQL
     */
    protected function formatSqlWithParams(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        // Simple parameter replacement for display
        $formatted = $sql;
        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? '?' : ':' . $key;
            $formattedValue = $this->formatValue($value);
            // Replace first occurrence of placeholder
            $replaced = preg_replace('/' . preg_quote($placeholder, '/') . '/', $formattedValue, $formatted, 1);
            if (is_string($replaced)) {
                $formatted = $replaced;
            }
        }

        return $formatted;
    }

    /**
     * Format value for SQL display.
     *
     * @param mixed $value Value to format
     *
     * @return string Formatted value
     */
    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if (is_array($value)) {
            try {
                $json = json_encode($value, JSON_THROW_ON_ERROR);
                return "'" . addslashes($json) . "'";
            } catch (\JsonException) {
                return "'" . addslashes('[...]') . "'";
            }
        }

        // Convert to string safely for other types
        if (is_object($value) && method_exists($value, '__toString')) {
            return "'" . addslashes($value->__toString()) . "'";
        }

        return "'" . addslashes(serialize($value)) . "'";
    }

    /**
     * {@inheritDoc}
     *
     * @return iterable<int, callable(QueryExecutedEvent): void>
     */
    public function getListenersForEvent(object $event): iterable
    {
        if ($event instanceof QueryExecutedEvent) {
            yield [$this, 'handleQueryExecuted'];
        }
    }
}
