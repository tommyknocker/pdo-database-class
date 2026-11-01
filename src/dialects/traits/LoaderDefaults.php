<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectLoaderInterface;

/**
 * Default implementation of the bulk file loading part of the dialect ({@see DialectLoaderInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait LoaderDefaults
{
    /**
     * Build SQL for loading data from XML file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadXML(string $table, string $filePath, array $options = []): string
    {
        return $this->getFileLoader()->loadFromXml($table, $filePath, $options);
    }

    /**
     * Build SQL generator for loading data from XML file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        return $this->getFileLoader()->loadFromXmlGenerator($table, $filePath, $options);
    }

    /**
     * Build SQL for loading data from CSV file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string
    {
        return $this->getFileLoader()->loadFromCsv($table, $filePath, $options);
    }

    /**
     * Build SQL generator for loading data from CSV file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        return $this->getFileLoader()->loadFromCsvGenerator($table, $filePath, $options);
    }

    /**
     * Build SQL for loading data from JSON file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadJson(string $table, string $filePath, array $options = []): string
    {
        return $this->getFileLoader()->loadFromJson($table, $filePath, $options);
    }

    /**
     * Build SQL generator for loading data from JSON file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadJsonGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        return $this->getFileLoader()->loadFromJsonGenerator($table, $filePath, $options);
    }
}
