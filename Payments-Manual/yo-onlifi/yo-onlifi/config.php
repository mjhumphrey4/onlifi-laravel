<?php
// config.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

// Database configuration
define('DB_HOST', '10.200.1.254');
define('DB_PORT', 3306);
define('DB_NAME', 'onlifi_1_1_stk');
define('DB_USER', 'yo');
define('DB_PASS', 'password');

// Site using this manual payment provider.
define('ONLIFI_SITE_ID', 1);
define('ONLIFI_DEFAULT_PROFILE', 'default');

// YO! Uganda API Configuration - Use these names for YoAPI class
define('YOAPI_USERNAME', '100812171094'); // Replace with your Yo! API Username
define('YOAPI_PASSWORD', 'BUid-ZAmO-b2M0-vF6n-CzBK-PBaL-8qJK-6SOf'); // Replace with your Yo! API Password
// Use 'sandbox' for testing, 'production' for live
define('YOAPI_MODE', 'production'); // Change to 'production' when ready to go live

// Your site URL (change to your actual domain)
// Use HTTPS in production
define('SITE_URL', 'https://bitetechsystems.com/yo/'); // Replace with your actual site URL

// Database connection function
function getDB() {
  try {
    $pdo = new PDO(
      "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
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
    // For API endpoints, return JSON error
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit; // Stop execution after sending error
  }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
  $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $stmt->execute([$column]);
  return (bool) $stmt->fetch();
}

function insertTransaction(PDO $pdo, array $data): void {
  $columns = [];
  $placeholders = [];
  $values = [];

  foreach ($data as $column => $value) {
    if (columnExists($pdo, 'transactions', $column)) {
      $columns[] = "`$column`";
      $placeholders[] = '?';
      $values[] = $value;
    }
  }

  $stmt = $pdo->prepare("INSERT INTO transactions (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
  $stmt->execute($values);
}

function updateTransaction(PDO $pdo, string $externalRef, array $data): void {
  $sets = [];
  $values = [];

  foreach ($data as $column => $value) {
    if (columnExists($pdo, 'transactions', $column)) {
      $sets[] = "`$column` = ?";
      $values[] = $value;
    }
  }

  if (!$sets) {
    return;
  }

  $values[] = $externalRef;
  $stmt = $pdo->prepare("UPDATE transactions SET " . implode(', ', $sets) . " WHERE external_ref = ?");
  $stmt->execute($values);
}

function fetchTransactionBy(PDO $pdo, string $column, string $value): ?array {
  $stmt = $pdo->prepare("SELECT * FROM transactions WHERE `$column` = ? LIMIT 1");
  $stmt->execute([$value]);
  $transaction = $stmt->fetch();

  return $transaction ?: null;
}
?>
