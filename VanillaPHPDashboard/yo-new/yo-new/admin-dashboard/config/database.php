<?php
date_default_timezone_set('Africa/Nairobi');

class Database {
    private static $instance = null;
    private $connections = [];
    
    private $host = 'localhost';
    private $username = 'yo';
    private $password = 'password';
    
    private $dbMap = [
        'omada'           => 'omada',
        'mikrotik'        => 'payment_mikrotik',
        'remmy'           => 'remmy_mikrotik',
        'guma'            => 'guma_omada',
    ];
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect($key) {
        if (!isset($this->connections[$key])) {
            $dbname = $this->dbMap[$key];
            try {
                $pdo = new PDO(
                    "mysql:host={$this->host};dbname={$dbname};charset=utf8mb4",
                    $this->username,
                    $this->password
                );
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connections[$key] = $pdo;
            } catch (PDOException $e) {
                error_log("DB connect failed [{$key}]: " . $e->getMessage());
                $this->connections[$key] = null;
            }
        }
        return $this->connections[$key];
    }
    
    public function getConnection() {
        return $this->connect('omada');
    }
    
    public function getMikrotikConnection() {
        return $this->connect('mikrotik');
    }
    
    public function getRemmyConnection() {
        return $this->connect('remmy');
    }
    
    public function getGumaConnection() {
        return $this->connect('guma');
    }
    
    public function getWithdrawDb() {
        if (!isset($this->connections['sqlite'])) {
            $withdrawDbPath = dirname(__DIR__, 2) . '/withdraw/withdrawals.db';
            try {
                $this->connections['sqlite'] = new SQLite3($withdrawDbPath);
            } catch (Exception $e) {
                error_log("SQLite connect failed: " . $e->getMessage());
                $this->connections['sqlite'] = null;
            }
        }
        return $this->connections['sqlite'];
    }
}
