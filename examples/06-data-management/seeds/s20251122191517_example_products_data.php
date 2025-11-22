<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class ExampleProductsDataSeed extends Seed
{
    public function run(): void
    {
        // Get category IDs
        $electronics = $this->find()->table('categories')->where('slug', 'electronics')->first();
        $books = $this->find()->table('categories')->where('slug', 'books')->first();
        $clothing = $this->find()->table('categories')->where('slug', 'clothing')->first();

        if (!$electronics || !$books || !$clothing) {
            throw new \Exception('Categories must be seeded first');
        }

        $products = [
            [
                'name' => 'Smartphone',
                'category_id' => $electronics['id'],
                'price' => 599.99,
                'description' => 'Latest smartphone with advanced features',
                'in_stock' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Laptop',
                'category_id' => $electronics['id'],
                'price' => 1299.99,
                'description' => 'High-performance laptop for work and gaming',
                'in_stock' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Programming Book',
                'category_id' => $books['id'],
                'price' => 49.99,
                'description' => 'Learn programming with this comprehensive guide',
                'in_stock' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'T-Shirt',
                'category_id' => $clothing['id'],
                'price' => 19.99,
                'description' => 'Comfortable cotton t-shirt',
                'in_stock' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMulti('products', $products);
    }

    public function rollback(): void
    {
        $this->delete('products', ['name' => 'Smartphone']);
        $this->delete('products', ['name' => 'Laptop']);
        $this->delete('products', ['name' => 'Programming Book']);
        $this->delete('products', ['name' => 'T-Shirt']);
    }
}