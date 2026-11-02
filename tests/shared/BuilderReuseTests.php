<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

final class BuilderReuseTests extends BaseSharedTestCase
{
    public function testABuilderCanRunMoreThanOnce(): void
    {
        $this->assertSame(BuilderReuseSupport::expected(), BuilderReuseSupport::run(self::$db));
    }

    public function testRunningABuilderAgainDoesNotGrowItsParameters(): void
    {
        $query = self::$db->find()->from('test_coverage')->where('value', 1, '>')->andWhere('name', 'x%', 'LIKE');
        $store = (new \ReflectionProperty($query, 'parameterManager'))->getValue($query);
        $params = new \ReflectionProperty($store, 'params');

        $before = count($params->getValue($store));
        for ($i = 0; $i < 50; $i++) {
            $query->get();
            $query->getOne();
            $query->limit(2)->offset($i % 3)->get();
        }

        $this->assertSame($before, count($params->getValue($store)));
    }
}
