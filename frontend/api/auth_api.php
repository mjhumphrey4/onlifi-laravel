<?php
/**
 * Authentication API - Multi-Tenant User Management
 * Handles signup, login, logout, and user management
 */

// Prevent any output before JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config_multitenant.php';

// Session management
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ONLIFI_SESSION');
    session_start();
}

// Helper functions
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($msg, $code = 400) {
    respond(['error' => $msg], $code);
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        fail('Unauthorized', 401);
    }
    return $_SESSION;
}

function requireAdmin() {
    $session = requireAuth();
    if ($session['role'] !== 'admin') {
        fail('Admin access required', 403);
    }
    return $session;
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ==================== SIGNUP ====================
        case 'signup':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $username = trim($input['username'] ?? '');
            $email = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $fullName = trim($input['full_name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            
            // Validation
            if (empty($username) || empty($email) || empty($password) || empty($fullName)) {
                fail('All fields are required');
            }
            
            if (strlen($username) < 3 || strlen($username) > 50) {
                fail('Username must be between 3 and 50 characters');
            }
            
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                fail('Username can only contain letters, numbers, and underscores');
            }
            
            if (!isValidEmail($email)) {
                fail('Invalid email address');
            }
            
            $passwordValidation = validatePassword($password);
            if (!$passwordValidation['valid']) {
                fail($passwordValidation['message']);
            }
            
            $db = getCentralDB();
            
            // Check if username or email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                fail('Username or email already exists');
            }
            
            // Generate unique database name
            $databaseName = generateDatabaseName($username);
            
            // Hash password
            $passwordHash = hashPassword($password);
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Create user with pending status - waiting for admin approval
                $stmt = $db->prepare(
                    "INSERT INTO users (username, email, password_hash, full_name, phone, database_name, status) 
                     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );
                $stmt->execute([$username, $email, $passwordHash, $fullName, $phone, $databaseName]);
                $userId = $db->lastInsertId();
                
                // Create user settings
                $stmt = $db->prepare("INSERT INTO user_settings (user_id) VALUES (?)");
                $stmt->execute([$userId]);
                
                // Create default subscription (but not active until approved)
                $stmt = $db->prepare(
                    "INSERT INTO user_subscriptions (user_id, plan_type, status, max_routers, max_vouchers, max_clients) 
                     VALUES (?, 'free', 'pending', 2, 500, 100)"
                );
                $stmt->execute([$userId]);
                
                // Log that account is awaiting approval (no database created yet)
                $stmt = $db->prepare(
                    "INSERT INTO database_provisioning_log (user_id, database_name, status, error_message) 
                     VALUES (?, ?, 'pending', 'Awaiting admin approval')"
                );
                $stmt->execute([$userId, $databaseName]);
                
                $db->commit();
                
                logUserActivity($userId, 'signup', 'User account created - awaiting admin approval');
                
                respond([
                    'success' => true,
                    'message' => 'Account created successfully! Your account is pending admin approval. You will be notified once approved.',
                    'user' => [
                        'id' => $userId,
                        'username' => $username,
                        'email' => $email,
                        'full_name' => $fullName
                    ]
                ], 201);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Signup error: " . $e->getMessage());
                fail('Failed to create account. Please try again.');
            }
            break;
        
        // ==================== LOGIN ====================
        case 'login':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            
            // Log the login attempt for debugging
            error_log("Login attempt - Username: $username");
            
            if (empty($username) || empty($password)) {
                error_log("Login failed - Empty credentials");
                fail('Username and password are required');
            }
            
            try {
                $db = getCentralDB();
            } catch (Exception $e) {
                error_log("Login failed - Database connection error: " . $e->getMessage());
                fail('Database connection failed');
            }
            
            // Get user
            $stmt = $db->prepare(
                "SELECT id, username, email, password_hash, full_name, role, status, database_name 
                 FROM users 
                 WHERE username = ? OR email = ?"
            );
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                error_log("Login failed - User not found: $username");
                fail('Invalid username or password');
            }
            
            error_log("Login - User found: {$user['username']}, Status: {$user['status']}");
            
            if ($user['status'] !== 'active') {
                error_log("Login failed - Account status: {$user['status']}");
                fail('Account is ' . $user['status'] . '. Please contact support.');
            }
            
            if (!verifyPassword($password, $user['password_hash'])) {
                error_log("Login failed - Invalid password for user: $username");
                fail('Invalid username or password');
            }
            
            error_log("Login successful - User: {$user['username']}");
            
            // Update last login
            $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            // Create session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['database_name'] = $user['database_name'];
            
            // Create session token
            $sessionToken = generateSecureToken(32);
            $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
            
            $stmt = $db->prepare(
                "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $user['id'],
                $sessionToken,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $expiresAt
            ]);
            
            logUserActivity($user['id'], 'login', 'User logged in successfully');
            
            respond([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'name' => $user['full_name'],
                    'role' => $user['role']
                ]
            ]);
            break;
        
        // ==================== LOGOUT ====================
        case 'logout':
            if (!empty($_SESSION['user_id'])) {
                logUserActivity($_SESSION['user_id'], 'logout', 'User logged out');
            }
            
            session_destroy();
            respond(['success' => true, 'message' => 'Logged out successfully']);
            break;
        
        // ==================== GET CURRENT USER ====================
        case 'me':
            $session = requireAuth();
            
            $db = getCentralDB();
            $stmt = $db->prepare(
                "SELECT u.id, u.username, u.email, u.full_name, u.phone, u.role, u.status, 
                        u.created_at, u.last_login, us.theme, us.language, us.timezone,
                        sub.plan_type, sub.max_routers, sub.max_vouchers, sub.max_clients
                 FROM users u
                 LEFT JOIN user_settings us ON u.id = us.user_id
                 LEFT JOIN user_subscriptions sub ON u.id = sub.user_id
                 WHERE u.id = ?"
            );
            $stmt->execute([$session['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                fail('User not found', 404);
            }
            
            respond([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'name' => $user['full_name'],
                    'phone' => $user['phone'],
                    'role' => $user['role'],
                    'status' => $user['status'],
                    'created_at' => $user['created_at'],
                    'last_login' => $user['last_login'],
                    'settings' => [
                        'theme' => $user['theme'],
                        'language' => $user['language'],
                        'timezone' => $user['timezone']
                    ],
                    'subscription' => [
                        'plan' => $user['plan_type'],
                        'max_routers' => $user['max_routers'],
                        'max_vouchers' => $user['max_vouchers'],
                        'max_clients' => $user['max_clients']
                    ]
                ]
            ]);
            break;
        
        // ==================== LIST ALL USERS (ADMIN ONLY) ====================
        case 'users':
            requireAdmin();
            
            $db = getCentralDB();
            $stmt = $db->query(
                "SELECT u.id, u.username, u.email, u.full_name, u.phone, u.role, u.status, 
                        u.created_at, u.last_login, u.database_name,
                        sub.plan_type, sub.status as subscription_status
                 FROM users u
                 LEFT JOIN user_subscriptions sub ON u.id = sub.user_id
                 ORDER BY u.created_at DESC"
            );
            $users = $stmt->fetchAll();
            
            respond([
                'success' => true,
                'users' => $users
            ]);
            break;
        
        // ==================== APPROVE USER (Admin Only) ====================
        case 'approve_user':
            requireAdmin();
            
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $userId = (int)($input['user_id'] ?? 0);
            
            if ($userId <= 0) {
                fail('Invalid user ID');
            }
            
            $db = getCentralDB();
            
            // Get user details
            $stmt = $db->prepare("SELECT id, username, database_name, status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                fail('User not found');
            }
            
            if ($user['status'] === 'active') {
                fail('User is already active');
            }
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Create tenant database
                $dbCreated = createTenantDatabase($user['database_name']);
                
                if (!$dbCreated) {
                    throw new Exception('Failed to create tenant database');
                }
                
                // Activate user account
                $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$userId]);
                
                // Activate subscription
                $stmt = $db->prepare("UPDATE user_subscriptions SET status = 'active' WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // Update provisioning log
                $stmt = $db->prepare(
                    "UPDATE database_provisioning_log 
                     SET status = 'success', error_message = NULL, completed_at = NOW() 
                     WHERE user_id = ? AND status = 'pending'"
                );
                $stmt->execute([$userId]);
                
                $db->commit();
                
                logUserActivity($userId, 'account_approved', 'Account approved and database provisioned by admin');
                
                respond([
                    'success' => true,
                    'message' => 'User approved successfully. Database created and account activated.'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("User approval error: " . $e->getMessage());
                
                // Log failed provisioning
                $stmt = $db->prepare(
                    "UPDATE database_provisioning_log 
                     SET status = 'failed', error_message = ?, completed_at = NOW() 
                     WHERE user_id = ? AND status = 'pending'"
                );
                $stmt->execute([$e->getMessage(), $userId]);
                
                fail('Failed to approve user: ' . $e->getMessage());
            }
            break;
        
        // ==================== UPDATE PROFILE ====================
        case 'update_profile':
            $session = requireAuth();
            
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $fullName = trim($input['full_name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            
            if (empty($fullName)) {
                fail('Full name is required');
            }
            
            if (!empty($email) && !isValidEmail($email)) {
                fail('Invalid email address');
            }
            
            $db = getCentralDB();
            
            // Check if email is already used by another user
            if (!empty($email)) {
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $session['user_id']]);
                if ($stmt->fetch()) {
                    fail('Email already in use');
                }
            }
            
            $stmt = $db->prepare(
                "UPDATE users SET full_name = ?, phone = ?, email = ? WHERE id = ?"
            );
            $stmt->execute([$fullName, $phone, $email, $session['user_id']]);
            
            logUserActivity($session['user_id'], 'update_profile', 'Profile updated');
            
            respond(['success' => true, 'message' => 'Profile updated successfully']);
            break;
        
        // ==================== CHANGE PASSWORD ====================
        case 'change_password':
            $session = requireAuth();
            
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $currentPassword = $input['current_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword)) {
                fail('Current and new password are required');
            }
            
            $passwordValidation = validatePassword($newPassword);
            if (!$passwordValidation['valid']) {
                fail($passwordValidation['message']);
            }
            
            $db = getCentralDB();
            
            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$session['user_id']]);
            $user = $stmt->fetch();
            
            if (!verifyPassword($currentPassword, $user['password_hash'])) {
                fail('Current password is incorrect');
            }
            
            // Update password
            $newHash = hashPassword($newPassword);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$newHash, $session['user_id']]);
            
            logUserActivity($session['user_id'], 'change_password', 'Password changed');
            
            respond(['success' => true, 'message' => 'Password changed successfully']);
            break;
        
        // ==================== DELETE USER (Admin Only) ====================
        case 'delete_user':
            requireAdmin();
            
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $userId = (int)($input['user_id'] ?? 0);
            
            if ($userId <= 0) {
                fail('Invalid user ID');
            }
            
            $db = getCentralDB();
            
            // Get user details
            $stmt = $db->prepare("SELECT id, username, email, database_name, role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                fail('User not found');
            }
            
            // Prevent deleting admin users
            if ($user['role'] === 'admin') {
                fail('Cannot delete admin users');
            }
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Delete user's tenant database if it exists
                $dbDeleted = true;
                if (!empty($user['database_name'])) {
                    $dbDeleted = deleteTenantDatabase($user['database_name']);
                    if (!$dbDeleted) {
                        error_log("Warning: Failed to delete database {$user['database_name']} for user {$user['username']}");
                    }
                }
                
                // Delete related records (cascading delete)
                $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                $stmt = $db->prepare("DELETE FROM user_settings WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                $stmt = $db->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                $stmt = $db->prepare("DELETE FROM database_provisioning_log WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                $stmt = $db->prepare("DELETE FROM user_activity_log WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // Finally, delete the user
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                
                $db->commit();
                
                error_log("User deleted successfully: {$user['username']} (ID: {$userId})");
                
                respond([
                    'success' => true,
                    'message' => 'User and all associated data deleted successfully.' . 
                                 ($dbDeleted ? ' Database removed.' : ' (Database deletion failed - may need manual cleanup)')
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("User deletion error: " . $e->getMessage());
                fail('Failed to delete user: ' . $e->getMessage());
            }
            break;
        
        // ==================== USER STATISTICS (ADMIN) ====================
        case 'user_stats':
            requireAdmin();
            
            $db = getCentralDB();
            
            // Total users
            $totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            // Active users
            $activeUsers = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
            
            // Users by role
            $stmt = $db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
            $usersByRole = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Recent signups (last 30 days)
            $recentSignups = $db->query(
                "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            )->fetchColumn();
            
            // Active sessions
            $activeSessions = $db->query(
                "SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()"
            )->fetchColumn();
            
            respond([
                'success' => true,
                'stats' => [
                    'total_users' => (int)$totalUsers,
                    'active_users' => (int)$activeUsers,
                    'users_by_role' => $usersByRole,
                    'recent_signups' => (int)$recentSignups,
                    'active_sessions' => (int)$activeSessions
                ]
            ]);
            break;
        
        default:
            fail('Invalid action', 400);
    }
    
} catch (Exception $e) {
    error_log("Auth API Error: " . $e->getMessage());
    fail('An error occurred. Please try again later.', 500);
}
