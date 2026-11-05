<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use tommyknocker\pdodb\helpers\Db;

/**
 * A number or a boolean given to whereJsonPath() / Db::jsonPath() is compared with the JSON value as that type. A JSON value
 * was read as text, so `age > 27` was `'5' > '27'` and returned the row with 5, and `ok = true` matched the number 1.
 *
 * Used by the per-dialect JsonTests, which provide `self::$db` and the DDL of a table with a JSON document column.
 */
trait JsonNumberComparisonScenarios
{
    /** CREATE TABLE json_number_cmp (id <integer> primary key, data <JSON document column>) */
    abstract protected function createJsonNumberTableSql(): string;

    /** Whether a JSON string that holds a number ("30") or a boolean ("true") has that type in a comparison (SQL Server and Oracle: yes) */
    abstract protected function textScalarsAreTyped(): bool;

    /** @var array<int, string> */
    private const array NUMBER_DOCUMENTS = [
        1 => '{"age":5,"p":{"age":5}}',
        2 => '{"age":30,"p":{"age":30}}',
        3 => '{"age":100,"p":{"age":100}}',
        4 => '{"age":2.5,"p":{"age":2.5}}',
        5 => '{"age":-3,"p":{"age":-3}}',
        6 => '{"age":"abc","p":{"age":"abc"}}',
        8 => '{"x":1}',
        9 => '{"age":null,"p":{"age":null}}',
        10 => '{"age":true,"p":{"age":true}}',
    ];

    /**
     * @return array<string, array{0: string, 1: int|float, 2: list<int>}> operator, value, ids that match
     */
    public static function numberComparisons(): array
    {
        return [
            'greater than a whole number' => ['>', 27, [2, 3]],
            'less than a whole number' => ['<', 27, [1, 4, 5]],
            'equal to a whole number' => ['=', 30, [2]],
            'at least' => ['>=', 30, [2, 3]],
            'equal to a fraction' => ['=', 2.5, [4]],
            'greater than a fraction' => ['>', 2.5, [1, 2, 3]],
            'greater than a negative number' => ['>', -5, [1, 2, 3, 4, 5]],
            'not equal' => ['!=', 30, [1, 3, 4, 5]],
        ];
    }

    /**
     * @param array<int, string>|null $documents
     */
    private function prepareJsonNumberTable(?array $documents = null): void
    {
        self::$db->schema()->dropTableIfExists('json_number_cmp');
        self::$db->rawQuery($this->createJsonNumberTableSql());
        foreach ($documents ?? self::NUMBER_DOCUMENTS as $id => $document) {
            self::$db->find()->table('json_number_cmp')->insert(['id' => $id, 'data' => $document]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function jsonNumberIds(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int)array_change_key_case($row)['id'], $rows);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('numberComparisons')]
    public function testAJsonValueIsComparedWithANumberAsANumber(string $operator, int|float $value, array $expected): void
    {
        $this->prepareJsonNumberTable();

        try {
            foreach (['age' => ['age'], 'nested' => ['p', 'age']] as $label => $path) {
                $byMethod = self::$db->find()->from('json_number_cmp')->whereJsonPath('data', $path, $operator, $value)->get();
                $byHelper = self::$db->find()->from('json_number_cmp')->where(Db::jsonPath('data', $path, $operator, $value))->get();

                $this->assertSame($expected, $this->jsonNumberIds($byMethod), "whereJsonPath, {$label}");
                $this->assertSame($expected, $this->jsonNumberIds($byHelper), "Db::jsonPath, {$label}");
            }
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testANumericConditionDoesNotFailOnValuesThatAreNotNumbers(): void
    {
        $this->prepareJsonNumberTable();

        try {
            // a string, null, a boolean and a missing key are in the table: none of them may make the statement fail
            $affected = self::$db->find()->table('json_number_cmp')->whereJsonPath('data', ['age'], '>', 27)->update(['id' => Db::raw('id + 1000')]);

            $this->assertSame(2, $affected);
            $this->assertSame([1, 4, 5, 6, 8, 9, 10, 1002, 1003], $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->get()));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testANumberWrittenByJsonSetIsFoundByANumericComparison(): void
    {
        $this->prepareJsonNumberTable();

        try {
            $query = self::$db->find()->table('json_number_cmp')->where('id', 8);
            $query->update(['data' => $query->jsonSet('data', ['age'], 999)]);

            $this->assertSame([3, 8], $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->whereJsonPath('data', ['age'], '>', 99)->get()));
            $this->assertSame([8], $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->whereJsonPath('data', ['age'], '=', 999)->get()));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    /** @var array<int, string> */
    private const array BOOLEAN_DOCUMENTS = [
        1 => '{"ok":true,"p":{"ok":true}}',
        2 => '{"ok":false,"p":{"ok":false}}',
        3 => '{"ok":1,"p":{"ok":1}}',
        4 => '{"ok":0,"p":{"ok":0}}',
        5 => '{"ok":null,"p":{"ok":null}}',
        6 => '{"x":1}',
        7 => '{"ok":"yes","p":{"ok":"yes"}}',
    ];

    /**
     * @return array<string, array{0: string, 1: bool, 2: list<int>}> operator, value, ids that match
     */
    public static function booleanComparisons(): array
    {
        return [
            'equal to true' => ['=', true, [1]],
            'equal to false' => ['=', false, [2]],
            'not equal to true' => ['!=', true, [2]],
            'not equal to false' => ['!=', false, [1]],
        ];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('booleanComparisons')]
    public function testAJsonValueIsComparedWithABooleanAsABoolean(string $operator, bool $value, array $expected): void
    {
        $this->prepareJsonNumberTable(self::BOOLEAN_DOCUMENTS);

        try {
            foreach (['ok' => ['ok'], 'nested' => ['p', 'ok']] as $label => $path) {
                $byMethod = self::$db->find()->from('json_number_cmp')->whereJsonPath('data', $path, $operator, $value)->get();
                $byHelper = self::$db->find()->from('json_number_cmp')->where(Db::jsonPath('data', $path, $operator, $value))->get();

                $this->assertSame($expected, $this->jsonNumberIds($byMethod), "whereJsonPath, {$label}");
                $this->assertSame($expected, $this->jsonNumberIds($byHelper), "Db::jsonPath, {$label}");
            }
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testABooleanConditionDoesNotFailOnValuesThatAreNotBooleans(): void
    {
        $this->prepareJsonNumberTable(self::BOOLEAN_DOCUMENTS);

        try {
            $affected = self::$db->find()->table('json_number_cmp')->whereJsonPath('data', ['ok'], '=', true)->update(['id' => Db::raw('id + 1000')]);

            $this->assertSame(1, $affected);
            $this->assertSame([2, 3, 4, 5, 6, 7, 1001], $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->get()));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testAJsonStringThatHoldsABooleanIsHandledPerDatabase(): void
    {
        $this->prepareJsonNumberTable(self::BOOLEAN_DOCUMENTS);
        self::$db->find()->table('json_number_cmp')->insert(['id' => 8, 'data' => '{"ok":"true"}']);

        try {
            $expected = $this->textScalarsAreTyped() ? [1, 8] : [1];
            $this->assertSame($expected, $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->whereJsonPath('data', ['ok'], '=', true)->get()));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testBigIntegersAndSmallFractionsAreNotRounded(): void
    {
        $this->prepareJsonNumberTable();
        foreach ([21 => '{"age":9007199254740993}', 22 => '{"age":0.000000000001}', 23 => '{"age":0}'] as $id => $document) {
            self::$db->find()->table('json_number_cmp')->insert(['id' => $id, 'data' => $document]);
        }

        try {
            $among = static fn (string $operator, int|float $value): array => self::$db->find()->from('json_number_cmp')
                ->where('id', 20, '>')->whereJsonPath('data', ['age'], $operator, $value)->get();

            // 2^53 + 1 is not representable as a float
            $this->assertSame([21], $this->jsonNumberIds($among('=', 9007199254740993)));
            $this->assertSame([], $this->jsonNumberIds($among('=', 9007199254740992)));
            // 1e-12 is not zero
            $this->assertSame([21, 22], $this->jsonNumberIds($among('>', 0)));
            $this->assertSame([23], $this->jsonNumberIds($among('=', 0)));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testAJsonStringThatHoldsANumberIsHandledPerDatabase(): void
    {
        $this->prepareJsonNumberTable();
        self::$db->find()->table('json_number_cmp')->insert(['id' => 7, 'data' => '{"age":"30"}']);

        try {
            $expected = $this->textScalarsAreTyped() ? [2, 7] : [2];
            $this->assertSame($expected, $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->whereJsonPath('data', ['age'], '=', 30)->get()));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }

    public function testAStringIsStillComparedAsText(): void
    {
        $this->prepareJsonNumberTable();
        self::$db->find()->table('json_number_cmp')->insert(['id' => 7, 'data' => '{"age":"30"}']);

        try {
            $this->assertSame([6], $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->whereJsonPath('data', ['age'], '=', 'abc')->get()));
            // text of the number 30 and of the string "30" is the same text
            $this->assertSame([2, 7], $this->jsonNumberIds(self::$db->find()->from('json_number_cmp')->whereJsonPath('data', ['age'], '=', '30')->get()));
        } finally {
            self::$db->schema()->dropTableIfExists('json_number_cmp');
        }
    }
}
