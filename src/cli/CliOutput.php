<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * CLI stdout/stderr writer (testable via injected streams).
 */
class CliOutput
{
    /** @var resource */
    protected $stdout;

    /** @var resource */
    protected $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        if ($stdout === null) {
            $stdout = fopen('php://output', 'wb');
            if ($stdout === false) {
                $stdout = STDOUT;
            }
        }
        if ($stderr === null) {
            $stderr = fopen('php://stderr', 'wb');
            if ($stderr === false) {
                $stderr = STDERR;
            }
        }
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function write(string $message): void
    {
        fwrite($this->stdout, $message);
    }

    public function writeln(string $message = ''): void
    {
        fwrite($this->stdout, $message . "\n");
    }

    public function writeError(string $message): void
    {
        fwrite($this->stderr, $message);
    }

    public function writeErrorLine(string $message = ''): void
    {
        fwrite($this->stderr, $message . "\n");
    }

    public function flushStdout(): void
    {
        fflush($this->stdout);
    }
}
