<?php
// bulk_sms_3hours_apr25.php
// 3-HOUR voucher codes — April 24/25 transactions (UGX 492 = 500)
// Run: php bulk_sms_3hours_apr25.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '3 Hours');

// ─── 8 ENTRIES (in transaction order, each entry gets its own code) ───────────
$recipientList = [
    ['phone' => '256787128193', 'code' => '4892'],  // #11949
    ['phone' => '256787128193', 'code' => '8733'],  // #11946
    ['phone' => '256750195999', 'code' => '8662'],  // #11943
    ['phone' => '256704493404', 'code' => '2282'],  // #11942
    ['phone' => '256768214096', 'code' => '6366'],  // #11937
    ['phone' => '256790013183', 'code' => '3558'],  // #11931
    ['phone' => '256704493404', 'code' => '4866'],  // #11930
    ['phone' => '256756978611', 'code' => '7693'],  // #11914
];

// Remaining unused codes: 7767, 3249, 6865 — not assigned (no matching transactions)

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
echo "  STK WIFI - 3-Hour Voucher SMS (Apr 25)\n";
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
