# ActiveRecord Relationships

Define and use relationships between ActiveRecord models with lazy and eager loading support.

## Overview

ActiveRecord relationships allow you to define associations between models:
- **hasOne** - One-to-one relationship (e.g., User has one Profile)
- **hasMany** - One-to-many relationship (e.g., User has many Posts)
- **belongsTo** - Many-to-one relationship (e.g., Post belongs to User)

Both lazy loading (on-demand) and eager loading (pre-loaded) are supported.

## Defining Relationships

Relationships are defined in the model's `relations()` method:

```php
use tommyknocker\pdodb\orm\Model;

class User extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'profile' => ['hasOne', 'modelClass' => Profile::class],
            'posts' => ['hasMany', 'modelClass' => Post::class],
        ];
    }
}

class Post extends Model
{
    public static function tableName(): string
    {
        return 'posts';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'user' => ['belongsTo', 'modelClass' => User::class],
            'comments' => ['hasMany', 'modelClass' => Comment::class],
        ];
    }
}
```

## Relationship Types

### hasOne

One-to-one relationship where the current model has one related model.

**Table Structure:**
```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100)
);

CREATE TABLE profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,  -- Foreign key
    bio TEXT
);
```

**Definition:**
```php
public static function relations(): array
{
    return [
        'profile' => ['hasOne', 'modelClass' => Profile::class],
        // Or with custom foreign key:
        'profile' => [
            'hasOne',
            'modelClass' => Profile::class,
            'foreignKey' => 'owner_id',  // Custom foreign key
            'localKey' => 'id',          // Custom local key
        ],
    ];
}
```

**Usage:**
```php
$user = User::findOne(1);
$profile = $user->profile;  // Lazy loading

if ($profile !== null) {
    echo $profile->bio;
}
```

### hasMany

One-to-many relationship where the current model has many related models.

**Table Structure:**
```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100)
);

CREATE TABLE posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,  -- Foreign key
    title VARCHAR(255)
);
```

**Definition:**
```php
public static function relations(): array
{
    return [
        'posts' => ['hasMany', 'modelClass' => Post::class],
    ];
}
```

**Usage:**
```php
$user = User::findOne(1);
$posts = $user->posts;  // Returns array of Post instances

foreach ($posts as $post) {
    echo $post->title;
}
```

### belongsTo

Many-to-one relationship where the current model belongs to a related model.

**Table Structure:**
```sql
CREATE TABLE posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,  -- Foreign key in posts table
    title VARCHAR(255)
);

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100)
);
```

**Definition:**
```php
public static function relations(): array
{
    return [
        'user' => ['belongsTo', 'modelClass' => User::class],
        // Or with custom keys:
        'author' => [
            'belongsTo',
            'modelClass' => User::class,
            'foreignKey' => 'author_id',  // Foreign key in posts table
            'ownerKey' => 'id',           // Primary key in users table
        ],
    ];
}
```

**Usage:**
```php
$post = Post::findOne(1);
$user = $post->user;  // Returns User instance

echo $post->title . " by " . $user->name;
```

## Auto-Detection

If foreign keys are not specified, they are auto-detected:

- **hasOne/hasMany**: Foreign key defaults to `{ownerTableName}_id` (e.g., `user_id`)
- **belongsTo**: Foreign key defaults to `{relatedTableName}_id` (e.g., `user_id`)
- **localKey/ownerKey**: Defaults to primary key (usually `id`)

## Lazy Loading

Relationships are loaded automatically when accessed for the first time:

```php
$user = User::findOne(1);

// First access triggers a query
$profile = $user->profile;  // SELECT * FROM profiles WHERE user_id = 1

// Subsequent accesses use cached result
$profileAgain = $user->profile;  // No query, returns cached
```

**Note:** Lazy loading can lead to N+1 query problems when accessing relationships in loops.

## Eager Loading

Eager loading pre-loads relationships to avoid N+1 queries:

```php
// Eager load single relationship
$users = User::find()
    ->with('profile')
    ->all();

// Access without additional queries
foreach ($users as $user) {
    echo $user->profile->bio;  // No query, data already loaded
}

// Eager load multiple relationships
$users = User::find()
    ->with(['profile', 'posts'])
    ->all();

// Nested eager loading
$users = User::find()
    ->with(['posts' => ['comments']])
    ->all();

// Access nested relationships
foreach ($users as $user) {
    foreach ($user->posts as $post) {
        foreach ($post->comments as $comment) {
            echo $comment->content;  // No additional queries
        }
    }
}
```

## Performance Comparison

### Lazy Loading (N+1 Problem)

```php
$users = User::find()->all();  // 1 query

foreach ($users as $user) {
    echo $user->profile->bio;  // N queries (one per user)
}
// Total: 1 + N queries
```

### Eager Loading (Optimized)

```php
$users = User::find()
    ->with('profile')
    ->all();  // 2 queries total (users + profiles)

foreach ($users as $user) {
    echo $user->profile->bio;  // No additional queries
}
// Total: 2 queries (regardless of N)
```

## Advanced Usage

### Custom Foreign Keys

```php
public static function relations(): array
{
    return [
        'profile' => [
            'hasOne',
            'modelClass' => Profile::class,
            'foreignKey' => 'owner_id',     // Custom foreign key
            'localKey' => 'user_id',        // Custom local key
        ],
    ];
}
```

### Direct Access via getRelation()

```php
$user = User::findOne(1);

// Access relationship directly
$profile = $user->getRelation('profile');
```

### Checking Relationship Data

```php
$user = User::findOne(1);

// Check if relationship is eager-loaded
$relationData = $user->getRelationData();
if (isset($relationData['profile'])) {
    // Profile was eager-loaded
}

// Clear eager-loaded data
$user->clearRelationData();
```

## Yii2-like Syntax: Calling Relationships as Methods

You can call relationships as methods to get an `ActiveQuery` instance that you can modify before executing. This is similar to Yii2's approach and provides more flexibility:

```php
$user = User::findOne(1);

// Call relationship as method - returns ActiveQuery
$query = $user->posts();
// This is equivalent to: Post::find()->where('user_id', $user->id)

// Can modify query before execution
$publishedPosts = $user->posts()
    ->where('published', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->all();

// Count with condition
$postCount = $user->posts()
    ->where('published', 1)
    ->select(['count' => Db::count()])
    ->getValue('count');

// Complex queries
$recentPosts = $user->posts()
    ->where('published', 1)
    ->andWhere('created_at', '2024-01-01', '>=')
    ->orderBy('created_at', 'DESC')
    ->limit(5)
    ->all();
```

### When to Use Method Syntax vs Property Access

**Use property access (`$user->posts`) when:**
- You want all related records without filtering
- Simple lazy loading is sufficient
- You're accessing a single relationship

**Use method syntax (`$user->posts()`) when:**
- You need to add conditions to the relationship query
- You want to order, limit, or modify the relationship query
- You're building complex queries on relationships
- You need aggregate functions (COUNT, SUM, etc.) on relationships

### Examples

```php
// hasOne as method
$user = User::findOne(1);
$activeProfile = $user->profile()->where('active', 1)->one();

// hasMany as method with conditions
$publishedPosts = $user->posts()->where('published', 1)->all();
$draftPosts = $user->posts()->where('published', 0)->all();

// belongsTo as method
$post = Post::findOne(1);
$author = $post->user()->where('active', 1)->one();

// Complex queries
$topPosts = $user->posts()
    ->where('published', 1)
    ->orderBy('views', 'DESC')
    ->limit(10)
    ->all();

// Aggregations
$totalViews = $user->posts()
    ->select(['total' => Db::sum('views')])
    ->getValue('total');
```

## Best Practices

1. **Use Eager Loading for Collections**: Always use `with()` when loading multiple models:
   ```php
   // Good
   $users = User::find()->with('posts')->all();
   
   // Bad (N+1 problem)
   $users = User::find()->all();
   foreach ($users as $user) {
       $posts = $user->posts;  // Query per user
   }
   ```

2. **Lazy Load for Single Models**: Lazy loading is fine for single model access:
   ```php
   $user = User::findOne(1);
   $profile = $user->profile;  // OK for single access
   ```

3. **Use Method Syntax for Filtered Relationships**: Use method syntax when you need to filter:
   ```php
   // Good - filtered relationship
   $publishedPosts = $user->posts()->where('published', 1)->all();
   
   // Bad - loads all then filters in PHP
   $allPosts = $user->posts;
   $publishedPosts = array_filter($allPosts, fn($p) => $p->published === 1);
   ```

4. **Nested Eager Loading**: Use nested eager loading for deep relationships:
   ```php
   $users = User::find()
       ->with(['posts' => ['comments' => ['author']]])
       ->all();
   ```

6. **Improve IDE Autocompletion for Relationships**: Use `@method` annotations on your model classes to help IDEs understand relationship methods:
   ```php
   use tommyknocker\pdodb\orm\ActiveQuery;
   use tommyknocker\pdodb\orm\Model;

   /**
    * User model.
    *
    * @method ActiveQuery<Post> posts()   Relationship query for user's posts.
    * @method ActiveQuery<Profile> profile() Relationship query for user's profile.
    */
   class User extends Model
   {
       public static function relations(): array
       {
           return [
               'profile' => ['hasOne', 'modelClass' => Profile::class],
               'posts' => ['hasMany', 'modelClass' => Post::class],
           ];
       }
   }
   ```

   These annotations are optional but highly recommended when you want your IDE to:
   - Autocomplete relationship method names (e.g. `posts()`, `profile()`).
   - Infer that `posts()` returns `ActiveQuery<Post>` and `profile()` returns `ActiveQuery<Profile>`.
   - Provide correct autocompletion for chained ActiveQuery methods after calling a relationship.

5. **Clear Unused Data**: Clear relation data when no longer needed (memory optimization):
   ```php
   $users = User::find()->with('posts')->all();
   // Process users...
   foreach ($users as $user) {
       $user->clearRelationData();  // Free memory
   }
   ```

## Many-to-Many Relationships

PDOdb supports many-to-many relationships through two approaches: `viaTable` (junction table) and `via` (through existing relationship).

### Using viaTable (Junction Table)

`viaTable` is used when you have a dedicated junction table for the many-to-many relationship:

```php
class User extends Model
{
    public static function relations(): array
    {
        return [
            'projects' => [
                'hasManyThrough',
                'modelClass' => Project::class,
                'viaTable' => 'user_project',  // Junction table
                'link' => ['id' => 'user_id'], // User.id -> user_project.user_id
                'viaLink' => ['project_id' => 'id'], // user_project.project_id -> Project.id
            ],
        ];
    }
}

// Usage
$user = User::findOne(1);
$projects = $user->projects;  // Lazy load

// Yii2-like syntax
$activeProjects = $user->projects()->where('status', 'active')->all();

// Eager loading
$users = User::find()->with('projects')->all();
```

### Using via (Through Existing Relationship)

`via` is used when you want to access a relationship through another existing relationship:

```php
class User extends Model
{
    public static function relations(): array
    {
        return [
            'posts' => ['hasMany', 'modelClass' => Post::class],
            'comments' => [
                'hasManyThrough',
                'modelClass' => Comment::class,
                'via' => 'posts',  // Use 'posts' relationship
                'viaLink' => ['post_id' => 'id'], // Comment.post_id -> Post.id
            ],
        ];
    }
}

// Usage
$user = User::findOne(1);
$comments = $user->comments;  // Gets all comments through user's posts

// Eager loading
$users = User::find()->with('comments')->all();
```

### Configuration Options

**viaTable:**
- `viaTable` (string): Name of the junction table
- `link` (array): Maps owner primary key to junction table column `[owner_pk => junction_owner_key]`
- `viaLink` (array): Maps junction table column to related primary key `[junction_related_key => related_pk]`

**via:**
- `via` (string): Name of the intermediate relationship
- `viaLink` (array): Maps foreign key in related model to primary key in intermediate model `[foreign_key => intermediate_pk]`

### Examples

See [ActiveRecord Relationships examples](../../examples/09-active-record/02-relationships.php) for comprehensive examples.
