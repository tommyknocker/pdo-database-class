<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\exporters\CsvExporter;

/**
 * Tests for CsvExporter class.
 */
final class CsvExporterTests extends BaseSharedTestCase
{
    public function testExportEmptyArray(): void
    {
        $exporter = new CsvExporter();
        $result = $exporter->export([], ',', '"', '\\');

        $this->assertEquals('', $result);
    }

    public function testExportSimpleData(): void
    {
        $exporter = new CsvExporter();
        $data = [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25],
        ];

        $result = $exporter->export($data, ',', '"', '\\');

        $this->assertStringContainsString('name,age', $result);
        $this->assertStringContainsString('John,30', $result);
        $this->assertStringContainsString('Jane,25', $result);
    }

    public function testExportWithCustomDelimiter(): void
    {
        $exporter = new CsvExporter();
        $data = [
            ['name' => 'John', 'age' => 30],
        ];

        $result = $exporter->export($data, ';', '"', '\\');

        $this->assertStringContainsString('name;age', $result);
        $this->assertStringContainsString('John;30', $result);
    }

    public function testExportWithSpecialCharacters(): void
    {
        $exporter = new CsvExporter();
        $data = [
            ['name' => 'John "Doe"', 'description' => 'He said, "Hello"'],
        ];

        $result = $exporter->export($data, ',', '"', '\\');

        $this->assertStringContainsString('name,description', $result);
        // CSV should properly escape quotes
        $this->assertStringContainsString('John', $result);
    }

    public function testExportWithEmptyValues(): void
    {
        $exporter = new CsvExporter();
        $data = [
            ['name' => 'John', 'age' => '', 'city' => null],
        ];

        $result = $exporter->export($data, ',', '"', '\\');

        $this->assertStringContainsString('name,age,city', $result);
        $this->assertStringContainsString('John', $result);
    }

    public function testExportWithNumericValues(): void
    {
        $exporter = new CsvExporter();
        $data = [
            ['id' => 1, 'price' => 99.99, 'active' => true],
            ['id' => 2, 'price' => 149.50, 'active' => false],
        ];

        $result = $exporter->export($data, ',', '"', '\\');

        $this->assertStringContainsString('id,price,active', $result);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('99.99', $result);
    }
}
