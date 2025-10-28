<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\exporters\CsvExporter;
use tommyknocker\pdodb\helpers\exporters\JsonExporter;
use tommyknocker\pdodb\helpers\exporters\XmlExporter;

/**
 * Trait for data export operations.
 */
trait ExportHelpersTrait
{
    /**
     * Export array data to JSON format.
     *
     * @param array<array<string, mixed>> $data The data to export.
     * @param int $flags Optional JSON encoding flags (default: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).
     * @param positive-int $depth Maximum encoding depth.
     *
     * @return string The JSON-encoded data.
     * @throws \JsonException If JSON encoding fails.
     */
    public static function toJson(array $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, int $depth = 512): string
    {
        $exporter = new JsonExporter();
        return $exporter->export($data, $flags, $depth);
    }

    /**
     * Export array data to CSV format.
     *
     * @param array<array<string, mixed>> $data The data to export.
     * @param string $delimiter CSV delimiter (default: ',').
     * @param string $enclosure CSV enclosure (default: '"').
     * @param string $escapeCharacter CSV escape character (default: '\\').
     *
     * @return string The CSV-encoded data.
     */
    public static function toCsv(array $data, string $delimiter = ',', string $enclosure = '"', string $escapeCharacter = '\\'): string
    {
        $exporter = new CsvExporter();
        return $exporter->export($data, $delimiter, $enclosure, $escapeCharacter);
    }

    /**
     * Export array data to XML format.
     *
     * @param array<array<string, mixed>> $data The data to export.
     * @param string $rootElement Root XML element name (default: 'data').
     * @param string $itemElement Individual item element name (default: 'item').
     * @param string $encoding XML encoding (default: 'UTF-8').
     *
     * @return string The XML-encoded data.
     */
    public static function toXml(array $data, string $rootElement = 'data', string $itemElement = 'item', string $encoding = 'UTF-8'): string
    {
        $exporter = new XmlExporter();
        return $exporter->export($data, $rootElement, $itemElement, $encoding);
    }
}
