<?php
// bulk_sms_24hours_may11.php
// 24-HOUR voucher codes — May 11 transactions (UGX 985 = 1000)
// Each entry is independent — duplicate numbers get separate codes
// Run: php bulk_sms_24hours_may11.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '24 Hours');

// ─── 27 ENTRIES (transaction order top→bottom, codes [1–27]) ──────────────────
$recipientList = [
    ['phone' => '256767688657', 'code' => '6433'],  // #14800 - [1]
    ['phone' => '256765639403', 'code' => '8655'],  // #14799 - [2]
    ['phone' => '256765639403', 'code' => '4665'],  // #14797 - [3] 2nd txn
    ['phone' => '256787899287', 'code' => '8578'],  // #14796 - [4]
    ['phone' => '256730093034', 'code' => '9856'],  // #14781 - [5]
    ['phone' => '256700195566', 'code' => '2226'],  // #14779 - [6]
    ['phone' => '256742184671', 'code' => '5444'],  // #14776 - [7]
    ['phone' => '256700305852', 'code' => '3649'],  // #14775 - [8]
    ['phone' => '256793576030', 'code' => '5978'],  // #14774 - [9]
    ['phone' => '256759628131', 'code' => '5542'],  // #14773 - [10]
    ['phone' => '256784810770', 'code' => '2294'],  // #14771 - [11]
    ['phone' => '256787899287', 'code' => '5855'],  // #14761 - [12] 2nd txn
    ['phone' => '256793576030', 'code' => '3349'],  // #14760 - [13] 2nd txn
    ['phone' => '256784810770', 'code' => '3573'],  // #14757 - [14] 2nd txn
    ['phone' => '256743887980', 'code' => '6465'],  // #14754 - [15]
    ['phone' => '256705545423', 'code' => '5433'],  // #14749 - [16]
    ['phone' => '256786251467', 'code' => '9528'],  // #14748 - [17]
    ['phone' => '256750792919', 'code' => '2884'],  // #14746 - [18]
    ['phone' => '256750792919', 'code' => '8674'],  // #14745 - [19] 2nd txn
    ['phone' => '256774342266', 'code' => '8244'],  // #14744 - [20]
    ['phone' => '256787461348', 'code' => '2954'],  // #14743 - [21]
    ['phone' => '256780888818', 'code' => '7923'],  // #14741 - [22]
    ['phone' => '256771578267', 'code' => '6396'],  // #14737 - [23]
    ['phone' => '256750832807', 'code' => '7789'],  // #14734 - [24]
    ['phone' => '256730581089', 'code' => '7292'],  // #14732 - [25]
    ['phone' => '256776409598', 'code' => '2673'],  // #14728 - [26]
    ['phone' => '256706420830', 'code' => '2643'],  // #14725 - [27]
];

// Unused codes [28–45]: 3662, 2579, 2983, 4925, 4828, 5599, 9676, 8556, 5956,
//                       2495, 9565, 7468, 8295, 3287, 3283, 4975, 6587, 3499

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getSDK(): CommsSDK {
    return CommsSDK::authenticate(SMS_USERNAME, SMS_API_KEY)
                   ->withSenderId(SMS_SENDER);
}

function buildMessage(string $code, string $package): string {
    return "STK WIFI Kampala: Your " . $package . " voucher code is: " . $code . ". Thank you! We apologize for the delays.";
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
echo "========================================\n";
echo "  STK WIFI - 24-Hour Voucher SMS (May 11)\n";
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
