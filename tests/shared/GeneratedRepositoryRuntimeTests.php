<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\cli\RepositoryGenerator;
use tommyknocker\pdodb\PdoDb;

/**
 * The generated repository is executed, not just searched for strings: its types must accept what the library hands out.
 */
final class GeneratedRepositoryRuntimeTests extends TestCase
{
    private object $repository;

    protected function setUp(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE gen_items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('CREATE TABLE gen_uuid (id TEXT PRIMARY KEY, name TEXT)');
        foreach ([1 => 'one', 2 => 'two'] as $id => $name) {
            $db->find()->table('gen_items')->insert(['id' => $id, 'name' => $name]);
        }
        $db->find()->table('gen_uuid')->insert(['id' => 'a-1', 'name' => 'uuid row']);

        $this->repository = $this->load($db, 'gen_items', 'GenItemsRepository');
    }

    private function load(PdoDb $db, string $table, string $class): object
    {
        $namespace = 'GeneratedRepositoryRuntime' . str_replace('_', '', $table);
        $fqcn = $namespace . '\\' . $class;
        if (!class_exists($fqcn, false)) {
            $method = (new ReflectionClass(RepositoryGenerator::class))->getMethod('generateRepositoryCode');
            $code = $method->invoke(null, $class, 'Item', $table, ['id'], $namespace, 'app\\models');
            $file = tempnam(sys_get_temp_dir(), 'repo');
            file_put_contents($file, $code);

            try {
                require $file;
            } finally {
                unlink($file);
            }
        }

        return new $fqcn($db);
    }

    public function testAMissingRowIsNullNotFalse(): void
    {
        $this->assertNull($this->repository->findById(999));
        $this->assertNull($this->repository->findOneBy('name', 'nobody'));
        $this->assertFalse($this->repository->exists(999));
        $this->assertTrue($this->repository->exists(1));
        $this->assertSame('one', $this->repository->findById(1)['name']);
    }

    public function testCountIsTheNumberOfRows(): void
    {
        $this->assertSame(2, $this->repository->count());
        $this->assertSame(1, $this->repository->count(['name' => 'two']));
        $this->assertSame(0, $this->repository->count(['name' => 'nobody']));
    }

    public function testTheKeyMayBeAStringAsCreateCanReturnOne(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE gen_uuid (id TEXT PRIMARY KEY, name TEXT)');
        $repository = $this->load($db, 'gen_uuid', 'GenUuidRepository');

        $this->assertSame('a-1', $repository->create(['id' => 'a-1', 'name' => 'x']), 'the key of the row, whatever its type');
        $this->assertSame('x', $repository->findById('a-1')['name']);
        $this->assertSame(1, $repository->update('a-1', ['name' => 'y']));
        $this->assertTrue($repository->exists('a-1'));
        $this->assertSame(1, $repository->delete('a-1'));
        $this->assertFalse($repository->exists('a-1'));
    }

    public function testAKeyBeyondIntIsAccepted(): void
    {
        $this->assertNull($this->repository->findById('9223372036854775808'));
        $this->assertFalse($this->repository->exists('9223372036854775808'));
    }
}
