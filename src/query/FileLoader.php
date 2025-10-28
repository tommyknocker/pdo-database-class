<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\query\interfaces\FileLoaderInterface;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class FileLoader implements FileLoaderInterface
{
    use TableManagementTrait;

    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;

    /** @var string|null table name */
    protected ?string $table = null;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
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
            $generator = $this->connection->getDialect()->buildLoadCsvSqlGenerator(
                $this->prefix . $this->table,
                $filePath,
                $options
            );

            foreach ($generator as $batchSql) {
                $sql = $batchSql;
                $this->connection->prepare($sql)->execute();
            }

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

            $generator = $this->connection->getDialect()->buildLoadXMLGenerator(
                $this->prefix . $this->table,
                $filePath,
                $options
            );

            foreach ($generator as $batchSql) {
                $sql = $batchSql;
                $this->connection->prepare($sql)->execute();
            }

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

    /**
     * Loads data from a JSON file into a table.
     *
     * @param string $filePath The path to the JSON file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadJson(string $filePath, array $options = []): bool
    {
        $sql = null;
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }

        try {
            $generator = $this->connection->getDialect()->buildLoadJsonGenerator(
                $this->prefix . $this->table,
                $filePath,
                $options
            );

            foreach ($generator as $batchSql) {
                $sql = $batchSql;
                $this->connection->prepare($sql)->execute();
            }

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
                ['operation' => 'loadJson', 'file' => $filePath]
            );
        }
    }
}
