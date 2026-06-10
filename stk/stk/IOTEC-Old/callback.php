<?php

require_once 'config.php';
require_once 'voucher_helper.php';
require_once 'sms_helper.php';

header('HTTP/1.1 200 OK');

function logIotecCallback($message, $type = 'INFO') {
    $logFile = __DIR__ . '/logs/iotec_callback_' . date('Y-m-d') . '.txt';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("IOTEC Callback: Failed to create logs directory: $logDir");
            return;
        }
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

$input = file_get_contents('php://input');
logIotecCallback("Received callback - Data: " . $input, 'RECEIVED');

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logIotecCallback("JSON decode error: " . json_last_error_msg(), 'ERROR');
    exit;
}

$transactionId = $data['id'] ?? '';
$status = strtolower($data['status'] ?? '');
$externalId = $data['externalId'] ?? '';
$amount = $data['amount'] ?? 0;
$statusMessage = $data['statusMessage'] ?? '';

logIotecCallback("Callback details - ID: $transactionId, Status: $status, ExternalID: $externalId, Amount: $amount", 'DETAILS');

if (empty($externalId)) {
    logIotecCallback("Missing externalId in callback", 'WARNING');
    exit;
}

try {
    $pdo = getIotecDB();
} catch (Exception $e) {
    $errorMessage = "Database connection failed: " . $e->getMessage();
    error_log("IOTEC Callback: $errorMessage");
    logIotecCallback($errorMessage, 'ERROR');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT external_ref, msisdn, voucher_code, status
        FROM transactions
        WHERE external_ref = ? LIMIT 1
    ");
    $stmt->execute([$externalId]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        logIotecCallback("Transaction not found for externalId: $externalId", 'WARNING');
        exit;
    }

    if ($status === 'success') {
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET status = 'success',
                status_message = ?,
                updated_at = NOW()
            WHERE external_ref = ?
        ");
        $stmt->execute([$statusMessage, $externalId]);
        
        logIotecCallback("Transaction marked as SUCCESS for externalId: $externalId", 'SUCCESS');

        if (!$transaction['voucher_code']) {
            logIotecCallback("Attempting to assign voucher for externalId: $externalId", 'VOUCHER_ASSIGNMENT');
            $voucherResult = assignVoucherToTransaction($externalId, $pdo);
            
            if ($voucherResult['success']) {
                logIotecCallback("Voucher assigned successfully: " . $voucherResult['voucherCode'] . " for externalId: $externalId", 'VOUCHER_SUCCESS');
                
                logIotecCallback("Attempting to send SMS to: " . $transaction['msisdn'], 'SMS_SEND');
                $smsResult = sendVoucherSMS($transaction['msisdn'], $voucherResult['voucherCode'], '');
                
                if ($smsResult['success']) {
                    logIotecCallback("SMS sent successfully to " . $transaction['msisdn'] . " with voucher: " . $voucherResult['voucherCode'], 'SMS_SUCCESS');
                } else {
                    logIotecCallback("SMS sending failed for " . $transaction['msisdn'] . " - Error: " . $smsResult['message'], 'SMS_ERROR');
                }
            } else {
                logIotecCallback("Voucher assignment failed for externalId: $externalId - Error: " . $voucherResult['error'], 'VOUCHER_ERROR');
            }
        } else {
            logIotecCallback("Voucher already assigned: " . $transaction['voucher_code'], 'INFO');
        }
    } elseif ($status === 'failed' || $status === 'declined') {
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET status = 'failed',
                status_message = ?,
                updated_at = NOW()
            WHERE external_ref = ?
        ");
        $stmt->execute([$statusMessage, $externalId]);
        
        logIotecCallback("Transaction marked as FAILED for externalId: $externalId - Reason: $statusMessage", 'FAILED');
    } else {
        logIotecCallback("Transaction status update - Status: $status, ExternalID: $externalId", 'INFO');
    }

} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
    error_log("IOTEC Callback: $errorMessage");
    logIotecCallback($errorMessage, 'ERROR');
}

exit;
?>
