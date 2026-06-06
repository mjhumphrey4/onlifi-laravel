<?php

function retryPaymentWithIOTEC($originalTransaction, $pdo, $logger) {
    $logger->info("=== INITIATING IOTEC FALLBACK ===", [
        'original_external_ref' => $originalTransaction['external_ref'],
        'amount' => $originalTransaction['amount'],
        'msisdn' => $originalTransaction['msisdn']
    ]);
    
    require_once __DIR__ . '/IOTEC/config.php';
    require_once __DIR__ . '/IOTEC/logger.php';
    require_once __DIR__ . '/IOTEC/auth_helper.php';
    
    $iotecExternalRef = 'IOTEC_FALLBACK_' . time() . '_' . uniqid();
    
    try {
        $iotecPdo = getIotecDB();
        
        $stmt = $iotecPdo->prepare("
            INSERT INTO transactions (external_ref, msisdn, amount, status, origin_site, client_mac, email, voucher_type, origin_url)
            VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $iotecExternalRef,
            $originalTransaction['msisdn'],
            $originalTransaction['amount'],
            $originalTransaction['origin_site'],
            $originalTransaction['client_mac'],
            $originalTransaction['email'],
            $originalTransaction['voucher_type'],
            $originalTransaction['origin_url']
        ]);
        
        $logger->info("IOTEC fallback transaction created in database", [
            'iotec_external_ref' => $iotecExternalRef
        ]);
        
    } catch (Exception $e) {
        $logger->error("Failed to create IOTEC fallback transaction in DB", [
            'error' => $e->getMessage()
        ]);
        return [
            'success' => false,
            'error' => 'Database error during fallback'
        ];
    }
    
    $collectionData = [
        'category' => 'MobileMoney',
        'currency' => 'UGX',
        'walletId' => IOTEC_WALLET_ID,
        'externalId' => $iotecExternalRef,
        'payer' => $originalTransaction['msisdn'],
        'payerName' => $originalTransaction['origin_site'],
        'payerNote' => "WiFi voucher payment - " . $originalTransaction['voucher_type'] . " (Fallback)",
        'amount' => (int)$originalTransaction['amount'],
        'payeeNote' => "FALLBACK - Site: " . $originalTransaction['origin_site'] . ", MAC: " . $originalTransaction['client_mac']
    ];
    
    $logger->info("Calling IOTEC API for fallback payment", [
        'collectionData' => $collectionData
    ]);
    
    $response = makeIotecApiRequest('POST', '/collections/collect', $collectionData);
    
    if (isset($response['error'])) {
        $errorMessage = $response['error'];
        $errorDetails = isset($response['response']) ? json_encode($response['response']) : '';
        
        $logger->error("IOTEC fallback API returned error", [
            'error' => $errorMessage,
            'details' => $errorDetails
        ]);
        
        try {
            $stmt = $iotecPdo->prepare("
                UPDATE transactions 
                SET status = 'failed', status_message = ? 
                WHERE external_ref = ?
            ");
            $stmt->execute([$errorMessage . ' ' . $errorDetails, $iotecExternalRef]);
        } catch (Exception $e) {
            $logger->error("Failed to update IOTEC transaction status", ['error' => $e->getMessage()]);
        }
        
        return [
            'success' => false,
            'error' => $errorMessage
        ];
    }
    
    $iotecTransactionId = $response['id'] ?? '';
    $statusMessage = $response['statusMessage'] ?? 'Payment initiated via backup service';
    
    $logger->success("IOTEC fallback payment initiated successfully", [
        'iotec_transaction_id' => $iotecTransactionId,
        'iotec_external_ref' => $iotecExternalRef
    ]);
    
    try {
        $stmt = $iotecPdo->prepare("
            UPDATE transactions 
            SET transaction_ref = ?, status_message = ? 
            WHERE external_ref = ?
        ");
        $stmt->execute([$iotecTransactionId, $statusMessage, $iotecExternalRef]);
        
        updateTransaction($pdo, $originalTransaction['external_ref'], [
            'status_message' => 'Retrying with backup payment service',
            'fallback_ref' => $iotecExternalRef,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $logger->info("Updated both databases with fallback references");
        
    } catch (Exception $e) {
        $logger->error("Failed to update transaction references", ['error' => $e->getMessage()]);
    }
    
    return [
        'success' => true,
        'iotec_external_ref' => $iotecExternalRef,
        'iotec_transaction_id' => $iotecTransactionId,
        'status_message' => $statusMessage
    ];
}

function checkIOTECFallbackStatus($iotecExternalRef, $logger) {
    require_once __DIR__ . '/IOTEC/config.php';
    require_once __DIR__ . '/IOTEC/logger.php';
    require_once __DIR__ . '/IOTEC/auth_helper.php';
    require_once __DIR__ . '/IOTEC/voucher_helper.php';
    
    $logger->info("Checking IOTEC fallback status", ['iotec_ref' => $iotecExternalRef]);
    
    try {
        $iotecPdo = getIotecDB();
        
        $stmt = $iotecPdo->prepare("
            SELECT status, external_ref, transaction_ref, origin_site, client_mac, voucher_type, email, amount, status_message, voucher_code, msisdn
            FROM transactions
            WHERE external_ref = ? LIMIT 1
        ");
        $stmt->execute([$iotecExternalRef]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            $logger->warning("IOTEC fallback transaction not found", ['ref' => $iotecExternalRef]);
            return [
                'status' => 0,
                'message' => 'Checking backup service...'
            ];
        }
        
        if ($transaction['status'] === 'success' && $transaction['voucher_code']) {
            $logger->success("IOTEC fallback succeeded with voucher", [
                'voucher_code' => $transaction['voucher_code']
            ]);
            return [
                'status' => 1,
                'voucher_code' => $transaction['voucher_code'],
                'message' => 'Payment successful via backup service'
            ];
        }
        
        if ($transaction['status'] === 'failed') {
            $logger->warning("IOTEC fallback failed", [
                'message' => $transaction['status_message']
            ]);
            return [
                'status' => -1,
                'message' => $transaction['status_message'] ?? 'Backup payment service failed'
            ];
        }
        
        $iotecTransactionId = $transaction['transaction_ref'];
        if (!$iotecTransactionId) {
            return [
                'status' => 0,
                'message' => 'Backup service processing...'
            ];
        }
        
        $apiResponse = makeIotecApiRequest('GET', '/collections/status/' . $iotecTransactionId);
        
        if (isset($apiResponse['error'])) {
            $logger->warning("IOTEC API error during fallback status check", [
                'error' => $apiResponse['error']
            ]);
            return [
                'status' => 0,
                'message' => 'Checking backup service...'
            ];
        }
        
        $apiStatus = strtolower($apiResponse['status'] ?? '');
        
        if ($apiStatus === 'success') {
            $logger->info("IOTEC API reports SUCCESS for fallback");
            
            $stmt = $iotecPdo->prepare("
                UPDATE transactions
                SET status = 'success',
                    status_message = ?,
                    updated_at = NOW()
                WHERE external_ref = ?
            ");
            $stmt->execute([$apiResponse['statusMessage'] ?? 'Success', $iotecExternalRef]);
            
            $voucherResult = assignVoucherToTransaction($iotecExternalRef, $iotecPdo);
            
            if ($voucherResult['success']) {
                $logger->success("Voucher assigned for IOTEC fallback", [
                    'voucher_code' => $voucherResult['voucherCode']
                ]);
                
                $smsResult = $voucherResult['sms'] ?? ['success' => false, 'message' => 'SMS result unavailable'];
                $logger->info("SMS sent for fallback voucher", [
                    'success' => $smsResult['success'] ?? false,
                    'message' => $smsResult['message'] ?? null,
                ]);
                
                return [
                    'status' => 1,
                    'voucher_code' => $voucherResult['voucherCode'],
                    'message' => 'Payment successful via backup service'
                ];
            } else {
                $logger->error("Voucher assignment failed for IOTEC fallback", [
                    'error' => $voucherResult['error']
                ]);
                return [
                    'status' => -1,
                    'message' => 'Voucher assignment failed: ' . $voucherResult['error']
                ];
            }
            
        } elseif ($apiStatus === 'failed' || $apiStatus === 'declined') {
            $logger->warning("IOTEC API reports FAILED for fallback");
            
            $stmt = $iotecPdo->prepare("
                UPDATE transactions
                SET status = 'failed',
                    status_message = ?,
                    updated_at = NOW()
                WHERE external_ref = ?
            ");
            $stmt->execute([$apiResponse['statusMessage'] ?? 'Failed', $iotecExternalRef]);
            
            return [
                'status' => -1,
                'message' => $apiResponse['statusMessage'] ?? 'Backup payment service declined'
            ];
        }
        
        return [
            'status' => 0,
            'message' => 'Backup service processing...'
        ];
        
    } catch (Exception $e) {
        $logger->error("Error checking IOTEC fallback status", [
            'error' => $e->getMessage()
        ]);
        return [
            'status' => 0,
            'message' => 'Error checking backup service'
        ];
    }
}
?>
