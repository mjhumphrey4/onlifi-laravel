<?php
// config.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'payment_mikrotik');
define('DB_USER', 'yo'); // Change to your MySQL username
define('DB_PASS', 'password'); // Change to your MySQL password


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