<?php
header('Content-Type: text/plain');

$logDir = __DIR__ . '/logs/';
$today = date('Y-m-d');

echo "=== IOTEC LOGS FOR $today ===\n\n";

$iotecLog = $logDir . 'iotec_' . $today . '.txt';
$callbackLog = $logDir . 'iotec_callback_' . $today . '.txt';

if (file_exists($iotecLog)) {
    echo "--- IOTEC Main Log ---\n";
    echo file_get_contents($iotecLog);
    echo "\n\n";
} else {
    echo "No IOTEC log file found for today: $iotecLog\n\n";
}

if (file_exists($callbackLog)) {
    echo "--- IOTEC Callback Log ---\n";
    echo file_get_contents($callbackLog);
    echo "\n\n";
} else {
    echo "No callback log file found for today: $callbackLog\n\n";
}

echo "=== END OF LOGS ===\n";
?>
