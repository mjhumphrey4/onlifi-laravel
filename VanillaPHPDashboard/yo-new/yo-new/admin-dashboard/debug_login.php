<?php
ob_start();
session_start();

$result = [];

// Test 1: Session working
$_SESSION['test'] = 'ok';
$result['session_write'] = isset($_SESSION['test']) ? 'OK' : 'FAIL';

// Test 2: password_verify
$hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$result['password_verify'] = password_verify('password', $hash) ? 'OK' : 'FAIL';

// Test 3: Can we include auth.php
try {
    require_once __DIR__ . '/config/auth.php';
    $result['auth_include'] = 'OK';
} catch (Throwable $e) {
    $result['auth_include'] = 'FAIL: ' . $e->getMessage();
}

// Test 4: Auth::login
try {
    $loginResult = Auth::login('admin', 'password');
    $result['auth_login'] = $loginResult ? 'OK - login returned true' : 'FAIL - login returned false';
    $result['session_user'] = isset($_SESSION['user']) ? 'SET: ' . json_encode($_SESSION['user']) : 'NOT SET';
} catch (Throwable $e) {
    $result['auth_login'] = 'FAIL: ' . $e->getMessage();
}

// Test 5: header redirect capability
$result['headers_sent'] = headers_sent($file, $line) ? "HEADERS ALREADY SENT in $file line $line" : 'NOT sent - redirect would work';

// Test 6: PHP version
$result['php_version'] = PHP_VERSION;

// Test 7: Session save path writable
$savePath = session_save_path() ?: sys_get_temp_dir();
$result['session_save_path'] = $savePath;
$result['session_path_writable'] = is_writable($savePath) ? 'WRITABLE' : 'NOT WRITABLE';

echo '<pre style="background:#111;color:#0f0;padding:20px;font-size:14px;">';
echo "=== LOGIN DEBUG ===\n\n";
foreach ($result as $k => $v) {
    echo str_pad($k, 30) . ": $v\n";
}
echo '</pre>';
