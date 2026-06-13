<?php
// Debug login script

// Set custom session save path to current directory
$sessionPath = __DIR__ . '/sessions';
if (!file_exists($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}
ini_set('session.save_path', $sessionPath);

session_start();

echo "<h2>Login Debug Information</h2>";

// Check if form was submitted
echo "<h3>POST Data:</h3>";
echo "Form submitted: " . (isset($_POST['login']) ? "YES" : "NO") . "<br>";
echo "Username: " . ($_POST['username'] ?? 'NOT SET') . "<br>";
echo "Password provided: " . (isset($_POST['password']) && !empty($_POST['password']) ? "YES" : "NO") . "<br>";
echo "<br>";

// Check session
echo "<h3>Session Data:</h3>";
echo "Session ID: " . session_id() . "<br>";
echo "User in session: " . ($_SESSION['user'] ?? 'NOT SET') . "<br>";
echo "<br>";

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    echo "<h3>Login Attempt:</h3>";
    echo "Attempting login for: <strong>$username</strong><br><br>";
    
    try {
        $db = new SQLite3(__DIR__ . '/withdrawals.db');
        echo "✓ Database connected<br>";
        
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($user) {
            echo "✓ User found in database<br>";
            echo "Username from DB: {$user['username']}<br>";
            
            $verified = password_verify($password, $user['password']);
            echo "Password verification: " . ($verified ? "<strong style='color: green;'>✓ SUCCESS</strong>" : "<strong style='color: red;'>✗ FAILED</strong>") . "<br>";
            
            if ($verified) {
                $_SESSION['user'] = $username;
                echo "<br><strong style='color: green;'>Login should succeed! Session set.</strong><br>";
                echo "Session user now: " . $_SESSION['user'] . "<br>";
            } else {
                echo "<br><strong style='color: red;'>Password does not match!</strong><br>";
            }
        } else {
            echo "<strong style='color: red;'>✗ User not found in database</strong><br>";
        }
        
    } catch (Exception $e) {
        echo "<span style='color: red;'>Database Error: " . $e->getMessage() . "</span><br>";
    }
}
?>

<hr>
<h3>Test Login Form:</h3>
<form method="POST">
    <label>Username:</label><br>
    <select name="username" required>
        <option value="">Select User</option>
        <option value="Enock">Enock</option>
        <option value="Richard">Richard</option>
        <option value="STK">STK</option>
    </select><br><br>
    
    <label>Password:</label><br>
    <input type="password" name="password" required placeholder="SecurePass@2024!"><br><br>
    
    <button type="submit" name="login">Test Login</button>
</form>

<br>
<a href="index.php">← Back to Main Login</a>
