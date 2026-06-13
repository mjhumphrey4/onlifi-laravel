<?php
// bulk_sms_24hours_apr27.php
// 24-HOUR voucher codes — April 27 transactions (UGX 985 = 1000)
// Each entry is independent — duplicate numbers get separate codes
// Run: php bulk_sms_24hours_apr27.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '24 Hours');

// ─── 8 ENTRIES (transaction order top→bottom, code order top→bottom) ──────────
$recipientList = [
    ['phone' => '256706420830', 'code' => '87334'],  // #12484
    ['phone' => '256766520894', 'code' => '99258'],  // #12482
    ['phone' => '256766520894', 'code' => '87375'],  // #12480 (2nd transaction)
    ['phone' => '256770628890', 'code' => '66669'],  // #12477
    ['phone' => '256781361734', 'code' => '72642'],  // #12476
    ['phone' => '256770628890', 'code' => '89698'],  // #12475 (2nd transaction)
    ['phone' => '256756155162', 'code' => '28742'],  // #12474
    ['phone' => '256756155162', 'code' => '84453'],  // #12473 (2nd transaction)
];

// Unused codes (no matching transactions): 32242, 95296, 42729, 97536, 84978, 88579, 82247, 75855, 33636

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
echo "  STK WIFI - 24-Hour Voucher SMS (Apr 27)\n";
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
