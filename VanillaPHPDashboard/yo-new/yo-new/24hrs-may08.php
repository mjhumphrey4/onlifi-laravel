<?php
// bulk_sms_24hours_may08.php
// 24-HOUR voucher codes — May 7-8 transactions (UGX 985 = 1000)
// Each entry is independent — duplicate numbers get separate codes
// Run: php bulk_sms_24hours_may08.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '24 Hours');

// ─── 8 ENTRIES (transaction order top→bottom, codes top→bottom) ───────────────
$recipientList = [
    ['phone' => '256757441098', 'code' => '7632'],  // #14161
    ['phone' => '256758892038', 'code' => '2246'],  // #14159
    ['phone' => '256758892038', 'code' => '8235'],  // #14153 (2nd transaction)
    ['phone' => '256779402186', 'code' => '4346'],  // #14144
    ['phone' => '256773221943', 'code' => '8332'],  // #14143
    ['phone' => '256756978611', 'code' => '9535'],  // #14134
    ['phone' => '256704043723', 'code' => '5796'],  // #14133
    ['phone' => '256747787435', 'code' => '4467'],  // #14131
];

// Unused codes: 8494, 4675, 7799, 4432, 2969, 4528, 7652, 7439

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
echo "  STK WIFI - 24-Hour Voucher SMS (May 08)\n";
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
