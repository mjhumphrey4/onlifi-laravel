<?php
// Test the balance API directly

echo "<h2>Balance API Test</h2>";

$sites = ['Enock', 'Richard', 'STK', 'Kigoma'];

foreach ($sites as $site) {
    echo "<h3>Testing site: $site</h3>";
    
    $url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/get_balance.php?site=" . urlencode($site);
    echo "API URL: $url<br>";
    
    $response = file_get_contents($url);
    echo "Raw Response: <pre>" . htmlspecialchars($response) . "</pre>";
    
    $data = json_decode($response, true);
    if ($data) {
        echo "Parsed JSON:<br>";
        echo "<pre>" . print_r($data, true) . "</pre>";
    } else {
        echo "<span style='color: red;'>Failed to parse JSON</span><br>";
    }
    
    echo "<hr>";
}
?>
