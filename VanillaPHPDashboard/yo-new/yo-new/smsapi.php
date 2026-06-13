<?php
// smsapi.php - Test/Example file for SMS API
// This file demonstrates how to use the CommsSDK for sending SMS

require_once 'vendor/autoload.php';
require_once 'sms_helper.php';

//use CommsSDK\V1\CommsSDK;

use PahappaLimited\CommsSDK\v1\CommsSDK;

echo "=== SMS API Test Script ===\n\n";

// Example 2: Check SMS Balance FIRST
echo "1. Checking SMS Balance:\n";
$balanceResult = checkSMSBalance();
if ($balanceResult['success']) {
    echo "Balance: " . $balanceResult['balance'] . " SMS credits\n";
    if ($balanceResult['balance'] <= 0) {
        echo "⚠️  WARNING: Insufficient balance! Please top up your SMS account.\n";
        echo "Visit your SMS provider dashboard to add credits.\n\n";
    } else {
        echo "✓ Balance is sufficient for testing.\n\n";
    }
} else {
    echo "Error: " . $balanceResult['message'] . "\n\n";
}

// Example 1: Using the helper function (RECOMMENDED)
echo "2. Testing SMS Helper Function:\n";
echo "NOTE: Replace '256771234567' with your actual phone number for testing.\n";
// Use a valid Ugandan phone number format - change this to your number
$testNumber = '256771234567'; // CHANGE THIS TO YOUR PHONE NUMBER
$result = sendVoucherSMS($testNumber, 'TEST-VOUCHER-123', '24 Hours');
echo "Result: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
echo "Message: " . $result['message'] . "\n\n";

// Example 3: Direct SDK usage (if you need more control)
echo "3. Direct SDK Usage Examples:\n";

// Authenticate with your username and API key
$sdk = CommsSDK::authenticate('humphreympairwe', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');

// Send SMS to a single number
echo "Sending to single number...\n";
$success = $sdk->sendSMS('256712345678', 'Hello from PHP!');
echo "Result: " . ($success ? 'Sent' : 'Failed') . "\n";

// Send SMS to multiple numbers
echo "Sending to multiple numbers...\n";
$success = $sdk->sendSMS(['256712345678', '256787654321'], 'Hello to all!');
echo "Result: " . ($success ? 'Sent' : 'Failed') . "\n";

// Send SMS with custom sender ID
echo "Sending with custom sender ID...\n";
$sdk = $sdk->withSenderId('STK WIFI');
$success = $sdk->sendSMS(['256712345678'], 'Hello from STK WIFI!');
echo "Result: " . ($success ? 'Sent' : 'Failed') . "\n";

echo "\n=== Test Complete ===\n";
?>