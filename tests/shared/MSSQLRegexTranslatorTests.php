<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\mssql\MSSQLRegexTranslator;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

final class MSSQLRegexTranslatorTests extends TestCase
{
    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function translatable(): array
    {
        return [
            'plain text matches anywhere' => ['abc', '%abc%', 'abc'],
            'start anchor' => ['^abc', 'abc%', null],
            'end anchor' => ['abc$', '%abc', null],
            'both anchors' => ['^abc$', 'abc', null],
            'any character' => ['a.c', '%a_c%', null],
            'any run' => ['a.*c', '%a%c%', null],
            'at least one' => ['a.+c', '%a_%c%', null],
            'escaped dot is literal' => ['a\.c', '%a.c%', 'a.c'],
            'escaped punctuation' => ['\(x\)', '%(x)%', '(x)'],
            'digit' => ['\d\d', '%[0-9][0-9]%', null],
            'class' => ['[abc]x', '%[abc]x%', null],
            'range class' => ['^[a-z]', '[a-z]%', null],
            'negated class' => ['[^0-9]', '%[^0-9]%', null],
            'like wildcards in text are escaped' => ['50%_off', '%50[%][_]off%', '50%_off'],
            'bracket in text is escaped' => ['\[x\]', '%[[]x]%', '[x]'],
            'at sign' => ['@', '%@%', '@'],
            'empty pattern matches everything' => ['', '%%', ''],
            'unicode text' => ['ünï', '%ünï%', 'ünï'],
        ];
    }

    #[DataProvider('translatable')]
    public function testTranslatesWhatLikeCanExpress(string $regex, string $like, ?string $literal): void
    {
        $this->assertSame(['like' => $like, 'literal' => $literal], MSSQLRegexTranslator::translate($regex));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untranslatable(): array
    {
        return [
            'star' => ['a*'],
            'plus' => ['a+'],
            'optional' => ['colou?r'],
            'alternation' => ['cat|dog'],
            'group' => ['(ab)c'],
            'counted' => ['a{2,3}'],
            'word class' => ['\w+'],
            'whitespace class' => ['a\sb'],
            'word boundary' => ['\bfoo'],
            'quantified class' => ['[0-9]+'],
            'quantified escape' => ['\d+'],
            'quantified any' => ['.*?'],
            'caret in the middle' => ['a^b'],
            'dollar in the middle' => ['a$b'],
            'unclosed class' => ['[abc'],
            'empty class' => ['[]'],
            'escape in class' => ['[\d]'],
            'trailing backslash' => ['abc\\'],
        ];
    }

    #[DataProvider('untranslatable')]
    public function testRefusesWhatLikeCannotExpress(string $regex): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('SQL Server before 2025');

        MSSQLRegexTranslator::translate($regex);
    }
}
