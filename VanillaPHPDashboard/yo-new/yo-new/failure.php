<?php
// failure.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

require './YoAPI.php';
require_once 'config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: POST');
    exit;
}

// Set header immediately to acknowledge receipt
header('HTTP/1.1 200 OK');

// Function to log to a dedicated file in ./logs/ (reusable)
function logFailureIPN($message, $type = 'INFO') {
    $logFile = './logs/failure_log_' . date('Y-m-d') . '.txt'; // Store in ./logs/ subdirectory
    // Ensure logs directory exists relative to script location
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        // Create the logs directory with appropriate permissions (e.g., 0755)
        // The third parameter 'true' allows creating parent directories recursively if needed
        if (!mkdir($logDir, 0755, true)) {
            // If directory creation fails, log to error log as fallback
            error_log("Failure IPN: Failed to create logs directory: $logDir");
            return; // Exit if we can't create the directory
        }
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message\n";
    // Use FILE_APPEND flag to add to the file, LOCK_EX for thread safety
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Log that failure notification was called
logFailureIPN("Received POST request - Data: " . print_r($_POST, true), 'RECEIVED');

if (isset($_POST)) {
    // Use credentials and mode from config.php
    $username = YOAPI_USERNAME;
    $password = YOAPI_PASSWORD;
    $mode = YOAPI_MODE;

    $yoAPI = new YoAPI($username, $password, $mode);
    $response = $yoAPI->receive_payment_failure_notification();

    if ($response['is_verified']) {
        logFailureIPN("VERIFIED FAILURE for failed_transaction_reference: " . $response['failed_transaction_reference'], 'VERIFIED');

        $failedTransactionRef = $response['failed_transaction_reference'];
        $transactionInitDate = $response['transaction_init_date'];

        // Optional: Log the received details
        logFailureIPN("Details - Failed Ref: $failedTransactionRef, Init Date: $transactionInitDate", 'DETAILS');

        // Get database connection
        try {
            $pdo = getDB();
        } catch (Exception $e) {
            $errorMessage = "Database connection failed: " . $e->getMessage();
            error_log("Failure IPN: $errorMessage"); // Still log to server error log
            logFailureIPN($errorMessage, 'ERROR'); // Also log to file
            exit;
        }

        // Update the transaction status in the DB
        try {
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'failed',
                    status_message = CONCAT('Transaction failed. Check if you have money on your account or use another number. Failed Ref: ', ?),
                    updated_at = NOW()
                WHERE external_ref = ?
            ");
            $stmt->execute([$failedTransactionRef, $failedTransactionRef]);

            if ($stmt->rowCount() > 0) {
                logFailureIPN("Database updated successfully for external_ref: $failedTransactionRef", 'SUCCESS');
            } else {
                logFailureIPN("WARNING - No rows updated for external_ref: $failedTransactionRef", 'WARNING');
            }
        } catch (PDOException $e) {
            $errorMessage = "Database update error: " . $e->getMessage();
            error_log("Failure IPN: $errorMessage"); // Still log to server error log
            logFailureIPN($errorMessage, 'ERROR'); // Also log to file
        }

    } else {
        logFailureIPN("VERIFICATION FAILED. POST  " . print_r($_POST, true), 'VERIFICATION_FAILED');
        // Do NOT update the database if verification fails.
    }
} else {
    logFailureIPN("Received request with no POST data.", 'NO_POST_DATA');
}

exit;
?>