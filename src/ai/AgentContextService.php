<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use Composer\InstalledVersions;
use PDO;
use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\PdoDb;

/** Small evidence-based context for development agents. Never includes credentials. */
class AgentContextService
{
    public function __construct(protected PdoDb $db)
    {
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        $dialect = $this->db->connection->getDialect()->getDriverName();
        return [
            'pdodb_version' => self::packageVersion(),
            'php_version' => PHP_VERSION,
            'database_version' => $this->db->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'dialect' => $dialect,
            'connections' => array_keys($this->db->getConnections()),
            'capabilities' => ['real_explain' => $dialect !== 'sqlsrv', 'explain_analyze' => false,
                'oracle_plan_table' => $dialect === 'oci', 'execute_sql_tool' => false],
            'contracts' => ['get' => 'Array of rows; empty array when no rows match.',
                'getOne' => 'One row, or false when no row matches.',
                'asObject' => 'Rows become stdClass; get() remains an array of rows.'],
            'llm_required' => false,
        ];
    }

    public static function packageVersion(): ?string
    {
        $package = 'tommyknocker/pdo-database-class';
        return InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
    }

    /** @return array<string, mixed> */
    public function searchSchema(string $query = '', int $limit = 20, int $offset = 0): array
    {
        $tables = TableManager::listTables($this->db);
        sort($tables);
        $tables = array_values(array_filter($tables, static fn ($table) => $query === '' || mb_stripos($table, $query, 0, 'UTF-8') !== false));
        $limit = max(1, min(100, $limit));
        return ['tables' => array_slice($tables, $offset, $limit),
            'next_offset' => count($tables) > $offset + $limit ? $offset + $limit : null,
            'total' => count($tables), 'detail_tool' => 'get_schema'];
    }
}
