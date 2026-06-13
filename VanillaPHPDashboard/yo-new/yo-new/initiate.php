<?php
// initiate.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require './YoAPI.php'; // Path to the YoAPI class file

// Set headers for JSON response
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 only for debugging

// Read JSON or form-encoded hotspot requests.
$data = onlifiReadInputData();

// Add origin_site validation/checking
// Also add client_mac, email, voucher_type, origin_url
if (!isset($data['amount']) || !isset($data['msisdn'])
    || !isset($data['client_mac']) || !isset($data['voucher_type']) || !isset($data['origin_url'])) {
  error_log("Missing required fields in initiate.php: amount, msisdn, client_mac, voucher_type, origin_url");
  echo json_encode(['error' => 'Missing required fields: amount, msisdn, client_mac, voucher_type, origin_url']);
  exit;
}

$paymentSite = onlifiCurrentSite($data);
if (!$paymentSite) {
  error_log("Unknown payment site in initiate.php: " . json_encode($data));
  echo json_encode(['error' => 'Unknown or inactive payment site']);
  exit;
}

$amount = (float) $data['amount'];
$msisdn = $data['msisdn'];
$originSite = $paymentSite['origin_site'];
$clientMac = $data['client_mac']; // Get client MAC
$email = $data['email'] ?? null; // Get email (optional)
$voucherType = $data['voucher_type']; // Get voucher type
$originUrl = $data['origin_url']; // Get origin URL
$familyType = $data['family_type'] ?? 'normal'; // Get family type (normal or private)
$wifiName = $data['wifi_name'] ?? null; // Get WiFi name for private router

// Optional: Validate origin_site, client_mac, voucher_type, origin_url format if needed
// if (!preg_match('/^[a-zA-Z0-9_-]+$/', $originSite)) { ... }
// if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $clientMac)) { ... }
// if (!filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== null) { ... }

// Validate MSISDN format (basic check for Uganda numbers)
if (!preg_match('/^256\d{9}$/', $msisdn)) {
    error_log("Invalid MSISDN format in initiate.php: $msisdn");
    echo json_encode(['error' => 'Invalid MSISDN format. Use 256XXXXXXXXX (e.g., 256771234567).']);
    exit;
}

// Generate a unique external reference for this transaction
$externalRef = 'TXN_' . time() . '_' . uniqid();

// Get database connection
try {
  $pdo = getDB();
} catch (Exception $e) {
  error_log("Database connection error in initiate.php: " . $e->getMessage());
  echo json_encode(['error' => 'Database connection failed']);
  exit;
}

// Insert initial transaction record into database
// Include the origin_site, client_mac, email, voucher_type, origin_url, family_type, wifi_name columns
// Calculate 3% telecom fee
$telecomFee = round($amount * 0.03, 2);
try {
  $stmt = $pdo->prepare("
      INSERT INTO transactions (external_ref, msisdn, amount, status, origin_site, client_mac, email, voucher_type, origin_url, telecom_fee, family_type, wifi_name)
      VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([$externalRef, $msisdn, $amount, $originSite, $clientMac, $email, $voucherType, $originUrl, $telecomFee, $familyType, $wifiName]);
  onlifiRecordPaymentTransaction($paymentSite, [
      'external_ref' => $externalRef,
      'transaction_type' => 'collection',
      'phone_number' => $msisdn,
      'amount' => $amount,
      'status' => 'pending',
      'response_message' => 'Awaiting customer approval',
  ]);
  error_log("Transaction inserted into DB: $externalRef from origin site: $originSite, MAC: $clientMac, Type: $voucherType, FamilyType: $familyType, WifiName: $wifiName, Fee: $telecomFee");
} catch (PDOException $e) {
  error_log("Database insert error in initiate.php: " . $e->getMessage());
  echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
  exit;
}

// --- Start YoAPI Specific Code ---
$yoAPI = new YoAPI(YOAPI_USERNAME, YOAPI_PASSWORD, YOAPI_MODE);

// Set the unique external reference generated above
$yoAPI->set_external_reference($externalRef);

// Set non-blocking for instant response
$yoAPI->set_nonblocking("TRUE");

// Define your IPN and Failure URLs using SITE_URL constant
$siteBaseUrl = onlifiSiteBaseUrl($paymentSite);
$ipnUrl = $siteBaseUrl . 'ipn.php';
$failureUrl = $siteBaseUrl . 'failure.php';

// Set the notification URLs
$yoAPI->set_instant_notification_url($ipnUrl);
$yoAPI->set_failure_notification_url($failureUrl);

// Call the deposit function
$response = $yoAPI->ac_deposit_funds($msisdn, $amount, 'Feature Payment'); // Adjust narrative as needed

// --- End YoAPI Specific Code ---

// Check YoAPI response
if($response['Status'] == 'OK') {
    // Transaction initiated successfully
    $yoTransactionRef = $response['TransactionReference'] ?? '';
    $statusCode = $response['StatusCode'] ?? 0;
    $statusMessage = $response['StatusMessage'] ?? '';

    // Update the database with the Yo! transaction reference
    // The other fields (origin_site, client_mac, email, voucher_type, origin_url) were already set during initial insert
    try {
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET transaction_ref = ?, status_message = ? 
            WHERE external_ref = ?
        ");
        $stmt->execute([$yoTransactionRef, $statusMessage, $externalRef]);
        onlifiRecordPaymentTransaction($paymentSite, [
            'external_ref' => $externalRef,
            'transaction_ref' => $yoTransactionRef,
            'transaction_type' => 'collection',
            'phone_number' => $msisdn,
            'amount' => $amount,
            'status' => 'pending',
            'response_message' => $statusMessage,
        ]);
        error_log("Transaction updated in DB with Yo! ref: $yoTransactionRef from origin site: $originSite");
    } catch (PDOException $e) {
        error_log("Database update error in initiate.php: " . $e->getMessage());
        // This is critical, but the transaction might still be pending, so we continue
    }

    // Determine status for frontend
    $frontendStatus = 1; // Assume pending for user action

    echo json_encode([
        'status' => $frontendStatus, // 1 for pending user action
        'transactionReference' => $yoTransactionRef, // Yo!'s transaction reference
        'externalReference' => $externalRef, // Your external reference
        'statusMessage' => $statusMessage,
        'debug' => [
            'yoApiStatusCode' => $statusCode,
            'originSite' => $originSite, // Optionally return origin_site to frontend for debugging
            'siteSlug' => $paymentSite['slug'],
            'note' => 'Check your phone to confirm the payment.'
        ]
    ]);

} else {
    // YoAPI returned an error
    $errorMessage = $response['StatusMessage'] ?? 'Unknown error from Yo! Payments';
    $errorCode = $response['StatusCode'] ?? -1;

    // Update the database to reflect the failure
    // The other fields were already set during initial insert
    try {
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET status = 'failed', status_message = ? 
            WHERE external_ref = ?
        ");
        $stmt->execute([$errorMessage, $externalRef]);
        onlifiRecordPaymentTransaction($paymentSite, [
            'external_ref' => $externalRef,
            'transaction_type' => 'collection',
            'phone_number' => $msisdn,
            'amount' => $amount,
            'status' => 'failed',
            'response_message' => $errorMessage,
        ]);
        error_log("Transaction marked as FAILED in DB: $externalRef from origin site: $originSite. Reason: $errorMessage");
    } catch (PDOException $e) {
        error_log("Database update error on failure in initiate.php: " . $e->getMessage());
    }

    echo json_encode([
        'status' => -1, // Indicate failure
        'errorCode' => $errorCode,
        'errorMessage' => $errorMessage,
        'externalReference' => $externalRef, // Still return the ref for tracking if needed
        'debug' => [
            'originSite' => $originSite, // Optionally return origin_site to frontend for debugging
            'siteSlug' => $paymentSite['slug'],
        ]
    ]);
}
?>
