<?php
// bulk_sms_24hours_may14.php
// 24-HOUR voucher codes — May 14 transactions (UGX 985 = 1000, UGX 492 = 500)
// Each entry is independent — duplicate numbers get separate codes
// Run: php bulk_sms_24hours_may14.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '24 Hours');

// ─── 23 ENTRIES (transaction order top→bottom, codes top→bottom) ──────────────
$recipientList = [
    ['phone' => '256752316660', 'code' => '6465'],  // #15301 - [1]
    ['phone' => '256772624481', 'code' => '2884'],  // #15299 - [2]
    ['phone' => '256781423701', 'code' => '8674'],  // #15295 - [3]
    ['phone' => '256785702519', 'code' => '2954'],  // #15293 - [4]
    ['phone' => '256785674577', 'code' => '6396'],  // #15292 - [5] (492=500)
    ['phone' => '256767341211', 'code' => '2643'],  // #15287 - [6]
    ['phone' => '256781423701', 'code' => '3662'],  // #15285 - [7] 2nd txn
    ['phone' => '256744426533', 'code' => '2579'],  // #15281 - [8]
    ['phone' => '256701659982', 'code' => '2983'],  // #15280 - [9]
    ['phone' => '256701659982', 'code' => '4925'],  // #15279 - [10] 2nd txn
    ['phone' => '256701740001', 'code' => '4828'],  // #15278 - [11]
    ['phone' => '256701740001', 'code' => '5599'],  // #15277 - [12] 2nd txn
    ['phone' => '256750792919', 'code' => '9676'],  // #15276 - [13]
    ['phone' => '256701802580', 'code' => '8556'],  // #15266 - [14]
    ['phone' => '256744426533', 'code' => '5956'],  // #15265 - [15] 2nd txn
    ['phone' => '256701802580', 'code' => '2495'],  // #15264 - [16] 2nd txn
    ['phone' => '256785674577', 'code' => '9565'],  // #15263 - [17] 2nd txn
    ['phone' => '256752708831', 'code' => '7468'],  // #15262 - [18]
    ['phone' => '256758892038', 'code' => '8295'],  // #15261 - [19]
    ['phone' => '256785858528', 'code' => '3287'],  // #15260 - [20]
    ['phone' => '256777330281', 'code' => '3283'],  // #15258 - [21]
    ['phone' => '256777330281', 'code' => '4975'],  // #15257 - [22] 2nd txn
    ['phone' => '256760097419', 'code' => '6587'],  // #15255 - [23]
];

// Unused code: 3499

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
echo "  STK WIFI - 24-Hour Voucher SMS (May 14)\n";
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
