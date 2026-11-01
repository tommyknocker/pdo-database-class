<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;
use tommyknocker\pdodb\dialects\sqlite\SQLiteSqlFormatter;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;
use tommyknocker\pdodb\PdoDb;

/**
 * SQL text produced by the SQLite formatter, plus the cases where SQLite itself has to accept it.
 */
final class SQLiteSqlFormatterTests extends TestCase
{
    private SQLiteSqlFormatter $formatter;

    private PdoDb $db;

    protected function setUp(): void
    {
        $this->formatter = new SQLiteSqlFormatter(new SqliteDialect());
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
    }

    public function testLimitAndOffset(): void
    {
        $this->assertSame('SELECT * FROM t LIMIT 10 OFFSET 5', $this->formatter->formatLimitOffset('SELECT * FROM t', 10, 5));
        $this->assertSame('SELECT * FROM t', $this->formatter->formatLimitOffset('SELECT * FROM t', null, null));
    }

    public function testJsonHelpers(): void
    {
        $this->assertSame("json_extract(\"data\", '\$.a.b')", $this->formatter->formatJsonGet('data', 'a.b', false));
        $this->assertSame("JSON_REMOVE(\"data\", '\$.a.b')", $this->formatter->formatJsonRemove('data', ['a', 'b']));
        $this->assertSame("json_type(json_extract(\"data\", '\$.a'))", $this->formatter->formatJsonType('data', ['a']));
    }

    public function testJsonKeysListsTheKeysOfAnObjectOnly(): void
    {
        $this->db->rawQuery('CREATE TABLE j (id INTEGER PRIMARY KEY, data TEXT)');
        $this->db->rawQuery("INSERT INTO j (id, data) VALUES (1, '{\"b\":1,\"a\":{\"x\":1,\"y\":2}}'), (2, '[1,2]')");

        $sql = $this->formatter->formatJsonKeys('data');
        $this->assertSame('["b","a"]', $this->db->rawQueryValue("SELECT {$sql} FROM j WHERE id = 1"));
        $this->assertNull($this->db->rawQueryValue("SELECT {$sql} FROM j WHERE id = 2"));

        $nested = $this->formatter->formatJsonKeys('data', ['a']);
        $this->assertSame('["x","y"]', $this->db->rawQueryValue("SELECT {$nested} FROM j WHERE id = 1"));
    }

    public function testTruncateCutsTowardZeroForBothSigns(): void
    {
        $this->db->rawQuery('CREATE TABLE n (id INTEGER PRIMARY KEY, x REAL)');
        $this->db->rawQuery('INSERT INTO n (id, x) VALUES (1, 1.239), (2, -1.239), (3, 1.5), (4, -0.005)');

        $sql = $this->formatter->formatTruncate('x', 2);
        $this->assertSame([1.23, -1.23, 1.5, 0.0], array_map(static fn (array $row): float => (float) array_values($row)[0], $this->db->rawQuery("SELECT {$sql} FROM n ORDER BY id")));
        $this->assertSame('CAST(x AS INTEGER)', $this->formatter->formatTruncate('x', 0));
    }

    public function testGroupConcatDistinct(): void
    {
        $this->db->rawQuery('CREATE TABLE g (n TEXT)');
        $this->db->rawQuery("INSERT INTO g (n) VALUES ('a'), ('b'), ('a')");

        $this->assertSame("GROUP_CONCAT(n, ', ')", $this->formatter->formatGroupConcat('n', ', ', false));
        $this->assertSame('a,b', $this->db->rawQueryValue('SELECT ' . $this->formatter->formatGroupConcat('n', ',', true) . ' FROM g'));
    }

    public function testGroupConcatDistinctWithACustomSeparatorIsRejected(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->formatter->formatGroupConcat('n', ', ', true);
    }

    public function testStringAndDateHelpers(): void
    {
        $this->assertSame("IFNULL(a, 'x')", $this->formatter->formatIfNull('a', 'x'));
        $this->assertSame('SUBSTR(name, 2, 3)', $this->formatter->formatSubstring('name', 2, 3));
        $this->assertSame("CAST(STRFTIME('%Y', d) AS INTEGER)", $this->formatter->formatYear('d'));
        $this->assertSame("datetime(d, '+1 day')", $this->formatter->formatInterval('d', '1', 'DAY', true));
        $this->assertSame('MAX(a, b)', $this->formatter->formatGreatest(['a', 'b']));
        $this->assertSame("INSTR(b, 'a')", $this->formatter->formatPosition('a', 'b'));
    }
}
