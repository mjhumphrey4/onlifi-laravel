<?php
// bulk_sms_12hours.php
// Sends 12-HOUR voucher codes to UGX 1,000 (and 985) customers
// Run: php bulk_sms_12hours.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '12 Hours');

// ─── 36 RECIPIENTS ────────────────────────────────────────────────────────────
// Phone (from transaction list, UGX 1000 / 985) paired with 12hr PDF codes [1–36]
$recipientList = [
    // PDF# => phone          => 12hr code
    ['phone' => '256756978611', 'code' => '7674'],  // #11762 - [1]
    ['phone' => '256762474213', 'code' => '2646'],  // #11755 - [2]
    ['phone' => '256787128193', 'code' => '4293'],  // #11753 - [3]
    ['phone' => '256779402186', 'code' => '2364'],  // #11751 - [4]
    ['phone' => '256780318394', 'code' => '8584'],  // #11748 - [5]
    ['phone' => '256757396416', 'code' => '8253'],  // #11746 - [6]
    ['phone' => '256743887980', 'code' => '7299'],  // #11722 - [7]
    ['phone' => '256752751485', 'code' => '6833'],  // #11717 - [8]
    ['phone' => '256756493636', 'code' => '4628'],  // #11715 - [9]
    ['phone' => '256773178554', 'code' => '5696'],  // #11713 - [10]
    ['phone' => '256771266772', 'code' => '5547'],  // #11711 - [11]
    ['phone' => '256744428376', 'code' => '4773'],  // #11707 - [12]
    ['phone' => '256758363160', 'code' => '6353'],  // #11705 - [13]
    ['phone' => '256763733941', 'code' => '2494'],  // #11704 - [14]
    ['phone' => '256761120389', 'code' => '6654'],  // #11702 - [15]
    ['phone' => '256761120389', 'code' => '6966'],  // #11700 - [16]
    ['phone' => '256730093034', 'code' => '9728'],  // #11696 - [17]
    ['phone' => '256707646429', 'code' => '9795'],  // #11693 - [18]
    ['phone' => '256750832807', 'code' => '5555'],  // #11689 - [19]
    ['phone' => '256748433949', 'code' => '7997'],  // #11688 - [20]
    ['phone' => '256747019548', 'code' => '7248'],  // #11684 - [21]
    ['phone' => '256778331377', 'code' => '2895'],  // #11683 - [22]
    ['phone' => '256755392556', 'code' => '5672'],  // #11682 - [23]
    ['phone' => '256776409598', 'code' => '8996'],  // #11679 - [24]
    ['phone' => '256759196316', 'code' => '2847'],  // #11672 - [25]
    ['phone' => '256776409598', 'code' => '2345'],  // #11593 - [26]
    ['phone' => '256748144624', 'code' => '6946'],  // #11591 - [27]
    ['phone' => '256707646429', 'code' => '4227'],  // #11590 - [28]
    ['phone' => '256778331377', 'code' => '4344'],  // #11587 - [29]
    ['phone' => '256771266772', 'code' => '8857'],  // #11586 - [30]
    ['phone' => '256779402186', 'code' => '3262'],  // #11585 - [31]
    ['phone' => '256753996233', 'code' => '4743'],  // #11575 - [32]
    ['phone' => '256702336554', 'code' => '7374'],  // #11574 - [33]
    ['phone' => '256762972712', 'code' => '8946'],  // #11568 - [34]
    ['phone' => '256755881984', 'code' => '6382'],  // #11567 - [35]
    ['phone' => '256701933713', 'code' => '3225'],  // #11561 - [36] (985=1000)
];

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getSDK(): CommsSDK {
    return CommsSDK::authenticate(SMS_USERNAME, SMS_API_KEY)
                   ->withSenderId(SMS_SENDER);
}

function buildMessage(string $code, string $package): string {
    return "STK WIFI Kampala: Your " . $package . " Refund Voucher Code is: " . $code . ". Thank you!";
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
echo "========================================\n";
echo "  STK WIFI - 12-Hour Voucher SMS Sender\n";
echo "  UGX 1,000 customers | " . count($recipientList) . " entries\n";
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
