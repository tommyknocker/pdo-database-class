<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\exporters;

/**
 * CSV exporter for array data.
 */
class CsvExporter
{
    /**
     * Export array data to CSV format.
     *
     * @param array<array<string, mixed>> $data The data to export.
     * @param string $delimiter CSV delimiter.
     * @param string $enclosure CSV enclosure.
     * @param string $escapeCharacter CSV escape character.
     *
     * @return string The CSV-encoded data.
     */
    public function export(array $data, string $delimiter, string $enclosure, string $escapeCharacter): string
    {
        if (empty($data)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        if ($output === false) {
            throw new \RuntimeException('Failed to create temporary stream for CSV export');
        }

        // Write header row
        fputcsv($output, array_keys($data[0]), $delimiter, $enclosure, $escapeCharacter);

        // Write data rows
        foreach ($data as $row) {
            fputcsv($output, $row, $delimiter, $enclosure, $escapeCharacter);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
