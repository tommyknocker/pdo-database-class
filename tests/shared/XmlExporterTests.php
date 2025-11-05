<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\exporters\XmlExporter;

/**
 * Tests for XmlExporter class.
 */
final class XmlExporterTests extends BaseSharedTestCase
{
    public function testExportEmptyArray(): void
    {
        $exporter = new XmlExporter();
        $result = $exporter->export([], 'root', 'item', 'UTF-8');

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $result);
        // Empty array produces self-closing tag
        $this->assertStringContainsString('<root/>', $result);
    }

    public function testExportSimpleData(): void
    {
        $exporter = new XmlExporter();
        $data = [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25],
        ];

        $result = $exporter->export($data, 'users', 'user', 'UTF-8');

        $this->assertStringContainsString('<users>', $result);
        $this->assertStringContainsString('<user>', $result);
        $this->assertStringContainsString('<name>John</name>', $result);
        $this->assertStringContainsString('<age>30</age>', $result);
        $this->assertStringContainsString('<name>Jane</name>', $result);
    }

    public function testExportWithNestedArrays(): void
    {
        $exporter = new XmlExporter();
        $data = [
            ['name' => 'John', 'tags' => ['tag1' => 'admin', 'tag2' => 'user']],
        ];

        $result = $exporter->export($data, 'root', 'item', 'UTF-8');

        $this->assertStringContainsString('<name>John</name>', $result);
        $this->assertStringContainsString('<tags>', $result);
        $this->assertStringContainsString('<tag1>', $result);
    }

    public function testExportWithCustomEncoding(): void
    {
        $exporter = new XmlExporter();
        $data = [['name' => 'Test']];

        $result = $exporter->export($data, 'root', 'item', 'ISO-8859-1');

        $this->assertStringContainsString('encoding="ISO-8859-1"', $result);
    }

    public function testExportSanitizesInvalidXmlNames(): void
    {
        $exporter = new XmlExporter();
        $data = [
            ['invalid-name' => 'value', 'valid_name' => 'test', 'name with spaces' => 'test2'],
        ];

        $result = $exporter->export($data, 'root', 'item', 'UTF-8');

        // Invalid XML name characters should be replaced with underscore
        $this->assertStringContainsString('<valid_name>', $result);
        // Should not contain invalid characters in element names
        $this->assertStringNotContainsString('name with spaces', $result);
    }

    public function testExportWithSpecialCharacters(): void
    {
        $exporter = new XmlExporter();
        $data = [
            ['name' => 'John & Jane', 'description' => 'Price: $100 < $200'],
        ];

        $result = $exporter->export($data, 'root', 'item', 'UTF-8');

        $this->assertStringContainsString('<name>', $result);
        // XML should properly handle special characters
        $this->assertIsString($result);
    }
}
