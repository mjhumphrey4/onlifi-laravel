<?php
// bulk_sms_sender.php
// Sends voucher codes to customers via SMS using CommsSDK
// Each row is its own entry — duplicate numbers each get their own code

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

// ─── CONFIG ───────────────────────────────────────────────────────────────────
define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');
define('PACKAGE_NAME', '3 Hours');

// ─── RECIPIENT LIST ───────────────────────────────────────────────────────────
// Each entry is independent. If a phone appears 3 times, it gets 3 SMS with 3 different codes.
// Order matches: phone list (top->bottom) paired with code list (top->bottom)
$recipientList = [
    ['phone' => '256703837246', 'code' => '4236247'],
    ['phone' => '256750195999', 'code' => '3329222'],
    ['phone' => '256763863765', 'code' => '4958864'],
    ['phone' => '256780445415', 'code' => '5667876'],
    ['phone' => '256779506352', 'code' => '9988368'],
    ['phone' => '256756861468', 'code' => '9838667'],
    ['phone' => '256782033681', 'code' => '3224775'],
    ['phone' => '256790868722', 'code' => '2748484'],
    ['phone' => '256790868722', 'code' => '4745927'],
    ['phone' => '256707255300', 'code' => '3677757'],
    ['phone' => '256756978611', 'code' => '3939527'],
    ['phone' => '256756656887', 'code' => '8978757'],
    ['phone' => '256776843179', 'code' => '3363262'],
    ['phone' => '256776843179', 'code' => '2244266'],
    ['phone' => '256769406931', 'code' => '4237498'],
    ['phone' => '256789253041', 'code' => '2385573'],
    ['phone' => '256701740001', 'code' => '6229238'],
    ['phone' => '256790598973', 'code' => '6396363'],
    ['phone' => '256751642415', 'code' => '5228486'],
    ['phone' => '256756978611', 'code' => '9798765'],
    ['phone' => '256756978611', 'code' => '5868552'],
    ['phone' => '256790598973', 'code' => '9727978'],
    ['phone' => '256756978611', 'code' => '3983292'],
    ['phone' => '256756978611', 'code' => '6454588'],
    ['phone' => '256790598973', 'code' => '5448786'],
    ['phone' => '256756978611', 'code' => '4328532'],
    ['phone' => '256790598973', 'code' => '6538323'],
    ['phone' => '256790598973', 'code' => '6795643'],
];

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getSDK(): CommsSDK {
    return CommsSDK::authenticate(SMS_USERNAME, SMS_API_KEY)
                   ->withSenderId(SMS_SENDER);
}

function buildMessage(string $code, string $package): string {
    return "STK WIFI Kampala: Your 3hours voucher code is -> " . $code . "";
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
echo "========================================\n";
echo "  STK WIFI - Bulk Voucher SMS Sender\n";
echo "========================================\n\n";

// 1. Balance check
echo "Checking SMS balance...\n";
try {
    $balance = getSDK()->getBalance();
    echo "Balance: $balance SMS credits\n";
    if ($balance <= 0) {
        echo "ERROR: Insufficient balance. Please top up and try again.\n";
        exit(1);
    }
    echo "OK: Balance sufficient\n\n";
} catch (Exception $e) {
    echo "WARNING: Could not check balance - " . $e->getMessage() . "\n";
    echo "Proceeding anyway...\n\n";
}

$total  = count($recipientList);
$sent   = 0;
$failed = 0;
$errors = [];

echo "Sending to $total entries (duplicates count separately)...\n";
echo "----------------------------------------\n";

// 2. Send one SMS per entry — no deduplication
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

    usleep(300000); // 0.3s pause between sends to avoid rate limiting
}

// 3. Summary
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
