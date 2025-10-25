<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

class FileLoader implements FileLoaderInterface
{
    protected ConnectionInterface $connection;

    /** @var string|null table name */
    protected ?string $table = null;

    /** @var string|null Table prefix */
    protected ?string $prefix = null;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Set table name.
     *
     * @param string $table
     *
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Set table prefix.
     *
     * @param string|null $prefix
     *
     * @return self
     */
    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $filePath The path to the CSV file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadCsv(string $filePath, array $options = []): bool
    {
        $sql = null;
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }

        try {
            $sql = $this->connection->getDialect()->buildLoadCsvSql($this->prefix . $this->table, $filePath, $options);
            $this->connection->prepare($sql)->execute();
            if ($this->connection->inTransaction()) {
                $this->connection->commit();
            }
            return $this->connection->getExecuteState() !== false;
        } catch (PDOException $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            // Convert to specialized exception and re-throw
            throw ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => 'loadCsv', 'file' => $filePath]
            );
        }
    }

    /**
     * Loads data from an XML file into a table.
     *
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     *
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        $sql = null;
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }

        try {
            $options = [
                'rowTag' => $rowTag,
                'linesToIgnore' => $linesToIgnore,
            ];
            $sql = $this->connection->getDialect()->buildLoadXML($this->prefix . $this->table, $filePath, $options);
            $this->connection->prepare($sql)->execute();
            if ($this->connection->inTransaction()) {
                $this->connection->commit();
            }
            return $this->connection->getExecuteState() !== false;
        } catch (PDOException $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            // Convert to specialized exception and re-throw
            throw ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => 'loadXml', 'file' => $filePath, 'rowTag' => $rowTag]
            );
        }
    }
}
