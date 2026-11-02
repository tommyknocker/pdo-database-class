<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\TableFormatter;

final class TableFormatterTests extends TestCase
{
    public function testCellsAreShownAsText(): void
    {
        $this->assertSame('(null)', TableFormatter::cell(null));
        $this->assertSame('', TableFormatter::cell(null, ''));
        $this->assertSame('true', TableFormatter::cell(true));
        $this->assertSame('false', TableFormatter::cell(false));
        $this->assertSame('{"a":"é"}', TableFormatter::cell(['a' => 'é']));
        $this->assertSame('3.5', TableFormatter::cell(3.5));
    }

    public function testBorderedRowsLineUpByCharactersNotBytes(): void
    {
        $lines = TableFormatter::bordered([
            ['name' => 'Zoë', 'city' => '東京'],
            ['name' => 'Bob', 'city' => 'Paris'],
        ]);

        // every line is as wide as the rules, although "ë" and the kanji take several bytes
        $width = mb_strlen($lines[0]);
        foreach ($lines as $line) {
            $this->assertSame($width, mb_strlen($line), $line);
        }
        $this->assertSame('| name | city  |', $lines[1]);
        $this->assertSame('| Zoë  | 東京    |', $lines[3]);
        $this->assertCount(6, $lines);
    }

    public function testLongCellsAreCutToTheWidth(): void
    {
        $lines = TableFormatter::bordered([['a' => str_repeat('x', 100), 'b' => 'y']], 40);

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(40, mb_strlen($line));
        }
    }

    public function testPlainRowsCoverEveryColumnOfEveryRow(): void
    {
        $lines = TableFormatter::plain([
            ['id' => 1, 'user' => 'ada'],
            ['id' => 2, 'host' => 'db1'],
        ]);

        $this->assertSame('  id  user  host  ', $lines[0]);
        $this->assertSame('  --  ----  ----  ', $lines[1]);
        $this->assertSame('  1   ada         ', $lines[2]);
        $this->assertSame('  2         db1   ', $lines[3]);
    }

    public function testNoRowsNoLines(): void
    {
        $this->assertSame([], TableFormatter::bordered([]));
        $this->assertSame([], TableFormatter::plain([]));
    }
}
