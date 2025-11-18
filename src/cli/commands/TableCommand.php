<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;

class TableCommand extends Command
{
    public function __construct()
    {
        parent::__construct('table', 'Manage database tables');
    }

    public function execute(): int
    {
        $sub = $this->getArgument(0);
        if ($sub === null || $sub === '--help' || $sub === 'help') {
            return $this->showHelp();
        }
        return match ($sub) {
            'info' => $this->showTableInfo(),
            'list' => $this->list(),
            'exists' => $this->exists(),
            'create' => $this->create(),
            'drop' => $this->drop(),
            'rename' => $this->rename(),
            'truncate' => $this->truncate(),
            'describe' => $this->describe(),
            'columns' => $this->columns(),
            'indexes' => $this->indexes(),
            'keys' => $this->keys(),
            default => $this->showError("Unknown subcommand: {$sub}"),
        };
    }

    protected function showTableInfo(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required');
        }
        $format = $this->getOption('format', 'table');
        $db = $this->getDb();
        $info = TableManager::info($db, $table);
        return $this->printFormatted($info, (string)$format);
    }

    protected function list(): int
    {
        $schema = $this->getOption('schema');
        $format = $this->getOption('format', 'table');
        $db = $this->getDb();
        $tables = TableManager::listTables($db, is_string($schema) ? $schema : null);
        if (empty($tables) && (string)$format === 'table') {
            static::info('No tables found');
            return 0;
        }
        return $this->printFormatted(['tables' => $tables], (string)$format);
    }

    protected function exists(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required');
        }
        $db = $this->getDb();
        $exists = TableManager::tableExists($db, $table);
        echo $exists ? "✓ Table '{$table}' exists\n" : "ℹ Table '{$table}' does not exist\n";
        return $exists ? 0 : 1;
    }

    protected function create(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required');
        }
        $force = (bool)$this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation("Are you sure you want to create table '{$table}'?", true);
            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }
        $ifNotExists = (bool)$this->getOption('if-not-exists', false);
        $columnsOpt = $this->getOption('columns');
        if (!is_string($columnsOpt) || $columnsOpt === '') {
            // Try interactive prompt for columns definition
            $columnsOpt = static::readInput('Enter columns (e.g., id:int, name:string:nullable)', null);
        }
        $options = $this->collectCreateOptions();
        /** @var string $columnsOpt */
        $columns = $this->parseColumns($columnsOpt !== '' ? $columnsOpt : null);
        if (empty($columns)) {
            return $this->showError('At least one column is required. Use --columns="id:int, name:string".');
        }
        $db = $this->getDb();
        TableManager::create($db, $table, $columns, $options, $ifNotExists);
        static::success("Table '{$table}' created successfully");
        return 0;
    }

    protected function drop(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required');
        }
        $force = (bool)$this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation("Are you sure you want to drop table '{$table}'? This action cannot be undone", false);
            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }
        $ifExists = (bool)$this->getOption('if-exists', false);
        $db = $this->getDb();
        TableManager::drop($db, $table, $ifExists);
        static::success("Table '{$table}' dropped successfully");
        return 0;
    }

    protected function rename(): int
    {
        $old = $this->getArgument(1);
        $new = $this->getArgument(2);
        if (!is_string($old) || $old === '' || !is_string($new) || $new === '') {
            return $this->showError('Old and new table names are required');
        }
        $force = (bool)$this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation("Are you sure you want to rename table '{$old}' to '{$new}'?", true);
            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }
        $db = $this->getDb();
        TableManager::rename($db, $old, $new);
        static::success("Table '{$old}' renamed to '{$new}'");
        return 0;
    }

    protected function truncate(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required');
        }
        $force = (bool)$this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation("Are you sure you want to truncate table '{$table}'? This action cannot be undone", false);
            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }
        $db = $this->getDb();
        TableManager::truncate($db, $table);
        static::success("Table '{$table}' truncated");
        return 0;
    }

    protected function describe(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required');
        }
        $format = $this->getOption('format', 'table');
        $db = $this->getDb();
        $rows = TableManager::describe($db, $table);
        return $this->printFormatted(['columns' => $rows], (string)$format);
    }

    protected function columns(): int
    {
        $op = $this->getArgument(1);
        $table = $this->getArgument(2);
        if (!is_string($op) || !is_string($table) || $table === '') {
            return $this->showError('Usage: pdodb table columns <list|add|alter|drop> <table> ...');
        }
        $db = $this->getDb();
        return match ($op) {
            'list' => $this->printFormatted(['columns' => TableManager::describe($db, $table)], (string)$this->getOption('format', 'table')),
            'add' => $this->columnsAdd($db, $table),
            'alter' => $this->columnsAlter($db, $table),
            'drop' => $this->columnsDrop($db, $table),
            default => $this->showError("Unknown columns operation: {$op}"),
        };
    }

    protected function indexes(): int
    {
        $op = $this->getArgument(1);
        $table = $this->getArgument(2);
        if (!is_string($op) || !is_string($table) || $table === '') {
            return $this->showError('Usage: pdodb table indexes <list|add|drop> <table> ...');
        }
        $db = $this->getDb();
        if ($op === 'list') {
            $info = TableManager::info($db, $table);
            return $this->printFormatted(['indexes' => $info['indexes']], (string)$this->getOption('format', 'table'));
        }
        if ($op === 'add') {
            $name = (string)$this->getArgument(3, '');
            $cols = (string)$this->getOption('columns', '');
            if ($name === '' || $cols === '') {
                return $this->showError('Index name and --columns are required');
            }
            $columns = array_map('trim', explode(',', $cols));
            $unique = (bool)$this->getOption('unique', false);
            TableManager::createIndex($db, $name, $table, $columns, $unique);
            static::success("Index '{$name}' created");
            return 0;
        }
        if ($op === 'drop') {
            $name = (string)$this->getArgument(3, '');
            if ($name === '') {
                return $this->showError('Index name is required');
            }
            $force = (bool)$this->getOption('force', false);
            if (!$force) {
                $confirmed = static::readConfirmation("Are you sure you want to drop index '{$name}' on '{$table}'?", false);
                if (!$confirmed) {
                    static::info('Operation cancelled');
                    return 0;
                }
            }
            TableManager::dropIndex($db, $name, $table);
            static::success("Index '{$name}' dropped");
            return 0;
        }
        return $this->showError("Unknown indexes operation: {$op}");
    }

    protected function keys(): int
    {
        $op = $this->getArgument(1);
        $db = $this->getDb();

        if ($op === 'check') {
            return $this->keysCheck($db);
        }

        $table = $this->getArgument(2);
        if (!is_string($op) || !is_string($table) || $table === '') {
            return $this->showError('Usage: pdodb table keys <list|add|drop> <table> ...');
        }

        return match ($op) {
            'list' => $this->keysList($db, $table),
            'add' => $this->keysAdd($db, $table),
            'drop' => $this->keysDrop($db, $table),
            default => $this->showError("Unknown keys operation: {$op}"),
        };
    }

    protected function keysList(\tommyknocker\pdodb\PdoDb $db, string $table): int
    {
        $foreignKeys = $db->schema()->getForeignKeys($table);
        $format = (string)$this->getOption('format', 'table');
        return $this->printFormatted(['foreign_keys' => $foreignKeys], $format);
    }

    protected function keysAdd(\tommyknocker\pdodb\PdoDb $db, string $table): int
    {
        $name = (string)$this->getArgument(3, '');
        $columns = (string)$this->getOption('columns', '');
        $refTable = (string)$this->getOption('ref-table', '');
        $refColumns = (string)$this->getOption('ref-columns', '');

        // Interactive mode if required parameters are missing
        if ($name === '') {
            $name = static::readInput('Foreign key name', null);
        }
        if ($columns === '') {
            $columns = static::readInput('Columns (comma-separated)', null);
        }
        if ($refTable === '') {
            $refTable = static::readInput('Referenced table', null);
        }
        if ($refColumns === '') {
            $refColumns = static::readInput('Referenced columns (comma-separated)', null);
        }

        if ($name === '' || $columns === '' || $refTable === '' || $refColumns === '') {
            return $this->showError('Foreign key name, columns, referenced table, and referenced columns are required');
        }

        $columnArray = array_map('trim', explode(',', $columns));
        $refColumnArray = array_map('trim', explode(',', $refColumns));

        if (count($columnArray) !== count($refColumnArray)) {
            return $this->showError('Number of columns must match number of referenced columns');
        }

        $onDelete = $this->getOption('on-delete');
        $onUpdate = $this->getOption('on-update');
        $deleteAction = is_string($onDelete) && $onDelete !== '' ? $onDelete : null;
        $updateAction = is_string($onUpdate) && $onUpdate !== '' ? $onUpdate : null;

        $db->schema()->addForeignKey(
            $name,
            $table,
            count($columnArray) === 1 ? $columnArray[0] : $columnArray,
            $refTable,
            count($refColumnArray) === 1 ? $refColumnArray[0] : $refColumnArray,
            $deleteAction,
            $updateAction
        );

        static::success("Foreign key '{$name}' added to '{$table}'");
        return 0;
    }

    protected function keysDrop(\tommyknocker\pdodb\PdoDb $db, string $table): int
    {
        $name = (string)$this->getArgument(3, '');
        if ($name === '') {
            return $this->showError('Foreign key name is required');
        }

        $force = (bool)$this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation("Are you sure you want to drop foreign key '{$name}' on '{$table}'?", false);
            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        $db->schema()->dropForeignKey($name, $table);
        static::success("Foreign key '{$name}' dropped from '{$table}'");
        return 0;
    }

    protected function keysCheck(\tommyknocker\pdodb\PdoDb $db): int
    {
        $schema = $db->schema();
        $tables = TableManager::listTables($db);
        $violations = [];
        $checked = 0;

        foreach ($tables as $table) {
            $foreignKeys = $schema->getForeignKeys($table);
            if (empty($foreignKeys)) {
                continue;
            }

            foreach ($foreignKeys as $fk) {
                $checked++;
                // Handle different dialect formats
                $fkName = $fk['CONSTRAINT_NAME'] ?? $fk['constraint_name'] ?? $fk['name'] ?? 'unknown';
                // SQLite uses 'from' and 'to', others use COLUMN_NAME/column_name and REFERENCED_COLUMN_NAME/referenced_column_name
                $column = $fk['COLUMN_NAME'] ?? $fk['column_name'] ?? $fk['from'] ?? null;
                $refTable = $fk['REFERENCED_TABLE_NAME'] ?? $fk['referenced_table_name'] ?? $fk['table'] ?? null;
                $refColumn = $fk['REFERENCED_COLUMN_NAME'] ?? $fk['referenced_column_name'] ?? $fk['to'] ?? null;

                if (!is_string($column) || !is_string($refTable) || !is_string($refColumn)) {
                    continue;
                }

                // Check for orphaned records
                $dialect = $schema->getDialect();
                $quotedTable = $dialect->quoteTable($table);
                $quotedColumn = $dialect->quoteIdentifier($column);
                $quotedRefTable = $dialect->quoteTable($refTable);
                $quotedRefColumn = $dialect->quoteIdentifier($refColumn);

                // Find records in child table that don't have matching parent records
                $sql = "SELECT COUNT(*) as cnt FROM {$quotedTable} t1
                        WHERE t1.{$quotedColumn} IS NOT NULL
                        AND NOT EXISTS (
                            SELECT 1 FROM {$quotedRefTable} t2
                            WHERE t2.{$quotedRefColumn} = t1.{$quotedColumn}
                        )";

                try {
                    $result = $db->rawQueryValue($sql);
                    $count = (int)($result ?? 0);
                    if ($count > 0) {
                        $violations[] = [
                            'table' => $table,
                            'foreign_key' => is_string($fkName) ? $fkName : 'unknown',
                            'column' => $column,
                            'referenced_table' => $refTable,
                            'referenced_column' => $refColumn,
                            'violations' => $count,
                        ];
                    }
                } catch (QueryException | ResourceException $e) {
                    // Skip if query fails (table might not exist, etc.)
                    continue;
                } catch (\Exception $e) {
                    // Skip other exceptions
                    continue;
                }
            }
        }

        if (empty($violations)) {
            static::success("All foreign key constraints are valid ({$checked} checked)");
            return 0;
        }

        static::warning('Found ' . count($violations) . ' foreign key constraint violation(s):');
        foreach ($violations as $violation) {
            echo "  - Table '{$violation['table']}', FK '{$violation['foreign_key']}': ";
            echo "{$violation['violations']} orphaned record(s) in '{$violation['column']}' ";
            echo "referencing '{$violation['referenced_table']}.{$violation['referenced_column']}'\n";
        }

        return 1;
    }

    protected function columnsAdd(\tommyknocker\pdodb\PdoDb $db, string $table): int
    {
        $name = (string)$this->getArgument(3, '');
        $type = $this->getOption('type');
        if ($name === '' || !is_string($type) || $type === '') {
            return $this->showError('Column name and --type are required');
        }
        $schema = $this->typeToSchema($type);
        if ($this->getOption('nullable', false)) {
            $schema['nullable'] = true;
        }
        $default = $this->getOption('default');
        if (is_string($default)) {
            $schema['default'] = $default;
        }
        TableManager::addColumn($db, $table, $name, $schema);
        static::success("Column '{$name}' added to '{$table}'");
        return 0;
    }

    protected function columnsAlter(\tommyknocker\pdodb\PdoDb $db, string $table): int
    {
        $name = (string)$this->getArgument(3, '');
        if ($name === '') {
            return $this->showError('Column name is required');
        }
        $schema = [];
        $type = $this->getOption('type');
        if (is_string($type) && $type !== '') {
            $schema = $this->typeToSchema($type);
        }
        if ($this->getOption('nullable', false)) {
            $schema['nullable'] = true;
        }
        if ($this->getOption('not-null', false)) {
            $schema['nullable'] = false;
        }
        $default = $this->getOption('default');
        if (is_string($default)) {
            $schema['default'] = $default;
        }
        if ($this->getOption('drop-default', false)) {
            $schema['default'] = null;
        }
        if ($this->getOption('comment') !== null && is_string($this->getOption('comment'))) {
            $schema['comment'] = (string)$this->getOption('comment');
        }
        if (!empty($schema)) {
            TableManager::alterColumn($db, $table, $name, $schema);
        }
        $rename = $this->getOption('rename');
        if (is_string($rename) && $rename !== '') {
            $db->schema()->renameColumn($table, $name, $rename);
        }
        static::success("Column '{$name}' altered on '{$table}'");
        return 0;
    }

    protected function columnsDrop(\tommyknocker\pdodb\PdoDb $db, string $table): int
    {
        $name = (string)$this->getArgument(3, '');
        if ($name === '') {
            return $this->showError('Column name is required');
        }
        $force = (bool)$this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation("Are you sure you want to drop column '{$name}' on '{$table}'?", false);
            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }
        TableManager::dropColumn($db, $table, $name);
        static::success("Column '{$name}' dropped from '{$table}'");
        return 0;
    }

    /**
     * @return array<string, string>
     */
    protected function collectCreateOptions(): array
    {
        $opts = [];
        foreach (['engine' => 'ENGINE', 'charset' => 'CHARSET', 'collation' => 'COLLATION', 'comment' => 'COMMENT'] as $opt => $key) {
            $val = $this->getOption($opt);
            if (is_string($val) && $val !== '') {
                $opts[$key] = $val;
            }
        }
        return $opts;
    }

    /**
     * Parse columns string to DDL column array.
     * Example: "id:int, name:string:nullable, created_at:datetime".
     *
     * @return array<string, array<string, mixed>|string>
     */
    protected function parseColumns(?string $columns): array
    {
        if ($columns === null || trim($columns) === '') {
            return [];
        }
        $result = [];
        $parts = array_map('trim', explode(',', $columns));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $seg = array_map('trim', explode(':', $part));
            $name = array_shift($seg);
            if ($name === null || $name === '') {
                continue;
            }
            $type = array_shift($seg) ?? 'string';
            $schema = $this->typeToSchema($type);
            foreach ($seg as $flag) {
                if ($flag === 'nullable') {
                    $schema['nullable'] = true;
                }
            }
            $result[$name] = $schema;
        }
        return $result;
    }

    /**
     * Convert simple type keyword into schema array.
     *
     * @return array<string, mixed>
     */
    protected function typeToSchema(string $type): array
    {
        $t = strtolower($type);
        return match ($t) {
            'int', 'integer' => ['type' => 'integer'],
            'bigint' => ['type' => 'bigint'],
            'smallint' => ['type' => 'smallint'],
            'string', 'varchar' => ['type' => 'string', 'length' => 255],
            'text' => ['type' => 'text'],
            'datetime', 'timestamp' => ['type' => 'datetime'],
            'date' => ['type' => 'date'],
            'time' => ['type' => 'time'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'json' => ['type' => 'json'],
            'float', 'double' => ['type' => 'float'],
            default => ['type' => $type],
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function printFormatted(array $data, string $format): int
    {
        $fmt = strtolower($format);
        if ($fmt === 'json') {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }
        if ($fmt === 'yaml') {
            // Simple YAML-like dump
            $this->printYaml($data);
            return 0;
        }
        // table (basic dump)
        $this->printTable($data);
        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function printYaml(array $data, int $indent = 0): void
    {
        foreach ($data as $key => $val) {
            $pad = str_repeat('  ', $indent);
            if (is_array($val)) {
                echo "{$pad}{$key}:\n";
                $this->printYaml($val, $indent + 1);
            } else {
                echo "{$pad}{$key}: {$val}\n";
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function printTable(array $data): void
    {
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                echo ucfirst((string)$key) . ":\n";
                foreach ($val as $rowKey => $rowVal) {
                    if (is_array($rowVal)) {
                        echo '  - ' . json_encode($rowVal, JSON_UNESCAPED_SLASHES) . "\n";
                    } else {
                        echo "  - {$rowVal}\n";
                    }
                }
            } else {
                echo ucfirst((string)$key) . ": {$val}\n";
            }
        }
    }

    protected function showHelp(): int
    {
        echo "Table Management\n\n";
        echo "Usage: pdodb table <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  info <table>                         Show table summary (columns, indexes)\n";
        echo "  list                                 List tables\n";
        echo "  exists <table>                       Check if table exists\n";
        echo "  create <table> [--columns=...]       Create table\n";
        echo "  drop <table>                         Drop table\n";
        echo "  rename <old> <new>                   Rename table\n";
        echo "  truncate <table>                     Truncate table\n";
        echo "  describe <table>                     Show detailed columns\n";
        echo "  columns <op> <table> [...]           Manage columns (list/add/alter/drop)\n";
        echo "  indexes <op> <table> [...]           Manage indexes (list/add/drop)\n";
        echo "  keys <op> <table> [...]              Manage foreign keys (list/add/drop)\n";
        echo "  keys check                           Check foreign key integrity\n\n";
        echo "Options:\n";
        echo "  --format=table|json|yaml             Output format (for info/list/describe)\n";
        echo "  --force                              Execute without confirmation\n";
        echo "  --columns=\"col:type:nullable,...\"    Columns for create\n";
        echo "  --engine=, --charset=, --collation=, --comment=   Table options (dialect-specific)\n";
        echo "  --if-not-exists, --if-exists         Safe create/drop variants\n";
        echo "  columns add/alter:\n";
        echo "    --type=TYPE [--nullable] [--default=VAL] [--comment=TXT]\n";
        echo "    alter: [--not-null] [--drop-default] [--rename=NEW]\n";
        echo "  indexes add:\n";
        echo "    <name> --columns=\"c1,c2\" [--unique]\n";
        echo "  keys add:\n";
        echo "    <name> --columns=\"c1,c2\" --ref-table=table --ref-columns=\"c1,c2\" [--on-delete=ACTION] [--on-update=ACTION]\n";
        echo "  keys check:\n";
        echo "    Check all foreign key constraints for integrity violations\n";
        return 0;
    }
}
