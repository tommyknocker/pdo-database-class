<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\BaseCliCommand;
use tommyknocker\pdodb\cli\CliOutput;
use tommyknocker\pdodb\cli\commands\ProfileCommand;

final class CliOutputTests extends TestCase
{
    protected function tearDown(): void
    {
        BaseCliCommand::resetIo();
        parent::tearDown();
    }

    public function testCliOutputWritesToInjectedStreams(): void
    {
        $stdout = fopen('php://memory', 'wb');
        $stderr = fopen('php://memory', 'wb');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $io = new CliOutput($stdout, $stderr);
        $io->write('a');
        $io->writeln('b');
        $io->writeErrorLine('err');

        rewind($stdout);
        rewind($stderr);
        $this->assertSame("ab\n", stream_get_contents($stdout));
        $this->assertSame("err\n", stream_get_contents($stderr));
    }

    public function testCommandUsesSharedIoForWriteln(): void
    {
        $stdout = fopen('php://memory', 'wb');
        $this->assertIsResource($stdout);
        BaseCliCommand::setIo(new CliOutput($stdout));

        $command = new ProfileCommand();
        $command->setArguments(['help']);
        $code = $command->execute();

        $this->assertSame(0, $code);
        rewind($stdout);
        $out = stream_get_contents($stdout);
        $this->assertIsString($out);
        $this->assertStringContainsString('Query profiling and diagnosis', $out);
    }

    public function testStaticErrorUsesStderrStream(): void
    {
        $stderr = fopen('php://memory', 'wb');
        $this->assertIsResource($stderr);
        BaseCliCommand::setIo(new CliOutput(null, $stderr));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Error: boom');
            BaseCliCommand::error('boom');
        } finally {
        }

        rewind($stderr);
        $this->assertSame("Error: boom\n", stream_get_contents($stderr));
    }

    public function testCommandFailWritesToStderrAndReturnsOne(): void
    {
        $stderr = fopen('php://memory', 'wb');
        $this->assertIsResource($stderr);
        BaseCliCommand::setIo(new CliOutput(null, $stderr));

        $command = new ProfileCommand();
        $command->setArguments(['import']);
        $code = $command->execute();

        $this->assertSame(1, $code);
        rewind($stderr);
        $this->assertStringContainsString('Error: Path to exported JSON is required', (string)stream_get_contents($stderr));
    }
}
