<?php
// bulk_sms_3hours_may10.php
// 3-HOUR voucher codes — May 10 transactions (UGX 492 = 500)
// Each entry is independent — duplicate numbers get separate codes
// Run: php bulk_sms_3hours_may10.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '3 Hours');

// ─── 7 ENTRIES (transaction order top→bottom, codes top→bottom) ───────────────
$recipientList = [
    ['phone' => '256746860145', 'code' => '733'],  // #14641
    ['phone' => '256702129540', 'code' => '284'],  // #14638
    ['phone' => '256755500160', 'code' => '254'],  // #14637
    ['phone' => '256703027265', 'code' => '392'],  // #14636
    ['phone' => '256755500160', 'code' => '645'],  // #14635 (2nd transaction)
    ['phone' => '256700305852', 'code' => '979'],  // #14633
    ['phone' => '256755882017', 'code' => '268'],  // #14631
];

// Unused code: 747

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getSDK(): CommsSDK {
    return CommsSDK::authenticate(SMS_USERNAME, SMS_API_KEY)
                   ->withSenderId(SMS_SENDER);
}

function buildMessage(string $code, string $package): string {
    return "STK WIFI Kampala: Your " . $package . " voucher code is: " . $code . ". Login: http://8.8.8.8 Thank you!";
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
echo "========================================\n";
echo "  STK WIFI - 3-Hour Voucher SMS (May 10)\n";
echo "  " . count($recipientList) . " entries\n";
echo "========================================\n\n";

$total  = count($recipientList);
$sent   = 0;
$failed = 0;
$errors = [];

echo "Sending to $total entries...\n";
echo "----------------------------------------\n";

foreach ($recipientList as $index => $recipient) {
    $phone   = $recipient['phone'];
    $code    = $recipient['code'];
    $message = buildMessage($code, PACKAGE_NAME);
    $num     = $index + 1;

    echo "[$num/$total] $phone  ->  Code: $code ... ";

    try {
        $success = getSDK()->sendSMS($phone, $message);

        if ($success) {
            echo "SENT\n";
            $sent++;
        } else {
            echo "FAILED\n";
            $failed++;
            $errors[] = "[$num] $phone (code: $code) - SDK returned false";
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $failed++;
        $errors[] = "[$num] $phone (code: $code) - " . $e->getMessage();
    }

    usleep(300000); // 0.3s pause
}

echo "----------------------------------------\n";
echo "DONE.\n";
echo "  Sent:   $sent\n";
echo "  Failed: $failed\n";
echo "  Total:  $total\n";

if (!empty($errors)) {
    echo "\nFailed entries:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

echo "========================================\n";
