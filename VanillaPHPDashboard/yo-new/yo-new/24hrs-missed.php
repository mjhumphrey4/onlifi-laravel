<?php
// bulk_sms_24hours_may05.php
// 24-HOUR voucher codes — May 5 transactions (UGX 985 = 1000)
// Each entry is independent — duplicate numbers get separate codes
// Run: php bulk_sms_24hours_may05.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '24 Hours');

// ─── 18 ENTRIES (transaction order top→bottom, PDF codes [1–18]) ──────────────
$recipientList = [
    ['phone' => '256787461348', 'code' => '6938'],  // #13858 - PDF[1]
    ['phone' => '256750958550', 'code' => '2853'],  // #13857 - PDF[2]
    ['phone' => '256773749220', 'code' => '5865'],  // #13855 - PDF[3]
    ['phone' => '256703837246', 'code' => '3233'],  // #13853 - PDF[4]
    ['phone' => '256750792919', 'code' => '6795'],  // #13852 - PDF[5]
    ['phone' => '256756144440', 'code' => '3437'],  // #13848 - PDF[6]
    ['phone' => '256756144440', 'code' => '2873'],  // #13847 - PDF[7] (2nd transaction)
    ['phone' => '256756144440', 'code' => '2475'],  // #13846 - PDF[8] (3rd transaction)
    ['phone' => '256742202546', 'code' => '8784'],  // #13845 - PDF[9]
    ['phone' => '256780888818', 'code' => '3423'],  // #13837 - PDF[10]
    ['phone' => '256790531133', 'code' => '7363'],  // #13832 - PDF[11]
    ['phone' => '256749259372', 'code' => '5378'],  // #13831 - PDF[12]
    ['phone' => '256755881984', 'code' => '6286'],  // #13829 - PDF[13]
    ['phone' => '256755881984', 'code' => '4338'],  // #13827 - PDF[14] (2nd transaction)
    ['phone' => '256767341211', 'code' => '5338'],  // #13826 - PDF[15]
    ['phone' => '256758618664', 'code' => '6975'],  // #13823 - PDF[16]
    ['phone' => '256700727703', 'code' => '2355'],  // #13822 - PDF[17]
    ['phone' => '256753733091', 'code' => '7992'],  // #13820 - PDF[18]
];

// Unused PDF codes [19–47]: 7987, 8249, 6825, 2358, 7593, 8788, 4282, 9379, 3356,
// 8278, 6295, 5756, 4524, 7632, 2246, 8235, 4346, 8332, 9535, 5796, 4467, 8494,
// 4675, 7799, 4432, 2969, 4528, 7652, 7439

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
echo "  STK WIFI - 24-Hour Voucher SMS (May 05)\n";
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
