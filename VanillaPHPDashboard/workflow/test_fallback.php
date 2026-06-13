<?php
/**
 * Test Script for Payment Fallback System
 * This simulates a failed YO payment and tests the fallback mechanism
 */

require_once 'config.php';
require_once 'logger.php';
require_once 'payment_fallback_helper.php';

echo "=== PAYMENT FALLBACK TEST ===\n\n";

// First, run the migration if you haven't already
echo "Step 1: Checking database schema...\n";
try {
    $pdo = getDB();
    $result = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'fallback_ref'");
    if ($result->rowCount() > 0) {
        echo "✓ Database schema is ready (fallback_ref column exists)\n\n";
    } else {
        echo "✗ ERROR: fallback_ref column not found!\n";
        echo "Please run: php run_migration.php\n\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "✗ Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Create a test transaction that simulates a failed YO payment
echo "Step 2: Creating test transaction...\n";
$testExternalRef = 'TEST_FALLBACK_' . time() . '_' . uniqid();

try {
    $stmt = $pdo->prepare("
        INSERT INTO transactions (external_ref, msisdn, amount, status, origin_site, client_mac, email, voucher_type, origin_url, transaction_ref, status_message)
        VALUES (?, ?, ?, 'failed', ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $testData = [
        $testExternalRef,
        '256700000000',  // Test phone number
        500,  // Test amount
        'TEST_SITE',
        'AA:BB:CC:DD:EE:FF',
        'test@example.com',
        '2hours',
        'http://test.local',
        'YO_TEST_REF_' . time(),
        'Data Invalid - Test failure'
    ];
    
    $stmt->execute($testData);
    echo "✓ Test transaction created: $testExternalRef\n\n";
    
} catch (PDOException $e) {
    echo "✗ Failed to create test transaction: " . $e->getMessage() . "\n";
    exit(1);
}

// Retrieve the test transaction
echo "Step 3: Retrieving test transaction...\n";
$stmt = $pdo->prepare("
    SELECT status, external_ref, transaction_ref, origin_site, client_mac, voucher_type, email, amount, status_message, voucher_code, msisdn, origin_url, fallback_ref
    FROM transactions
    WHERE external_ref = ? LIMIT 1
");
$stmt->execute([$testExternalRef]);
$transaction = $stmt->fetch();

if (!$transaction) {
    echo "✗ Could not retrieve test transaction\n";
    exit(1);
}

echo "✓ Transaction retrieved:\n";
echo "   - External Ref: {$transaction['external_ref']}\n";
echo "   - MSISDN: {$transaction['msisdn']}\n";
echo "   - Amount: {$transaction['amount']}\n";
echo "   - Status: {$transaction['status']}\n";
echo "   - Voucher Type: {$transaction['voucher_type']}\n\n";

// Test the fallback mechanism
echo "Step 4: Testing fallback to IOTEC...\n";
echo "This will attempt to create a real IOTEC transaction!\n";
echo "WARNING: This may charge the test phone number if IOTEC is in production mode.\n";
echo "\nDo you want to continue? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim($line) != 'yes') {
    echo "\nTest cancelled. Cleaning up...\n";
    $pdo->exec("DELETE FROM transactions WHERE external_ref = '$testExternalRef'");
    echo "✓ Test transaction deleted\n";
    exit(0);
}

echo "\nInitiating IOTEC fallback...\n";
$fallbackResult = retryPaymentWithIOTEC($transaction, $pdo, $logger);

if ($fallbackResult['success']) {
    echo "\n✓ FALLBACK INITIATED SUCCESSFULLY!\n";
    echo "   - IOTEC External Ref: {$fallbackResult['iotec_external_ref']}\n";
    echo "   - IOTEC Transaction ID: {$fallbackResult['iotec_transaction_id']}\n";
    echo "   - Status Message: {$fallbackResult['status_message']}\n\n";
    
    echo "Step 5: Verifying database updates...\n";
    $stmt = $pdo->prepare("SELECT fallback_ref FROM transactions WHERE external_ref = ?");
    $stmt->execute([$testExternalRef]);
    $updated = $stmt->fetch();
    
    if ($updated && $updated['fallback_ref']) {
        echo "✓ Original transaction updated with fallback_ref: {$updated['fallback_ref']}\n\n";
    } else {
        echo "✗ Original transaction not updated properly\n\n";
    }
    
    echo "Step 6: Checking IOTEC transaction status...\n";
    echo "Waiting 5 seconds for IOTEC to process...\n";
    sleep(5);
    
    $fallbackStatus = checkIOTECFallbackStatus($fallbackResult['iotec_external_ref'], $logger);
    echo "   - Status Code: {$fallbackStatus['status']}\n";
    echo "   - Message: {$fallbackStatus['message']}\n";
    if (isset($fallbackStatus['voucher_code'])) {
        echo "   - Voucher Code: {$fallbackStatus['voucher_code']}\n";
    }
    echo "\n";
    
} else {
    echo "\n✗ FALLBACK FAILED!\n";
    echo "   - Error: {$fallbackResult['error']}\n\n";
}

echo "=== TEST COMPLETE ===\n\n";
echo "Check your logs for detailed information:\n";
echo "- YO logs: /var/www/html/BiteTechsystems/yo/logs/paymentlogs.txt\n";
echo "- IOTEC logs: /var/www/html/BiteTechsystems/yo/IOTEC/logs/\n\n";

echo "Cleanup: Do you want to delete the test transaction? (yes/no): ";
$line = fgets($handle);
if (trim($line) == 'yes') {
    $pdo->exec("DELETE FROM transactions WHERE external_ref = '$testExternalRef'");
    echo "✓ Test transaction deleted from YO database\n";
    echo "Note: IOTEC transaction still exists in IOTEC database\n";
}

fclose($handle);
?>
