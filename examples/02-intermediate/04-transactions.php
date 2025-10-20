<?php
/**
 * Example: Transaction Management
 * 
 * Demonstrates transaction handling, rollback, and error recovery
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('sqlite', ['path' => ':memory:']);

echo "=== Transaction Management Example ===\n\n";

// Setup
$db->rawQuery("CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT, balance REAL DEFAULT 0)");
$db->rawQuery("CREATE TABLE transactions (id INTEGER PRIMARY KEY, from_account INTEGER, to_account INTEGER, amount REAL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

$db->find()->table('accounts')->insertMulti([
    ['name' => 'Alice', 'balance' => 1000.00],
    ['name' => 'Bob', 'balance' => 500.00],
    ['name' => 'Carol', 'balance' => 750.00],
]);

echo "✓ Created accounts with initial balances\n\n";

// Example 1: Successful transaction
echo "1. Transferring $200 from Alice to Bob (successful)...\n";

$db->startTransaction();
try {
    // Deduct from Alice
    $db->find()
        ->table('accounts')
        ->where('name', 'Alice')
        ->update(['balance' => Db::dec(200)]);
    
    // Add to Bob
    $db->find()
        ->table('accounts')
        ->where('name', 'Bob')
        ->update(['balance' => Db::inc(200)]);
    
    // Record transaction
    $db->find()->table('transactions')->insert([
        'from_account' => 1,
        'to_account' => 2,
        'amount' => 200
    ]);
    
    $db->commit();
    echo "✓ Transaction committed successfully\n\n";
    
} catch (\Throwable $e) {
    $db->rollBack();
    echo "✗ Transaction failed: {$e->getMessage()}\n\n";
}

// Verify balances
$accounts = $db->find()->from('accounts')->select(['name', 'balance'])->orderBy('id')->get();
echo "  Current balances:\n";
foreach ($accounts as $acc) {
    echo "  • {$acc['name']}: \$" . number_format($acc['balance'], 2) . "\n";
}
echo "\n";

// Example 2: Failed transaction with rollback
echo "2. Attempting invalid transfer (will rollback)...\n";

$db->startTransaction();
try {
    // Try to deduct $2000 from Bob (only has $700)
    $bob = $db->find()->from('accounts')->where('name', 'Bob')->getOne();
    
    if ($bob['balance'] < 2000) {
        throw new \Exception('Insufficient funds');
    }
    
    // This won't execute
    $db->find()
        ->table('accounts')
        ->where('name', 'Bob')
        ->update(['balance' => Db::dec(2000)]);
    
    $db->commit();
    
} catch (\Throwable $e) {
    $db->rollBack();
    echo "✗ Transaction rolled back: {$e->getMessage()}\n\n";
}

// Verify Bob's balance is unchanged
$bob = $db->find()->from('accounts')->where('name', 'Bob')->getOne();
echo "  Bob's balance (unchanged): \${$bob['balance']}\n\n";

// Example 3: Multiple operations in single transaction
echo "3. Multiple transfers in one transaction...\n";

$db->startTransaction();
try {
    $transfers = [
        ['from' => 'Carol', 'to' => 'Alice', 'amount' => 100],
        ['from' => 'Bob', 'to' => 'Carol', 'amount' => 50],
    ];
    
    foreach ($transfers as $transfer) {
        // Deduct
        $db->find()
            ->table('accounts')
            ->where('name', $transfer['from'])
            ->update(['balance' => Db::dec($transfer['amount'])]);
        
        // Add
        $db->find()
            ->table('accounts')
            ->where('name', $transfer['to'])
            ->update(['balance' => Db::inc($transfer['amount'])]);
        
        // Log
        $fromId = $db->find()->from('accounts')->where('name', $transfer['from'])->getValue('id');
        $toId = $db->find()->from('accounts')->where('name', $transfer['to'])->getValue('id');
        
        $db->find()->table('transactions')->insert([
            'from_account' => $fromId,
            'to_account' => $toId,
            'amount' => $transfer['amount']
        ]);
    }
    
    $db->commit();
    echo "✓ Batch transfer completed\n\n";
    
} catch (\Throwable $e) {
    $db->rollBack();
    echo "✗ Batch transfer failed: {$e->getMessage()}\n\n";
}

// Final balances
echo "4. Final balances:\n";
$accounts = $db->find()->from('accounts')->select(['name', 'balance'])->orderBy('balance', 'DESC')->get();
foreach ($accounts as $acc) {
    echo "  • {$acc['name']}: \$" . number_format($acc['balance'], 2) . "\n";
}
echo "\n";

// Transaction history
echo "5. Transaction history:\n";
$history = $db->find()
    ->from('transactions AS t')
    ->join('accounts AS af', 'af.id = t.from_account')
    ->join('accounts AS at', 'at.id = t.to_account')
    ->select([
        'from_name' => 'af.name',
        'to_name' => 'at.name',
        't.amount',
        't.created_at'
    ])
    ->orderBy('t.id')
    ->get();

foreach ($history as $t) {
    echo "  • {$t['from_name']} → {$t['to_name']}: \$" . number_format($t['amount'], 2) . "\n";
}

echo "\nTransaction management example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Always wrap related operations in transactions\n";
echo "  • Use try-catch with rollback for error handling\n";
echo "  • Transactions ensure data consistency\n";

