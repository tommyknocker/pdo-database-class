<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

final class ProfileCliTests extends TestCase
{
    /** @return array{int, string, string} */
    private function cli(array $arguments, bool $connect = false): array
    {
        $env = ['PATH' => (string)getenv('PATH')];
        if ($connect) {
            $env['PDODB_DRIVER'] = 'sqlite';
            $env['PDODB_PATH'] = ':memory:';
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/pdodb', 'profile', ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
            $env
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testExportedReportIsAvailableInSeparateProcess(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->enableProfiling(0);
        $db->rawQuery("SELECT 'private-value-421' AS value");
        $file = tempnam(sys_get_temp_dir(), 'pdodb-profile-');
        self::assertIsString($file);

        try {
            file_put_contents($file, $db->profileReport()->export());
            [$exit, $out, $err] = $this->cli(['summary', $file]);
            self::assertSame(0, $exit, $err);
            $report = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($report['enabled']);
            self::assertNotEmpty($report['queries']);
            self::assertStringNotContainsString('private-value-421', $out);

            [$textExit, $textOut, $textErr] = $this->cli(['summary', $file, '--text']);
            self::assertSame(0, $textExit, $textErr);
            self::assertStringContainsString('Query profile summary', $textOut);
            self::assertStringContainsString('Total queries:', $textOut);

            [$aliasExit, $aliasOut] = $this->cli(['import', $file]);
            self::assertSame(0, $aliasExit);
            self::assertSame($report, json_decode($aliasOut, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($file);
        }
    }

    public function testDiagnosisAcceptsSqlAndBoundParameters(): void
    {
        [$exit, $out, $err] = $this->cli(['diagnose', 'SELECT ? AS value', '--param=[42]'], true);
        self::assertSame(0, $exit, $err);
        $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['model_request']);
        self::assertSame('database', $result['source']);
    }

    public function testSummaryRejectsInvalidReport(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pdodb-profile-');
        self::assertIsString($file);

        try {
            file_put_contents($file, '[]');
            [$exit, $out, $err] = $this->cli(['summary', $file]);
            self::assertNotSame(0, $exit);
            self::assertStringContainsString('Profile export', $out . $err);
        } finally {
            unlink($file);
        }
    }
}
