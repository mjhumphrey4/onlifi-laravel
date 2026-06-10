<?php
/**
 * IOTEC Payment Callback Handler
 * 
 * This endpoint receives real-time payment notifications from ioTec Pay API.
 * It validates transactions, assigns vouchers, and sends SMS notifications.
 * 
 * ioTec Callback Payload Fields:
 * - id: ioTec transaction ID
 * - externalId: Our external reference (IOTEC_timestamp_uniqid)
 * - status: Transaction status (SentToVendor, Success, Failed, Declined, Cancelled)
 * - statusCode: Status code (pending, success, not-enough-funds, etc.)
 * - statusMessage: Human-readable status message
 * - amount: Transaction amount
 * - payer: Phone number (256XXXXXXXXX)
 * - payerName: Customer name from mobile money
 * - vendor: Mobile money provider (Mtn, Airtel)
 * - vendorTransactionId: Provider's transaction reference
 * - transactionCharge: ioTec charge
 * - vendorCharge: Mobile money provider charge
 * - totalTransactionCharge: Total charges
 * - createdAt: Transaction creation timestamp
 * - processedAt: Transaction completion timestamp (for success)
 */

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

require_once 'config.php';
require_once 'voucher_helper.php';
require_once 'sms_helper.php';

// Immediately acknowledge receipt to ioTec
header('HTTP/1.1 200 OK');
header('Content-Type: application/json');

/**
 * Log callback events to a dedicated file
 */
function logIotecCallback($message, $type = 'INFO', $context = []) {
    $logFile = __DIR__ . '/logs/iotec_callback_' . date('Y-m-d') . '.txt';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            error_log("IOTEC Callback: Failed to create logs directory: $logDir");
            return;
        }
    }
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
    $logEntry = "[$timestamp] [$type] $message$contextStr\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Read raw input
$input = file_get_contents('php://input');
logIotecCallback("Received callback", 'RECEIVED', ['rawData' => substr($input, 0, 1000)]);

// Parse JSON payload
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logIotecCallback("JSON decode error: " . json_last_error_msg(), 'ERROR');
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Extract all relevant fields from ioTec callback
$transactionId = $data['id'] ?? '';
$status = strtolower($data['status'] ?? '');
$statusCode = $data['statusCode'] ?? '';
$externalId = $data['externalId'] ?? '';
$amount = (float)($data['amount'] ?? 0);
$statusMessage = $data['statusMessage'] ?? '';
$payer = $data['payer'] ?? '';
$payerName = $data['payerName'] ?? '';
$vendor = $data['vendor'] ?? '';
$vendorTransactionId = $data['vendorTransactionId'] ?? '';
$transactionCharge = (float)($data['transactionCharge'] ?? 0);
$vendorCharge = (float)($data['vendorCharge'] ?? 0);
$totalTransactionCharge = (float)($data['totalTransactionCharge'] ?? 0);

logIotecCallback("Callback details", 'DETAILS', [
    'iotecId' => $transactionId,
    'status' => $status,
    'statusCode' => $statusCode,
    'externalId' => $externalId,
    'amount' => $amount,
    'payer' => $payer,
    'payerName' => $payerName,
    'vendor' => $vendor,
    'vendorTxnId' => $vendorTransactionId
]);

// Validate required fields
if (empty($externalId)) {
    logIotecCallback("Missing externalId in callback", 'WARNING');
    echo json_encode(['status' => 'error', 'message' => 'Missing externalId']);
    exit;
}

// Connect to database
try {
    $pdo = getIotecDB();
} catch (Exception $e) {
    $errorMessage = "Database connection failed: " . $e->getMessage();
    error_log("IOTEC Callback: $errorMessage");
    logIotecCallback($errorMessage, 'ERROR');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

try {
    // Fetch transaction from database
    $stmt = $pdo->prepare("
        SELECT id, external_ref, msisdn, voucher_code, voucher_type, amount, origin_site, status
        FROM transactions
        WHERE external_ref = ? LIMIT 1
    ");
    $stmt->execute([$externalId]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        logIotecCallback("Transaction not found for externalId: $externalId", 'WARNING');
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
        exit;
    }

    // Skip if already processed
    if ($transaction['status'] === 'success' && $status === 'success') {
        logIotecCallback("Transaction already processed as SUCCESS for externalId: $externalId", 'INFO');
        echo json_encode(['status' => 'ok', 'message' => 'Already processed']);
        exit;
    }

    // Handle SUCCESS status
    if ($status === 'success') {
        logIotecCallback("Processing SUCCESS callback for externalId: $externalId", 'PROCESSING');
        
        // Calculate telecom fee (4% for IOTEC STK push)
        $telecomFee = round($amount * 0.04, 2);
        
        // Calculate platform fee - first successful transaction of the day per origin_site
        $platformFee = 0;
        $originSite = $transaction['origin_site'];
        
        if ($originSite) {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM transactions 
                WHERE origin_site = ? 
                AND status = 'success' 
                AND DATE(created_at) = CURDATE()
            ");
            $checkStmt->execute([$originSite]);
            $todayCount = $checkStmt->fetchColumn();
            
            // If this is the first transaction today, apply platform fee
            if ($todayCount == 0) {
                $platformFee = 500;
                logIotecCallback("First transaction today for $originSite - applying platform fee: $platformFee", 'PLATFORM_FEE');
            }
        }
        
        // Update transaction to success with all fees
        // Note: transaction_ref is already set by initiate.php, don't overwrite it
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET status = 'success',
                status_message = ?,
                telecom_fee = ?,
                platform_fee = ?,
                updated_at = NOW()
            WHERE external_ref = ?
            AND status = 'pending'
        ");
        $stmt->execute([$statusMessage, $telecomFee, $platformFee, $externalId]);
        
        if ($stmt->rowCount() > 0) {
            logIotecCallback("Transaction marked as SUCCESS", 'SUCCESS', [
                'externalId' => $externalId,
                'telecomFee' => $telecomFee,
                'platformFee' => $platformFee,
                'vendorTxnId' => $vendorTransactionId,
                'note' => 'transaction_ref preserved (not overwritten)'
            ]);
            
            // Check if voucher already assigned
            if (!$transaction['voucher_code']) {
                $txnAmount = (int)$transaction['amount'];
                $voucherType = $transaction['voucher_type'];
                $msisdn = $transaction['msisdn'];
                
                // Check if this is the special 10,000/= 7-day voucher package
                $isSpecialPackage = ($txnAmount == 10000 && $voucherType == '7days');
                
                if ($isSpecialPackage) {
                    // Special handling: Assign TWO vouchers for 10,000/= 7-day package
                    logIotecCallback("Detected special 10,000/= 7-day package. Assigning TWO vouchers", 'VOUCHER_ASSIGNMENT', ['externalId' => $externalId]);
                    $voucherResult = assignTwoVouchersToTransaction($externalId, $pdo);
                    
                    if ($voucherResult['success']) {
                        logIotecCallback("TWO vouchers assigned successfully", 'VOUCHER_SUCCESS', [
                            'externalId' => $externalId,
                            'voucherCodes' => $voucherResult['voucherCodes']
                        ]);
                        
                        // Send SMS with BOTH voucher codes to customer
                        logIotecCallback("Attempting to send SMS with 2 voucher codes to: $msisdn", 'SMS_SEND');
                        $smsResult = sendTwoVouchersSMS($msisdn, $voucherResult['voucherCodes'], '7 Days');
                        
                        if ($smsResult['success']) {
                            logIotecCallback("SMS sent successfully with 2 vouchers", 'SMS_SUCCESS', [
                                'msisdn' => $msisdn,
                                'voucherCodes' => $voucherResult['voucherCodes']
                            ]);
                        } else {
                            logIotecCallback("SMS sending failed", 'SMS_ERROR', [
                                'msisdn' => $msisdn,
                                'error' => $smsResult['message']
                            ]);
                        }
                    } else {
                        logIotecCallback("TWO vouchers assignment failed", 'VOUCHER_ERROR', [
                            'externalId' => $externalId,
                            'error' => $voucherResult['error']
                        ]);
                    }
                } else {
                    // Normal handling: Assign ONE voucher for all other packages
                    logIotecCallback("Attempting to assign voucher", 'VOUCHER_ASSIGNMENT', ['externalId' => $externalId]);
                    $voucherResult = assignVoucherToTransaction($externalId, $pdo);
                    
                    if ($voucherResult['success']) {
                        logIotecCallback("Voucher assigned successfully", 'VOUCHER_SUCCESS', [
                            'externalId' => $externalId,
                            'voucherCode' => $voucherResult['voucherCode']
                        ]);
                        
                        // Send SMS with voucher code to customer
                        logIotecCallback("Attempting to send SMS to: $msisdn", 'SMS_SEND');
                        $smsResult = sendVoucherSMS($msisdn, $voucherResult['voucherCode'], '');
                        
                        if ($smsResult['success']) {
                            logIotecCallback("SMS sent successfully", 'SMS_SUCCESS', [
                                'msisdn' => $msisdn,
                                'voucherCode' => $voucherResult['voucherCode']
                            ]);
                        } else {
                            logIotecCallback("SMS sending failed", 'SMS_ERROR', [
                                'msisdn' => $msisdn,
                                'error' => $smsResult['message']
                            ]);
                        }
                    } else {
                        logIotecCallback("Voucher assignment failed", 'VOUCHER_ERROR', [
                            'externalId' => $externalId,
                            'error' => $voucherResult['error']
                        ]);
                    }
                }
            } else {
                logIotecCallback("Voucher already assigned", 'INFO', [
                    'externalId' => $externalId,
                    'voucherCode' => $transaction['voucher_code']
                ]);
            }
        } else {
            logIotecCallback("No rows updated - transaction may have already been processed", 'WARNING', ['externalId' => $externalId]);
        }
    }
    // Handle FAILED/DECLINED/CANCELLED status
    elseif ($status === 'failed' || $status === 'declined' || $status === 'cancelled') {
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET status = 'failed',
                status_message = ?,
                updated_at = NOW()
            WHERE external_ref = ?
            AND status = 'pending'
        ");
        $stmt->execute([$statusMessage ?: $statusCode, $externalId]);
        
        if ($stmt->rowCount() > 0) {
            logIotecCallback("Transaction marked as FAILED", 'FAILED', [
                'externalId' => $externalId,
                'reason' => $statusMessage ?: $statusCode,
                'statusCode' => $statusCode
            ]);
        }
    }
    // Handle intermediate statuses (SentToVendor, Pending, etc.)
    else {
        logIotecCallback("Intermediate status received", 'INFO', [
            'externalId' => $externalId,
            'status' => $status,
            'statusCode' => $statusCode
        ]);
    }

    echo json_encode(['status' => 'ok', 'message' => 'Callback processed']);

} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
    error_log("IOTEC Callback: $errorMessage");
    logIotecCallback($errorMessage, 'ERROR');
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}

exit;
?>
