<?php
/**
 * Real-World Example: User Authentication System
 * 
 * Demonstrates a complete user authentication with password hashing,
 * sessions, and role-based access control
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== User Authentication System Example (on $driver) ===\n\n";

// Create schema
echo "Setting up authentication database...\n";

recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'username' => 'TEXT UNIQUE NOT NULL',
    'email' => 'TEXT UNIQUE NOT NULL',
    'password_hash' => 'TEXT NOT NULL',
    'role' => 'TEXT DEFAULT \'user\'',
    'is_active' => 'INTEGER DEFAULT 1',
    'email_verified' => 'INTEGER DEFAULT 0',
    'failed_login_attempts' => 'INTEGER DEFAULT 0',
    'last_login_at' => 'DATETIME',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

recreateTable($db, 'sessions', [
    'id' => 'TEXT PRIMARY KEY',
    'user_id' => 'INTEGER NOT NULL',
    'ip_address' => 'TEXT',
    'user_agent' => 'TEXT',
    'expires_at' => 'DATETIME NOT NULL',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

recreateTable($db, 'permissions', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'role' => 'TEXT NOT NULL',
    'resource' => 'TEXT NOT NULL',
    'action' => 'TEXT NOT NULL',
    'UNIQUE(role, resource, action)' => ''
]);

echo "✓ Schema created (users, sessions, permissions)\n\n";

// Scenario 1: User registration
echo "1. Registering new users...\n";

function registerUser($db, $username, $email, $password, $role = 'user') {
    $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
    
    return $db->find()->table('users')->insert([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => $role
    ]);
}

$userId1 = registerUser($db, 'john_doe', 'john@example.com', 'SecurePass123!', 'admin');
$userId2 = registerUser($db, 'jane_smith', 'jane@example.com', 'AnotherPass456!', 'user');
$userId3 = registerUser($db, 'bob_wilson', 'bob@example.com', 'Password789!', 'user');

echo "✓ Registered 3 users (1 admin, 2 regular users)\n\n";

// Scenario 2: Setup permissions
echo "2. Setting up role-based permissions...\n";

$permissions = [
    // Admin permissions
    ['role' => 'admin', 'resource' => 'users', 'action' => 'create'],
    ['role' => 'admin', 'resource' => 'users', 'action' => 'read'],
    ['role' => 'admin', 'resource' => 'users', 'action' => 'update'],
    ['role' => 'admin', 'resource' => 'users', 'action' => 'delete'],
    ['role' => 'admin', 'resource' => 'posts', 'action' => 'delete'],
    
    // User permissions
    ['role' => 'user', 'resource' => 'users', 'action' => 'read'],
    ['role' => 'user', 'resource' => 'posts', 'action' => 'create'],
    ['role' => 'user', 'resource' => 'posts', 'action' => 'update'],
];

$db->find()->table('permissions')->insertMulti($permissions);

echo "✓ Created " . count($permissions) . " permissions\n\n";

// Scenario 3: User login
echo "3. User login simulation...\n";

function loginUser($db, $username, $password) {
    // Get user by username
    $user = $db->find()
        ->from('users')
        ->where('username', $username)
        ->andWhere('is_active', 1)
        ->getOne();
    
    if (!$user) {
        return ['success' => false, 'message' => 'Invalid credentials'];
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        // Increment failed attempts
        $db->find()
            ->table('users')
            ->where('id', $user['id'])
            ->update(['failed_login_attempts' => Db::inc()]);
        
        return ['success' => false, 'message' => 'Invalid credentials'];
    }
    
    // Reset failed attempts and update last login
    $db->find()
        ->table('users')
        ->where('id', $user['id'])
        ->update([
            'failed_login_attempts' => 0,
            'last_login_at' => Db::now()
        ]);
    
    // Create session
    $sessionId = bin2hex(random_bytes(32));
    $db->find()->table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user['id'],
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'expires_at' => Db::now('+7 days')
    ]);
    
    return [
        'success' => true,
        'session_id' => $sessionId,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ]
    ];
}

$loginResult = loginUser($db, 'john_doe', 'SecurePass123!');

if ($loginResult['success']) {
    echo "✓ User '{$loginResult['user']['username']}' logged in successfully\n";
    echo "  Session ID: " . substr($loginResult['session_id'], 0, 16) . "...\n";
    echo "  Role: {$loginResult['user']['role']}\n\n";
}

// Failed login attempt
$failedLogin = loginUser($db, 'jane_smith', 'WrongPassword');
echo "✗ Failed login for 'jane_smith': {$failedLogin['message']}\n\n";

// Scenario 4: Check permissions
echo "4. Checking user permissions...\n";

function hasPermission($db, $userId, $resource, $action) {
    $count = $db->find()
        ->from('users u')
        ->join('permissions p', 'p.role = u.role')
        ->select([Db::count()])
        ->where('u.id', $userId)
        ->andWhere('p.resource', $resource)
        ->andWhere('p.action', $action)
        ->getValue();
    
    return $count > 0;
}

$canDelete = hasPermission($db, $userId1, 'users', 'delete');
echo "  Admin can delete users: " . ($canDelete ? 'YES' : 'NO') . "\n";

$canDeleteUser = hasPermission($db, $userId2, 'users', 'delete');
echo "  Regular user can delete users: " . ($canDeleteUser ? 'YES' : 'NO') . "\n\n";

// Scenario 5: Active sessions
echo "5. Getting active sessions...\n";

$activeSessions = $db->find()
    ->from('sessions AS s')
    ->join('users AS u', 'u.id = s.user_id')
    ->select(['s.id', 'u.username', 's.created_at', 's.expires_at'])
    ->where('s.expires_at', Db::now(), '>')
    ->get();

echo "  Active sessions: " . count($activeSessions) . "\n";
foreach ($activeSessions as $session) {
    echo "  • {$session['username']}: " . substr($session['id'], 0, 16) . "...\n";
}
echo "\n";

// Scenario 6: User statistics
echo "6. User statistics...\n";

$stats = $db->find()
    ->from('users')
    ->select([
        'total_users' => Db::count(),
        'active_users' => Db::sum(Db::case(['is_active = 1' => '1'], '0')),
        'verified_users' => Db::sum(Db::case(['email_verified = 1' => '1'], '0')),
        'admin_count' => Db::sum(Db::case(["role = 'admin'" => '1'], '0'))
    ])
    ->getOne();

echo "  📊 User Statistics:\n";
echo "     Total: {$stats['total_users']}\n";
echo "     Active: {$stats['active_users']}\n";
echo "     Verified: {$stats['verified_users']}\n";
echo "     Admins: {$stats['admin_count']}\n\n";

// Scenario 7: Account lockout check
echo "7. Checking account security...\n";

$lockedAccounts = $db->find()
    ->from('users')
    ->select(['username', 'failed_login_attempts'])
    ->where('failed_login_attempts', 3, '>=')
    ->get();

if (count($lockedAccounts) > 0) {
    echo "  ⚠️  Accounts with high failed login attempts:\n";
    foreach ($lockedAccounts as $acc) {
        echo "  • {$acc['username']}: {$acc['failed_login_attempts']} failed attempts\n";
    }
} else {
    echo "  ✓ No locked accounts\n";
}
echo "\n";

// Scenario 8: Cleanup expired sessions
echo "8. Cleaning up expired sessions...\n";

$deleted = $db->find()
    ->table('sessions')
    ->where('expires_at', Db::now(), '<')
    ->delete();

echo "  ✓ Deleted $deleted expired session(s)\n\n";

echo "User authentication system example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Password hashing with password_hash/password_verify\n";
echo "  • Session management with expiration\n";
echo "  • Role-based access control (RBAC) with JOIN queries\n";
echo "  • Account security with failed login tracking\n";
echo "  • Automated session cleanup\n";

