<?php
// validate.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

require_once 'config.php';
require_once 'logger.php'; // Include logger
require_once 'voucher_helper.php'; // Include shared voucher assignment logic

$isBackgroundCall = isset($_GET['background_call']) && $_GET['background_call'] == 1;
$externalRef = $_GET['external_ref'] ?? null;

$logger->info("validate.php started", [
    'externalRef' => $externalRef,
    'isBackgroundCall' => $isBackgroundCall
]);

if (!$externalRef) {
    $logger->warning("Accessed without external_ref parameter", ['isBackgroundCall' => $isBackgroundCall]);
    if ($isBackgroundCall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing external_ref parameter']);
        exit;
    } else {
        header("Location: " . $_SERVER['HTTP_REFERER'] ?? SITE_URL);
        exit;
    }
}

try {
    $pdo = getDB();
    $logger->debug("Database connection established");
} catch (Exception $e) {
    $logger->error("Database connection failed", ['error' => $e->getMessage()]);
    if ($isBackgroundCall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    } else {
        die("Database connection failed.");
    }
}

// Fetch transaction details
try {
    $stmt = $pdo->prepare("
        SELECT status, client_mac, voucher_type, amount, origin_site, voucher_code
        FROM transactions
        WHERE external_ref = ? LIMIT 1
    ");
    $stmt->execute([$externalRef]);
    $transaction = $stmt->fetch();

    $logger->debug("Transaction query executed", [
        'externalRef' => $externalRef,
        'found' => $transaction ? true : false
    ]);

    if (!$transaction) {
        $logger->error("Transaction not found for external_ref", ['externalRef' => $externalRef]);
        if ($isBackgroundCall) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            exit;
        } else {
            die("Transaction not found.");
        }
    }

    $logger->debug("Transaction details retrieved", [
        'externalRef' => $externalRef,
        'status' => $transaction['status'],
        'voucher_code' => $transaction['voucher_code'],
        'voucher_type' => $transaction['voucher_type'],
        'client_mac' => $transaction['client_mac']
    ]);

    // Check if transaction is successful
    if ($transaction['status'] !== 'success') {
        $logger->warning("Transaction not marked as success", [
            'externalRef' => $externalRef,
            'status' => $transaction['status']
        ]);
        if ($isBackgroundCall) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Transaction not completed successfully']);
            exit;
        } else {
            die("Transaction not completed successfully.");
        }
    }

    // Check if voucher already assigned
    if ($transaction['voucher_code']) {
        $logger->info("Voucher already assigned to transaction", [
            'externalRef' => $externalRef,
            'voucherCode' => $transaction['voucher_code']
        ]);
        if ($isBackgroundCall) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'voucherCode' => $transaction['voucher_code']]);
            exit;
        } else {
            header("Location: " . $_SERVER['HTTP_REFERER'] ?? SITE_URL);
            exit;
        }
    }

    $clientMac = $transaction['client_mac'];
    $voucherType = $transaction['voucher_type'];
    $amount = $transaction['amount'];
    $originSite = $transaction['origin_site'];

    $logger->info("Ready to assign voucher", [
        'externalRef' => $externalRef,
        'clientMac' => $clientMac,
        'voucherType' => $voucherType,
        'amount' => $amount
    ]);

} catch (PDOException $e) {
    $logger->error("Database query error while fetching transaction", [
        'error' => $e->getMessage(),
        'externalRef' => $externalRef
    ]);
    if ($isBackgroundCall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database query failed']);
        exit;
    } else {
        die("Database query failed.");
    }
}

// --- Use Shared Voucher Assignment Logic ---
$logger->debug("Calling shared voucher assignment function", [
    'externalRef' => $externalRef
]);

$voucherResult = assignVoucherToTransaction($externalRef, $pdo);

if ($voucherResult['success']) {
    $voucherCode = $voucherResult['voucherCode'];
    
    $logger->success("Voucher assignment complete", [
        'voucherCode' => $voucherCode,
        'externalRef' => $externalRef,
        'isBackgroundCall' => $isBackgroundCall
    ]);

    if ($isBackgroundCall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'voucherCode' => $voucherCode]);
        exit;
    } else {
        header("Location: " . $_SERVER['HTTP_REFERER'] ?? SITE_URL);
        exit;
    }
} else {
    $logger->error("Voucher assignment failed", [
        'externalRef' => $externalRef,
        'error' => $voucherResult['error'],
        'isBackgroundCall' => $isBackgroundCall
    ]);

    if ($isBackgroundCall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $voucherResult['error']]);
        exit;
    } else {
        die("Failed to assign voucher: " . $voucherResult['error']);
    }
}
?>