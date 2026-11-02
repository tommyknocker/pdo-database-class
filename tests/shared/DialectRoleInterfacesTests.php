<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\dialects\oracle\OracleDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;
use tommyknocker\pdodb\dialects\traits\SqlFunctionsDefaults;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

/**
 * DialectInterface is assembled from role interfaces. Every method belongs to exactly one role, so a role stays the
 * single place to look for (and to document) its part of the contract.
 */
final class DialectRoleInterfacesTests extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function roles(): array
    {
        $roles = (new ReflectionClass(DialectInterface::class))->getInterfaceNames();
        $roles = array_values(array_filter($roles, static fn (string $name): bool => str_contains($name, '\\dialects\\roles\\')));
        sort($roles);

        return $roles;
    }

    public function testTheAggregateDeclaresNoMethodsOfItsOwn(): void
    {
        $own = array_filter(
            (new ReflectionClass(DialectInterface::class))->getMethods(),
            static fn ($method): bool => $method->getDeclaringClass()->getName() === DialectInterface::class
        );

        $this->assertSame([], array_values($own));
    }

    public function testEveryMethodIsDeclaredByExactlyOneRole(): void
    {
        $seen = [];
        foreach ($this->roles() as $role) {
            foreach ((new ReflectionClass($role))->getMethods() as $method) {
                $seen[$method->getName()][] = $role;
            }
        }

        $this->assertNotEmpty($seen);
        $shared = array_filter($seen, static fn (array $roles): bool => count($roles) > 1);
        $this->assertSame([], $shared, 'A method declared in two roles: ' . implode(', ', array_keys($shared)));
        $this->assertCount(count((new ReflectionClass(DialectInterface::class))->getMethods()), $seen);
    }

    public function testEveryDialectImplementsEveryRole(): void
    {
        foreach ([SqliteDialect::class, MySQLDialect::class, PostgreSQLDialect::class, MSSQLDialect::class, OracleDialect::class] as $dialect) {
            foreach ($this->roles() as $role) {
                $this->assertTrue(is_subclass_of($dialect, $role), "{$dialect} does not implement {$role}");
            }
        }
    }

    public function testRolesAreCohesive(): void
    {
        foreach ($this->roles() as $role) {
            $count = count((new ReflectionClass($role))->getMethods());
            $this->assertGreaterThan(0, $count, $role);
            $this->assertLessThanOrEqual(60, $count, "{$role} has grown into a second DialectInterface; split it");
        }
    }

    public function testSqlFunctionsWithoutAPortableFormFailInsteadOfEmittingAnotherDatabasesSyntax(): void
    {
        $defaults = new class () {
            use SqlFunctionsDefaults;

            public function getDriverName(): string
            {
                return 'custom';
            }
        };

        foreach ([
            fn () => $defaults->formatTruncate('price', 2),
            fn () => $defaults->formatRegexpLike('name', 'a.c'),
        ] as $call) {
            try {
                $call();
                $this->fail('A dialect that does not define the function must not get MySQL syntax.');
            } catch (UnsupportedOperationException $e) {
                $this->assertStringContainsString('does not define', $e->getMessage());
            }
        }
    }
}
