<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mssql\MSSQLSqlFormatter;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

/**
 * SQL Server 2025 (version 17) has REGEXP_LIKE, REGEXP_REPLACE and REGEXP_SUBSTR; earlier servers get the LIKE
 * translation. The server version decides, so both paths are checked here without a server.
 */
final class MSSQLNativeRegexpTests extends TestCase
{
    private function formatter(int $majorVersion): MSSQLSqlFormatter
    {
        $dialect = new class ($majorVersion) extends MSSQLDialect {
            public function __construct(private readonly int $major)
            {
                parent::__construct();
            }

            protected function serverMajorVersion(): int
            {
                return $this->major;
            }
        };

        return new MSSQLSqlFormatter($dialect);
    }

    public function testServersBefore2025AreNotNative(): void
    {
        foreach ([0, 13, 15, 16] as $major) {
            $dialect = $this->formatter($major);
            $this->assertSame("(c LIKE '%ab%')", $dialect->formatRegexpMatch('c', 'ab'), "version {$major}");
        }
    }

    public function testSqlServer2025UsesTheNativeFunctions(): void
    {
        $formatter = $this->formatter(17);

        $this->assertSame("REGEXP_LIKE(c, '^a+b$')", $formatter->formatRegexpMatch('c', '^a+b$'));
        $this->assertSame("REGEXP_LIKE(c, '^a+b$')", $formatter->formatRegexpLike('c', '^a+b$'));
        $this->assertSame("REGEXP_REPLACE(c, '[0-9]+', '#')", $formatter->formatRegexpReplace('c', '[0-9]+', '#'));
        $this->assertSame("REGEXP_SUBSTR(c, '[0-9]+')", $formatter->formatRegexpExtract('c', '[0-9]+'));
        $this->assertSame("REGEXP_SUBSTR(c, '(a)(b)', 1, 1, '', 2)", $formatter->formatRegexpExtract('c', '(a)(b)', 2));
    }

    public function testPatternsLikeCannotExpressStillWorkNativelyButNotBefore2025(): void
    {
        $this->assertStringContainsString('REGEXP_LIKE', $this->formatter(17)->formatRegexpMatch('c', 'a+b'));

        $this->expectException(UnsupportedOperationException::class);
        $this->formatter(16)->formatRegexpMatch('c', 'a+b');
    }

    public function testNativePatternsWithNonAsciiTextKeepTheNPrefix(): void
    {
        $this->assertSame("REGEXP_LIKE(c, N'ünï')", $this->formatter(17)->formatRegexpMatch('c', 'ünï'));
    }
}
