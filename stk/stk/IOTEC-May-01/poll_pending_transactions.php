#!/usr/bin/env php
<?php
/**
 * Background Polling Script for IOTEC Transactions
 * 
 * This script runs in the background to check pending IOTEC transactions
 * and update their status, assign vouchers, and send SMS - similar to how
 * the old Yo! Payments IPN system worked.
 * 
 * Run this via cron every 1-2 minutes:
 * Example cron: Every minute run this script and log output
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/voucher_helper.php';
require_once __DIR__ . '/sms_helper.php';
require_once __DIR__ . '/logger.php';

// Prevent multiple instances from running
$lockFile = __DIR__ . '/poll_pending.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    logIotec("Another polling instance is already running, exiting", 'POLLING');
    exit(0);
}

logIotec("=== POLLING STARTED ===", 'POLLING');

try {
    $pdo = getIotecDB();
    logIotec("Database connection established", 'POLLING');
} catch (Exception $e) {
    logIotec("Database connection failed: " . $e->getMessage(), 'POLLING_ERROR');
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}

// Get pending transactions that:
// 1. Have a transaction_ref (IOTEC transaction ID)
// 2. Are still pending
// 3. Were created in the last 24 hours (to avoid checking very old transactions)
// 4. Were created at least 30 seconds ago (give IOTEC time to process)
try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            external_ref, 
            transaction_ref, 
            msisdn,
            amount,
            origin_site,
            voucher_type,
            created_at
        FROM transactions 
        WHERE status = 'pending' 
        AND transaction_ref IS NOT NULL
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND created_at <= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $pendingTransactions = $stmt->fetchAll();
    
    $count = count($pendingTransactions);
    logIotec("Found {$count} pending transactions to check", 'POLLING', ['count' => $count]);
    
    if ($count === 0) {
        logIotec("No pending transactions to process", 'POLLING');
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(0);
    }
    
} catch (PDOException $e) {
    logIotec("Database query error: " . $e->getMessage(), 'POLLING_ERROR');
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}

// Process each pending transaction
$successCount = 0;
$failedCount = 0;
$stillPendingCount = 0;

foreach ($pendingTransactions as $txn) {
    $externalRef = $txn['external_ref'];
    $iotecTxnId = $txn['transaction_ref'];
    $msisdn = $txn['msisdn'];
    
    logIotec("Checking transaction status", 'POLLING', [
        'externalRef' => $externalRef,
        'iotecTxnId' => $iotecTxnId
    ]);
    
    // Query IOTEC API for current status
    $apiResponse = makeIotecApiRequest('GET', '/collections/status/' . $iotecTxnId);
    
    if (isset($apiResponse['error'])) {
        logIotec("IOTEC API error for transaction", 'POLLING_ERROR', [
            'externalRef' => $externalRef,
            'error' => $apiResponse['error']
        ]);
        continue;
    }
    
    $apiStatus = strtolower($apiResponse['status'] ?? '');
    $statusMessage = $apiResponse['statusMessage'] ?? '';
    
    logIotec("IOTEC API returned status", 'POLLING', [
        'externalRef' => $externalRef,
        'apiStatus' => $apiStatus,
        'statusMessage' => $statusMessage
    ]);
    
    // Handle SUCCESS status
    if ($apiStatus === 'success') {
        try {
            // Calculate 4% telecom fee for IOTEC API (STK)
            $telecomFee = round($txn['amount'] * 0.04, 2);
            
            // Calculate platform fee - first successful transaction of the day per origin_site
            $platformFee = 0;
            $originSite = $txn['origin_site'];
            
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
                    logIotec("First transaction today for $originSite - applying platform fee: $platformFee", 'POLLING_PLATFORM_FEE');
                }
            }
            
            // Update transaction to success
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
            $stmt->execute([$statusMessage, $telecomFee, $platformFee, $externalRef]);
            
            if ($stmt->rowCount() > 0) {
                logIotec("Transaction updated to SUCCESS", 'POLLING_SUCCESS', [
                    'externalRef' => $externalRef,
                    'telecomFee' => $telecomFee,
                    'platformFee' => $platformFee
                ]);
                
                // Check if this is the special 10,000/= 7-day voucher package
                $txnAmount = (int)$txn['amount'];
                $voucherType = $txn['voucher_type'];
                $isSpecialPackage = ($txnAmount == 10000 && $voucherType == '7days');
                
                if ($isSpecialPackage) {
                    // Special handling: Assign TWO vouchers for 10,000/= 7-day package
                    logIotec("Detected special 10,000/= 7-day package. Assigning TWO vouchers", 'POLLING_VOUCHER', ['externalRef' => $externalRef]);
                    $voucherResult = assignTwoVouchersToTransaction($externalRef, $pdo);
                    
                    if ($voucherResult['success']) {
                        logIotec("TWO vouchers assigned successfully", 'POLLING_VOUCHER', [
                            'externalRef' => $externalRef,
                            'voucherCodes' => $voucherResult['voucherCodes']
                        ]);
                        
                        // Send SMS with BOTH voucher codes
                        $smsResult = sendTwoVouchersSMS($msisdn, $voucherResult['voucherCodes'], '7 Days');
                        
                        if ($smsResult['success']) {
                            logIotec("SMS sent successfully with 2 vouchers", 'POLLING_SMS', [
                                'externalRef' => $externalRef,
                                'msisdn' => $msisdn,
                                'voucherCodes' => $voucherResult['voucherCodes']
                            ]);
                        } else {
                            logIotec("SMS sending failed", 'POLLING_SMS_ERROR', [
                                'externalRef' => $externalRef,
                                'msisdn' => $msisdn,
                                'error' => $smsResult['message']
                            ]);
                        }
                        
                        $successCount++;
                    } else {
                        logIotec("TWO vouchers assignment failed", 'POLLING_VOUCHER_ERROR', [
                            'externalRef' => $externalRef,
                            'error' => $voucherResult['error']
                        ]);
                        $successCount++; // Still count as success since payment succeeded
                    }
                } else {
                    // Normal handling: Assign ONE voucher
                    $voucherResult = assignVoucherToTransaction($externalRef, $pdo);
                    
                    if ($voucherResult['success']) {
                        $voucherCode = $voucherResult['voucherCode'];
                        logIotec("Voucher assigned successfully", 'POLLING_VOUCHER', [
                            'externalRef' => $externalRef,
                            'voucherCode' => $voucherCode
                        ]);
                        
                        // Send SMS with voucher code
                        $smsResult = sendVoucherSMS($msisdn, $voucherCode, '');
                        
                        if ($smsResult['success']) {
                            logIotec("SMS sent successfully", 'POLLING_SMS', [
                                'externalRef' => $externalRef,
                                'msisdn' => $msisdn,
                                'voucherCode' => $voucherCode
                            ]);
                        } else {
                            logIotec("SMS sending failed", 'POLLING_SMS_ERROR', [
                                'externalRef' => $externalRef,
                                'msisdn' => $msisdn,
                                'error' => $smsResult['message']
                            ]);
                        }
                        
                        $successCount++;
                    } else {
                        logIotec("Voucher assignment failed", 'POLLING_VOUCHER_ERROR', [
                            'externalRef' => $externalRef,
                            'error' => $voucherResult['error']
                        ]);
                        $successCount++; // Still count as success since payment succeeded
                    }
                }
            }
            
        } catch (PDOException $e) {
            logIotec("Database update error", 'POLLING_ERROR', [
                'externalRef' => $externalRef,
                'error' => $e->getMessage()
            ]);
        }
    }
    // Handle FAILED/DECLINED status
    elseif ($apiStatus === 'failed' || $apiStatus === 'declined' || $apiStatus === 'cancelled') {
        try {
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'failed',
                    status_message = ?,
                    updated_at = NOW()
                WHERE external_ref = ?
                AND status = 'pending'
            ");
            $stmt->execute([$statusMessage, $externalRef]);
            
            if ($stmt->rowCount() > 0) {
                logIotec("Transaction marked as FAILED", 'POLLING_FAILED', [
                    'externalRef' => $externalRef,
                    'reason' => $statusMessage
                ]);
                $failedCount++;
            }
            
        } catch (PDOException $e) {
            logIotec("Database update error", 'POLLING_ERROR', [
                'externalRef' => $externalRef,
                'error' => $e->getMessage()
            ]);
        }
    }
    // Still pending
    else {
        logIotec("Transaction still pending", 'POLLING', [
            'externalRef' => $externalRef,
            'apiStatus' => $apiStatus
        ]);
        $stillPendingCount++;
    }
    
    // Small delay to avoid overwhelming the API
    usleep(100000); // 100ms delay between requests
}

logIotec("=== POLLING COMPLETED ===", 'POLLING_COMPLETE', [
    'total' => $count,
    'success' => $successCount,
    'failed' => $failedCount,
    'stillPending' => $stillPendingCount
]);

// Release lock
flock($fp, LOCK_UN);
fclose($fp);

exit(0);
?>
