# Code Organization

Structure your application to work efficiently with PDOdb.

## Repository Pattern

### User Repository

```php
class UserRepository {
    protected PdoDb $db;
    
    public function __construct(PdoDb $db) {
        $this->db = $db;
    }
    
    public function findById(int $id): ?array {
        return $this->db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
    }
    
    public function findAll(): array {
        return $this->db->find()
            ->from('users')
            ->where('active', 1)
            ->orderBy('name')
            ->get();
    }
    
    public function create(array $data): int {
        return $this->db->find()
            ->table('users')
            ->insert($data);
    }
    
    public function update(int $id, array $data): int {
        return $this->db->find()
            ->table('users')
            ->where('id', $id)
            ->update($data);
    }
}
```

## Service Layer

### User Service

```php
class UserService {
    protected PdoDb $db;
    
    public function registerUser(array $data): int {
        $this->db->startTransaction();
        
        try {
            // Create user
            $userId = $this->db->find()
                ->table('users')
                ->insert($data);
            
            // Create profile
            $this->db->find()
                ->table('profiles')
                ->insert(['user_id' => $userId]);
            
            $this->db->commit();
            return $userId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
```

## Dependency Injection

### Inject PdoDb Instance

```php
class Application {
    protected PdoDb $db;
    
    public function __construct(PdoDb $db) {
        $this->db = $db;
    }
    
    protected function getUserRepository(): UserRepository {
        return new UserRepository($this->db);
    }
}
```

## Configuration Class

### Environment Configuration

```php
class DatabaseConfig {
    public static function getConfig(): array {
        return [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'dbname' => getenv('DB_NAME'),
            'port' => getenv('DB_PORT') ?: 3306
        ];
    }
}
```

## Multi-Database Architecture

### Separate Read/Write

```php
class DatabaseManager {
    protected PdoDb $read;
    protected PdoDb $write;
    
    public function __construct() {
        $this->read = new PdoDb('mysql', DatabaseConfig::getReadConfig());
        $this->write = new PdoDb('mysql', DatabaseConfig::getWriteConfig());
    }
    
    public function read(): PdoDb {
        return $this->read;
    }
    
    public function write(): PdoDb {
        return $this->write;
    }
}
```

## Next Steps

- [Security](security.md) - Security best practices
- [Performance](performance.md) - Query optimization
- [Common Pitfalls](common-pitfalls.md) - Mistakes to avoid

