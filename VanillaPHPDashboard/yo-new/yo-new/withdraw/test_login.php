<?php
// Test script to debug login issues

echo "<h2>Database Test</h2>";

// Check if database exists
$dbPath = __DIR__ . '/withdrawals.db';
echo "Database path: " . $dbPath . "<br>";
echo "Database exists: " . (file_exists($dbPath) ? "YES" : "NO") . "<br><br>";

try {
    $db = new SQLite3($dbPath);
    echo "✓ Database connection successful<br><br>";
    
    // Check users table
    echo "<h3>Users in database:</h3>";
    $result = $db->query('SELECT username, created_at FROM users');
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Username</th><th>Created At</th></tr>";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        echo "<tr><td>{$row['username']}</td><td>{$row['created_at']}</td></tr>";
    }
    echo "</table><br>";
    
    // Test password verification
    echo "<h3>Password Test:</h3>";
    $testPassword = 'SecurePass@2024!';
    echo "Testing password: <strong>$testPassword</strong><br><br>";
    
    $stmt = $db->prepare('SELECT username, password FROM users WHERE username = :username');
    $stmt->bindValue(':username', 'Enock', SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        echo "User 'Enock' found in database<br>";
        echo "Stored hash (first 50 chars): " . substr($user['password'], 0, 50) . "...<br>";
        echo "Hash length: " . strlen($user['password']) . " characters<br>";
        
        $verified = password_verify($testPassword, $user['password']);
        echo "<br><strong>Password verification result: " . ($verified ? "✓ SUCCESS" : "✗ FAILED") . "</strong><br>";
        
        if (!$verified) {
            echo "<br><span style='color: red;'>Password does not match! Database may need reset.</span><br>";
        }
    } else {
        echo "<span style='color: red;'>User 'Enock' not found in database!</span><br>";
    }
    
    // Generate a fresh hash for comparison
    echo "<br><h3>Fresh Hash Generation:</h3>";
    $freshHash = password_hash($testPassword, PASSWORD_BCRYPT);
    echo "New hash generated: " . substr($freshHash, 0, 50) . "...<br>";
    echo "Verification test: " . (password_verify($testPassword, $freshHash) ? "✓ Works" : "✗ Failed") . "<br>";
    
} catch (Exception $e) {
    echo "<span style='color: red;'>Error: " . $e->getMessage() . "</span>";
}
?>
