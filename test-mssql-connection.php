<?php
/**
 * Test MSSQL Connection via PDO
 * 
 * Tests connection to Microsoft SQL Server using PDO
 */

echo "=== Testing MSSQL Connection ===\n\n";

// Connection parameters for SA (admin)
$host = 'localhost';
$port = 1433;
$saUsername = 'sa';
$saPassword = 'YourStrong!Passw0rd';
$database = 'master';

// Test user credentials
$testUsername = 'testuser';
$testPassword = 'testpass'; // Simple password (CHECK_POLICY = OFF will be used)
$testDatabase = 'testdb';

// Check if sqlsrv PDO driver is available
$availableDrivers = PDO::getAvailableDrivers();
echo "Available PDO drivers: " . implode(', ', $availableDrivers) . "\n\n";

if (!in_array('sqlsrv', $availableDrivers)) {
    echo "❌ ERROR: sqlsrv PDO driver is not available!\n";
    echo "\nTo install sqlsrv driver on Linux:\n";
    echo "1. Install Microsoft ODBC Driver for SQL Server\n";
    echo "2. Install PHP sqlsrv extension:\n";
    echo "   - For Ubuntu/Debian: sudo apt-get install php-sqlsrv\n";
    echo "   - Or download from: https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server\n";
    echo "\nTo install sqlsrv driver on Windows:\n";
    echo "1. Download Microsoft Drivers for PHP for SQL Server\n";
    echo "2. Enable extension in php.ini: extension=php_sqlsrv_XX_ts.dll\n";
    exit(1);
}

echo "✓ sqlsrv driver is available\n\n";

// Build DSN with SSL options for self-signed certificates
function buildDsn($host, $port, $database) {
    return "sqlsrv:Server={$host},{$port};Database={$database};TrustServerCertificate=yes;Encrypt=yes";
}

// Connect as SA to setup test user
echo "=== Step 1: Connecting as SA ===\n";
$dsn = buildDsn($host, $port, $database);
echo "DSN: {$dsn}\n";
echo "Username: {$saUsername}\n\n";

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    
    $pdo = new PDO($dsn, $saUsername, $saPassword, $options);
    echo "✅ Connected as SA successfully!\n\n";
    
    // Ensure testdb exists
    echo "=== Step 2: Ensuring testdb database exists ===\n";
    $stmt = $pdo->query("SELECT name FROM sys.databases WHERE name = '{$testDatabase}'");
    $dbExists = $stmt->fetch();
    
    if (!$dbExists) {
        echo "Creating database '{$testDatabase}'...\n";
        $pdo->exec("CREATE DATABASE {$testDatabase}");
        echo "✅ Database '{$testDatabase}' created\n\n";
    } else {
        echo "✅ Database '{$testDatabase}' already exists\n\n";
    }
    
    // Check if testuser exists
    echo "=== Step 3: Checking testuser account ===\n";
    $stmt = $pdo->query("SELECT name FROM sys.server_principals WHERE name = '{$testUsername}'");
    $userExists = $stmt->fetch();
    
    if (!$userExists) {
        echo "Creating login '{$testUsername}'...\n";
        // Create SQL Server login
        // CHECK_POLICY = OFF allows simple passwords (for testing only!)
        // In production, use CHECK_POLICY = ON with complex passwords
        try {
            // Try with simple password and CHECK_POLICY = OFF
            $pdo->exec("CREATE LOGIN [{$testUsername}] WITH PASSWORD = '{$testPassword}', CHECK_POLICY = OFF");
            echo "✅ Login '{$testUsername}' created with password '{$testPassword}'\n\n";
        } catch (PDOException $e) {
            echo "❌ Failed to create login: {$e->getMessage()}\n";
            throw $e;
        }
    } else {
        echo "✅ Login '{$testUsername}' already exists\n";
        // Update password in case it was changed
        echo "Updating password for '{$testUsername}'...\n";
        try {
            $pdo->exec("ALTER LOGIN [{$testUsername}] WITH PASSWORD = '{$testPassword}', CHECK_POLICY = OFF");
            echo "✅ Password updated to '{$testPassword}'\n\n";
        } catch (PDOException $e) {
            echo "⚠️  Could not update password: {$e->getMessage()}\n\n";
        }
    }
    
    // Grant access to testdb
    echo "=== Step 4: Granting access to testdb ===\n";
    $pdo->exec("USE [{$testDatabase}]");
    
    // Check if user exists in database
    $stmt = $pdo->query("SELECT name FROM sys.database_principals WHERE name = '{$testUsername}'");
    $dbUserExists = $stmt->fetch();
    
    if (!$dbUserExists) {
        echo "Creating user '{$testUsername}' in database '{$testDatabase}'...\n";
        $pdo->exec("CREATE USER [{$testUsername}] FOR LOGIN [{$testUsername}]");
        echo "✅ User created in database\n";
    } else {
        echo "✅ User already exists in database\n";
    }
    
    // Grant permissions
    echo "Granting permissions...\n";
    $pdo->exec("ALTER ROLE db_owner ADD MEMBER [{$testUsername}]");
    echo "✅ Granted db_owner role to '{$testUsername}'\n\n";
    
    // Test connection as testuser
    echo "=== Step 5: Testing connection as testuser ===\n";
    $testDsn = buildDsn($host, $port, $testDatabase);
    echo "DSN: {$testDsn}\n";
    echo "Username: {$testUsername}\n";
    echo "Database: {$testDatabase}\n\n";
    
    try {
        $testPdo = new PDO($testDsn, $testUsername, $testPassword, $options);
        echo "✅ SUCCESS: Connected as testuser!\n\n";
        
        // Test query
        echo "Testing query execution...\n";
        $stmt = $testPdo->query("SELECT DB_NAME() AS db_name, SUSER_SNAME() AS user_name");
        $result = $stmt->fetch();
        
        echo "✅ Query executed successfully!\n";
        echo "Current Database: {$result['db_name']}\n";
        echo "Current User: {$result['user_name']}\n\n";
        
        // Test DML operations
        echo "Testing DML operations...\n";
        
        // Create test table
        $testPdo->exec("IF OBJECT_ID('test_connection', 'U') IS NOT NULL DROP TABLE test_connection");
        $testPdo->exec("CREATE TABLE test_connection (
            id INT IDENTITY(1,1) PRIMARY KEY,
            test_value NVARCHAR(100),
            created_at DATETIME DEFAULT GETDATE()
        )");
        echo "✅ CREATE TABLE successful\n";
        
        // INSERT test
        $stmt = $testPdo->prepare("INSERT INTO test_connection (test_value) VALUES (?)");
        $stmt->execute(['Connection test successful']);
        echo "✅ INSERT operation successful\n";
        
        // SELECT test
        $stmt = $testPdo->query("SELECT * FROM test_connection");
        $result = $stmt->fetch();
        echo "✅ SELECT operation successful - Retrieved: {$result['test_value']}\n";
        
        // UPDATE test
        $stmt = $testPdo->prepare("UPDATE test_connection SET test_value = ? WHERE id = ?");
        $stmt->execute(['Updated value', $result['id']]);
        echo "✅ UPDATE operation successful\n";
        
        // DELETE test
        $stmt = $testPdo->prepare("DELETE FROM test_connection WHERE id = ?");
        $stmt->execute([$result['id']]);
        echo "✅ DELETE operation successful\n";
        
        // Cleanup
        $testPdo->exec("DROP TABLE test_connection");
        echo "✅ Cleanup successful\n\n";
        
        echo "✅ All tests completed successfully!\n";
        echo "\nSummary:\n";
        echo "  - SA Connection: ✅ Working\n";
        echo "  - Database '{$testDatabase}': ✅ Exists\n";
        echo "  - User '{$testUsername}': ✅ Created/Updated\n";
        echo "  - Permissions: ✅ Granted (db_owner)\n";
        echo "  - Testuser Connection: ✅ Working\n";
        echo "  - SSL/TLS: ✅ Configured (TrustServerCertificate=yes)\n";
        echo "  - Query execution: ✅ Working\n";
        echo "  - DML operations: ✅ Working\n";
        
    } catch (PDOException $e) {
        echo "❌ ERROR: Connection as testuser failed!\n\n";
        echo "Error Code: {$e->getCode()}\n";
        echo "Error Message: {$e->getMessage()}\n\n";
        
        echo "Troubleshooting:\n";
        echo "1. Verify login was created: SELECT name FROM sys.server_principals WHERE name = '{$testUsername}'\n";
        echo "2. Verify user exists in database: USE {$testDatabase}; SELECT name FROM sys.database_principals WHERE name = '{$testUsername}'\n";
        echo "3. Verify permissions: USE {$testDatabase}; SELECT dp.name, dp.type_desc FROM sys.database_principals dp WHERE dp.name = '{$testUsername}'\n";
        
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "❌ ERROR: Connection failed!\n\n";
    echo "Error Code: {$e->getCode()}\n";
    echo "Error Message: {$e->getMessage()}\n\n";
    
    echo "Common issues:\n";
    echo "1. SQL Server is not running\n";
    echo "2. SQL Server Browser service is not running (for named instances)\n";
    echo "3. TCP/IP protocol is not enabled in SQL Server Configuration Manager\n";
    echo "4. Firewall is blocking port 1433\n";
    echo "5. Incorrect SA username/password\n";
    
    exit(1);
}

