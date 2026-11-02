<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * insert() returns the id the insert produced, not a placeholder.
 */
final class InsertIdTests extends BaseOracleTestCase
{
    protected function tearDown(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('insert_id_probe');
        $schema->dropTableIfExists('insert_id_probe_archive');
        $schema->dropTableIfExists('insert_id_uuid');
        $this->dropHandMade();
        parent::tearDown();
    }

    private function createProbe(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('insert_id_probe');
        $schema->createTable('insert_id_probe', [
            'id' => $schema->primaryKey(),
            'amount' => $schema->integer()->notNull(),
            'note' => $schema->string(20),
        ]);
    }

    public function testConsecutiveInsertsReturnTheirOwnIds(): void
    {
        $this->createProbe();
        $ids = [];
        foreach ([10, 20, 30] as $amount) {
            $ids[] = self::$db->find()->table('insert_id_probe')->insert(['amount' => $amount]);
        }

        $stored = array_map(
            static fn (array $row): int => (int)array_change_key_case($row)['id'],
            self::$db->find()->table('insert_id_probe')->select(['id'])->orderBy('amount')->get()
        );
        $this->assertSame($stored, $ids, 'each insert returns the id of the row it created');
        $this->assertCount(3, array_unique($ids));
    }

    public function testAnInsertInsideATransactionReturnsTheNewId(): void
    {
        $this->createProbe();
        $first = self::$db->find()->table('insert_id_probe')->insert(['amount' => 1]);
        $second = self::$db->transaction(fn () => self::$db->find()->table('insert_id_probe')->insert(['amount' => 2]));

        $this->assertSame($first + 1, $second);
    }

    public function testASuppliedKeyIsReturnedNotTheSequencesOldValue(): void
    {
        $this->createProbe();
        self::$db->find()->table('insert_id_probe')->insert(['amount' => 1]); // moves the sequence

        $this->assertSame(500, self::$db->find()->table('insert_id_probe')->insert(['id' => 500, 'amount' => 2]));
    }

    public function testAnExpressionAmongTheValuesDoesNotConfuseTheLookup(): void
    {
        $this->createProbe();
        $id = self::$db->find()->table('insert_id_probe')->insert(['amount' => Db::raw('1 + 1'), 'note' => 'x']);

        $stored = (int)self::$db->find()->table('insert_id_probe')->select(['id'])->where('note', 'x')->getValue();
        $this->assertSame($stored, $id);
    }

    public function testTheSequenceOfAnotherTableWithTheSamePrefixIsNotUsed(): void
    {
        $schema = self::$db->schema();
        $this->createProbe();
        $schema->dropTableIfExists('insert_id_probe_archive');
        $schema->createTable('insert_id_probe_archive', ['id' => $schema->primaryKey(), 'amount' => $schema->integer()->notNull()]);
        for ($i = 0; $i < 5; $i++) {
            self::$db->find()->table('insert_id_probe_archive')->insert(['amount' => $i]); // the archive sequence is at 5
        }

        $this->assertSame(1, self::$db->find()->table('insert_id_probe')->insert(['amount' => 1]));
    }

    public function testATableWithoutAGeneratedKeyStillInserts(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('insert_id_uuid');
        $schema->createTable('insert_id_uuid', ['id' => $schema->string(36)->notNull()->unique(), 'amount' => $schema->integer()->notNull()]);

        $result = self::$db->find()->table('insert_id_uuid')->insert(['id' => 'a-1', 'amount' => 1]);

        $this->assertSame(1, (int)self::$db->rawQueryValue('SELECT COUNT(*) FROM "INSERT_ID_UUID"'));
        $this->assertIsInt($result);
    }

    private function dropHandMade(): void
    {
        foreach (['DROP TABLE "INSERT_ID_HAND" CASCADE CONSTRAINTS', 'DROP SEQUENCE insert_id_hand_seq', 'DROP SEQUENCE insert_id_hand_id_seq', 'DROP TABLE "INSERT_ID_IDENT" CASCADE CONSTRAINTS', 'DROP TABLE "INSERT_ID_DEFAULT" CASCADE CONSTRAINTS', 'DROP SEQUENCE insert_id_default_seq', 'DROP TABLE "INSERT_ID_ASSIGN" CASCADE CONSTRAINTS', 'DROP SEQUENCE insert_id_assign_seq'] as $sql) {
            try {
                self::$db->rawQuery($sql);
            } catch (\Throwable) {
                // not there
            }
        }
    }

    public function testASequenceLeftOverUnderTheConventionalNameIsNotMistakenForTheTablesKey(): void
    {
        $this->dropHandMade();
        // a table made by hand: its trigger calls insert_id_hand_seq; insert_id_hand_id_seq is a stray from an old table
        self::$db->rawQuery('CREATE TABLE "INSERT_ID_HAND" ("ID" NUMBER PRIMARY KEY, "AMOUNT" NUMBER)');
        self::$db->rawQuery('CREATE SEQUENCE insert_id_hand_seq START WITH 1 INCREMENT BY 1');
        self::$db->rawQuery('CREATE SEQUENCE insert_id_hand_id_seq START WITH 100 INCREMENT BY 1');
        self::$db->rawQuery('CREATE OR REPLACE TRIGGER insert_id_hand_trg BEFORE INSERT ON "INSERT_ID_HAND" FOR EACH ROW BEGIN IF :NEW."ID" IS NULL THEN SELECT insert_id_hand_seq.NEXTVAL INTO :NEW."ID" FROM DUAL; END IF; END;');

        $first = self::$db->find()->table('insert_id_hand')->insert(['amount' => 1]);
        $second = self::$db->find()->table('insert_id_hand')->insert(['amount' => 2]);
        $supplied = self::$db->find()->table('insert_id_hand')->insert(['id' => 40, 'amount' => 3]);

        $this->assertSame([1, 2, 40], [$first, $second, $supplied]);
    }

    public function testAnIdentityColumnReturnsItsId(): void
    {
        $this->dropHandMade();
        self::$db->rawQuery('CREATE TABLE "INSERT_ID_IDENT" ("ID" NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY, "AMOUNT" NUMBER)');

        $ids = [
            self::$db->find()->table('insert_id_ident')->insert(['amount' => 1]),
            self::$db->find()->table('insert_id_ident')->insert(['amount' => 2]),
        ];

        $this->assertSame([1, 2], $ids);
    }

    public function testAColumnDefaultingToASequenceReturnsItsId(): void
    {
        $this->dropHandMade();
        self::$db->rawQuery('CREATE SEQUENCE insert_id_default_seq START WITH 1 INCREMENT BY 1');
        self::$db->rawQuery('CREATE TABLE "INSERT_ID_DEFAULT" ("ID" NUMBER DEFAULT insert_id_default_seq.NEXTVAL PRIMARY KEY, "AMOUNT" NUMBER)');

        $ids = [
            self::$db->find()->table('insert_id_default')->insert(['amount' => 1]),
            self::$db->find()->table('insert_id_default')->insert(['amount' => 2]),
            self::$db->find()->table('insert_id_default')->insert(['id' => 50, 'amount' => 3]),
        ];

        $this->assertSame([1, 2, 50], $ids);
    }

    public function testARecreatedTableIsNotAnsweredFromTheOldOnesSequence(): void
    {
        $this->dropHandMade();
        for ($round = 0; $round < 2; $round++) {
            self::$db->rawQuery('CREATE TABLE "INSERT_ID_IDENT" ("ID" NUMBER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "AMOUNT" NUMBER)');
            for ($i = 0; $i < 3 + $round; $i++) {
                self::$db->find()->table('insert_id_ident')->insert(['amount' => $i]);
            }
            $this->assertSame(4 + $round, self::$db->find()->table('insert_id_ident')->insert(['amount' => 9]), "round {$round}");
            self::$db->rawQuery('DROP TABLE "INSERT_ID_IDENT" CASCADE CONSTRAINTS');
        }
    }

    public function testATriggerThatAssignsTheSequenceStillRecognizesASuppliedKey(): void
    {
        $this->dropHandMade();
        self::$db->rawQuery('CREATE SEQUENCE insert_id_assign_seq START WITH 1 INCREMENT BY 1');
        self::$db->rawQuery('CREATE TABLE "INSERT_ID_ASSIGN" ("ID" NUMBER PRIMARY KEY, "AMOUNT" NUMBER)');
        self::$db->rawQuery('CREATE OR REPLACE TRIGGER insert_id_assign_trg BEFORE INSERT ON "INSERT_ID_ASSIGN" FOR EACH ROW BEGIN IF :NEW.ID IS NULL THEN :NEW.ID := insert_id_assign_seq.NEXTVAL; END IF; END;');

        $ids = [
            self::$db->find()->table('insert_id_assign')->insert(['amount' => 1]),
            self::$db->find()->table('insert_id_assign')->insert(['amount' => 2]),
            self::$db->find()->table('insert_id_assign')->insert(['id' => 70, 'amount' => 3]),
        ];

        $this->assertSame([1, 2, 70], $ids);
    }
}
