<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\ui\InputHandler;

/**
 * Tests for InputHandler class.
 */
final class InputHandlerTests extends BaseSharedTestCase
{
    public function testInputHandlerReadKeyReturnsNullWhenNotTty(): void
    {
        // InputHandler checks if STDIN is a TTY
        // In test environment, STDIN is usually not a TTY, so it should return null
        $result = InputHandler::readKey(1000);
        $this->assertNull($result);
    }

    public function testInputHandlerReadKeyWithTimeout(): void
    {
        // Test with different timeout values
        $result1 = InputHandler::readKey(1000);
        $this->assertNull($result1);

        $result2 = InputHandler::readKey(50000);
        $this->assertNull($result2);
    }
}
