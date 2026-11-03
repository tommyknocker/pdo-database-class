<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

/**
 * insert() reports the id of the row it inserted, and null when that row has no integer key. SQLite's
 * lastInsertId() is the rowid of the last INSERT on the connection, so each case runs after an insert elsewhere.
 */
final class InsertIdTests extends BaseSqliteTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('CREATE TABLE ids_int (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
        self::$db->rawQuery('CREATE TABLE ids_text (id TEXT PRIMARY KEY, name TEXT)');
        self::$db->rawQuery('CREATE TABLE ids_without_rowid (id TEXT PRIMARY KEY, name TEXT) WITHOUT ROWID');
        self::$db->rawQuery('CREATE TABLE ids_composite (a INTEGER, b INTEGER, PRIMARY KEY (a, b))');
        self::$db->rawQuery('CREATE TABLE ids_nokey (note TEXT)');
        self::$db->find()->table('ids_int')->insert(['id' => 42, 'name' => 'earlier']); // lastInsertId() is now 42
    }

    public function testGeneratedAndSuppliedIntegerKeys(): void
    {
        $this->assertSame(43, self::$db->find()->table('ids_int')->insert(['name' => 'a']));
        $this->assertSame(500, self::$db->find()->table('ids_int')->insert(['id' => 500, 'name' => 'b']));
        $this->assertSame(501, self::$db->transaction(fn () => self::$db->find()->table('ids_int')->insert(['name' => 'c'])));
    }

    public function testASkippedInsertHasNoId(): void
    {
        $this->assertNull(self::$db->find()->table('ids_int')->option('IGNORE')->insert(['name' => 'earlier']));
    }

    public function testATextKeyIsReturnedAsTheKey(): void
    {
        $this->assertSame('uuid-key', self::$db->find()->table('ids_text')->insert(['id' => 'uuid-key', 'name' => 'x']), 'not the rowid');
    }

    public function testAWithoutRowidTableReturnsItsKeyNotAnEarlierInsertsId(): void
    {
        self::$db->find()->table('ids_text')->insert(['id' => 'a', 'name' => 'x']);

        $this->assertSame('b', self::$db->find()->table('ids_without_rowid')->insert(['id' => 'b', 'name' => 'y']));
    }

    public function testACompositeKeyHasNoSingleId(): void
    {
        $this->assertNull(self::$db->find()->table('ids_composite')->insert(['a' => 1, 'b' => 2]));
    }

    public function testATableWithoutAKeyReportsItsRowid(): void
    {
        $this->assertSame([1, 2], [
            self::$db->find()->table('ids_nokey')->insert(['note' => 'x']),
            self::$db->find()->table('ids_nokey')->insert(['note' => 'y']),
        ]);
    }

    public function testAnUpsertReportsTheRowItWroteInBothBranches(): void
    {
        $same = self::$db->find()->table('ids_int')->insert(['name' => 'same']); // 43
        self::$db->find()->table('ids_int')->insert(['name' => 'other']);        // 44, the last rowid

        $updated = self::$db->find()->table('ids_int')->onDuplicate(['name'], 'name')->insert(['name' => 'same']);
        $inserted = self::$db->find()->table('ids_int')->onDuplicate(['name'], 'name')->insert(['name' => 'new']);

        $this->assertSame(43, $same);
        $this->assertSame(43, $updated, 'the row the conflict updated, not the last rowid');
        $this->assertSame(45, $inserted);
    }

    public function testReplaceReportsTheNewRow(): void
    {
        $this->assertSame(600, self::$db->find()->table('ids_int')->replace(['id' => 600, 'name' => 'r']));
    }

    public function testATableAlteredLaterIsLookedUpAgain(): void
    {
        self::$db->rawQuery('CREATE TABLE ids_later (note TEXT)');
        $this->assertSame(1, self::$db->find()->table('ids_later')->insert(['note' => 'a']), 'the rowid of a table without a key');
        self::$db->rawQuery('DROP TABLE ids_later');
        self::$db->rawQuery('CREATE TABLE ids_later (code TEXT PRIMARY KEY, note TEXT)');
        $this->assertSame('x1', self::$db->find()->table('ids_later')->insert(['code' => 'x1', 'note' => 'b']));
        self::$db->rawQuery('DROP TABLE ids_later');
        self::$db->rawQuery('CREATE TABLE ids_later (id INTEGER PRIMARY KEY, note TEXT)');

        $this->assertSame(1, self::$db->find()->table('ids_later')->insert(['note' => 'b']));
    }

    public function testATempTableThatShadowsATableIsLookedUpAgain(): void
    {
        self::$db->rawQuery('CREATE TABLE shadowed (main_key TEXT PRIMARY KEY, note TEXT)');
        $this->assertSame('main-a', self::$db->find()->table('shadowed')->insert(['main_key' => 'main-a', 'note' => 'a']));

        // From now on the name means the TEMP table, which has another key column
        self::$db->rawQuery('CREATE TEMP TABLE shadowed (temp_key TEXT PRIMARY KEY, note TEXT)');

        $this->assertSame('temp-a', self::$db->find()->table('shadowed')->insert(['temp_key' => 'temp-a', 'note' => 'a']));
    }
}
