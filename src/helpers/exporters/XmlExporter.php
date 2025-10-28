<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\exporters;

/**
 * XML exporter for array data.
 */
class XmlExporter
{
    /**
     * Export array data to XML format.
     *
     * @param array<array<string, mixed>> $data The data to export.
     * @param string $rootElement Root XML element name.
     * @param string $itemElement Individual item element name.
     * @param string $encoding XML encoding.
     *
     * @return string The XML-encoded data.
     */
    public function export(array $data, string $rootElement, string $itemElement, string $encoding): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', $encoding);
        $xml->startElement($rootElement);

        foreach ($data as $row) {
            $xml->startElement($itemElement);
            $this->writeXmlElement($xml, $row);
            $xml->endElement();
        }

        $xml->endElement();
        return $xml->outputMemory();
    }

    /**
     * Recursively write array data as XML elements.
     *
     * @param \XMLWriter $xml The XML writer.
     * @param array<string, mixed> $data The data to write.
     */
    protected function writeXmlElement(\XMLWriter $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            $key = $this->sanitizeXmlName($key);

            if (is_array($value)) {
                $xml->startElement($key);
                $this->writeXmlElement($xml, $value);
                $xml->endElement();
            } else {
                $xml->writeElement($key, (string)$value);
            }
        }
    }

    /**
     * Sanitize XML element name.
     *
     * @param string $name The name to sanitize.
     *
     * @return string The sanitized name.
     */
    protected function sanitizeXmlName(string $name): string
    {
        // Replace invalid XML name characters
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?? $name;
    }
}
