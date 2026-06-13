<?php
// Script to reset all user passwords

$dbPath = __DIR__ . '/withdrawals.db';

try {
    $db = new SQLite3($dbPath);
    
    // Generate fresh password hash
    $password = 'SecurePass@2024!';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    
    echo "<h2>Password Reset Script</h2>";
    echo "Resetting all user passwords to: <strong>$password</strong><br><br>";
    
    // Update all users
    $users = ['Enock', 'Richard', 'STK', 'Guma', 'Kigoma'];
    
    foreach ($users as $user) {
        $stmt = $db->prepare('UPDATE users SET password = :password WHERE username = :username');
        $stmt->bindValue(':password', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':username', $user, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result) {
            echo "✓ Updated password for: <strong>$user</strong><br>";
        } else {
            echo "✗ Failed to update: <strong>$user</strong><br>";
        }
    }
    
    echo "<br><h3>Verification Test:</h3>";
    
    // Test one user
    $stmt = $db->prepare('SELECT username, password FROM users WHERE username = :username');
    $stmt->bindValue(':username', 'Enock', SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user) {
        $verified = password_verify($password, $user['password']);
        echo "Password verification for 'Enock': " . ($verified ? "<strong style='color: green;'>✓ SUCCESS</strong>" : "<strong style='color: red;'>✗ FAILED</strong>") . "<br>";
    }
    
    echo "<br><a href='index.php'>← Back to Login</a>";
    
} catch (Exception $e) {
    echo "<span style='color: red;'>Error: " . $e->getMessage() . "</span>";
}
?>
