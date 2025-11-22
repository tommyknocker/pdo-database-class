<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class ExampleCategoriesDataSeed extends Seed
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Electronics',
                'slug' => 'electronics',
                'description' => 'Electronic devices and gadgets',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Books',
                'slug' => 'books',
                'description' => 'Books and literature',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Clothing',
                'slug' => 'clothing',
                'description' => 'Apparel and fashion',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMulti('categories', $categories);
    }

    public function rollback(): void
    {
        $this->delete('categories', ['slug' => 'electronics']);
        $this->delete('categories', ['slug' => 'books']);
        $this->delete('categories', ['slug' => 'clothing']);
    }
}