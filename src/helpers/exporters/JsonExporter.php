<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\exporters;

/**
 * JSON exporter for array data.
 */
class JsonExporter
{
    /**
     * Export array data to JSON format.
     *
     * @param array<array<string, mixed>> $data The data to export.
     * @param int $flags JSON encoding flags.
     * @param positive-int $depth Maximum encoding depth.
     *
     * @return string The JSON-encoded data.
     * @throws \JsonException If JSON encoding fails.
     */
    public function export(array $data, int $flags, int $depth): string
    {
        /** @var positive-int $safeDepth */
        $safeDepth = max(1, $depth);
        return json_encode($data, $flags | JSON_THROW_ON_ERROR, $safeDepth);
    }
}
