# Real-World Examples

Complete application examples using PDOdb.

## Blog System

### Create Posts Table

```php
$db->rawQuery('CREATE TABLE posts (
    id INT AUTO_INCREMENT PRIMARY KEY,                    -- SERIAL in PostgreSQL, INTEGER AUTOINCREMENT in SQLite
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,                         -- TEXT in SQLite
    slug VARCHAR(255) UNIQUE NOT NULL,                   -- TEXT in SQLite
    content TEXT,
    published TINYINT DEFAULT 0,                          -- SMALLINT in PostgreSQL, INTEGER in SQLite
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,       -- DATETIME in SQLite
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP  -- DATETIME in SQLite (triggers needed)
)');
```

### Create Post with Comments

```php
use tommyknocker\pdodb\helpers\Db;

function createPost(PdoDb $db, string $title, string $content, int $userId): int {
    $slug = strtolower(str_replace(' ', '-', $title));
    
    $db->startTransaction();
    
    try {
        $postId = $db->find()->table('posts')->insert([
            'user_id' => $userId,
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'created_at' => Db::now()
        ]);
        
        $db->commit();
        return $postId;
    } catch (\Exception $e) {
        $db->rollback();
        throw $e;
    }
}
```

### Get Published Posts

```php
function getPublishedPosts(PdoDb $db, int $limit = 10): array {
    return $db->find()
        ->from('posts')
        ->where('published', 1)
        ->orderBy('created_at', 'DESC')
        ->limit($limit)
        ->get();
}
```

## User Authentication

### Login System

```php
function loginUser(PdoDb $db, string $email, string $password): ?array {
    $user = $db->find()
        ->from('users')
        ->where('email', $email)
        ->getOne();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Update last login
        $db->find()
            ->table('users')
            ->where('id', $user['id'])
            ->update(['last_login' => Db::now()]);
        
        return $user;
    }
    
    return null;
}
```

## E-commerce Orders

### Process Order

```php
function processOrder(PdoDb $db, int $userId, array $items): int {
    $db->startTransaction();
    
    try {
        // Create order
        $orderId = $db->find()->table('orders')->insert([
            'user_id' => $userId,
            'status' => 'pending',
            'total' => 0,
            'created_at' => Db::now()
        ]);
        
        $total = 0;
        foreach ($items as $item) {
            $product = $db->find()
                ->from('products')
                ->where('id', $item['product_id'])
                ->getOne();
            
            $subtotal = $item['quantity'] * $product['price'];
            $total += $subtotal;
            
            // Create order item
            $db->find()->table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $product['price']
            ]);
            
            // Update inventory
            $db->find()
                ->table('products')
                ->where('id', $item['product_id'])
                ->update(['stock' => Db::dec($item['quantity'])]);
        }
        
        // Update order total
        $db->find()
            ->table('orders')
            ->where('id', $orderId)
            ->update([
                'total' => $total,
                'status' => 'completed'
            ]);
        
        $db->commit();
        return $orderId;
    } catch (\Exception $e) {
        $db->rollback();
        throw $e;
    }
}
```

## Analytics Dashboard

### Get Statistics

```php
function getDashboardStats(PdoDb $db, string $date): array {
    return [
        'total_users' => $db->find()
            ->from('users')
            ->select(Db::count())
            ->getValue(),
        
        'total_revenue' => $db->find()
            ->from('orders')
            ->select(Db::sum('total'))
            ->where('status', 'completed')
            ->andWhere('created_at', $date, '>=')
            ->getValue(),
        
        'total_orders' => $db->find()
            ->from('orders')
            ->select(Db::count())
            ->where('created_at', $date, '>=')
            ->getValue()
    ];
}
```

## Next Steps

- [Common Patterns](common-patterns.md) - Reusable patterns
- [Troubleshooting](troubleshooting.md) - Common issues
- [Best Practices](../08-best-practices/security.md) - Security
