<?php
// config.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

// Central database configuration. The site registry in this DB decides where
// each site's payment transactions and vouchers are written.
define('DB_HOST', getenv('ONLIFI_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('ONLIFI_CENTRAL_DB_NAME') ?: 'payment_mikrotik');
define('DB_USER', getenv('ONLIFI_DB_USER') ?: 'yo');
define('DB_PASS', getenv('ONLIFI_DB_PASS') ?: 'password');
define('CENTRAL_DB_NAME', DB_NAME);

// Admin dashboard credentials.
// In production, prefer environment variables with the same names.
define('ONLIFI_ADMIN_USERNAME', getenv('ONLIFI_ADMIN_USERNAME') ?: 'admin');
define('ONLIFI_ADMIN_PASSWORD', getenv('ONLIFI_ADMIN_PASSWORD') ?: '##12345678Aa');
define('ONLIFI_ADMIN_PASSWORD_HASH', getenv('ONLIFI_ADMIN_PASSWORD_HASH') ?: '');
define('ONLIFI_ADMIN_EMAIL', getenv('ONLIFI_ADMIN_EMAIL') ?: 'admin@payments.onlifi.net');


// YO! Uganda API Configuration - Use these names for YoAPI class
define('YOAPI_USERNAME', '100812171094'); // Replace with your Yo! API Username
define('YOAPI_PASSWORD', 'BUid-ZAmO-b2M0-vF6n-CzBK-PBaL-8qJK-6SOf'); // Replace with your Yo! API Password
// Use 'sandbox' for testing, 'production' for live
define('YOAPI_MODE', 'production'); // Change to 'production' when ready to go live

// Your site URL (change to your actual domain)
// Use HTTPS in production
define('MAIN_PORTAL_URL', getenv('ONLIFI_PAYMENTS_URL') ?: 'https://payments.onlifi.net');

require_once __DIR__ . '/site_registry.php';

if (!defined('SITE_URL')) {
  $siteForUrl = onlifiCurrentSite();
  define('SITE_URL', $siteForUrl ? onlifiSiteBaseUrl($siteForUrl) : rtrim(MAIN_PORTAL_URL, '/') . '/');
}

// Database connection function
function getDB() {
  try {
    $site = onlifiCurrentSite();
    if ($site) {
      return onlifiSitePdo($site);
    }

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
    // For API endpoints, return JSON error
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit; // Stop execution after sending error
  }
}
?>
