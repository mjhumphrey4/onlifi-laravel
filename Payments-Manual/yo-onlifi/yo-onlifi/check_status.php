<?php
// check_status.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

require_once 'config.php';
require_once 'logger.php'; // Include logger

handleCorsPreflight();

require './YoAPI.php';
require_once 'payment_fallback_helper.php';
require_once 'voucher_helper.php';
require_once 'sms_helper.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$ref = $_GET['ref'] ?? '';

$logger->info("check_status.php started", ['ref' => $ref, 'method' => $_SERVER['REQUEST_METHOD']]);

if (empty($ref)) {
    $logger->warning("Missing transaction reference");
    echo json_encode(['transactionStatus' => -1, 'errorMessage' => 'Missing transaction reference']);
    exit;
}

try {
    $pdo = getDB();
    $logger->debug("Database connection established");
} catch (Exception $e) {
    $logger->error("Database connection failed", ['error' => $e->getMessage()]);
    echo json_encode(['transactionStatus' => -1, 'errorMessage' => 'Database error']);
    exit;
}

$transaction = null;
$foundBy = null;
$apiRef = $ref;

try {
    // Try finding by external_ref first
    $transaction = fetchTransactionBy($pdo, 'external_ref', $ref);

    if ($transaction) {
        $logger->debug("Transaction found by external_ref", [
            'external_ref' => $transaction['external_ref'],
            'status' => $transaction['status'],
            'voucher_code' => $transaction['voucher_code'],
            'client_mac' => $transaction['client_mac']
        ]);

        if ($transaction['transaction_ref']) {
            $apiRef = $transaction['transaction_ref'];
            $foundBy = 'external_ref';
        }
    } else {
        // Try by transaction_ref
        $transaction = fetchTransactionBy($pdo, 'transaction_ref', $ref);

        if ($transaction) {
            $logger->debug("Transaction found by transaction_ref", [
                'transaction_ref' => $transaction['transaction_ref'],
                'status' => $transaction['status'],
                'voucher_code' => $transaction['voucher_code']
            ]);
            $foundBy = 'transaction_ref';
        } else {
            $logger->warning("No transaction found in database", ['ref' => $ref]);
        }
    }

    // If transaction is marked as 'success'
    if ($transaction && $transaction['status'] === 'success') {
        $logger->info("Transaction marked as SUCCESS in database", [
            'external_ref' => $transaction['external_ref'],
            'voucher_code' => $transaction['voucher_code']
        ]);

        $voucherCode = $transaction['voucher_code'];

        if ($voucherCode) {
            $smsResult = sendTransactionVoucherSms($pdo, $transaction, [$voucherCode]);
            $logger->success("Voucher already assigned, returning to frontend", [
                'voucherCode' => $voucherCode,
                'external_ref' => $transaction['external_ref'],
                'sms_sent' => $smsResult['success'] ?? false,
                'sms_message' => $smsResult['message'] ?? null,
            ]);
            echo json_encode([
                'transactionStatus' => 1,
                'message' => 'Payment successful',
                'voucherCode' => $voucherCode
            ]);
            exit;
        } else {
            $logger->warning("Status is SUCCESS but voucher_code is missing. Triggering validate.php", [
                'external_ref' => $transaction['external_ref']
            ]);

            $validateUrl = SITE_URL . 'validate.php';
            $validateParams = http_build_query([
                'external_ref' => $transaction['external_ref'],
                'background_call' => 1
            ]);
            $fullValidateUrl = $validateUrl . '?' . $validateParams;

            $logger->debug("Calling validate.php", ['url' => $fullValidateUrl]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullValidateUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            $validateResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $logger->debug("validate.php response received", [
                'httpCode' => $httpCode,
                'response' => $validateResponse,
                'curlError' => $curlError
            ]);

            if ($curlError) {
                $logger->error("cURL error calling validate.php", ['error' => $curlError]);
                echo json_encode([
                    'transactionStatus' => -1,
                    'errorMessage' => 'Error processing voucher assignment.'
                ]);
                exit;
            }

            if ($httpCode !== 200) {
                $logger->error("validate.php returned non-200 HTTP code", [
                    'httpCode' => $httpCode,
                    'response' => $validateResponse
                ]);
                echo json_encode([
                    'transactionStatus' => -1,
                    'errorMessage' => 'Voucher assignment service error.'
                ]);
                exit;
            }

            $validateData = json_decode($validateResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $logger->error("Failed to decode validate.php JSON response", [
                    'response' => $validateResponse,
                    'jsonError' => json_last_error_msg()
                ]);
                echo json_encode([
                    'transactionStatus' => -1,
                    'errorMessage' => 'Invalid response from voucher service.'
                ]);
                exit;
            }

            $logger->debug("Decoded validate.php response", $validateData);

            if ($validateData['success'] ?? false) {
                $stmt = $pdo->prepare("SELECT voucher_code FROM transactions WHERE external_ref = ? LIMIT 1");
                $stmt->execute([$transaction['external_ref']]);
                $updatedTransaction = $stmt->fetch();
                $newVoucherCode = $updatedTransaction['voucher_code'] ?? null;

                if ($newVoucherCode) {
                    $logger->success("Voucher assigned via validate.php", [
                        'voucherCode' => $newVoucherCode,
                        'external_ref' => $transaction['external_ref']
                    ]);
                    echo json_encode([
                        'transactionStatus' => 1,
                        'message' => 'Payment successful',
                        'voucherCode' => $newVoucherCode
                    ]);
                    exit;
                } else {
                    $logger->error("validate.php succeeded but voucher_code not in database", [
                        'external_ref' => $transaction['external_ref']
                    ]);
                    echo json_encode([
                        'transactionStatus' => -1,
                        'errorMessage' => 'Voucher assignment failed internally.'
                    ]);
                    exit;
                }
            } else {
                $errorMsg = $validateData['error'] ?? 'Unknown error during voucher assignment.';
                $logger->error("validate.php reported failure", [
                    'error' => $errorMsg,
                    'external_ref' => $transaction['external_ref']
                ]);
                echo json_encode([
                    'transactionStatus' => -1,
                    'errorMessage' => $errorMsg
                ]);
                exit;
            }
        }
    } elseif ($transaction && $transaction['status'] === 'failed') {
        $logger->warning("Transaction marked as FAILED in database", [
            'external_ref' => $transaction['external_ref'],
            'message' => $transaction['status_message']
        ]);
        
        // Check if there's a fallback to IOTEC
        if (!empty($transaction['fallback_ref'])) {
            $logger->info("Failed transaction has IOTEC fallback, checking status", [
                'fallback_ref' => $transaction['fallback_ref']
            ]);
            
            $fallbackStatus = checkIOTECFallbackStatus($transaction['fallback_ref'], $logger);
            
            if ($fallbackStatus['status'] === 1) {
                // Fallback succeeded!
                $logger->success("IOTEC fallback succeeded, returning voucher", [
                    'voucher_code' => $fallbackStatus['voucher_code']
                ]);
                
                // Update original transaction
                try {
                    $stmt = $pdo->prepare("
                        UPDATE transactions
                        SET status = 'success',
                            voucher_code = ?,
                            status_message = ?
                        WHERE external_ref = ?
                    ");
                    $stmt->execute([$fallbackStatus['voucher_code'], 'Completed via backup service', $transaction['external_ref']]);
                } catch (Exception $e) {
                    $logger->error("Failed to update original transaction", ['error' => $e->getMessage()]);
                }
                
                echo json_encode([
                    'transactionStatus' => 1,
                    'statusMessage' => $fallbackStatus['message'],
                    'voucherCode' => $fallbackStatus['voucher_code']
                ]);
                exit;
                
            } elseif ($fallbackStatus['status'] === -1) {
                // Fallback also failed
                $logger->error("Both YO and IOTEC services failed");
                echo json_encode([
                    'transactionStatus' => -1,
                    'errorMessage' => 'Both payment services failed: ' . $fallbackStatus['message']
                ]);
                exit;
                
            } else {
                // Fallback still pending
                $logger->info("IOTEC fallback still processing");
                echo json_encode([
                    'transactionStatus' => 2,
                    'statusMessage' => $fallbackStatus['message']
                ]);
                exit;
            }
        }
        
        // No fallback, return the failure
        echo json_encode([
            'transactionStatus' => -1,
            'errorMessage' => $transaction['status_message'] ?? 'Payment failed'
        ]);
        exit;
    }

} catch (PDOException $e) {
    $logger->error("Database query error", ['error' => $e->getMessage()]);
}

// Check YO! API as backup
$logger->debug("Checking YO! API as backup", ['apiRef' => $apiRef]);

$yoAPI = new YoAPI(YOAPI_USERNAME, YOAPI_PASSWORD, YOAPI_MODE);

try {
    $apiResponse = $yoAPI->ac_transaction_check_status($apiRef);
    $logger->debug("YO! API response received", $apiResponse);

    $apiStatus = $apiResponse['TransactionStatus'] ?? '';
    $apiMessage = $apiResponse['StatusMessage'] ?? '';
    $apiErrorMessage = $apiResponse['ErrorMessage'] ?? '';

    $frontendStatus = 0;
    $frontendMessage = $apiMessage;
    $frontendErrorMessage = $apiErrorMessage;

    if (strcmp($apiStatus, 'SUCCEEDED') === 0) {
        $frontendStatus = 1;
        $logger->info("YO! API reports SUCCEEDED", ['apiRef' => $apiRef]);

        if ($transaction) {
            updateTransaction($pdo, $transaction['external_ref'], [
                'status' => 'success',
                'status_message' => $apiMessage ?: 'Payment successful',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $voucherResult = assignVoucherToTransaction($transaction['external_ref'], $pdo);

            if ($voucherResult['success'] ?? false) {
                $voucherCode = $voucherResult['voucherCode'];
                $smsResult = $voucherResult['sms'] ?? ['success' => false, 'message' => 'SMS result unavailable'];

                $response = [
                    'transactionStatus' => 1,
                    'statusMessage' => 'Payment successful',
                    'errorMessage' => '',
                    'voucherCode' => $voucherCode,
                ];

                $logger->success("Voucher assigned from check_status recovery path", [
                    'external_ref' => $transaction['external_ref'],
                    'voucherCode' => $voucherCode,
                    'sms_sent' => $smsResult['success'] ?? false,
                    'sms_message' => $smsResult['message'] ?? null,
                ]);

                echo json_encode($response);
                exit;
            }

            $logger->error("check_status recovery voucher assignment failed", [
                'external_ref' => $transaction['external_ref'],
                'error' => $voucherResult['error'] ?? 'Unknown error',
            ]);

            echo json_encode([
                'transactionStatus' => -1,
                'errorMessage' => $voucherResult['error'] ?? 'Voucher assignment failed',
            ]);
            exit;
        }
    } elseif (strcmp($apiStatus, 'FAILED') === 0 || strcmp($apiStatus, 'DECLINED') === 0) {
        $logger->warning("YO! API reports FAILED/DECLINED - Attempting IOTEC fallback", ['apiRef' => $apiRef, 'error' => $apiErrorMessage]);
        
        // Check if this is a Data Invalid error or any failure - trigger fallback
        if ($transaction && empty($transaction['fallback_ref'])) {
            $logger->info("Initiating automatic fallback to IOTEC API");
            
            $fallbackResult = retryPaymentWithIOTEC($transaction, $pdo, $logger);
            
            if ($fallbackResult['success']) {
                // Fallback initiated successfully - return pending status with special message
                $frontendStatus = 2; // Special status code for "retrying with backup"
                $frontendMessage = 'Primary service failed. Retrying with backup payment service...';
                $frontendErrorMessage = '';
                
                $logger->info("Fallback initiated, returning status 2 to frontend", [
                    'iotec_ref' => $fallbackResult['iotec_external_ref']
                ]);
            } else {
                // Fallback also failed
                $frontendStatus = -1;
                $frontendMessage = 'Both payment services failed';
                $frontendErrorMessage = $apiErrorMessage;
                $logger->error("Both YO and IOTEC services failed", ['error' => $fallbackResult['error']]);
            }
        } elseif ($transaction && !empty($transaction['fallback_ref'])) {
            // Already has a fallback - check its status
            $logger->info("Checking existing IOTEC fallback status", ['fallback_ref' => $transaction['fallback_ref']]);
            
            $fallbackStatus = checkIOTECFallbackStatus($transaction['fallback_ref'], $logger);
            
            if ($fallbackStatus['status'] === 1) {
                // Fallback succeeded!
                $frontendStatus = 1;
                $frontendMessage = $fallbackStatus['message'];
                $frontendErrorMessage = '';
                
                // Update original transaction
                try {
                    $stmt = $pdo->prepare("
                        UPDATE transactions
                        SET status = 'success',
                            voucher_code = ?,
                            status_message = ?
                        WHERE external_ref = ?
                    ");
                    $stmt->execute([$fallbackStatus['voucher_code'], 'Completed via backup service', $transaction['external_ref']]);
                } catch (Exception $e) {
                    $logger->error("Failed to update original transaction with fallback success", ['error' => $e->getMessage()]);
                }
                
                $response = [
                    'transactionStatus' => $frontendStatus,
                    'statusMessage' => $frontendMessage,
                    'errorMessage' => $frontendErrorMessage,
                    'voucherCode' => $fallbackStatus['voucher_code']
                ];
                
                $logger->success("Fallback payment succeeded, returning voucher to frontend", $response);
                echo json_encode($response);
                exit;
                
            } elseif ($fallbackStatus['status'] === -1) {
                // Fallback failed
                $frontendStatus = -1;
                $frontendMessage = $fallbackStatus['message'];
                $frontendErrorMessage = 'Both payment services failed: ' . $apiErrorMessage;
            } else {
                // Fallback still pending
                $frontendStatus = 2;
                $frontendMessage = $fallbackStatus['message'];
                $frontendErrorMessage = '';
            }
        } else {
            // No transaction found, return standard failure
            $frontendStatus = -1;
        }
    } else {
        $logger->debug("YO! API reports PENDING or unknown status", ['status' => $apiStatus]);
    }

    $response = [
        'transactionStatus' => $frontendStatus,
        'statusMessage' => $frontendMessage,
        'errorMessage' => $frontendErrorMessage
    ];

    $logger->info("Sending response to frontend", $response);
    echo json_encode($response);

} catch (Exception $e) {
    $logger->error("YO! API check failed", ['error' => $e->getMessage()]);
    echo json_encode([
        'transactionStatus' => -1,
        'errorMessage' => 'Error checking status with payment provider.'
    ]);
}
?>
