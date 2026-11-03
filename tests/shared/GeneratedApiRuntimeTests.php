<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\cli\ApiGenerator;
use tommyknocker\pdodb\cli\TestGenerator;
use tommyknocker\pdodb\PdoDb;

/**
 * Generated code is run, not just read: it must only call methods the library has.
 */
final class GeneratedApiRuntimeTests extends TestCase
{
    private function controller(PdoDb $db, string $table, string $class): object
    {
        $namespace = 'GeneratedApiRuntime' . str_replace('_', '', $table);
        $fqcn = $namespace . '\\' . $class;
        if (!class_exists($fqcn, false)) {
            $method = (new ReflectionClass(ApiGenerator::class))->getMethod('generateControllerCode');
            $code = $method->invoke(null, $class, $table, null, [], ['id'], $namespace, 'json');
            $file = tempnam(sys_get_temp_dir(), 'api');
            file_put_contents($file, $code);

            try {
                require $file;
            } finally {
                unlink($file);
            }
        }

        return new $fqcn($db);
    }

    public function testTheControllerStoresShowsUpdatesAndDeletesWithAnIntegerKey(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE api_items (id INTEGER PRIMARY KEY, name TEXT)');
        $controller = $this->controller($db, 'api_items', 'ItemsController');

        $stored = $controller->store(['name' => 'one']);
        $this->assertSame('one', $stored['name']);
        $this->assertSame([['id' => 1, 'name' => 'one']], $controller->index());
        $this->assertSame('one', $controller->show(1)['name']);
        $this->assertNull($controller->show(99));

        $this->assertSame('changed', $controller->update(1, ['name' => 'changed'])['name']);
        $this->assertTrue($controller->destroy(1));
        $this->assertFalse($controller->destroy(1));
        $this->assertSame([], $controller->index());
    }

    public function testTheControllerStoresARowWhoseKeyIsAString(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE api_uuid (id TEXT PRIMARY KEY, name TEXT)');
        $controller = $this->controller($db, 'api_uuid', 'UuidController');

        $stored = $controller->store(['id' => 'a-1', 'name' => 'x']);

        $this->assertSame(['id' => 'a-1', 'name' => 'x'], $stored, 'insert() has no integer id: the key sent is used');
        $this->assertSame('x', $controller->show('a-1')['name']);
    }

    public function testTheGeneratedIntegrationTestIsValidPhpAndUsesTheRealApi(): void
    {
        $method = (new ReflectionClass(TestGenerator::class))->getMethod('generateTestCode');
        $code = $method->invoke(null, 'ItemsTest', 'Items', 'table', 'items', [], ['id'], 'tests', 'integration');

        token_get_all($code, TOKEN_PARSE);
        $this->assertStringNotContainsString('->db->insert(', $code);
        $this->assertStringNotContainsString('->execute()', $code);
        $this->assertStringNotContainsString('->one()', $code);
        $this->assertStringContainsString("->table('items')->insert(", $code);
    }
}
