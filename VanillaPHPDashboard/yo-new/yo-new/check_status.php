<?php
// check_status.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'logger.php'; // Include logger
require './YoAPI.php';

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
    $stmt = $pdo->prepare("
        SELECT status, external_ref, transaction_ref, origin_site, client_mac, voucher_type, email, amount, status_message, voucher_code, family_type, wifi_name
        FROM transactions
        WHERE external_ref = ? LIMIT 1
    ");
    $stmt->execute([$ref]);
    $transaction = $stmt->fetch();

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
        $stmt = $pdo->prepare("
            SELECT status, external_ref, transaction_ref, origin_site, client_mac, voucher_type, email, amount, status_message, voucher_code, family_type, wifi_name
            FROM transactions
            WHERE transaction_ref = ? LIMIT 1
        ");
        $stmt->execute([$ref]);
        $transaction = $stmt->fetch();

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
            'voucher_code' => $transaction['voucher_code'],
            'family_type' => $transaction['family_type'] ?? 'normal'
        ]);

        $voucherCode = $transaction['voucher_code'];
        $familyType = $transaction['family_type'] ?? 'normal';
        $wifiName = $transaction['wifi_name'] ?? null;

        // Check if this is a private router purchase (no voucher expected)
        if ($familyType === 'private') {
            $logger->success("Private router purchase successful, no voucher needed", [
                'external_ref' => $transaction['external_ref'],
                'wifi_name' => $wifiName
            ]);
            echo json_encode([
                'transactionStatus' => 1,
                'message' => 'Payment successful - Private Router',
                'familyType' => 'private',
                'wifiName' => $wifiName,
                'voucherCode' => null
            ]);
            exit;
        }

        if ($voucherCode) {
            $logger->success("Voucher already assigned, returning to frontend", [
                'voucherCode' => $voucherCode,
                'external_ref' => $transaction['external_ref']
            ]);
            echo json_encode([
                'transactionStatus' => 1,
                'message' => 'Payment successful',
                'voucherCode' => $voucherCode,
                'familyType' => $familyType
            ]);
            exit;
        } else {
            $logger->warning("Status is SUCCESS but voucher_code is missing. Triggering validate.php", [
                'external_ref' => $transaction['external_ref']
            ]);

            $validateUrl = 'https://bitetechsystems.com/yo/validate.php';
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
    } elseif (strcmp($apiStatus, 'FAILED') === 0 || strcmp($apiStatus, 'DECLINED') === 0) {
        $frontendStatus = -1;
        $logger->warning("YO! API reports FAILED/DECLINED", ['apiRef' => $apiRef, 'error' => $apiErrorMessage]);
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