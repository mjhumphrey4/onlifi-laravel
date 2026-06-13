<?php
// voucher_helper.php
// Shared voucher assignment logic that can be used by both IPN and validate.php

require_once 'config.php';

/**
 * Assigns a voucher to a successful transaction
 * 
 * @param string $externalRef The external reference of the transaction
 * @param PDO|null $pdo Optional PDO connection (will create new if not provided)
 * @return array ['success' => bool, 'voucherCode' => string|null, 'error' => string|null]
 */
function assignVoucherToTransaction($externalRef, $pdo = null) {
    $shouldClosePdo = false;
    
    // Get database connection if not provided
    if ($pdo === null) {
        try {
            $pdo = getDB();
            $shouldClosePdo = true;
        } catch (Exception $e) {
            return [
                'success' => false,
                'voucherCode' => null,
                'error' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
    }
    
    try {
        // Fetch transaction details
        $stmt = $pdo->prepare("
            SELECT status, client_mac, voucher_type, amount, origin_site, voucher_code, family_type, wifi_name
            FROM transactions
            WHERE external_ref = ? LIMIT 1
        ");
        $stmt->execute([$externalRef]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            return [
                'success' => false,
                'voucherCode' => null,
                'error' => 'Transaction not found'
            ];
        }
        
        // Check if transaction is successful
        if ($transaction['status'] !== 'success') {
            return [
                'success' => false,
                'voucherCode' => null,
                'error' => 'Transaction not completed successfully (status: ' . $transaction['status'] . ')'
            ];
        }
        
        // Check if this is a private router purchase - no voucher needed
        if ($transaction['family_type'] === 'private') {
            return [
                'success' => true,
                'voucherCode' => null,
                'error' => null,
                'isPrivateRouter' => true,
                'wifiName' => $transaction['wifi_name']
            ];
        }
        
        // Check if voucher already assigned
        if ($transaction['voucher_code']) {
            return [
                'success' => true,
                'voucherCode' => $transaction['voucher_code'],
                'error' => null
            ];
        }
        
        $clientMac = $transaction['client_mac'];
        $voucherType = $transaction['voucher_type'];
        
        // Query available vouchers
        $stmt = $pdo->prepare("
            SELECT code
            FROM vouchers
            WHERE type = ? AND assigned_mac IS NULL AND used = 0
            LIMIT 1
        ");
        $stmt->execute([$voucherType]);
        $voucherRow = $stmt->fetch();
        
        if (!$voucherRow) {
            return [
                'success' => false,
                'voucherCode' => null,
                'error' => 'No available voucher found for type: ' . $voucherType
            ];
        }
        
        $voucherCode = $voucherRow['code'];
        
        // Assign voucher
        $stmt = $pdo->prepare("
            UPDATE vouchers
            SET assigned_mac = ?, assigned_date = NOW(), used = 1
            WHERE code = ? AND assigned_mac IS NULL
        ");
        $stmt->execute([$clientMac, $voucherCode]);
        
        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'voucherCode' => null,
                'error' => 'Failed to assign voucher (may have been assigned to another transaction)'
            ];
        }
        
        // Update transaction record
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET voucher_code = ?
            WHERE external_ref = ?
        ");
        $stmt->execute([$voucherCode, $externalRef]);
        
        return [
            'success' => true,
            'voucherCode' => $voucherCode,
            'error' => null
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'voucherCode' => null,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    } finally {
        if ($shouldClosePdo && $pdo) {
            $pdo = null;
        }
    }
}
?>
