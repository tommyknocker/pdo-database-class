<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

interface FileLoaderInterface
{
    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $filePath The path to the CSV file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadCsv(string $filePath, array $options = []): bool;

    /**
     * Loads data from an XML file into a table.
     *
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     *
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool;

    /**
     * Loads data from a JSON file into a table.
     *
     * @param string $filePath The path to the JSON file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadJson(string $filePath, array $options = []): bool;

    /**
     * Set the table name for the file loader.
     *
     * @param string $table The table name.
     *
     * @return static The current instance.
     */
    public function setTable(string $table): static;

    /**
     * Set the prefix for the file loader.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return static The current instance.
     */
    public function setPrefix(?string $prefix): static;
}
