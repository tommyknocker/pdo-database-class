<?php
/**
 * Real-World Example: Multi-Tenant Application
 * 
 * Demonstrates a SaaS multi-tenant architecture with tenant isolation,
 * cross-tenant analytics, and resource management
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Multi-Tenant Application Example (on $driver) ===\n\n";

// Create schema
echo "Setting up multi-tenant database...\n";

recreateTable($db, 'tenants', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT NOT NULL',
    'slug' => 'TEXT UNIQUE NOT NULL',
    'plan' => 'TEXT DEFAULT \'free\'',
    'max_users' => 'INTEGER DEFAULT 5',
    'max_storage_mb' => 'INTEGER DEFAULT 100',
    'is_active' => 'INTEGER DEFAULT 1',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'tenant_id' => 'INTEGER NOT NULL',
    'name' => 'TEXT NOT NULL',
    'email' => 'TEXT NOT NULL',
    'role' => 'TEXT DEFAULT \'member\'',
    'is_active' => 'INTEGER DEFAULT 1',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
    'UNIQUE(tenant_id, email)' => ''
]);

recreateTable($db, 'documents', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'tenant_id' => 'INTEGER NOT NULL',
    'user_id' => 'INTEGER NOT NULL',
    'title' => 'TEXT NOT NULL',
    'content' => 'TEXT',
    'size_kb' => 'INTEGER DEFAULT 0',
    'is_public' => 'INTEGER DEFAULT 0',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

recreateTable($db, 'api_usage', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'tenant_id' => 'INTEGER NOT NULL',
    'endpoint' => 'TEXT NOT NULL',
    'requests_count' => 'INTEGER DEFAULT 0',
    'date' => 'DATE NOT NULL',
    'UNIQUE(tenant_id, endpoint, date)' => ''
]);

echo "✓ Schema created (tenants, users, documents, api_usage)\n\n";

// Scenario 1: Create tenants with different plans
echo "1. Creating tenants with different subscription plans...\n";

$tenants = [
    ['name' => 'Acme Corp', 'slug' => 'acme', 'plan' => 'enterprise', 'max_users' => 100, 'max_storage_mb' => 10000],
    ['name' => 'StartupXYZ', 'slug' => 'startupxyz', 'plan' => 'business', 'max_users' => 20, 'max_storage_mb' => 1000],
    ['name' => 'Freelancer Joe', 'slug' => 'freelancer-joe', 'plan' => 'free', 'max_users' => 5, 'max_storage_mb' => 100],
    ['name' => 'TechSolutions Inc', 'slug' => 'techsolutions', 'plan' => 'business', 'max_users' => 20, 'max_storage_mb' => 1000],
];

$db->find()->table('tenants')->insertMulti($tenants);

echo "✓ Created " . count($tenants) . " tenants\n";

$tenantsList = $db->find()->from('tenants')->get();
foreach ($tenantsList as $t) {
    echo "  • {$t['name']} ({$t['plan']}): {$t['max_users']} users, {$t['max_storage_mb']} MB\n";
}
echo "\n";

// Scenario 2: Add users to tenants
echo "2. Adding users to tenants...\n";

$users = [
    // Acme Corp users
    ['tenant_id' => 1, 'name' => 'Alice Admin', 'email' => 'alice@acme.com', 'role' => 'admin'],
    ['tenant_id' => 1, 'name' => 'Bob Manager', 'email' => 'bob@acme.com', 'role' => 'manager'],
    ['tenant_id' => 1, 'name' => 'Charlie User', 'email' => 'charlie@acme.com', 'role' => 'member'],
    ['tenant_id' => 1, 'name' => 'Diana User', 'email' => 'diana@acme.com', 'role' => 'member'],
    
    // StartupXYZ users
    ['tenant_id' => 2, 'name' => 'Eve Founder', 'email' => 'eve@startupxyz.com', 'role' => 'admin'],
    ['tenant_id' => 2, 'name' => 'Frank Dev', 'email' => 'frank@startupxyz.com', 'role' => 'member'],
    
    // Freelancer Joe (single user)
    ['tenant_id' => 3, 'name' => 'Joe Freelancer', 'email' => 'joe@freelancer.com', 'role' => 'admin'],
    
    // TechSolutions users
    ['tenant_id' => 4, 'name' => 'Grace CTO', 'email' => 'grace@techsolutions.com', 'role' => 'admin'],
    ['tenant_id' => 4, 'name' => 'Henry Dev', 'email' => 'henry@techsolutions.com', 'role' => 'member'],
    ['tenant_id' => 4, 'name' => 'Ivy Designer', 'email' => 'ivy@techsolutions.com', 'role' => 'member'],
];

$db->find()->table('users')->insertMulti($users);

echo "✓ Added " . count($users) . " users across all tenants\n\n";

// Scenario 3: Tenant-scoped query (get users for specific tenant)
echo "3. Tenant-scoped query: Getting users for 'Acme Corp'...\n";

$acmeTenantId = 1;
$acmeUsers = $db->find()
    ->from('users')
    ->where('tenant_id', $acmeTenantId)
    ->andWhere('is_active', 1)
    ->orderBy('role')
    ->get();

echo "  Acme Corp has " . count($acmeUsers) . " active users:\n";
foreach ($acmeUsers as $user) {
    echo "  • {$user['name']} ({$user['email']}) - {$user['role']}\n";
}
echo "\n";

// Scenario 4: Create documents
echo "4. Creating documents for various tenants...\n";

$documents = [
    ['tenant_id' => 1, 'user_id' => 1, 'title' => 'Q4 Report', 'content' => 'Financial analysis...', 'size_kb' => 250, 'is_public' => 0],
    ['tenant_id' => 1, 'user_id' => 2, 'title' => 'Product Roadmap', 'content' => 'Feature planning...', 'size_kb' => 180, 'is_public' => 1],
    ['tenant_id' => 1, 'user_id' => 3, 'title' => 'Meeting Notes', 'content' => 'Team sync...', 'size_kb' => 45, 'is_public' => 0],
    
    ['tenant_id' => 2, 'user_id' => 5, 'title' => 'Pitch Deck', 'content' => 'Investor presentation...', 'size_kb' => 520, 'is_public' => 0],
    ['tenant_id' => 2, 'user_id' => 6, 'title' => 'Technical Spec', 'content' => 'Architecture design...', 'size_kb' => 340, 'is_public' => 0],
    
    ['tenant_id' => 3, 'user_id' => 7, 'title' => 'Client Proposal', 'content' => 'Project scope...', 'size_kb' => 95, 'is_public' => 0],
    
    ['tenant_id' => 4, 'user_id' => 8, 'title' => 'API Documentation', 'content' => 'REST endpoints...', 'size_kb' => 420, 'is_public' => 1],
    ['tenant_id' => 4, 'user_id' => 9, 'title' => 'Bug Report', 'content' => 'Issue tracker...', 'size_kb' => 67, 'is_public' => 0],
];

$db->find()->table('documents')->insertMulti($documents);

echo "✓ Created " . count($documents) . " documents\n\n";

// Scenario 5: Check storage usage per tenant
echo "5. Storage usage by tenant:\n";

$storageUsage = $db->find()
    ->from('documents AS d')
    ->join('tenants AS t', 't.id = d.tenant_id')
    ->select([
        't.name AS tenant_name',
        't.max_storage_mb',
        'total_docs' => Db::count(),
        'total_size_kb' => Db::sum('d.size_kb'),
        'avg_size_kb' => Db::avg('d.size_kb')
    ])
    ->groupBy(['d.tenant_id', 't.name', 't.max_storage_mb'])
    ->orderBy(Db::sum('d.size_kb'), 'DESC')
    ->get();

echo "  📦 Storage usage:\n";
foreach ($storageUsage as $usage) {
    $usedMb = number_format($usage['total_size_kb'] / 1024, 2);
    $percentUsed = number_format(($usage['total_size_kb'] / 1024 / $usage['max_storage_mb']) * 100, 1);
    $avgKb = number_format($usage['avg_size_kb'], 0);
    echo "  • {$usage['tenant_name']}: {$usedMb} MB / {$usage['max_storage_mb']} MB ({$percentUsed}%)\n";
    echo "    {$usage['total_docs']} docs, avg size: {$avgKb} KB\n";
}
echo "\n";

// Scenario 6: Check user count vs limits
echo "6. User count vs limits:\n";

$tenants = $db->find()->from('tenants')->select(['id', 'name', 'plan', 'max_users'])->get();

echo "  👥 User limits:\n";
foreach ($tenants as $tenant) {
    $currentUsers = $db->find()
        ->from('users')
        ->select([Db::count()])
        ->where('tenant_id', $tenant['id'])
        ->andWhere('is_active', 1)
        ->getValue();
    
    $percentUsed = number_format(($currentUsers / $tenant['max_users']) * 100, 0);
    $status = $percentUsed >= 90 ? '⚠️' : '✓';
    echo "  {$status} {$tenant['name']} ({$tenant['plan']}): {$currentUsers}/{$tenant['max_users']} ({$percentUsed}%)\n";
}
echo "\n";

// Scenario 7: Record API usage
echo "7. Recording API usage...\n";

$apiUsage = [
    ['tenant_id' => 1, 'endpoint' => '/api/documents', 'requests_count' => 1250, 'date' => '2025-10-20'],
    ['tenant_id' => 1, 'endpoint' => '/api/users', 'requests_count' => 340, 'date' => '2025-10-20'],
    ['tenant_id' => 2, 'endpoint' => '/api/documents', 'requests_count' => 680, 'date' => '2025-10-20'],
    ['tenant_id' => 2, 'endpoint' => '/api/search', 'requests_count' => 420, 'date' => '2025-10-20'],
    ['tenant_id' => 3, 'endpoint' => '/api/documents', 'requests_count' => 85, 'date' => '2025-10-20'],
    ['tenant_id' => 4, 'endpoint' => '/api/documents', 'requests_count' => 920, 'date' => '2025-10-20'],
    ['tenant_id' => 4, 'endpoint' => '/api/analytics', 'requests_count' => 150, 'date' => '2025-10-20'],
];

$db->find()->table('api_usage')->insertMulti($apiUsage);

echo "✓ Recorded API usage for today\n\n";

// Scenario 8: API usage analytics
echo "8. API usage analytics by tenant:\n";

$apiStats = $db->find()
    ->from('api_usage AS a')
    ->join('tenants AS t', 't.id = a.tenant_id')
    ->select([
        't.name',
        't.plan',
        'total_requests' => Db::sum('a.requests_count'),
        'endpoints_count' => Db::count('DISTINCT a.endpoint')
    ])
    ->groupBy(['a.tenant_id', 't.name', 't.plan'])
    ->orderBy(Db::sum('a.requests_count'), 'DESC')
    ->get();

echo "  📊 API usage today:\n";
foreach ($apiStats as $stat) {
    echo "  • {$stat['name']} ({$stat['plan']}): {$stat['total_requests']} requests across {$stat['endpoints_count']} endpoints\n";
}
echo "\n";

// Scenario 9: Cross-tenant analytics (admin dashboard)
echo "9. Cross-tenant analytics (platform overview):\n";

$platformStats = $db->find()
    ->from('tenants')
    ->select([
        'total_tenants' => Db::count(),
        'active_tenants' => Db::sum(Db::case(['is_active = 1' => '1'], '0')),
        'enterprise_count' => Db::sum(Db::case(["plan = 'enterprise'" => '1'], '0')),
        'business_count' => Db::sum(Db::case(["plan = 'business'" => '1'], '0')),
        'free_count' => Db::sum(Db::case(["plan = 'free'" => '1'], '0'))
    ])
    ->getOne();

$totalUsers = $db->find()->from('users')->select([Db::count()])->getValue();
$totalDocs = $db->find()->from('documents')->select([Db::count()])->getValue();
$totalRequests = $db->find()->from('api_usage')->select([Db::sum('requests_count')])->getValue() ?: 0;

echo "  🏢 Platform Statistics:\n";
echo "     Total Tenants: {$platformStats['total_tenants']} (Active: {$platformStats['active_tenants']})\n";
echo "     Plans: {$platformStats['enterprise_count']} Enterprise, {$platformStats['business_count']} Business, {$platformStats['free_count']} Free\n";
echo "     Total Users: {$totalUsers}\n";
echo "     Total Documents: {$totalDocs}\n";
echo "     API Requests (today): {$totalRequests}\n";
echo "\n";

// Scenario 10: Tenant isolation verification
echo "10. Tenant isolation: Verify user can only see their tenant's data\n";

function getTenantDocuments($db, $userId) {
    return $db->find()
        ->from('documents AS d')
        ->join('users AS u', 'u.id = d.user_id')
        ->where('d.user_id', $userId)
        ->andWhere('u.tenant_id = d.tenant_id') // Ensure tenant isolation
        ->select(['d.title', 'd.size_kb', 'u.name AS author'])
        ->get();
}

$userDocs = getTenantDocuments($db, 1); // Alice from Acme Corp
echo "  Alice's accessible documents: " . count($userDocs) . "\n";
foreach ($userDocs as $doc) {
    echo "  • {$doc['title']} by {$doc['author']} ({$doc['size_kb']} KB)\n";
}
echo "\n";

// Scenario 11: Upgrade simulation
echo "11. Simulating tenant plan upgrade...\n";

$upgradeResult = $db->find()
    ->table('tenants')
    ->where('slug', 'freelancer-joe')
    ->update([
        'plan' => 'business',
        'max_users' => 20,
        'max_storage_mb' => 1000
    ]);

if ($upgradeResult) {
    $upgraded = $db->find()->from('tenants')->where('slug', 'freelancer-joe')->getOne();
    echo "  ✓ Freelancer Joe upgraded to {$upgraded['plan']} plan\n";
    echo "    New limits: {$upgraded['max_users']} users, {$upgraded['max_storage_mb']} MB storage\n";
}
echo "\n";

// Scenario 12: Most active tenant
echo "12. Most active tenant (by document creation):\n";

$mostActive = $db->find()
    ->from('documents AS d')
    ->join('tenants AS t', 't.id = d.tenant_id')
    ->select([
        't.name',
        'doc_count' => Db::count(),
        'total_size_kb' => Db::sum('d.size_kb'),
        'public_docs' => Db::sum(Db::case(['d.is_public = 1' => '1'], '0'))
    ])
    ->groupBy(['d.tenant_id', 't.name'])
    ->orderBy(Db::count(), 'DESC')
    ->limit(1)
    ->getOne();

echo "  🏆 Most active: {$mostActive['name']}\n";
echo "     Documents: {$mostActive['doc_count']}\n";
echo "     Total size: " . number_format($mostActive['total_size_kb'] / 1024, 2) . " MB\n";
echo "     Public docs: {$mostActive['public_docs']}\n";

echo "\n";

echo "Multi-tenant application example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Tenant-scoped queries with tenant_id filtering\n";
echo "  • Resource usage tracking (storage, users, API calls)\n";
echo "  • Plan-based limits and quota enforcement\n";
echo "  • Cross-tenant analytics for platform monitoring\n";
echo "  • Tenant isolation with JOIN constraints\n";
echo "  • Upgrade/downgrade simulation\n";
echo "  • Activity tracking per tenant\n";

