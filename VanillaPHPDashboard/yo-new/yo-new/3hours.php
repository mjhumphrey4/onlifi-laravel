<?php
// bulk_sms_3hours.php
// Sends 3-HOUR voucher codes to UGX 500 (and 492) customers
// Run: php bulk_sms_3hours.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '3 Hours');

// ─── 31 RECIPIENTS ────────────────────────────────────────────────────────────
// Phone (from transaction list, UGX 500 / 492) paired with 3hr PDF codes [1–31]
$recipientList = [
    // PDF# => phone          => 3hr code
    ['phone' => '256758700347', 'code' => '4822'],  // #11763 - [1]
    ['phone' => '256786928383', 'code' => '5934'],  // #11758 - [2]
    ['phone' => '256781115594', 'code' => '5559'],  // #11747 - [3]
    ['phone' => '256704493404', 'code' => '3529'],  // #11743 - [4]
    ['phone' => '256701954241', 'code' => '2356'],  // #11737 - [5]
    ['phone' => '256704043723', 'code' => '3673'],  // #11734 - [6]
    ['phone' => '256767976363', 'code' => '9757'],  // #11733 - [7]
    ['phone' => '256760516966', 'code' => '3385'],  // #11731 - [8]
    ['phone' => '256750848071', 'code' => '9624'],  // #11730 - [9]
    ['phone' => '256744426533', 'code' => '5327'],  // #11729 - [10]
    ['phone' => '256781115594', 'code' => '8673'],  // #11723 - [11]
    ['phone' => '256743887980', 'code' => '7764'],  // #11722 - NOTE: a4PG9f was 1000, skip; this slot used for next 500
    ['phone' => '256700305852', 'code' => '4425'],  // #11720 - [12]
    ['phone' => '256786928383', 'code' => '5384'],  // #11719 - [13]
    ['phone' => '256755882017', 'code' => '4457'],  // #11718 - [14]
    ['phone' => '256767976363', 'code' => '3978'],  // #11714 - [15]
    ['phone' => '256777181012', 'code' => '4573'],  // #11706 - [16]
    ['phone' => '256760471499', 'code' => '5792'],  // #11698 - [17]
    ['phone' => '256790868722', 'code' => '6296'],  // #11695 - [18]
    ['phone' => '256740718587', 'code' => '8554'],  // #11692 - [19]
    ['phone' => '256756493636', 'code' => '4439'],  // #11691 - [20]
    ['phone' => '256741698864', 'code' => '8284'],  // #11686 - [21]
    ['phone' => '256758252027', 'code' => '9462'],  // #11678 - [22]
    ['phone' => '256787813827', 'code' => '9598'],  // #11675 - [23]
    ['phone' => '256700305852', 'code' => '6389'],  // #11674 - [24]
    ['phone' => '256700727703', 'code' => '6565'],  // #11673 - [25]
    ['phone' => '256759196316', 'code' => '6354'],  // #11592 - [26]
    ['phone' => '256744200147', 'code' => '6267'],  // #11572 - [27]
    ['phone' => '256777181012', 'code' => '4493'],  // #11569 - [28]
    ['phone' => '256700727703', 'code' => '3265'],  // #11564 - [29] (492=500)
    ['phone' => '256789838292', 'code' => '8976'],  // #11562 - [30] (492=500)
];

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getSDK(): CommsSDK {
    return CommsSDK::authenticate(SMS_USERNAME, SMS_API_KEY)
                   ->withSenderId(SMS_SENDER);
}

function buildMessage(string $code, string $package): string {
    return "STK WIFI Kampala: Your " . $package . " refund voucher code is: " . $code . ". Thank you!";
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
echo "========================================\n";
echo "  STK WIFI - 3-Hour Voucher SMS Sender\n";
echo "  UGX 500 customers | " . count($recipientList) . " entries\n";
echo "========================================\n\n";

$total  = count($recipientList);
$sent   = 0;
$failed = 0;
$errors = [];

echo "Sending to $total recipients...\n";
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
