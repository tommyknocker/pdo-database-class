<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\mariadb\MariaDBDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\helpers\Db;

/**
 * MySQL 8.0.19 and newer read the inserted row through an alias; everything else keeps VALUES(column).
 */
final class MySqlRowAliasTests extends TestCase
{
    private function pdoReporting(string $version): PDO
    {
        return new class ('sqlite::memory:', $version) extends PDO {
            public function __construct(string $dsn, private readonly string $version)
            {
                parent::__construct($dsn);
            }

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : parent::getAttribute($attribute);
            }
        };
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function versions(): array
    {
        return [
            'first version with the alias' => ['8.0.19', true],
            'one before' => ['8.0.18', false],
            'current 8.0' => ['8.0.46', true],
            'suffix after the number' => ['8.4.2-log', true],
            'next major' => ['9.1.0', true],
            '5.7' => ['5.7.44', false],
            'old 8.0 build' => ['8.0.11', false],
            'MariaDB behind the mysql driver' => ['5.5.5-10.11.6-MariaDB', false],
            'MariaDB without the compatibility prefix' => ['11.4.2-MariaDB-log', false],
            'unknown' => ['', false],
            'not a version' => ['latest', false],
        ];
    }

    #[DataProvider('versions')]
    public function testTheAliasIsUsedFromMySql8019On(string $version, bool $alias): void
    {
        $this->assertSame($alias, MySQLDialect::versionHasRowAlias($version));

        $dialect = new MySQLDialect();
        $dialect->setPdo($this->pdoReporting($version));
        $clause = $dialect->buildUpsertClauseForValues(['age'], ['name', 'age'], 'id', 'users');

        $this->assertSame(
            $alias
                ? 'AS pdodb_new (pdodb_c0, pdodb_c1) ON DUPLICATE KEY UPDATE `age` = pdodb_new.pdodb_c1'
                : 'ON DUPLICATE KEY UPDATE `age` = VALUES(`age`)',
            $clause
        );
    }

    public function testWithoutAConnectionTheOldFormIsUsed(): void
    {
        $this->assertSame(
            'ON DUPLICATE KEY UPDATE `age` = VALUES(`age`)',
            (new MySQLDialect())->buildUpsertClauseForValues(['age'], ['age'])
        );
    }

    public function testMariaDbNeverGetsTheAlias(): void
    {
        $dialect = new MariaDBDialect();
        $dialect->setPdo($this->pdoReporting('8.0.46'));

        $this->assertSame(
            'ON DUPLICATE KEY UPDATE `age` = VALUES(`age`)',
            $dialect->buildUpsertClauseForValues(['age'], ['name', 'age'])
        );
    }

    public function testInsertSelectKeepsValuesBecauseTheAliasExistsOnlyForValues(): void
    {
        $dialect = new MySQLDialect();
        $dialect->setPdo($this->pdoReporting('8.0.46'));

        $this->assertSame('ON DUPLICATE KEY UPDATE `age` = VALUES(`age`)', $dialect->buildUpsertClause(['age'], 'id', 'users'));
    }

    public function testExpressionsKeepTheirUnqualifiedColumnsBecauseTheAliasHasColumnNamesOfItsOwn(): void
    {
        $dialect = new MySQLDialect();
        $dialect->setPdo($this->pdoReporting('8.0.46'));

        $clause = $dialect->buildUpsertClauseForValues(
            ['age' => Db::raw('age + 5'), 'visits' => Db::inc(2), 'note' => true],
            ['name', 'age', 'visits'],
            'id',
            'users'
        );

        // `age + 5` still names the table's column; the alias cannot be confused with it
        $this->assertSame(
            'AS pdodb_new (pdodb_c0, pdodb_c1, pdodb_c2) ON DUPLICATE KEY UPDATE `age` = age + 5, `visits` = `visits` + 2, `note` = VALUES(`note`)',
            $clause,
            'a column that is updated but not inserted has no alias column and keeps VALUES()'
        );
    }

    public function testTheIdempotentUpsertUsesTheAliasToo(): void
    {
        $dialect = new MySQLDialect();
        $dialect->setPdo($this->pdoReporting('8.0.46'));
        [$sql] = $dialect->buildIdempotentUpsertSql('users', ['email' => 'a@example.test', 'name' => 'A'], ['email'], ['name']);

        $this->assertStringContainsString('VALUES (:', $sql);
        $this->assertStringContainsString(' AS pdodb_new (pdodb_c0, pdodb_c1) ON DUPLICATE KEY UPDATE `name` = pdodb_new.pdodb_c1', $sql);

        $old = new MySQLDialect();
        $old->setPdo($this->pdoReporting('8.0.18'));
        [$oldSql] = $old->buildIdempotentUpsertSql('users', ['email' => 'a@example.test', 'name' => 'A'], ['email'], ['name']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $oldSql);
        $this->assertStringNotContainsString('pdodb_new', $oldSql);
    }
}
