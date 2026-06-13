<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('PAYDASH_SESSION');
    session_start();
}

class Auth {
    private static $users = [
        'admin' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'name' => 'Administrator',
            'email' => 'admin@yopayments.com',
            'role' => 'admin'
        ],
        'enock' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'name' => 'Enock',
            'email' => 'enock@bitetech.com',
            'role' => 'user',
            'site' => 'Enock'
        ],
        'richard' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'name' => 'Richard',
            'email' => 'richard@network.com',
            'role' => 'user',
            'site' => 'Richard'
        ],
        'stk' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'name' => 'STK Admin',
            'email' => 'stk@wifi.com',
            'role' => 'user',
            'site' => 'STK'
        ],
        'remmy' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'name' => 'Remmy',
            'email' => 'remmy@network.com',
            'role' => 'user',
            'site' => 'Remmy'
        ],
        'guma' => [
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            'name' => 'Guma',
            'email' => 'guma@omada.com',
            'role' => 'user',
            'site' => 'Guma'
        ]
    ];
    
    public static function login($username, $password) {
        if (isset(self::$users[$username])) {
            $user = self::$users[$username];
            if (password_verify($password, $user['password'])) {
                $_SESSION['user'] = [
                    'username' => $username,
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'site' => $user['site'] ?? null
                ];
                return true;
            }
        }
        return false;
    }
    
    public static function logout() {
        session_destroy();
        ob_end_clean();
        header('Location: login.php');
        exit;
    }
    
    public static function check() {
        if (!isset($_SESSION['user'])) {
            ob_end_clean();
            header('Location: login.php');
            exit;
        }
    }
    
    public static function user() {
        return $_SESSION['user'] ?? null;
    }
    
    public static function isAdmin() {
        return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
    }
    
    public static function getUserSite() {
        return $_SESSION['user']['site'] ?? null;
    }
}
