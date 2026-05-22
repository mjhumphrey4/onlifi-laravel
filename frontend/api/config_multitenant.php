<?php
/**
 * Multi-tenant Database Configuration
 * Provides database connection functions for the telemetry ingestion API
 */

// Load environment variables from backend .env if available
function loadEnv() {
    $envFile = __DIR__ . '/../../backend/.env';
    if (!file_exists($envFile)) {
        $envFile = __DIR__ . '/../../../backend/.env';
    }
    
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }
}

loadEnv();

// Database configuration
function getCentralDB() {
    $host = getenv('CENTRAL_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('CENTRAL_DB_PORT') ?: getenv('DB_PORT') ?: '3306';
    $database = getenv('CENTRAL_DB_DATABASE') ?: 'onlifi_central';
    $username = getenv('CENTRAL_DB_USERNAME') ?: getenv('DB_USERNAME') ?: 'root';
    $password = getenv('CENTRAL_DB_PASSWORD') ?: getenv('DB_PASSWORD') ?: '';
    
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Central DB connection failed: " . $e->getMessage());
        return null;
    }
}

function getTenantDB($databaseName) {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$databaseName;charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Tenant DB connection failed for $databaseName: " . $e->getMessage());
        return null;
    }
}
