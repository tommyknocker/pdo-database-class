<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\ui\actions\KillConnectionAction;
use tommyknocker\pdodb\cli\ui\actions\KillQueryAction;
use tommyknocker\pdodb\cli\ui\InputHandler;

/**
 * Tests for UI classes.
 */
final class UiClassesTests extends BaseSharedTestCase
{
    public function testInputHandlerReadKeyReturnsNullWhenNotTty(): void
    {
        // InputHandler checks if STDIN is a TTY
        // In test environment, STDIN is usually not a TTY, so it should return null
        $result = InputHandler::readKey(1000);
        $this->assertNull($result);
    }

    public function testKillQueryActionExecuteWithInvalidProcessId(): void
    {
        // SQLite doesn't support killing queries, so it should return false
        $result = KillQueryAction::execute(self::$db, 99999);
        // SQLite doesn't support killQuery, so it should return false or throw
        // We just check that the method doesn't crash
        $this->assertIsBool($result);
    }

    public function testKillConnectionActionExecuteWithInvalidProcessId(): void
    {
        // SQLite doesn't support killing connections, so it should return false
        $result = KillConnectionAction::execute(self::$db, 99999);
        // SQLite doesn't support killConnection, so it should return false
        $this->assertIsBool($result);
    }

    public function testKillQueryActionExecuteWithStringProcessId(): void
    {
        // Test with string process ID
        $result = KillQueryAction::execute(self::$db, '99999');
        $this->assertIsBool($result);
    }

    public function testKillConnectionActionExecuteWithStringProcessId(): void
    {
        // Test with string process ID
        $result = KillConnectionAction::execute(self::$db, '99999');
        $this->assertIsBool($result);
    }

    public function testKillQueryActionExecuteHandlesExceptions(): void
    {
        // The method should catch exceptions and return false
        // SQLite will likely throw an exception or return false
        $result = KillQueryAction::execute(self::$db, -1);
        $this->assertIsBool($result);
    }

    public function testKillConnectionActionExecuteHandlesExceptions(): void
    {
        // The method should catch exceptions and return false
        // SQLite will likely throw an exception or return false
        $result = KillConnectionAction::execute(self::$db, -1);
        $this->assertIsBool($result);
    }
}
