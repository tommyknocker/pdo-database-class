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

    /** @var resource|null Input given by the caller instead of the terminal (tests, scripted runs) */
    protected $stdin;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin Answers to prompts; when given, prompts are answered from it instead of the terminal
     */
    public function __construct($stdout = null, $stderr = null, $stdin = null)
    {
        $this->stdin = $stdin;
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

    /**
     * Whether the caller supplied the answers to prompts.
     */
    public function hasScriptedInput(): bool
    {
        return $this->stdin !== null;
    }

    /**
     * Read one line of input (without the line break) from the supplied input, or the terminal.
     */
    public function readLine(): string
    {
        $line = fgets($this->stdin ?? STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
