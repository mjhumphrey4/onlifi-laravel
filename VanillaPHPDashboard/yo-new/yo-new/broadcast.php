<?php
// broadcast_sms.php
// Sends ONE broadcast message to each unique customer number
// Run: php broadcast_sms.php

require_once __DIR__ . '/vendor/autoload.php';

use PahappaLimited\CommsSDK\v1\CommsSDK;

define('SMS_USERNAME', 'humphreympairwe');
define('SMS_API_KEY',  '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
define('SMS_SENDER',   'STK WIFI');

// ─── BROADCAST MESSAGE ────────────────────────────────────────────────────────
// Edit this message before running
define('BROADCAST_MESSAGE', "STK WIFI Kampala: We experienced technical difficulties in the past 2 days. You bought a voucher and  deserve a refund to enjoy full service without interruptions. We are committed to giving you the best service. Your refund voucher is on the way. Thank you! For support, call 0786979317.");

// ─── ALL NUMBERS (deduplicated automatically below) ───────────────────────────
$allNumbers = [
    '256756978611',
    '256762474213',
    '256787128193',
    '256779402186',
    '256780318394',
    '256757396416',
    '256743887980',
    '256752751485',
    '256756493636',
    '256773178554',
    '256771266772',
    '256744428376',
    '256758363160',
    '256763733941',
    '256761120389',
    '256730093034',
    '256707646429',
    '256750832807',
    '256748433949',
    '256747019548',
    '256778331377',
    '256755392556',
    '256776409598',
    '256759196316',
    '256748144624',
    '256753996233',
    '256702336554',
    '256762972712',
    '256755881984',
    '256701933713',
    '256758700347',
    '256786928383',
    '256781115594',
    '256704493404',
    '256701954241',
    '256704043723',
    '256767976363',
    '256760516966',
    '256750848071',
    '256744426533',
    '256700305852',
    '256755882017',
    '256777181012',
    '256760471499',
    '256790868722',
    '256740718587',
    '256741698864',
    '256758252027',
    '256787813827',
    '256700727703',
    '256744200147',
    '256789838292',
];

// Deduplicate while preserving order
$uniqueNumbers = array_values(array_unique($allNumbers));

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function getSDK(): CommsSDK {
    return CommsSDK::authenticate(SMS_USERNAME, SMS_API_KEY)
                   ->withSenderId(SMS_SENDER);
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────
echo "========================================\n";
echo "  STK WIFI - Broadcast SMS\n";
echo "  Unique numbers: " . count($uniqueNumbers) . "\n";
echo "========================================\n\n";
echo "Message:\n\"" . BROADCAST_MESSAGE . "\"\n\n";

$total  = count($uniqueNumbers);
$sent   = 0;
$failed = 0;
$errors = [];

echo "Sending to $total unique numbers...\n";
echo "----------------------------------------\n";

foreach ($uniqueNumbers as $index => $phone) {
    $num = $index + 1;
    echo "[$num/$total] $phone ... ";

    try {
        $success = getSDK()->sendSMS($phone, BROADCAST_MESSAGE);

        if ($success) {
            echo "SENT\n";
            $sent++;
        } else {
            echo "FAILED\n";
            $failed++;
            $errors[] = "[$num] $phone - SDK returned false";
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $failed++;
        $errors[] = "[$num] $phone - " . $e->getMessage();
    }

    usleep(300000); // 0.3s pause
}

echo "----------------------------------------\n";
echo "DONE.\n";
echo "  Sent:   $sent\n";
echo "  Failed: $failed\n";
echo "  Total:  $total\n";

if (!empty($errors)) {
    echo "\nFailed numbers:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

echo "\nNow run your voucher scripts:\n";
echo "  php bulk_sms_12hours.php\n";
echo "  php bulk_sms_3hours.php\n";
echo "========================================\n";
