<?php
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$dbUsername = 'yo';
$dbPassword = 'password';

// Get the site parameter
$site = $_GET['site'] ?? '';

if (empty($site)) {
    echo json_encode(['error' => 'Site parameter is required']);
    exit;
}

try {
    // Initialize SQLite for withdrawals
    $withdrawDb = new SQLite3(__DIR__ . '/withdrawals.db');
    
    // Function to get total withdrawals by user
    function getTotalWithdrawals($withdrawDb, $username) {
        $stmt = $withdrawDb->prepare('SELECT SUM(amount) as total FROM transactions WHERE username = :username AND status = "SUCCEEDED"');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row['total'] ?? 0;
    }
    
    // Function to get sales data by site
    function getSalesDataBySite($pdo, $siteName) {
        $stmt = $pdo->prepare("
            SELECT 
                SUM(amount) as total_amount
            FROM transactions 
            WHERE origin_site = :site
            AND status = 'success'
        ");
        $stmt->execute(['site' => $siteName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_amount'] ?? 0;
    }
    
    $balance = 0;
    $totalRevenue = 0;
    $totalWithdrawals = 0;
    $siteName = '';
    
    switch ($site) {
        case 'Enock':
            $siteName = 'Enock';
            $pdo = new PDO("mysql:host=$host;dbname=omada;charset=utf8mb4", $dbUsername, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $totalRevenue = getSalesDataBySite($pdo, 'Bite Tech Network');
            $totalWithdrawals = getTotalWithdrawals($withdrawDb, 'Enock');
            $balance = $totalRevenue - $totalWithdrawals;
            break;
            
        case 'Richard':
            $siteName = 'Richard';
            $pdo = new PDO("mysql:host=$host;dbname=omada;charset=utf8mb4", $dbUsername, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $totalRevenue = getSalesDataBySite($pdo, 'Richard Network');
            $totalWithdrawals = getTotalWithdrawals($withdrawDb, 'Richard');
            $balance = $totalRevenue - $totalWithdrawals;
            break;
            
        case 'STK':
            $siteName = 'STK';
            $pdo = new PDO("mysql:host=$host;dbname=payment_mikrotik;charset=utf8mb4", $dbUsername, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $totalRevenue = getSalesDataBySite($pdo, 'STK WIFI');
            $totalWithdrawals = getTotalWithdrawals($withdrawDb, 'STK');
            $balance = $totalRevenue - $totalWithdrawals;
            break;
            
        case 'Kigoma':
            $siteName = 'Kigoma';
            $pdo = new PDO("mysql:host=$host;dbname=kigoma;charset=utf8mb4", $dbUsername, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $totalRevenue = getSalesDataBySite($pdo, 'BITE TECH NETWORK');
            $totalWithdrawals = getTotalWithdrawals($withdrawDb, 'Kigoma');
            $balance = $totalRevenue - $totalWithdrawals;
            break;
            
        case 'Guma':
            $siteName = 'Guma';
            $pdo = new PDO("mysql:host=$host;dbname=guma_omada;charset=utf8mb4", $dbUsername, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $totalRevenue = getSalesDataBySite($pdo, 'guma');
            $totalWithdrawals = getTotalWithdrawals($withdrawDb, 'Guma');
            $balance = $totalRevenue - $totalWithdrawals;
            break;
            
        case 'Remmy':
            $siteName = 'Remmy';
            $pdo = new PDO("mysql:host=$host;dbname=remmy_mikrotik;charset=utf8mb4", $dbUsername, $dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $totalRevenue = getSalesDataBySite($pdo, 'remmy');
            $totalWithdrawals = getTotalWithdrawals($withdrawDb, 'Remmy');
            $balance = $totalRevenue - $totalWithdrawals;
            break;
            
        default:
            echo json_encode(['error' => 'Invalid site']);
            exit;
    }
    
    echo json_encode([
        'success' => true,
        'site' => $site,
        'username' => $siteName ?? $site,
        'total_revenue' => $totalRevenue,
        'total_withdrawals' => $totalWithdrawals,
        'balance' => $balance
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
