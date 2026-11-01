<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use ReflectionProperty;
use tommyknocker\pdodb\cli\BaseCliCommand;
use tommyknocker\pdodb\cli\CliOutput;
use tommyknocker\pdodb\cli\ui\Dashboard;
use tommyknocker\pdodb\cli\ui\DashboardState;
use tommyknocker\pdodb\cli\ui\SqlScratchpad;

/**
 * A Dashboard whose keys, frames and state can be driven from a test without a terminal.
 */
final class DashboardHarness extends Dashboard
{
    public function press(string ...$keys): bool
    {
        $exit = false;
        foreach ($keys as $key) {
            $exit = $this->handleKey($key);
        }

        return $exit;
    }

    public function prop(string $name): mixed
    {
        [$class, $object] = $this->owner($name);

        return (new ReflectionProperty($class, $name))->getValue($object);
    }

    public function set(string $name, mixed $value): void
    {
        [$class, $object] = $this->owner($name);
        (new ReflectionProperty($class, $name))->setValue($object, $value);
    }

    /**
     * @return array{class-string, object} Where a piece of state lives: scratchpad state is in SqlScratchpad
     */
    private function owner(string $name): array
    {
        if (str_starts_with($name, 'scratchpad')) {
            return [SqlScratchpad::class, $this->scratchpad];
        }

        return property_exists(DashboardState::class, $name) ? [DashboardState::class, $this->state] : [Dashboard::class, $this];
    }

    public function screen(): int
    {
        return $this->layout->getCurrentScreen();
    }

    public function refreshData(): void
    {
        $this->refresh();
    }

    /**
     * Text a render method writes, with the terminal control sequences removed.
     */
    public function frame(string $method = 'render'): string
    {
        $stream = fopen('php://memory', 'w+');
        BaseCliCommand::setIo(new CliOutput($stream));

        try {
            $this->$method();
            rewind($stream);
            $raw = (string)stream_get_contents($stream);
        } finally {
            BaseCliCommand::resetIo();
            fclose($stream);
        }

        return (string)preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $raw);
    }
}
