<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'logger.php';
require_once 'voucher_helper.php';

ob_end_clean();

$ref = $_GET['ref'] ?? '';

logIotec("=== STATUS CHECK STARTED ===", 'STATUS', ['ref' => $ref]);

if (empty($ref)) {
    logIotec("Missing transaction reference", 'STATUS_ERROR');
    echo json_encode(['transactionStatus' => -1, 'errorMessage' => 'Missing transaction reference']);
    exit;
}

try {
    $pdo = getIotecDB();
    logIotec("Database connection established", 'STATUS');
} catch (Exception $e) {
    logIotec("Database connection failed: " . $e->getMessage(), 'STATUS_ERROR');
    echo json_encode(['transactionStatus' => -1, 'errorMessage' => 'Database error']);
    exit;
}

$transaction = null;
$iotecTransactionId = null;

try {
    $stmt = $pdo->prepare("
        SELECT status, external_ref, transaction_ref, origin_site, client_mac, voucher_type, email, amount, status_message, voucher_code, msisdn
        FROM transactions
        WHERE external_ref = ? LIMIT 1
    ");
    $stmt->execute([$ref]);
    $transaction = $stmt->fetch();

    if ($transaction) {
        $iotecTransactionId = $transaction['transaction_ref'];
        logIotec("Transaction found by external_ref", 'STATUS', [
            'externalRef' => $transaction['external_ref'],
            'status' => $transaction['status'],
            'voucherCode' => $transaction['voucher_code']
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT status, external_ref, transaction_ref, origin_site, client_mac, voucher_type, email, amount, status_message, voucher_code, msisdn
            FROM transactions
            WHERE transaction_ref = ? LIMIT 1
        ");
        $stmt->execute([$ref]);
        $transaction = $stmt->fetch();
        
        if ($transaction) {
            $iotecTransactionId = $ref;
            logIotec("Transaction found by transaction_ref", 'STATUS', [
                'transactionRef' => $iotecTransactionId,
                'status' => $transaction['status']
            ]);
        } else {
            logIotec("Transaction not found in database", 'STATUS', ['ref' => $ref]);
        }
    }

    if ($transaction && $transaction['status'] === 'success') {
        $voucherCode = $transaction['voucher_code'];
        logIotec("Transaction status is SUCCESS", 'STATUS', ['voucherCode' => $voucherCode]);

        if ($voucherCode) {
            logIotec("Voucher already assigned, returning to frontend", 'STATUS_SUCCESS', ['voucherCode' => $voucherCode]);
            echo json_encode([
                'transactionStatus' => 1,
                'message' => 'Payment successful',
                'voucherCode' => $voucherCode
            ]);
            exit;
        } else {
            logIotec("No voucher assigned yet, assigning now", 'STATUS');
            $voucherResult = assignVoucherToTransaction($transaction['external_ref'], $pdo);
            
            if ($voucherResult['success']) {
                logIotec("Voucher assigned successfully", 'STATUS_SUCCESS', ['voucherCode' => $voucherResult['voucherCode']]);
                
                require_once 'sms_helper.php';
                logIotec("Sending SMS to " . $transaction['msisdn'], 'SMS');
                $smsResult = sendVoucherSMS($transaction['msisdn'], $voucherResult['voucherCode'], '');
                logIotec("SMS send result", 'SMS', ['success' => $smsResult['success'], 'message' => $smsResult['message']]);
                
                echo json_encode([
                    'transactionStatus' => 1,
                    'message' => 'Payment successful',
                    'voucherCode' => $voucherResult['voucherCode']
                ]);
                exit;
            } else {
                logIotec("Voucher assignment failed", 'STATUS_ERROR', ['error' => $voucherResult['error']]);
                echo json_encode([
                    'transactionStatus' => -1,
                    'errorMessage' => 'Voucher assignment failed: ' . $voucherResult['error']
                ]);
                exit;
            }
        }
    } elseif ($transaction && $transaction['status'] === 'failed') {
        logIotec("Transaction status is FAILED", 'STATUS', ['statusMessage' => $transaction['status_message']]);
        echo json_encode([
            'transactionStatus' => -1,
            'errorMessage' => $transaction['status_message'] ?? 'Payment failed'
        ]);
        exit;
    }

} catch (PDOException $e) {
    logIotec("Database query error: " . $e->getMessage(), 'STATUS_ERROR');
}

if (!$iotecTransactionId) {
    logIotec("No IOTEC transaction ID found, returning pending", 'STATUS');
    echo json_encode([
        'transactionStatus' => 0,
        'statusMessage' => 'Transaction pending'
    ]);
    exit;
}

logIotec("Querying IOTEC API for status", 'STATUS', ['iotecTransactionId' => $iotecTransactionId]);
$apiResponse = makeIotecApiRequest('GET', '/collections/status/' . $iotecTransactionId);

if (isset($apiResponse['error'])) {
    logIotec("IOTEC API error during status check", 'STATUS_ERROR', ['error' => $apiResponse['error']]);
    echo json_encode([
        'transactionStatus' => 0,
        'statusMessage' => 'Checking status...'
    ]);
    exit;
}

$apiStatus = strtolower($apiResponse['status'] ?? '');
$statusCode = $apiResponse['statusCode'] ?? '';

logIotec("IOTEC API status received", 'STATUS', ['apiStatus' => $apiStatus, 'statusCode' => $statusCode]);

$frontendStatus = 0;
$frontendMessage = $apiResponse['statusMessage'] ?? '';

if ($apiStatus === 'success') {
    $frontendStatus = 1;
    logIotec("IOTEC API reports SUCCESS", 'STATUS_SUCCESS');
    
    if ($transaction) {
        try {
            // Calculate 4% telecom fee for IOTEC API (STK)
            $amount = (float)$transaction['amount'];
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
                    $platformFee = 2000;
                    logIotec("First transaction today for $originSite - applying platform fee: $platformFee", 'STATUS_PLATFORM_FEE');
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'success',
                    status_message = ?,
                    telecom_fee = ?,
                    platform_fee = ?,
                    updated_at = NOW()
                WHERE external_ref = ?
            ");
            $stmt->execute([$frontendMessage, $telecomFee, $platformFee, $transaction['external_ref']]);
            logIotec("Transaction updated to SUCCESS in database", 'STATUS', [
                'telecomFee' => $telecomFee,
                'platformFee' => $platformFee
            ]);
            
            // Check if this is the special 10,000/= 7-day voucher package
            $txnAmount = (int)$transaction['amount'];
            $voucherType = $transaction['voucher_type'];
            $isSpecialPackage = ($txnAmount == 10000 && $voucherType == '7days');
            
            if ($isSpecialPackage) {
                // Special handling: Assign TWO vouchers for 10,000/= 7-day package
                logIotec("Detected special 10,000/= 7-day package. Assigning TWO vouchers", 'STATUS_VOUCHER');
                $voucherResult = assignTwoVouchersToTransaction($transaction['external_ref'], $pdo);
                
                if ($voucherResult['success']) {
                    logIotec("TWO vouchers assigned successfully", 'STATUS_SUCCESS', ['voucherCodes' => $voucherResult['voucherCodes']]);
                    
                    require_once 'sms_helper.php';
                    logIotec("Sending SMS with 2 voucher codes to " . $transaction['msisdn'], 'SMS');
                    $smsResult = sendTwoVouchersSMS($transaction['msisdn'], $voucherResult['voucherCodes'], '7 Days');
                    logIotec("SMS send result", 'SMS', ['success' => $smsResult['success'], 'message' => $smsResult['message']]);
                    
                    echo json_encode([
                        'transactionStatus' => 1,
                        'message' => 'Payment successful',
                        'voucherCode' => $voucherResult['voucherCodes'][0],
                        'voucherCode2' => $voucherResult['voucherCodes'][1]
                    ]);
                    exit;
                } else {
                    logIotec("TWO vouchers assignment failed", 'STATUS_ERROR', ['error' => $voucherResult['error']]);
                }
            } else {
                // Normal handling: Assign ONE voucher
                logIotec("Assigning voucher", 'STATUS');
                $voucherResult = assignVoucherToTransaction($transaction['external_ref'], $pdo);
                
                if ($voucherResult['success']) {
                    logIotec("Voucher assigned successfully", 'STATUS_SUCCESS', ['voucherCode' => $voucherResult['voucherCode']]);
                    
                    require_once 'sms_helper.php';
                    logIotec("Sending SMS to " . $transaction['msisdn'], 'SMS');
                    $smsResult = sendVoucherSMS($transaction['msisdn'], $voucherResult['voucherCode'], '');
                    logIotec("SMS send result", 'SMS', ['success' => $smsResult['success'], 'message' => $smsResult['message']]);
                    
                    echo json_encode([
                        'transactionStatus' => 1,
                        'message' => 'Payment successful',
                        'voucherCode' => $voucherResult['voucherCode']
                    ]);
                    exit;
                } else {
                    logIotec("Voucher assignment failed", 'STATUS_ERROR', ['error' => $voucherResult['error']]);
                }
            }
        } catch (PDOException $e) {
            logIotec("Database update error: " . $e->getMessage(), 'STATUS_ERROR');
        }
    }
} elseif ($apiStatus === 'failed' || $apiStatus === 'declined') {
    $frontendStatus = -1;
    logIotec("IOTEC API reports FAILED/DECLINED", 'STATUS', ['apiStatus' => $apiStatus]);
    
    if ($transaction) {
        try {
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'failed',
                    status_message = ?,
                    updated_at = NOW()
                WHERE external_ref = ?
            ");
            $stmt->execute([$frontendMessage, $transaction['external_ref']]);
            logIotec("Transaction updated to FAILED in database", 'STATUS');
        } catch (PDOException $e) {
            logIotec("Database update error: " . $e->getMessage(), 'STATUS_ERROR');
        }
    }
} else {
    logIotec("Transaction still pending", 'STATUS', ['apiStatus' => $apiStatus]);
}

logIotec("=== STATUS CHECK COMPLETED ===", 'STATUS', ['frontendStatus' => $frontendStatus]);

echo json_encode([
    'transactionStatus' => $frontendStatus,
    'statusMessage' => $frontendMessage
]);
?>
