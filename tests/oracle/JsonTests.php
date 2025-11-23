<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * JsonTests tests for Oracle.
 */
final class JsonTests extends BaseOracleTestCase
{
    public function testJsonMethods(): void
    {
        $db = self::$db;

        // Prepare table - Oracle uses VARCHAR2 with IS JSON constraint
        try {
            $db->rawQuery('DROP TRIGGER t_json_trigger');
        } catch (\Throwable) {
            // Trigger doesn't exist, continue
        }

        try {
            $db->rawQuery('DROP SEQUENCE t_json_seq');
        } catch (\Throwable) {
            // Sequence doesn't exist, continue
        }

        try {
            $db->rawQuery('DROP TABLE t_json CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }
        $db->rawQuery('CREATE TABLE t_json (id NUMBER PRIMARY KEY, meta VARCHAR2(4000) CONSTRAINT t_json_meta_is_json CHECK (meta IS JSON))');
        $db->rawQuery('CREATE SEQUENCE t_json_seq START WITH 1 INCREMENT BY 1');
        $db->rawQuery('
            CREATE OR REPLACE TRIGGER t_json_trigger
            BEFORE INSERT ON t_json
            FOR EACH ROW
            BEGIN
                IF :NEW.id IS NULL THEN
                    SELECT t_json_seq.NEXTVAL INTO :NEW.id FROM DUAL;
                END IF;
            END;
        ');

        // Insert initial rows
        $payload1 = ['a' => ['b' => 1], 'tags' => ['x', 'y'], 'score' => 10];
        $id1 = $db->find()->table('t_json')->insert(['meta' => json_encode($payload1, JSON_UNESCAPED_UNICODE)]);
        $this->assertIsInt($id1);
        $this->assertGreaterThan(0, $id1);

        $payload2 = ['a' => ['b' => 2], 'tags' => ['y', 'z'], 'score' => 5];
        $id2 = $db->find()->table('t_json')->insert(['meta' => json_encode($payload2, JSON_UNESCAPED_UNICODE)]);
        $this->assertIsInt($id2);
        $this->assertGreaterThan(0, $id2);

        // --- selectJson: fetch meta.a.b for id1 via QueryBuilder::selectJson
        $row = $db->find()
            ->table('t_json')
            ->selectJson('meta', ['a', 'b'], 'ab')
            ->where('id', $id1)
            ->getOne();
        $this->assertNotNull($row);
        $this->assertEquals(1, (int)$row['AB']);

        // --- whereJsonContains: verify tags contains 'x' for id1
        $contains = $db->find()
            ->table('t_json')
            ->whereJsonContains('meta', 'x', ['tags'])
            ->where('id', $id1)
            ->exists();
        $this->assertTrue($contains);

        // --- whereJsonExists: check that path $.a.b exists for id1
        $exists = $db->find()
            ->table('t_json')
            ->whereJsonExists('meta', ['a', 'b'])
            ->where('id', $id1)
            ->exists();
        $this->assertTrue($exists);

        // --- whereJsonPath: check $.a.b = 1 for id1
        $matches = $db->find()
            ->table('t_json')
            ->whereJsonPath('meta', ['a', 'b'], '=', 1)
            ->where('id', $id1)
            ->exists();
        $this->assertTrue($matches);

        // --- jsonSet: set meta.a.c = 42 for id1 using QueryBuilder::jsonSet
        $qb = $db->find()->table('t_json')->where('id', $id1);
        $rawSet = $qb->jsonSet('meta', ['a', 'c'], 42);
        $qb->update(['meta' => $rawSet]);

        $val = $db->find()
            ->table('t_json')
            ->selectJson('meta', ['a', 'c'], 'ac')
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals(42, (int)$val['AC']);

        // --- jsonRemove: remove meta.a.b for id1 using QueryBuilder::jsonRemove
        $qb2 = $db->find()->table('t_json')->where('id', $id1);
        $rawRemove = $qb2->jsonRemove('meta', ['a', 'b']);
        $qb2->update(['meta' => $rawRemove]);

        $after = $db->find()
            ->table('t_json')
            ->selectJson('meta', ['a', 'b'], 'ab')
            ->where('id', $id1)
            ->getOne();
        $this->assertNull($after['AB']);

        // --- orderByJson: order by JSON key meta.score ASC using QueryBuilder::orderByJson
        $list = $db->find()
            ->table('t_json')
            ->select(['id'])
            ->selectJson('meta', ['score'], 'score')
            ->orderByJson('meta', ['score'], 'ASC')
            ->get();

        $this->assertCount(2, $list);
        // After all operations, id2 should still have score=5, id1 should still have score=10
        // So in ASC order: id2 (5) first, then id1 (10)
        $this->assertEquals(5, (int)$list[0]['SCORE']);
        $this->assertEquals(10, (int)$list[1]['SCORE']);

        // Cleanup
        try {
            $db->rawQuery('DROP TABLE t_json CASCADE CONSTRAINTS');
            $db->rawQuery('DROP SEQUENCE t_json_seq');
        } catch (\Throwable) {
            // Ignore
        }
    }
}
