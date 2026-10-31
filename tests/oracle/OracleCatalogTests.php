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

    public function testQuotedNamesThatDifferOnlyByCaseStaySeparate(): void
    {
        $db = self::$db;
        foreach (['"advice_case_mix"', '"ADVICE_CASE_MIX"'] as $table) {
            try {
                $db->rawQuery('DROP TABLE ' . $table . ' CASCADE CONSTRAINTS');
            } catch (\Throwable) {
                // The tables are created below.
            }
        }
        $db->rawQuery('CREATE TABLE "advice_case_mix" (id NUMBER, lower_only VARCHAR2(20))');
        $db->rawQuery('CREATE TABLE "ADVICE_CASE_MIX" (id NUMBER, upper_only VARCHAR2(20))');

        try {
            $collector = new QueryMetadataCollector($db, (new AiQueryOptions())->context);
            $lower = $collector->collect('"advice_case_mix"');
            $upper = $collector->collect('"ADVICE_CASE_MIX"');
            $lowerNames = array_map(static fn (array $column): string => (string)$column['name'], $lower['columns']);
            $upperNames = array_map(static fn (array $column): string => (string)$column['name'], $upper['columns']);

            $this->assertContains('LOWER_ONLY', $lowerNames);
            $this->assertNotContains('UPPER_ONLY', $lowerNames);
            $this->assertContains('UPPER_ONLY', $upperNames);
            $this->assertNotContains('LOWER_ONLY', $upperNames);
        } finally {
            $db->rawQuery('DROP TABLE "advice_case_mix" CASCADE CONSTRAINTS');
            $db->rawQuery('DROP TABLE "ADVICE_CASE_MIX" CASCADE CONSTRAINTS');
        }
    }

    public function testQuotedSchemaDoesNotKeepTheUnquotedTableCase(): void
    {
        $db = self::$db;
        $schema = $db->rawQueryValue("SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') FROM DUAL");
        $this->assertIsString($schema);
        foreach (['"pdodb_quote_mix"', 'pdodb_quote_mix'] as $table) {
            try {
                $db->rawQuery('DROP TABLE ' . $table . ' CASCADE CONSTRAINTS');
            } catch (\Throwable) {
                // The tables are created below.
            }
        }
        $db->rawQuery('CREATE TABLE "pdodb_quote_mix" (id NUMBER, lower_only VARCHAR2(20))');
        $db->rawQuery('CREATE TABLE pdodb_quote_mix (id NUMBER, upper_only VARCHAR2(20))');

        try {
            $collector = new QueryMetadataCollector($db, (new AiQueryOptions())->context);
            $folded = $collector->collect('"' . $schema . '".pdodb_quote_mix');
            $quoted = $collector->collect('"' . $schema . '"."pdodb_quote_mix"');
            $foldedNames = array_map(static fn (array $column): string => (string)$column['name'], $folded['columns']);
            $quotedNames = array_map(static fn (array $column): string => (string)$column['name'], $quoted['columns']);

            $this->assertContains('UPPER_ONLY', $foldedNames);
            $this->assertNotContains('LOWER_ONLY', $foldedNames);
            $this->assertContains('LOWER_ONLY', $quotedNames);
            $this->assertNotContains('UPPER_ONLY', $quotedNames);
        } finally {
            $db->rawQuery('DROP TABLE "pdodb_quote_mix" CASCADE CONSTRAINTS');
            $db->rawQuery('DROP TABLE pdodb_quote_mix CASCADE CONSTRAINTS');
        }
    }
}
