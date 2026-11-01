<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\helpers\Inflector;
use tommyknocker\pdodb\orm\Model;

final class InflectorTests extends TestCase
{
    public function testCamelToSnakeAndTableize(): void
    {
        $this->assertSame('user', Inflector::camelToSnake('User'));
        $this->assertSame('order_item', Inflector::camelToSnake('OrderItem'));
        $this->assertSame('users', Inflector::tableize('User'));
        $this->assertSame('user_profiles', Inflector::tableize('UserProfile'));
        $this->assertSame('order_items', Inflector::tableize('OrderItem'));
        $this->assertSame('people', Inflector::tableize('Person'));
        $this->assertSame('categories', Inflector::tableize('Category'));
        $this->assertSame('statuses', Inflector::tableize('Status'));
        $this->assertSame('businesses', Inflector::tableize('Business'));
    }

    public function testClassifyFromTableNames(): void
    {
        foreach ([
            'categories' => 'Category',
            'statuses' => 'Status',
            'people' => 'Person',
            'addresses' => 'Address',
            'news' => 'News',
            'businesses' => 'Business',
            'users' => 'User',
            'order_items' => 'OrderItem',
        ] as $table => $expected) {
            $this->assertSame($expected, Inflector::classify($table), 'table ' . $table);
        }
    }

    public function testPluralizeAndSingularizeWords(): void
    {
        $this->assertSame('users', Inflector::pluralize('user'));
        $this->assertSame('categories', Inflector::pluralize('category'));
        $this->assertSame('statuses', Inflector::pluralize('status'));
        $this->assertSame('people', Inflector::pluralize('person'));
        $this->assertSame('news', Inflector::pluralize('news'));

        $this->assertSame('user', Inflector::singularize('users'));
        $this->assertSame('category', Inflector::singularize('categories'));
        $this->assertSame('status', Inflector::singularize('statuses'));
        $this->assertSame('person', Inflector::singularize('people'));
        $this->assertSame('business', Inflector::singularize('businesses'));
    }

    public function testForeignKeyStemUsesSingularTableSegment(): void
    {
        $this->assertSame('belongs_to_user', Inflector::foreignKeyStem(BelongsToTestUserModel::class));
    }

    public function testModelDefaultTableNameUsesInflector(): void
    {
        $this->assertSame('inflector_default_table_users', InflectorDefaultTableUser::tableName());
    }

    public function testClassBasename(): void
    {
        $this->assertSame('User', Inflector::classBasename('App\\Models\\User'));
    }
}

class InflectorDefaultTableUser extends Model
{
}
