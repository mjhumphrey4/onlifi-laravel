<?php
// ipn.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

require './YoAPI.php';
require_once 'config.php';
require_once 'voucher_helper.php';
require_once 'sms_helper.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: POST');
    exit;
}

// Set header immediately to acknowledge receipt
header('HTTP/1.1 200 OK');

// Function to log to a dedicated file in ./logs/
function logIPN($message, $type = 'INFO') {
    $logFile = './logs/ipn_log_' . date('Y-m-d') . '.txt'; // Store in ./logs/ subdirectory
    // Ensure logs directory exists relative to script location
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        // Create the logs directory with appropriate permissions (e.g., 0755)
        // The third parameter 'true' allows creating parent directories recursively if needed
        if (!mkdir($logDir, 0755, true)) {
            // If directory creation fails, log to error log as fallback
            error_log("IPN: Failed to create logs directory: $logDir");
            return; // Exit if we can't create the directory
        }
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message\n";
    // Use FILE_APPEND flag to add to the file, LOCK_EX for thread safety
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Log that IPN was called
logIPN("Received POST request - Data: " . print_r($_POST, true), 'RECEIVED');

if (isset($_POST)) {
    // Use credentials and mode from config.php
    $username = YOAPI_USERNAME;
    $password = YOAPI_PASSWORD;
    $mode = YOAPI_MODE;

    $yoAPI = new YoAPI($username, $password, $mode);
    $response = $yoAPI->receive_payment_notification();

    if ($response['is_verified']) {
        logIPN("VERIFIED SUCCESS for external_ref: " . $response['external_ref'], 'VERIFIED');

        $msisdn = $response['msisdn'];
        $dateTime = $response['date_time'];
        $narrative = $response['narrative'];
        $amount = $response['amount'];
        $networkRef = $response['network_ref'];
        $externalRef = $response['external_ref'];

        // Optional: Log the received details
        logIPN("Details - MSISDN: $msisdn, Amount: $amount, NetworkRef: $networkRef", 'DETAILS');

        // Get database connection
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            $errorMessage = "Database connection failed: " . $e->getMessage();
            error_log("IPN: $errorMessage"); // Still log to server error log
            logIPN($errorMessage, 'ERROR'); // Also log to file
            exit;
        }

        // Check if this is the first successful transaction today for this origin_site
        $platformFee = 0;
        try {
            // Get the origin_site for this transaction
            $siteStmt = $pdo->prepare("SELECT origin_site FROM transactions WHERE external_ref = ?");
            $siteStmt->execute([$externalRef]);
            $originSite = $siteStmt->fetchColumn();
            
            if ($originSite) {
                // Check if there are any successful transactions today for this origin_site
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
                    logIPN("First transaction today for $originSite - applying platform fee: $platformFee", 'PLATFORM_FEE');
                }
            }
        } catch (PDOException $e) {
            logIPN("Error checking platform fee: " . $e->getMessage(), 'ERROR');
        }

        // Update the transaction status in the DB
        try {
            // Get origin_site to determine telecom fee rate
            // STK uses IOTEC API with 4% fee, others use 3%
            $siteCheckStmt = $pdo->prepare("SELECT origin_site FROM transactions WHERE external_ref = ?");
            $siteCheckStmt->execute([$externalRef]);
            $originSite = $siteCheckStmt->fetchColumn();
            
            // IOTEC API (STK) charges 4%, others charge 3%
            $telecomFeeRate = ($originSite === 'STK WIFI') ? 0.04 : 0.03;
            
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'success',
                    status_message = ?,
                    telecom_fee = ROUND(amount * :fee_rate, 2),
                    platform_fee = ?,
                    updated_at = NOW()
                WHERE external_ref = ?
            ");
            $stmt->execute([
                'status_message' => $narrative,
                'fee_rate' => $telecomFeeRate,
                'platform_fee' => $platformFee,
                'external_ref' => $externalRef
            ]);

            if ($stmt->rowCount() > 0) {
                logIPN("Database updated successfully for external_ref: $externalRef", 'SUCCESS');
                
                // Immediately assign voucher to this successful transaction
                logIPN("Attempting to assign voucher for external_ref: $externalRef", 'VOUCHER_ASSIGNMENT');
                $voucherResult = assignVoucherToTransaction($externalRef, $pdo);
                
                if ($voucherResult['success']) {
                    // Check if this is a private router purchase
                    if (isset($voucherResult['isPrivateRouter']) && $voucherResult['isPrivateRouter']) {
                        $wifiName = $voucherResult['wifiName'] ?? 'Your Network';
                        logIPN("Private router purchase confirmed for external_ref: $externalRef, WiFi: $wifiName", 'PRIVATE_ROUTER_SUCCESS');
                        
                        // Send SMS notification for private router (no voucher)
                        logIPN("Attempting to send private router SMS to: $msisdn", 'SMS_SEND');
                        $smsMessage = "Thank you for your Family Monthly purchase! Your private router for '$wifiName' will be configured by our team. We will contact you shortly. Support: 0786979317";
                        $smsResult = sendCustomSMS($msisdn, $smsMessage);
                        
                        if ($smsResult['success']) {
                            logIPN("Private router SMS sent successfully to $msisdn", 'SMS_SUCCESS');
                        } else {
                            logIPN("Private router SMS sending failed for $msisdn - Error: " . $smsResult['message'], 'SMS_ERROR');
                        }
                    } else {
                        logIPN("Voucher assigned successfully: " . $voucherResult['voucherCode'] . " for external_ref: $externalRef", 'VOUCHER_SUCCESS');
                        
                        // Send SMS with voucher code to customer
                        logIPN("Attempting to send SMS to: $msisdn", 'SMS_SEND');
                        $smsResult = sendVoucherSMS($msisdn, $voucherResult['voucherCode'], '');
                        
                        if ($smsResult['success']) {
                            logIPN("SMS sent successfully to $msisdn with voucher: " . $voucherResult['voucherCode'], 'SMS_SUCCESS');
                        } else {
                            logIPN("SMS sending failed for $msisdn - Error: " . $smsResult['message'], 'SMS_ERROR');
                        }
                    }
                } else {
                    logIPN("Voucher assignment failed for external_ref: $externalRef - Error: " . $voucherResult['error'], 'VOUCHER_ERROR');
                }
            } else {
                logIPN("WARNING - No rows updated for external_ref: $externalRef", 'WARNING');
            }
        } catch (PDOException $e) {
            $errorMessage = "Database update error: " . $e->getMessage();
            error_log("IPN: $errorMessage"); // Still log to server error log
            logIPN($errorMessage, 'ERROR'); // Also log to file
        }

        // Optional: Trigger an SMS response back to the user via Yo!
        // $message = "Thank you for your payment!";
        // echo 'narrative=' . urlencode($message);

    } else {
        logIPN("VERIFICATION FAILED. POST  " . print_r($_POST, true), 'VERIFICATION_FAILED');
        // Do NOT update the database if verification fails.
    }
} else {
    logIPN("Received request with no POST data.", 'NO_POST_DATA');
}

exit;
?>