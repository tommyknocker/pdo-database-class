<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\ai\AiQueryOptions;
use tommyknocker\pdodb\ai\QueryMetadataCollector;

/**
 * PostgreSQL folds each unquoted identifier. A quoted schema does not keep the table's case.
 */
final class CatalogIdentifierCaseTests extends BasePostgreSQLTestCase
{
    public function testQuotedSchemaDoesNotKeepTheUnquotedTableCase(): void
    {
        $db = self::$db;
        $schema = $db->rawQueryValue('SELECT current_schema()');
        $this->assertIsString($schema);
        $db->rawQuery('DROP TABLE IF EXISTS "PDODB_QUOTE_MIX"');
        $db->rawQuery('DROP TABLE IF EXISTS pdodb_quote_mix');
        $db->rawQuery('CREATE TABLE "PDODB_QUOTE_MIX" (upper_only int)');
        $db->rawQuery('CREATE TABLE pdodb_quote_mix (lower_only int)');

        try {
            $collector = new QueryMetadataCollector($db, (new AiQueryOptions())->context);
            $folded = $collector->collect('"' . $schema . '".PDODB_QUOTE_MIX');
            $quoted = $collector->collect('"PDODB_QUOTE_MIX"');
            $foldedNames = array_map(static fn (array $column): string => (string)$column['name'], $folded['columns']);
            $quotedNames = array_map(static fn (array $column): string => (string)$column['name'], $quoted['columns']);

            $this->assertContains('lower_only', $foldedNames);
            $this->assertNotContains('upper_only', $foldedNames);
            $this->assertContains('upper_only', $quotedNames);
            $this->assertNotContains('lower_only', $quotedNames);
        } finally {
            $db->rawQuery('DROP TABLE IF EXISTS "PDODB_QUOTE_MIX"');
            $db->rawQuery('DROP TABLE IF EXISTS pdodb_quote_mix');
        }
    }
}
