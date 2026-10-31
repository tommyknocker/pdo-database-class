<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\BaseCliCommand;
use tommyknocker\pdodb\cli\CliOutput;

/**
 * Captures CLI stdout (output buffering) and stderr (injected CliOutput) in tests.
 */
final class CliTestCapture
{
    /**
     * @param list<string> $argv
     *
     * @return array{code: int, stdout: string, stderr: string, output: string}
     */
    public static function runApplication(Application $app, array $argv): array
    {
        $captured = self::capture(static fn (): int => $app->run($argv));

        return [
            'code' => (int) $captured['result'],
            'stdout' => $captured['stdout'],
            'stderr' => $captured['stderr'],
            'output' => $captured['output'],
        ];
    }

    /**
     * @return array{result: mixed, stdout: string, stderr: string, output: string}
     */
    public static function capture(callable $callback): array
    {
        $stderr = fopen('php://memory', 'wb');
        if ($stderr === false) {
            throw new \RuntimeException('Failed to open memory stream for stderr capture');
        }

        BaseCliCommand::setIo(new CliOutput(null, $stderr));
        ob_start();

        try {
            $result = $callback();
            $stdout = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            BaseCliCommand::resetIo();
            fclose($stderr);

            throw $e;
        }

        BaseCliCommand::resetIo();
        rewind($stderr);
        $stderrStr = (string) stream_get_contents($stderr);
        fclose($stderr);

        return [
            'result' => $result,
            'stdout' => $stdout,
            'stderr' => $stderrStr,
            'output' => $stdout . $stderrStr,
        ];
    }
}
