<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use tommyknocker\pdodb\query\pagination\Cursor;

/**
 * Tests for Cursor class.
 */
final class CursorTests extends BaseSharedTestCase
{
    public function testCursorParameters(): void
    {
        $params = ['id' => 10, 'created_at' => '2024-01-01'];
        $cursor = new Cursor($params);
        $this->assertEquals($params, $cursor->parameters());
    }

    public function testCursorPointsToNextItems(): void
    {
        $cursor = new Cursor(['id' => 10], true);
        $this->assertTrue($cursor->pointsToNextItems());
        $this->assertFalse($cursor->pointsToPreviousItems());
    }

    public function testCursorPointsToPreviousItems(): void
    {
        $cursor = new Cursor(['id' => 10], false);
        $this->assertFalse($cursor->pointsToNextItems());
        $this->assertTrue($cursor->pointsToPreviousItems());
    }

    public function testCursorEncode(): void
    {
        $params = ['id' => 10, 'created_at' => '2024-01-01'];
        $cursor = new Cursor($params, true);
        $encoded = $cursor->encode();
        $this->assertIsString($encoded);
        $this->assertNotEmpty($encoded);
    }

    public function testCursorDecode(): void
    {
        $params = ['id' => 10, 'created_at' => '2024-01-01'];
        $cursor = new Cursor($params, true);
        $encoded = $cursor->encode();

        $decoded = Cursor::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertEquals($params, $decoded->parameters());
        $this->assertTrue($decoded->pointsToNextItems());
    }

    public function testCursorDecodePrevious(): void
    {
        $params = ['id' => 10, 'created_at' => '2024-01-01'];
        $cursor = new Cursor($params, false);
        $encoded = $cursor->encode();

        $decoded = Cursor::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertEquals($params, $decoded->parameters());
        $this->assertFalse($decoded->pointsToNextItems());
    }

    public function testCursorDecodeNull(): void
    {
        $decoded = Cursor::decode(null);
        $this->assertNull($decoded);
    }

    public function testCursorDecodeEmptyString(): void
    {
        $decoded = Cursor::decode('');
        $this->assertNull($decoded);
    }

    public function testCursorDecodeInvalidBase64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cursor format');
        Cursor::decode('invalid_base64!!!');
    }

    public function testCursorDecodeInvalidJson(): void
    {
        $invalid = base64_encode('invalid json');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cursor data');
        Cursor::decode($invalid);
    }

    public function testCursorDecodeMissingParams(): void
    {
        $invalid = base64_encode(json_encode(['direction' => 'next']));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cursor data');
        Cursor::decode($invalid);
    }

    public function testCursorDecodeMissingDirection(): void
    {
        $invalid = base64_encode(json_encode(['params' => ['id' => 10]]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cursor data');
        Cursor::decode($invalid);
    }

    public function testCursorFromItem(): void
    {
        $item = ['id' => 10, 'name' => 'John', 'email' => 'john@example.com'];
        $columns = ['id', 'email'];

        $cursor = Cursor::fromItem($item, $columns);
        $this->assertEquals(['id' => 10, 'email' => 'john@example.com'], $cursor->parameters());
        $this->assertTrue($cursor->pointsToNextItems());
    }

    public function testCursorFromItemMissingColumn(): void
    {
        $item = ['id' => 10, 'name' => 'John'];
        $columns = ['id', 'email'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column email not found in item');
        Cursor::fromItem($item, $columns);
    }

    public function testCursorEncodeDecodeRoundTrip(): void
    {
        $params = ['id' => 100, 'name' => 'Test', 'created_at' => '2024-01-01 12:00:00'];
        $cursor1 = new Cursor($params, true);
        $encoded = $cursor1->encode();

        $cursor2 = Cursor::decode($encoded);
        $this->assertNotNull($cursor2);
        $this->assertEquals($params, $cursor2->parameters());
        $this->assertTrue($cursor2->pointsToNextItems());
    }

    public function testCursorEncodeWithComplexData(): void
    {
        $params = [
            'id' => 100,
            'name' => 'Test User',
            'metadata' => ['key1' => 'value1', 'key2' => 'value2'],
            'tags' => ['tag1', 'tag2', 'tag3'],
        ];
        $cursor = new Cursor($params, true);
        $encoded = $cursor->encode();

        $decoded = Cursor::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertEquals($params, $decoded->parameters());
    }
}
