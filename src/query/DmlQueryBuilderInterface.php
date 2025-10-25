<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\helpers\RawValue;

interface DmlQueryBuilderInterface
{
    /**
     * Insert data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insert(array $data, array $onDuplicate = []): int;

    /**
     * Insert multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insertMulti(array $rows, array $onDuplicate = []): int;

    /**
     * Replace data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replace(array $data, array $onDuplicate = []): int;

    /**
     * Replace multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replaceMulti(array $rows, array $onDuplicate = []): int;

    /**
     * Execute UPDATE statement.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     *
     * @return int
     */
    public function update(array $data): int;

    /**
     * Execute DELETE statement.
     *
     * @return int
     */
    public function delete(): int;

    /**
     * Execute TRUNCATE statement.
     *
     * @return bool
     */
    public function truncate(): bool;

    /**
     * Add query option.
     *
     * @param string|array<int|string, mixed> $option
     *
     * @return self
     */
    public function addOption(string|array $option): self;

    /**
     * Set query options.
     *
     * @param array<int|string, mixed> $options
     *
     * @return self
     */
    public function setOptions(array $options): self;

    /**
     * Add ON DUPLICATE clause.
     *
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return self The current instance.
     */
    public function onDuplicate(array $onDuplicate): self;

    /**
     * Set the table name for the DML query builder.
     *
     * @param string $table The table name.
     *
     * @return self The current instance.
     */
    public function setTable(string $table): self;

    /**
     * Set the prefix for the DML query builder.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return self The current instance.
     */
    public function setPrefix(?string $prefix): self;

    /**
     * Set the limit for the DML query builder.
     *
     * @param int|null $limit The limit to set.
     *
     * @return self The current instance.
     */
    public function setLimit(?int $limit): self;
}
