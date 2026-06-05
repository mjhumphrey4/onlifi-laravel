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

// Public URL for callbacks. This follows the deployed folder, e.g.
// https://pay.onlifi.net/ranken/ when the project is hosted at /ranken.
define('SITE_URL', currentSiteUrl());

function currentSiteUrl(): string {
  $host = $_SERVER['HTTP_HOST'] ?? 'pay.onlifi.net';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/ranken/initiate.php';
  $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');

  if ($directory === '' || $directory === '.') {
    $directory = '';
  }

  return 'https://' . $host . $directory . '/';
}

function sendCorsHeaders(): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
  header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
  header('Access-Control-Max-Age: 86400');
}

function handleCorsPreflight(): void {
  sendCorsHeaders();

  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

function readRequestData(): array {
  $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
  $input = file_get_contents('php://input') ?: '';

  if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
    }

    return is_array($data) ? $data : [];
  }

  if (!empty($_POST)) {
    return $_POST;
  }

  $data = [];
  parse_str($input, $data);

  return is_array($data) ? $data : [];
}

function normalizeClientMac($value): ?string {
  $value = trim((string) $value);

  if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $value, $matches)) {
    return strtoupper(str_replace('-', ':', $matches[0]));
  }

  if ($value === '' || strpos($value, '$(') !== false) {
    return null;
  }

  return substr($value, 0, 17);
}

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
    ensureManualPaymentSchema($pdo);

    return $pdo;
  } catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // For API endpoints, return JSON error
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit; // Stop execution after sending error
  }
}

function isValidIdentifier(string $identifier): bool {
  return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
}

function columnExists(PDO $pdo, string $table, string $column): bool {
  if (!isValidIdentifier($table) || !isValidIdentifier($column)) {
    return false;
  }

  $columns = tableColumns($pdo, $table);

  return isset($columns[$column]);
}

function tableExists(PDO $pdo, string $table): bool {
  if (!isValidIdentifier($table)) {
    return false;
  }

  return !empty(tableColumns($pdo, $table));
}

function tableColumns(PDO $pdo, string $table): array {
  static $cache = [];

  if (!isValidIdentifier($table)) {
    return [];
  }

  $key = spl_object_hash($pdo) . ':' . $table;

  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $columns = [];

    foreach ($stmt->fetchAll() as $column) {
      $columns[$column['Field']] = $column;
    }

    $cache[$key] = $columns;
  } catch (PDOException $e) {
    $cache[$key] = [];
  }

  return $cache[$key];
}

function ensureManualPaymentSchema(PDO $pdo): void {
  static $checked = false;

  if ($checked) {
    return;
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS transactions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      external_ref VARCHAR(100) NOT NULL,
      transaction_ref VARCHAR(100) NULL,
      msisdn VARCHAR(20) NOT NULL,
      amount DECIMAL(10,2) NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL DEFAULT 'pending',
      status_message VARCHAR(255) NULL,
      origin_site VARCHAR(100) NULL,
      client_mac VARCHAR(64) NULL,
      email VARCHAR(190) NULL,
      voucher_type VARCHAR(100) NULL,
      voucher_code VARCHAR(64) NULL,
      origin_url TEXT NULL,
      network_ref VARCHAR(100) NULL,
      site_id BIGINT UNSIGNED NULL,
      created_at TIMESTAMP NULL,
      updated_at TIMESTAMP NULL,
      UNIQUE KEY transactions_external_ref_unique (external_ref),
      KEY transactions_status_created_at_index (status, created_at),
      KEY transactions_transaction_ref_index (transaction_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $columns = [
    'external_ref' => 'VARCHAR(100) NULL',
    'transaction_ref' => 'VARCHAR(100) NULL',
    'msisdn' => 'VARCHAR(20) NULL',
    'amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'status' => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
    'status_message' => 'VARCHAR(255) NULL',
    'origin_site' => 'VARCHAR(100) NULL',
    'client_mac' => 'VARCHAR(64) NULL',
    'email' => 'VARCHAR(190) NULL',
    'voucher_type' => 'VARCHAR(100) NULL',
    'voucher_code' => 'VARCHAR(64) NULL',
    'origin_url' => 'TEXT NULL',
    'network_ref' => 'VARCHAR(100) NULL',
    'site_id' => 'BIGINT UNSIGNED NULL',
    'created_at' => 'TIMESTAMP NULL',
    'updated_at' => 'TIMESTAMP NULL',
  ];

  foreach ($columns as $column => $definition) {
    if (!columnExists($pdo, 'transactions', $column)) {
      $pdo->exec("ALTER TABLE transactions ADD COLUMN `$column` $definition");
    }
  }

  try {
    $pdo->exec("ALTER TABLE transactions MODIFY client_mac VARCHAR(64) NULL");
  } catch (PDOException $e) {
    // If the DB user cannot alter existing Laravel tables, normalizeClientMac()
    // still keeps inserted values within the original 17-character column.
  }

  $checked = true;
}

function insertTransaction(PDO $pdo, array $data): void {
  ensureManualPaymentSchema($pdo);

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

  if (!$columns) {
    throw new RuntimeException('The transactions table has none of the expected manual payment columns.');
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
  if (!isValidIdentifier($column) || !columnExists($pdo, 'transactions', $column)) {
    return null;
  }

  $stmt = $pdo->prepare("SELECT * FROM transactions WHERE `$column` = ? LIMIT 1");
  $stmt->execute([$value]);
  $transaction = $stmt->fetch();

  return $transaction ?: null;
}
?>
