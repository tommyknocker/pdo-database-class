<?php
/**
 * Example: ActiveRecord Relationships.
 *
 * Demonstrates hasOne, hasMany, and belongsTo relationships with lazy and eager loading.
 *
 * Usage:
 *   php examples/27-active-record-relationships/01-relationships.php
 *   PDODB_DRIVER=mysql php examples/27-active-record-relationships/01-relationships.php
 *   PDODB_DRIVER=pgsql php examples/27-active-record-relationships/01-relationships.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\orm\Model;

// Define models with relationships
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

class Profile extends Model
{
    public static function tableName(): string
    {
        return 'profiles';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'user' => ['belongsTo', 'modelClass' => User::class],
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

class Comment extends Model
{
    public static function tableName(): string
    {
        return 'comments';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'post' => ['belongsTo', 'modelClass' => Post::class],
        ];
    }
}

// Get database from environment or default to SQLite
$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== ActiveRecord Relationships Examples ===\n\n";
echo "Driver: $driver\n\n";

// Create tables
$db->schema()->dropTableIfExists('comments');
$db->schema()->dropTableIfExists('posts');
$db->schema()->dropTableIfExists('profiles');
$db->schema()->dropTableIfExists('users');

$db->schema()->createTable('users', [
    'id' => $db->schema()->primaryKey(),
    'name' => $db->schema()->string(100)->notNull(),
    'email' => $db->schema()->string(255)->notNull(),
]);

$db->schema()->createTable('profiles', [
    'id' => $db->schema()->primaryKey(),
    'user_id' => $db->schema()->integer()->notNull(),
    'bio' => $db->schema()->text(),
    'website' => $db->schema()->string(255),
]);

$db->schema()->createTable('posts', [
    'id' => $db->schema()->primaryKey(),
    'user_id' => $db->schema()->integer()->notNull(),
    'title' => $db->schema()->string(255)->notNull(),
    'content' => $db->schema()->text(),
]);

$db->schema()->createTable('comments', [
    'id' => $db->schema()->primaryKey(),
    'post_id' => $db->schema()->integer()->notNull(),
    'author' => $db->schema()->string(100)->notNull(),
    'content' => $db->schema()->text()->notNull(),
]);

// Set database for models
User::setDb($db);
Profile::setDb($db);
Post::setDb($db);
Comment::setDb($db);

echo "✓ Tables created\n\n";

// Example 1: Lazy Loading - HasOne
echo "1. Lazy Loading - HasOne\n";
echo "--------------------------\n";

$user1 = new User();
$user1->name = 'Alice';
$user1->email = 'alice@example.com';
$user1->save();

$profile1 = new Profile();
$profile1->user_id = $user1->id;
$profile1->bio = 'Software Developer';
$profile1->website = 'https://alice.example.com';
$profile1->save();

// Access relationship (lazy loading)
$loadedProfile = $user1->profile;
echo "User: {$user1->name}\n";
echo "Profile Bio: {$loadedProfile->bio}\n";
echo "Profile Website: {$loadedProfile->website}\n\n";

// Example 2: Lazy Loading - HasMany
echo "2. Lazy Loading - HasMany\n";
echo "---------------------------\n";

$post1 = new Post();
$post1->user_id = $user1->id;
$post1->title = 'First Post';
$post1->content = 'This is my first post';
$post1->save();

$post2 = new Post();
$post2->user_id = $user1->id;
$post2->title = 'Second Post';
$post2->content = 'This is my second post';
$post2->save();

// Access relationship (lazy loading)
$userPosts = $user1->posts;
echo "User: {$user1->name}\n";
echo "Posts count: " . count($userPosts) . "\n";
foreach ($userPosts as $post) {
    echo "  - {$post->title}\n";
}
echo "\n";

// Example 3: Lazy Loading - BelongsTo
echo "3. Lazy Loading - BelongsTo\n";
echo "-----------------------------\n";

// Access user from post (belongsTo)
$postUser = $post1->user;
echo "Post: {$post1->title}\n";
echo "Author: {$postUser->name} ({$postUser->email})\n\n";

// Example 4: Eager Loading - Single Relationship
echo "4. Eager Loading - Single Relationship\n";
echo "----------------------------------------\n";

$user2 = new User();
$user2->name = 'Bob';
$user2->email = 'bob@example.com';
$user2->save();

$profile2 = new Profile();
$profile2->user_id = $user2->id;
$profile2->bio = 'Designer';
$profile2->save();

// Eager load with profile
$users = User::find()->with('profile')->all();
echo "Users with profiles loaded:\n";
foreach ($users as $user) {
    echo "  {$user->name}: ";
    if ($user->profile !== null) {
        echo "{$user->profile->bio}\n";
    } else {
        echo "No profile\n";
    }
}
echo "\n";

// Example 5: Eager Loading - Multiple Relationships
echo "5. Eager Loading - Multiple Relationships\n";
echo "------------------------------------------\n";

$user3 = new User();
$user3->name = 'Charlie';
$user3->email = 'charlie@example.com';
$user3->save();

$post3 = new Post();
$post3->user_id = $user3->id;
$post3->title = 'Charlie\'s Post';
$post3->content = 'Content';
$post3->save();

// Eager load multiple relationships
$allUsers = User::find()->with(['profile', 'posts'])->all();
echo "Users with profiles and posts:\n";
foreach ($allUsers as $user) {
    echo "  {$user->name}:\n";
    if ($user->profile !== null) {
        echo "    Profile: {$user->profile->bio}\n";
    }
    echo "    Posts: " . count($user->posts) . "\n";
}
echo "\n";

// Example 6: Nested Eager Loading
echo "6. Nested Eager Loading\n";
echo "------------------------\n";

$comment1 = new Comment();
$comment1->post_id = $post3->id;
$comment1->author = 'Commenter 1';
$comment1->content = 'Great post!';
$comment1->save();

$comment2 = new Comment();
$comment2->post_id = $post3->id;
$comment2->author = 'Commenter 2';
$comment2->content = 'I agree!';
$comment2->save();

// Nested eager loading: posts with comments
$usersWithPostsAndComments = User::find()
    ->with(['posts' => ['comments']])
    ->all();

echo "Users with posts and comments:\n";
foreach ($usersWithPostsAndComments as $user) {
    echo "  {$user->name}:\n";
    foreach ($user->posts as $post) {
        echo "    Post: {$post->title}\n";
        echo "      Comments: " . count($post->comments) . "\n";
        foreach ($post->comments as $comment) {
            echo "        - {$comment->author}: {$comment->content}\n";
        }
    }
}
echo "\n";

// Example 7: BelongsTo with Eager Loading
echo "7. BelongsTo with Eager Loading\n";
echo "---------------------------------\n";

$postsWithUsers = Post::find()->with('user')->all();
echo "Posts with authors:\n";
foreach ($postsWithUsers as $post) {
    echo "  {$post->title} by {$post->user->name}\n";
}
echo "\n";

// Example 8: Yii2-like Syntax - Calling Relationships as Methods
echo "8. Yii2-like Syntax - Calling Relationships as Methods\n";
echo "--------------------------------------------------------\n";

// Add published column for demonstration
$driver = getCurrentDriver($db);
if ($driver === 'sqlsrv') {
    // MSSQL doesn't support COLUMN keyword in ALTER TABLE ADD
    $db->rawQuery('ALTER TABLE posts ADD published INT DEFAULT 1');
} else {
    $db->rawQuery('ALTER TABLE posts ADD COLUMN published INTEGER DEFAULT 1');
}
$db->find()->table('posts')->where('title', 'Charlie\'s Post')->update(['published' => 1]);

// Call relationship as method to get ActiveQuery
$publishedPosts = $user3->posts()->where('published', 1)->all();
echo "Published posts for {$user3->name}:\n";
foreach ($publishedPosts as $post) {
    echo "  - {$post->title}\n";
}

// Add more query modifications
$recentPosts = $user3->posts()
    ->orderBy('id', 'DESC')
    ->limit(2)
    ->all();
echo "\nRecent posts (limit 2):\n";
foreach ($recentPosts as $post) {
    echo "  - {$post->title}\n";
}

// Count with condition
$postCount = $user3->posts()->where('published', 1)->select(['count' => \tommyknocker\pdodb\helpers\Db::count()])->getValue('count');
echo "\nPublished posts count: {$postCount}\n";

// Cleanup
if ($driver === 'sqlsrv') {
    // MSSQL: Drop default constraint first, then drop column
    $constraintQuery = "SELECT name FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID('posts') AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('posts'), 'published', 'ColumnId')";
    $constraints = $db->rawQuery($constraintQuery);
    foreach ($constraints as $constraint) {
        $db->rawQuery("ALTER TABLE posts DROP CONSTRAINT [{$constraint['name']}]");
    }
    $db->rawQuery('ALTER TABLE posts DROP COLUMN published');
} else {
    $db->rawQuery('ALTER TABLE posts DROP COLUMN published');
}
echo "\n";

    // Example 9: Many-to-Many Relationships
    echo "9. Many-to-Many Relationships\n";
    echo "-------------------------------\n";

    // Drop tables if they exist
    $db->schema()->dropTableIfExists('user_project');
    $db->schema()->dropTableIfExists('projects');

    // Create junction table and project table
    // For MSSQL, use NVARCHAR(MAX) instead of TEXT to avoid DISTINCT issues
    $db->schema()->createTable('projects', [
    'id' => $db->schema()->primaryKey(),
    'name' => $db->schema()->string(100)->notNull(),
    'description' => ($driver === 'sqlsrv') ? $db->schema()->string(null) : $db->schema()->text(),
]);

// Create junction table with composite primary key (dialect-specific)
$driver = getCurrentDriver($db);
if ($driver === 'sqlite') {
    $db->rawQuery('CREATE TABLE user_project (
        user_id INTEGER NOT NULL,
        project_id INTEGER NOT NULL,
        PRIMARY KEY (user_id, project_id)
    )');
} else {
    $db->schema()->createTable('user_project', [
        'user_id' => $db->schema()->integer()->notNull(),
        'project_id' => $db->schema()->integer()->notNull(),
    ]);
    $db->rawQuery('ALTER TABLE user_project ADD PRIMARY KEY (user_id, project_id)');
}

// Define Project model with relation to User
class Project extends Model
{
    public static function tableName(): string
    {
        return 'projects';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'users' => [
                'hasManyThrough',
                'modelClass' => User::class,
                'viaTable' => 'user_project',
                'link' => ['id' => 'project_id'],
                'viaLink' => ['user_id' => 'id'],
            ],
        ];
    }
}

// Update User model to include projects relation
class UserWithProjects extends User
{
    public static function relations(): array
    {
        $parentRelations = parent::relations();
        return array_merge($parentRelations, [
            'projects' => [
                'hasManyThrough',
                'modelClass' => Project::class,
                'viaTable' => 'user_project',
                'link' => ['id' => 'user_id'],
                'viaLink' => ['project_id' => 'id'],
            ],
        ]);
    }
}

// Set database for Project model
Project::setDb($db);
UserWithProjects::setDb($db);

// Create projects
$project1 = new Project();
$project1->name = 'Project Alpha';
$project1->description = 'Alpha project description';
$project1->save();

$project2 = new Project();
$project2->name = 'Project Beta';
$project2->description = 'Beta project description';
$project2->save();

// Link user to projects through junction table
$user3Id = $user3->id;
$db->find()->table('user_project')->insert(['user_id' => $user3Id, 'project_id' => $project1->id]);
$db->find()->table('user_project')->insert(['user_id' => $user3Id, 'project_id' => $project2->id]);

// Access many-to-many relationship (lazy loading)
$userWithProjects = UserWithProjects::findOne($user3Id);
$projects = $userWithProjects->projects;
echo "Projects for {$userWithProjects->name}:\n";
foreach ($projects as $project) {
    echo "  - {$project->name}: {$project->description}\n";
}

// Yii2-like syntax for many-to-many (create fresh query)
$projectQuery = $userWithProjects->projects();
$betaProjects = $projectQuery->where('name', 'Project Beta')->all();
echo "\nBeta projects:\n";
foreach ($betaProjects as $project) {
    echo "  - {$project->name}\n";
}

// Eager loading for many-to-many
$usersWithProjects = UserWithProjects::find()->with('projects')->all();
echo "\nUsers with projects (eager loaded):\n";
foreach ($usersWithProjects as $u) {
    echo "  {$u->name}: " . count($u->projects) . " project(s)\n";
}

// Cleanup
$db->schema()->dropTableIfExists('user_project');
$db->schema()->dropTableIfExists('projects');
echo "\n";

// Cleanup
echo "Cleaning up...\n";
$db->schema()->dropTableIfExists('comments');
$db->schema()->dropTableIfExists('posts');
$db->schema()->dropTableIfExists('profiles');
$db->schema()->dropTableIfExists('users');
echo "✓ Done\n";

