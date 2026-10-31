<?php

declare(strict_types=1);

namespace tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Ensures shared code under src/ does not branch on driver names for SQL behavior.
 */
final class DriverBranchingGuardTest extends TestCase
{
    private const string SRC_ROOT = __DIR__ . '/../../src';

    private const string DIALECTS_PREFIX = self::SRC_ROOT . '/dialects';

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function forbiddenPatternProvider(): array
    {
        return [
            ['getDriverName\\(\\)\\s*(===|!==)\\s*[\'"]', 'getDriverName() strict comparison to string'],
            ["getenv\\('PDODB_DRIVER'\\)\\s*(===|!==|==|!=)", 'getenv(PDODB_DRIVER) comparison'],
            ['match\\(\\$this->driver', 'match($this->driver'],
            ['match\\(\\$driver\\s*[,)]', 'match($driver'],
            ['\\$driver\\s*(===|!==)\\s*\'(mysql|mariadb|pgsql|sqlite|sqlsrv|oci)\'', 'driver variable compared to driver name literal'],
            ['\\$dialect\\s*(===|!==)\\s*\'(mysql|mariadb|pgsql|sqlite|sqlsrv|oci)\'', 'dialect variable compared to driver name literal'],
            ['in_array\\(\\$driver,\\s*\\[', 'in_array($driver, ['],
            ["case\\s+'(mysql|mariadb|pgsql|sqlite|sqlsrv|oci)'\\s*:", 'switch/case on driver name'],
        ];
    }

    #[DataProvider('forbiddenPatternProvider')]
    public function testSharedSrcHasNoForbiddenDriverBranching(string $pattern, string $label): void
    {
        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SRC_ROOT, \FilesystemIterator::SKIP_DOTS)
        );
        $files = new RegexIterator($iterator, '/\.php$/');

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_starts_with($path, self::DIALECTS_PREFIX . '/')) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            if (preg_match('/' . $pattern . '/', $contents) === 1) {
                $violations[] = str_replace(self::SRC_ROOT . '/', 'src/', $path) . ' (' . $label . ')';
            }
        }

        self::assertSame([], $violations, "Forbidden driver branching found:\n" . implode("\n", $violations));
    }
}
