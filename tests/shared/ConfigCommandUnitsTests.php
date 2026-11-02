<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\config\MySQLConfigStrategy;
use tommyknocker\pdodb\dialects\config\OracleConfigStrategy;
use tommyknocker\pdodb\dialects\config\PostgreSQLConfigStrategy;

/**
 * Only settings that are an amount of memory get a unit; counts and ratios are written as they are.
 */
final class ConfigCommandUnitsTests extends TestCase
{
    private const GIB = 1024 ** 3;

    /**
     * @return array<string, string> setting => generated statement
     */
    private function commands(object $strategy, int $memory, int $connections): array
    {
        $commands = [];
        foreach ($strategy->recommend($memory, 4, 'oltp', 'ssd', $connections, []) as $name => $recommendation) {
            $commands[$name] = $strategy->command($name, $recommendation['value']);
        }

        return $commands;
    }

    public function testPostgreSqlCountsAndRatiosAreNotBytes(): void
    {
        $commands = $this->commands(new PostgreSQLConfigStrategy(), 8 * self::GIB, 200);

        $this->assertSame("ALTER SYSTEM SET max_connections = '200';", $commands['max_connections']);
        $this->assertSame("ALTER SYSTEM SET random_page_cost = '1.5';", $commands['random_page_cost'], 'the fraction is kept');
        $this->assertSame("ALTER SYSTEM SET effective_io_concurrency = '4';", $commands['effective_io_concurrency']);
        $this->assertSame("ALTER SYSTEM SET shared_buffers = '2GB';", $commands['shared_buffers']);
        $this->assertMatchesRegularExpression("/^ALTER SYSTEM SET work_mem = '\\d+MB';$/", $commands['work_mem']);
    }

    public function testMySqlCountsAreNotScaledToKilo(): void
    {
        $strategy = new MySQLConfigStrategy();

        // 2048 connections or table-cache entries are not "2K" of anything
        $this->assertSame('SET GLOBAL max_connections = 2048;', $strategy->command('max_connections', '2048'));
        $this->assertSame('SET GLOBAL table_open_cache = 4000;', $strategy->command('table_open_cache', '4000'));
        $this->assertSame('SET GLOBAL innodb_buffer_pool_size = 6G;', $strategy->command('innodb_buffer_pool_size', (string)(6 * self::GIB)));
        $this->assertSame('SET GLOBAL tmp_table_size = 256M;', $strategy->command('tmp_table_size', (string)(256 * 1024 * 1024)));
    }

    public function testOracleCountsAreNotTurnedIntoMegabytes(): void
    {
        $strategy = new OracleConfigStrategy();

        $this->assertSame('ALTER SYSTEM SET processes = 300 SCOPE = SPFILE;', $strategy->command('processes', '300'));
        $this->assertSame('ALTER SYSTEM SET sga_target = 4G SCOPE = SPFILE;', $strategy->command('sga_target', (string)(4 * self::GIB)));
        $this->assertSame('ALTER SYSTEM SET db_cache_size = 512M SCOPE = SPFILE;', $strategy->command('db_cache_size', (string)(512 * 1024 * 1024)));
    }

    public function testAQuoteInAValueCannotLeaveItsLiteral(): void
    {
        $this->assertSame(
            "ALTER SYSTEM SET log_line_prefix = 'a''b';",
            (new PostgreSQLConfigStrategy())->command('log_line_prefix', "a'b")
        );
    }

    public function testAFractionalAmountIsNeverRoundedDown(): void
    {
        $bytes = (string)(int)(1.5 * self::GIB);

        $this->assertSame("ALTER SYSTEM SET shared_buffers = '1536MB';", (new PostgreSQLConfigStrategy())->command('shared_buffers', $bytes));
        $this->assertSame('SET GLOBAL innodb_buffer_pool_size = 1536M;', (new MySQLConfigStrategy())->command('innodb_buffer_pool_size', $bytes));
        $this->assertSame('ALTER SYSTEM SET sga_target = 1536M SCOPE = SPFILE;', (new OracleConfigStrategy())->command('sga_target', $bytes));
        $this->assertSame('SET GLOBAL innodb_buffer_pool_size = 4608M;', (new MySQLConfigStrategy())->command('innodb_buffer_pool_size', '4831838208'));
        $this->assertSame('ALTER SYSTEM SET sga_target = 3994M SCOPE = SPFILE;', (new OracleConfigStrategy())->command('sga_target', '4188012544'));
    }

    public function testAnAmountThatIsNotAWholeUnitIsRoundedToTheSmallestUnit(): void
    {
        $this->assertSame("ALTER SYSTEM SET work_mem = '5kB';", (new PostgreSQLConfigStrategy())->command('work_mem', '5000'));
        $this->assertSame('SET GLOBAL tmp_table_size = 5K;', (new MySQLConfigStrategy())->command('tmp_table_size', '5000'));
    }
}
