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
     * @param string|null $input Answers to the prompts, one per line; null leaves prompting to the environment
     *
     * @return array{result: mixed, stdout: string, stderr: string, output: string}
     */
    public static function capture(callable $callback, ?string $input = null): array
    {
        $stdin = null;
        if ($input !== null) {
            $stdin = fopen('php://memory', 'w+b');
            if ($stdin === false) {
                throw new \RuntimeException('Failed to open memory stream for stdin');
            }
            fwrite($stdin, $input);
            rewind($stdin);
        }

        $stderr = fopen('php://memory', 'wb');
        if ($stderr === false) {
            throw new \RuntimeException('Failed to open memory stream for stderr capture');
        }

        BaseCliCommand::setIo(new CliOutput(null, $stderr, $stdin));
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
