<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\loaders;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SplFileObject;
use XMLReader;

class FileLoader
{
    protected const int DEFAULT_BATCH_SIZE = 500;

    public function __construct(
        protected PDO $pdo
    ) {
    }

    /**
     * Load data from CSV file.
     *
     * @param array<string, mixed> $options
     */
    public function loadFromCsv(string $table, string $filePath, array $options = []): string
    {
        $sqlParts = [];
        foreach ($this->loadFromCsvGenerator($table, $filePath, $options) as $sql) {
            $sqlParts[] = $sql;
        }
        return $sqlParts === [] ? '' : implode("\n", $sqlParts);
    }

    /**
     * Load data from CSV file using generator for memory efficiency.
     *
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function loadFromCsvGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        $defaults = [
            'fieldChar' => ',',
            'fieldEnclosure' => null,
            'fields' => [],
            'lineChar' => null,
            'linesToIgnore' => null,
            'lineStarting' => null,
        ];
        $opts = $options + $defaults;

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("CSV file is not readable: {$filePath}");
        }

        $delimiter = (string)$opts['fieldChar'];
        $enclosure = $opts['fieldEnclosure'] ?? '"';
        $escape = '\\';

        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_AHEAD);

        // Skip physical lines before processing begins
        if (!empty($opts['linesToIgnore'])) {
            $skip = (int)$opts['linesToIgnore'];
            for ($i = 0; $i < $skip && !$file->eof(); $i++) {
                $file->fgets();
            }
        }

        // Determine columns: either from options['fields'] or from the first non-empty CSV row
        $columns = $opts['fields'];
        if (empty($columns)) {
            while (!$file->eof()) {
                $row = $file->fgetcsv($delimiter, $enclosure, $escape);
                if ($row === false || $row === null) {
                    continue;
                }
                $isBlank = count($row) === 1 && ($row[0] === null || $row[0] === '');
                if ($isBlank) {
                    continue;
                }
                $columns = array_map(fn ($v) => trim((string)($v ?? '')), $row);
                break;
            }
        }

        if (empty($columns)) {
            throw new RuntimeException('No CSV header detected and no fields provided.');
        }

        // If a specific logical line (1-based) is requested, seek to it
        if (!empty($opts['lineStarting']) && (int)$opts['lineStarting'] > 1) {
            $target = (int)$opts['lineStarting'];
            $file->rewind();
            for ($line = 1; $line < $target && !$file->eof(); $line++) {
                $file->fgets();
            }
        }

        $tableQ = $this->quoteIdentifier($table);
        $colsQ = array_map(fn ($c) => $this->quoteColumnName($c), $columns);

        $batchSize = self::DEFAULT_BATCH_SIZE;
        $batch = [];

        // Read and build batches
        while (!$file->eof()) {
            $row = $file->fgetcsv($delimiter, $enclosure, $escape);
            if ($row === false || $row === null) {
                continue;
            }
            $isBlank = count($row) === 1 && ($row[0] === null || $row[0] === '');
            if ($isBlank) {
                continue;
            }

            // Normalize row length to match column count
            $cells = array_map(static function ($c) {
                return $c === null ? null : trim((string)$c);
            }, $row);

            if (count($cells) < count($columns)) {
                $cells = array_merge($cells, array_fill(0, count($columns) - count($cells), null));
            } elseif (count($cells) > count($columns)) {
                $cells = array_slice($cells, 0, count($columns));
            }

            // Skip completely empty rows
            $allEmpty = true;
            foreach ($cells as $c) {
                if ($c !== null && $c !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            $vals = [];
            foreach ($cells as $v) {
                $vals[] = $this->quoteValue($v);
            }

            $batch[] = '(' . implode(', ', $vals) . ')';

            if (count($batch) >= $batchSize) {
                yield 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsQ) . ')'
                    . ' VALUES ' . implode(', ', $batch) . ';';
                $batch = [];
            }
        }

        if (!empty($batch)) {
            yield 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsQ) . ')'
                . ' VALUES ' . implode(', ', $batch) . ';';
        }
    }

    /**
     * Load data from XML file.
     *
     * @param array<string, mixed> $options
     */
    public function loadFromXml(string $table, string $filePath, array $options = []): string
    {
        $sqlParts = [];
        foreach ($this->loadFromXmlGenerator($table, $filePath, $options) as $sql) {
            $sqlParts[] = $sql;
        }
        return $sqlParts === [] ? '' : implode("\n", $sqlParts);
    }

    /**
     * Load data from XML file using generator for memory efficiency.
     *
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function loadFromXmlGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        $defaults = [
            'rowTag' => '<row>',
            'linesToIgnore' => 0,
        ];
        $opts = $options + $defaults;

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("XML file is not readable: {$filePath}");
        }

        $rowTag = trim((string)$opts['rowTag'], " \t\n\r\0\x0B<>");
        $skipRows = max(0, (int)$opts['linesToIgnore']);

        if (!$reader = XMLReader::open($filePath)) {
            throw new RuntimeException("Unable to open XML file: {$filePath}");
        }

        $tableQ = $this->quoteIdentifier($table);
        $columns = [];
        $batch = [];
        $batchSize = self::DEFAULT_BATCH_SIZE;
        $skipped = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName !== $rowTag) {
                    continue;
                }

                // Skip first N logical row elements if requested
                if ($skipped < $skipRows) {
                    $skipped++;
                    $reader->next();
                    continue;
                }

                $xml = $reader->readOuterXml();
                if ($xml === '') {
                    $reader->next();
                    continue;
                }

                $elem = simplexml_load_string($xml);
                if ($elem === false) {
                    $reader->next();
                    continue;
                }

                // Determine columns from the first encountered row
                if ($columns === []) {
                    foreach ($elem->children() as $child) {
                        $columns[] = (string)$child->getName();
                    }

                    // fallback to attributes if no child elements
                    if ($columns === []) {
                        foreach ($elem->attributes() as $name => $val) {
                            $columns[] = (string)$name;
                        }
                    }

                    if ($columns === []) {
                        $reader->close();
                        return;
                    }
                }

                $values = [];
                foreach ($columns as $col) {
                    $val = null;

                    if (isset($elem->{$col}) && (string)$elem->{$col} !== '') {
                        $val = (string)$elem->{$col};
                    } elseif ($elem->attributes()->{$col} !== null) {
                        $val = (string)$elem->attributes()->{$col};
                    }

                    $values[] = $this->quoteValue($val);
                }

                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= $batchSize) {
                    $colsEscaped = array_map(fn ($c) => $this->quoteColumnName($c), $columns);

                    yield 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsEscaped) . ')'
                        . ' VALUES ' . implode(', ', $batch) . ';';
                    $batch = [];
                }

                $reader->next();
            }

            if ($batch !== []) {
                $colsEscaped = array_map(fn ($c) => $this->quoteColumnName($c), $columns);

                yield 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsEscaped) . ')'
                    . ' VALUES ' . implode(', ', $batch) . ';';
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Quote identifier for SQL.
     */
    protected function quoteIdentifier(string $ident): string
    {
        $parts = explode('.', $ident);
        $parts = array_map(static function ($p) {
            $p = trim($p);
            return preg_match('/^[A-Za-z0-9_]+$/', $p) ? "\"{$p}\"" : $p;
        }, $parts);
        return implode('.', $parts);
    }

    /**
     * Quote column name for SQL.
     */
    protected function quoteColumnName(string $col): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $col) ? "\"{$col}\"" : $col;
    }

    /**
     * Quote value for SQL.
     */
    protected function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return $this->pdo->quote((string)$value);
    }
}
