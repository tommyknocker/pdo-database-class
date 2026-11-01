<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * A query builder describes a query; running it must not use that description up. Every dialect runs the same
 * scenarios, each executing one builder more than once.
 */
final class BuilderReuseSupport
{
    /**
     * @return array<string, mixed>
     */
    public static function run(PdoDb $db): array
    {
        $schema = $db->schema();
        $schema->dropTableIfExists('reuse_items');
        $schema->createTable('reuse_items', [
            'id' => $schema->integer()->notNull(),
            'name' => $schema->string(50),
            'qty' => $schema->integer(),
            'status' => $schema->string(20),
        ]);
        foreach ([[1, 'apple', 5, 'active'], [2, 'banana', 15, 'active'], [3, 'cherry', 25, 'archived'], [4, 'apricot', 35, 'active']] as [$id, $name, $qty, $status]) {
            $db->find()->table('reuse_items')->insert(['id' => $id, 'name' => $name, 'qty' => $qty, 'status' => $status]);
        }

        $ids = static fn (array $rows): array => array_map(
            static fn (array $row): int => (int) array_change_key_case($row, CASE_LOWER)['id'],
            $rows
        );
        $r = [];

        try {
            $q = $db->find()->from('reuse_items')->where('status', 'active')->orderBy('id');
            $r['where'] = [$ids($q->get()), $ids($q->get()), $ids($q->get())];

            $q = $db->find()->from('reuse_items')->where('status', 'active')->orderBy('id');
            $r['existsThenGet'] = [$q->exists(), $ids($q->get()), $q->exists()];

            $q = $db->find()->from('reuse_items')->where('id', [1, 2, 3], 'IN')->where('qty', 10, '>')->orderBy('id');
            $r['whereIn'] = [$ids($q->get()), $ids($q->get())];

            $q = $db->find()->from('reuse_items')->where(Db::between('qty', 10, 40))->where(Db::like('name', 'a%'), null)->orderBy('id');
            $r['betweenLike'] = [$ids($q->get()), $ids($q->get())];

            $q = $db->find()->from('reuse_items')->where('qty', 10, '>')->orderBy('id');
            $r['getThenOneThenValue'] = [
                $ids($q->get()),
                (int) array_change_key_case((array) $q->getOne(), CASE_LOWER)['id'],
                $ids($q->get()),
            ];

            $q = $db->find()->from('reuse_items')->where('id', static function ($sub): void {
                $sub->from('reuse_items')->select(['id'])->where('qty', 20, '>');
            }, 'IN')->orderBy('id');
            $r['subquery'] = [$ids($q->get()), $ids($q->get())];

            $q = $db->find()->from('reuse_items')->select(['id'])->where('qty', 10, '<')
                ->union($db->find()->from('reuse_items')->select(['id'])->where('qty', 30, '>'))
                ->orderBy('id');
            $r['union'] = [$ids($q->get()), $ids($q->get())];

            $update = $db->find()->table('reuse_items')->where('id', 1);
            $r['update'] = [$update->update(['qty' => 6]), $update->update(['qty' => 7])];
            $r['updated'] = (int) $db->find()->from('reuse_items')->select(['qty'])->where('id', 1)->getValue();

            $insert = $db->find()->table('reuse_items');
            $insert->insert(['id' => 5, 'name' => 'date', 'qty' => 45, 'status' => 'active']);
            $insert->insert(['id' => 6, 'name' => 'elder', 'qty' => 55, 'status' => 'active']);
            $r['inserted'] = (int) $db->find()->from('reuse_items')->select(['COUNT(*) AS c'])->getValue();

            $q = $db->find()->from('reuse_items')->where('status', 'active')->orderBy('id');
            $r['getUpdateGet'] = [$ids($q->get()), $db->find()->table('reuse_items')->where('id', 2)->update(['status' => 'archived']), $ids($q->get())];
        } finally {
            $schema->dropTableIfExists('reuse_items');
        }

        return $r;
    }

    /**
     * @return array<string, mixed>
     */
    public static function expected(): array
    {
        return [
            'where' => [[1, 2, 4], [1, 2, 4], [1, 2, 4]],
            'existsThenGet' => [true, [1, 2, 4], true],
            'whereIn' => [[2, 3], [2, 3]],
            'betweenLike' => [[4], [4]],
            'getThenOneThenValue' => [[2, 3, 4], 2, [2, 3, 4]],
            'subquery' => [[3, 4], [3, 4]],
            'union' => [[1, 4], [1, 4]],
            'update' => [1, 1],
            'updated' => 7,
            'inserted' => 6,
            'getUpdateGet' => [[1, 2, 4, 5, 6], 1, [1, 4, 5, 6]],
        ];
    }
}
