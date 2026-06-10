<?php

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'payment_mikrotik');
define('DB_USER', 'yo');
define('DB_PASS', 'password');

// Site URL
define('SITE_URL', 'https://bitetechsystems.com/yo/');

// IOTEC API Configuration
define('IOTEC_CLIENT_ID', 'pay-019d1218-db15-7552-8549-ba735895df96');
define('IOTEC_CLIENT_SECRET', 'IO-08qJhyz5Xy6yX9wTvWGA7TJLou5Hb4Tuz');
define('IOTEC_WALLET_ID', '019d19e1-d50f-708b-adb6-c86d8b577157');

define('IOTEC_AUTH_URL', 'https://id.iotec.io/connect/token');
define('IOTEC_API_BASE_URL', 'https://pay.iotec.io/api');
define('IOTEC_CALLBACK_URL', SITE_URL . 'IOTEC/callback.php');

// Database connection function
function getDB() {
  try {
    $pdo = new PDO(
      "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
      DB_USER,
      DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
      ]
    );
    return $pdo;
  } catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
  }
}

function getIotecDB() {
    return getDB();
}
?>
