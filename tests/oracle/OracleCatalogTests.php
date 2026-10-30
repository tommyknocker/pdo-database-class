<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\ai\AiQueryOptions;
use tommyknocker\pdodb\ai\QueryMetadataCollector;

/**
 * Oracle stores a quoted identifier in the case used at creation.
 */
final class OracleCatalogTests extends BaseOracleTestCase
{
    public function testQuotedLowercaseTableColumnsAreFound(): void
    {
        $db = self::$db;

        try {
            $db->rawQuery('DROP TABLE "advice_catalog_case" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // The table is created below.
        }
        $db->rawQuery('CREATE TABLE "advice_catalog_case" (id NUMBER, status VARCHAR2(20))');

        try {
            $collector = new QueryMetadataCollector($db, (new AiQueryOptions(['context' => ['include_statistics' => true]]))->context);
            $metadata = $collector->collect('advice_catalog_case');
            $names = array_map(static fn (array $column): string => strtolower((string)$column['name']), $metadata['columns']);

            $this->assertContains('status', $names);
            $this->assertArrayHasKey('statistics', $metadata);
            $this->assertNotContains(
                'No columns were returned; this object may be missing or inaccessible',
                $metadata['warnings']
            );
        } finally {
            $db->rawQuery('DROP TABLE "advice_catalog_case" CASCADE CONSTRAINTS');
        }
    }
}
