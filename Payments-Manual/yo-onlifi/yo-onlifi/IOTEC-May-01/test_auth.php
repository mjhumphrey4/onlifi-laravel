<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

echo "<h2>IOTEC Authentication Test</h2>";
echo "<pre>";

echo "=== Configuration ===\n";
echo "Client ID: " . IOTEC_CLIENT_ID . "\n";
echo "Client Secret: " . substr(IOTEC_CLIENT_SECRET, 0, 10) . "...\n";
echo "Wallet ID: " . IOTEC_WALLET_ID . "\n";
echo "Auth URL: " . IOTEC_AUTH_URL . "\n\n";

echo "=== Testing Authentication ===\n";

$ch = curl_init(IOTEC_AUTH_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => IOTEC_CLIENT_ID,
    'client_secret' => IOTEC_CLIENT_SECRET,
    'grant_type' => 'client_credentials'
]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
if ($error) {
    echo "cURL Error: " . $error . "\n";
}
echo "Response:\n";
echo $response . "\n\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['access_token'])) {
        echo "✅ Authentication SUCCESSFUL!\n";
        echo "Token: " . substr($data['access_token'], 0, 50) . "...\n";
        echo "Expires in: " . ($data['expires_in'] ?? 'N/A') . " seconds\n\n";
        
        echo "=== Testing API Request ===\n";
        $token = $data['access_token'];
        
        $testCh = curl_init(IOTEC_API_BASE_URL . '/collections/status/test-id');
        curl_setopt($testCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($testCh, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        
        $testResponse = curl_exec($testCh);
        $testHttpCode = curl_getinfo($testCh, CURLINFO_HTTP_CODE);
        curl_close($testCh);
        
        echo "Test API HTTP Code: " . $testHttpCode . "\n";
        echo "Test API Response:\n";
        echo $testResponse . "\n";
        
    } else {
        echo "❌ Authentication response missing access_token\n";
    }
} else {
    echo "❌ Authentication FAILED!\n";
    echo "Please check your credentials in config.php\n";
}

echo "</pre>";
?>
