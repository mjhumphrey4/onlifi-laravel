
<?php
// process_withdrawal.php - Handles the actual withdrawal API call

header('Content-Type: application/json');

require 'YoAPI.php'; // Make sure YoAPI.php is in the same directory

// Get POST data
$msisdn = $_POST['msisdn'] ?? '';
$amount = $_POST['amount'] ?? '';

// Validate inputs
if (empty($msisdn) || empty($amount)) {
    echo json_encode([
        'status' => 'FAILED',
        'message' => 'Missing required parameters',
        'reference' => null
    ]);
    exit;
}

// Configure your Yo! Payments credentials
$username = "100812171094"; // SET YOUR YO PAYMENTS USERNAME HERE
$password = "BUid-ZAmO-b2M0-vF6n-CzBK-PBaL-8qJK-6SOf"; // SET YOUR YO PAYMENTS PASSWORD HERE
$mode = "production"; // In production, set this to "production"
$private_key_file_location = 'private_key.pem'; // SET YOUR PRIVATE KEY PATH HERE

$narrative = 'Withdrawal from dashboard - ' . date('Y-m-d H:i:s');

try {
    $yoAPI = new YoAPI($username, $password, $mode);
    $yoAPI->set_external_reference(date("YmdHis") . rand(1, 100));
    $yoAPI->set_private_key_file_location($private_key_file_location);
    $yoAPI->set_public_key_authentication_nonce(date("YmdHis") . rand(1, 100));
    $yoAPI->generate_public_key_authentication_signature($msisdn, $amount, $narrative);
    
    $response = $yoAPI->ac_withdraw_funds($msisdn, $amount, $narrative);
    
    if ($response['TransactionStatus'] == 'SUCCEEDED') {
        echo json_encode([
            'status' => 'SUCCEEDED',
            'message' => 'Payment made successfully',
            'reference' => $response['TransactionReference']
        ]);
    } else {
        echo json_encode([
            'status' => 'FAILED',
            'message' => $response['StatusMessage'] ?? 'Transaction failed',
            'reference' => $response['TransactionReference'] ?? null
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'FAILED',
        'message' => 'Exception: ' . $e->getMessage(),
        'reference' => null
    ]);
}
?>
