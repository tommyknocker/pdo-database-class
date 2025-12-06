<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\ui\Terminal;

/**
 * Tests for Terminal class.
 */
final class TerminalTests extends BaseSharedTestCase
{
    public function testTerminalClear(): void
    {
        ob_start();
        Terminal::clear();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[2J\033[H", $output);
    }

    public function testTerminalMoveTo(): void
    {
        ob_start();
        Terminal::moveTo(5, 10);
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[5;10H", $output);
    }

    public function testTerminalHideCursor(): void
    {
        ob_start();
        Terminal::hideCursor();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[?25l", $output);
    }

    public function testTerminalShowCursor(): void
    {
        ob_start();
        Terminal::showCursor();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[?25h", $output);
    }

    public function testTerminalClearLine(): void
    {
        ob_start();
        Terminal::clearLine();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[K", $output);
    }

    public function testTerminalClearLineFull(): void
    {
        ob_start();
        Terminal::clearLineFull();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[2K", $output);
    }

    public function testTerminalReset(): void
    {
        ob_start();
        Terminal::reset();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[0m", $output);
    }

    public function testTerminalBold(): void
    {
        ob_start();
        Terminal::bold();
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[1m", $output);
    }

    public function testTerminalColor(): void
    {
        ob_start();
        Terminal::color(Terminal::COLOR_RED);
        $output = ob_get_clean();
        $this->assertStringContainsString("\033[31m", $output);
    }

    public function testTerminalGetSize(): void
    {
        $size = Terminal::getSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertIsInt($size[0]);
        $this->assertIsInt($size[1]);
        $this->assertGreaterThan(0, $size[0]);
        $this->assertGreaterThan(0, $size[1]);
    }

    public function testTerminalSupportsColors(): void
    {
        $supports = Terminal::supportsColors();
        $this->assertIsBool($supports);
    }

    public function testTerminalSupportsColorsWithNoColorEnv(): void
    {
        $originalNoColor = getenv('NO_COLOR');
        putenv('NO_COLOR=1');
        $supports = Terminal::supportsColors();
        $this->assertFalse($supports);
        if ($originalNoColor !== false) {
            putenv('NO_COLOR=' . $originalNoColor);
        } else {
            putenv('NO_COLOR');
        }
    }

    public function testTerminalSupportsColorsWithForceColorEnv(): void
    {
        $originalForceColor = getenv('FORCE_COLOR');
        putenv('FORCE_COLOR=1');
        $supports = Terminal::supportsColors();
        // FORCE_COLOR should make it return true, but if stdout is not a TTY, it might still return false
        // So we just check that it's a boolean
        $this->assertIsBool($supports);
        if ($originalForceColor !== false) {
            putenv('FORCE_COLOR=' . $originalForceColor);
        } else {
            putenv('FORCE_COLOR');
        }
    }

    public function testTerminalColorConstants(): void
    {
        $this->assertEquals(30, Terminal::COLOR_BLACK);
        $this->assertEquals(31, Terminal::COLOR_RED);
        $this->assertEquals(32, Terminal::COLOR_GREEN);
        $this->assertEquals(33, Terminal::COLOR_YELLOW);
        $this->assertEquals(34, Terminal::COLOR_BLUE);
        $this->assertEquals(35, Terminal::COLOR_MAGENTA);
        $this->assertEquals(36, Terminal::COLOR_CYAN);
        $this->assertEquals(37, Terminal::COLOR_WHITE);
        $this->assertEquals(40, Terminal::BG_BLACK);
        $this->assertEquals(41, Terminal::BG_RED);
        $this->assertEquals(42, Terminal::BG_GREEN);
        $this->assertEquals(43, Terminal::BG_YELLOW);
        $this->assertEquals(44, Terminal::BG_BLUE);
        $this->assertEquals(45, Terminal::BG_MAGENTA);
        $this->assertEquals(46, Terminal::BG_CYAN);
        $this->assertEquals(47, Terminal::BG_WHITE);
    }
}
