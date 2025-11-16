<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\TableManager;

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
        $options = $this->collectCreateOptions();
        $columns = $this->parseColumns(is_string($columnsOpt) ? $columnsOpt : null);
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
        echo "  indexes <op> <table> [...]           Manage indexes (list/add/drop)\n\n";
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
        return 0;
    }
}
