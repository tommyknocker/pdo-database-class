<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * MySQL double quotes are strings unless ANSI_QUOTES is enabled.
 */
final class ProfileRedactionTests extends BaseMySQLTestCase
{
    public function testDoubleQuotedTextFollowsTheSessionSqlMode(): void
    {
        $db = self::$db;
        $mode = $db->rawQueryValue('SELECT @@SESSION.sql_mode');
        $this->assertIsString($mode);
        $db->enableProfiling(0.0);
        $handler = new TestHandler();
        $logger = new Logger('profile');
        $logger->pushHandler($handler);
        $db->getProfiler()?->setLogger($logger);

        try {
            $db->rawQuery('SELECT "PRIVATE_VALUE_428" AS sample');
            $summary = json_encode($db->profileReport()->summary(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('PRIVATE_VALUE_428', $summary);
            $this->assertNotEmpty($handler->getRecords());
            $this->assertStringNotContainsString('PRIVATE_VALUE_428', (string)$handler->getRecords()[0]['context']['sql']);

            $db->resetProfiler();
            $db->rawQuery('SELECT `name` FROM users WHERE `name` = "PRIVATE_VALUE_428"');
            $identifiers = json_encode($db->profileReport()->summary(), JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('`name`', $identifiers);
            $this->assertStringNotContainsString('PRIVATE_VALUE_428', $identifiers);

            $db->rawQuery("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ANSI_QUOTES')");
            $db->resetProfiler();
            $db->rawQuery('SELECT "id" FROM users WHERE 0 = 1');
            $quotedQueries = $db->profileReport()->summary()['queries'];
            $this->assertIsArray($quotedQueries);
            $this->assertStringContainsString('"id"', (string)$quotedQueries[0]['sql']);
        } finally {
            $quotedMode = $db->connection->getPdo()->quote($mode);
            if ($quotedMode === false) {
                $quotedMode = "'" . str_replace("'", "''", $mode) . "'";
            }
            $db->rawQuery('SET SESSION sql_mode = ' . $quotedMode);
            $db->disableProfiling();
        }
    }
}
