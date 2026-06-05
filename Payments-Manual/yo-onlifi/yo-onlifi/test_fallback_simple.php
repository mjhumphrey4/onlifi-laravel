<?php
/**
 * Simple Fallback Test - Simulates check_status.php returning status 2
 * Access this via: http://pay.onlustech.com/yo/test_fallback_simple.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simulate what check_status.php returns when fallback is triggered
$response = [
    'transactionStatus' => 2,  // Status 2 = Retrying with backup
    'statusMessage' => 'Primary service failed. Retrying with backup payment service...',
    'errorMessage' => ''
];

echo json_encode($response);
?>
