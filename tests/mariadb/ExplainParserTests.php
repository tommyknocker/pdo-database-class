<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\mariadb\MariaDBDialect;
use tommyknocker\pdodb\query\analysis\parsers\MariaDBExplainParser;

final class ExplainParserTests extends TestCase
{
    public function testParsesBasicExplainRow(): void
    {
        $rows = [[
            'id' => 1,
            'select_type' => 'SIMPLE',
            'table' => 'users',
            'type' => 'ALL',
            'possible_keys' => null,
            'key' => null,
            'rows' => 100,
            'extra' => 'Using temporary',
        ]];

        $parser = new MariaDBExplainParser();
        $plan = $parser->parse($rows);
        $this->assertIsObject($plan);
        $this->assertObjectHasProperty('nodes', $plan);
        $this->assertIsArray($plan->nodes);
        $this->assertSame('users', $plan->nodes[0]['table']);
    }

    public function testMariaDbDialectUsesTheMariaDbParser(): void
    {
        $this->assertInstanceOf(MariaDBExplainParser::class, (new MariaDBDialect())->getExplainParser());
    }
}
