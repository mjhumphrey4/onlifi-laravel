<?php
// Set timezone to Nairobi
date_default_timezone_set('Africa/Nairobi');

// Database configuration
$host = 'localhost';
$dbname = 'omada';
$username = 'yo';
$password = 'password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Second database connection for STK
    $pdo_mikrotik = new PDO("mysql:host=$host;dbname=payment_mikrotik;charset=utf8mb4", $username, $password);
    $pdo_mikrotik->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Initialize SQLite for withdrawals
try {
    $withdrawDb = new SQLite3(__DIR__ . '/withdraw/withdrawals.db');
} catch(Exception $e) {
    die("SQLite connection failed: " . $e->getMessage());
}

// Function to get total withdrawals by user
function getTotalWithdrawals($withdrawDb, $username) {
    $stmt = $withdrawDb->prepare('SELECT SUM(amount) as total FROM transactions WHERE username = :username AND status = "SUCCEEDED"');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row['total'] ?? 0;
}

// Fetch sales data by site
function getSalesDataBySite($pdo, $siteName) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_sales,
            SUM(amount) as total_amount,
            SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as completed_amount,
            SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'success' THEN amount ELSE 0 END) as today_amount
        FROM transactions 
        WHERE origin_site = :site
        AND status = 'success'
    ");
    $stmt->execute(['site' => $siteName]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- PAGINATION AND FILTERING SETUP ---
$per_page = 20;
$last_log_time = $_GET['last_log_time'] ?? null;
$active_tab = $_GET['tab'] ?? 'all'; // Get active tab from URL

// --- FETCH LOGS LOGIC ---
if ($last_log_time) {
    $sql_condition = "AND created_at < :last_log_time";
    $params = [':last_log_time' => $last_log_time];
} else {
    $sql_condition = "";
    $params = [];
}

// Determine which sites to fetch based on active tab
$fetch_omada = in_array($active_tab, ['all', 'enock', 'richard']);
$fetch_mikrotik = in_array($active_tab, ['all', 'stk']);

$logs1 = [];
$logs2 = [];

// Fetch from omada database
if ($fetch_omada) {
    $site_filter = "";
    if ($active_tab === 'enock') {
        $site_filter = "AND origin_site = 'Bite Tech Network'";
    } elseif ($active_tab === 'richard') {
        $site_filter = "AND origin_site = 'Richard Network'";
    }
    
    $stmt1 = $pdo->prepare("
        SELECT 
            id,
            external_ref,
            msisdn,
            amount,
            status,
            created_at,
            origin_site,
            voucher_code
        FROM transactions 
        WHERE origin_site IN ('Bite Tech Network', 'Richard Network')
        $site_filter
        $sql_condition
        ORDER BY created_at DESC 
        LIMIT :limit
    ");
    $stmt1->bindValue(':limit', $per_page + 1, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt1->bindValue($key, $value);
    }
    $stmt1->execute();
    $logs1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch from mikrotik database
if ($fetch_mikrotik) {
    $stmt2 = $pdo_mikrotik->prepare("
        SELECT 
            id,
            external_ref,
            msisdn,
            amount,
            status,
            created_at,
            origin_site,
            voucher_code
        FROM transactions 
        WHERE origin_site = 'STK WIFI'
        " . $sql_condition . "
        ORDER BY created_at DESC 
        LIMIT :limit
    ");
    $stmt2->bindValue(':limit', $per_page + 1, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt2->bindValue($key, $value);
    }
    $stmt2->execute();
    $logs2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// Merge and sort logs
$all_logs = array_merge($logs1, $logs2);
usort($all_logs, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$has_more_logs = count($all_logs) > $per_page;
$logs = array_slice($all_logs, 0, $per_page);

$last_log_time_for_next = '';
if (!empty($logs)) {
    $last_log_time_for_next = end($logs)['created_at'];
}

// Get sales data for each site
$sites = ['Enock', 'Richard', 'STK'];
$dbSites = [
    'Enock' => 'Bite Tech Network',
    'Richard' => 'Richard Network',
    'STK' => 'STK WIFI'
];

$salesData = [];
$withdrawalsData = [];
foreach ($sites as $site) {
    if ($site === 'STK') {
        $salesData[$site] = getSalesDataBySite($pdo_mikrotik, $dbSites[$site]);
    } else {
        $salesData[$site] = getSalesDataBySite($pdo, $dbSites[$site]);
    }
    
    // Get withdrawal data for this user
    $withdrawalsData[$site] = getTotalWithdrawals($withdrawDb, $site);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Sales Tracking Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-success { background-color: #DEF7EC; color: #03543F; }
        .status-pending { background-color: #FEF3C7; color: #92400E; }
        .status-failed { background-color: #FDE8E8; color: #9B1C1C; }
        
        .withdraw-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s;
        }
        .withdraw-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }
        
        .tab-button {
            padding: 12px 24px;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            cursor: pointer;
        }
        .tab-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .tab-button.active {
            border-bottom-color: white;
            background-color: rgba(255, 255, 255, 0.15);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-r from-blue-600 to-green-600 shadow-lg">
        <div class="max-w-7xl mx-auto px-6 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="bg-white/20 backdrop-blur-sm rounded-lg p-2">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Yo Payments Tracking</h1>
                        <p class="text-blue-100">Real-time Voucher Sales Monitor to Yo-Payments</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="withdraw/index.php" class="bg-white/20 hover:bg-white/30 backdrop-blur-sm text-white px-4 py-2 rounded-lg font-semibold transition-all">
                        💸 Withdrawal Dashboard
                    </a>
                    <a href="statement.php" class="bg-white/20 hover:bg-white/30 backdrop-blur-sm text-white px-4 py-2 rounded-lg font-semibold transition-all">
                        Account Statement
                    </a>
                    <a href="stats.php" class="bg-white/20 hover:bg-white/30 backdrop-blur-sm text-white px-4 py-2 rounded-lg font-semibold transition-all">
                        Check Voucher Stock
                    </a>                    
                    <div class="text-right">
                        <div class="text-sm text-white font-semibold">Last Updated</div>
                        <div class="text-blue-100"><?php echo date('h:i:s A'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <!-- Sales Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <?php 
            $colors = [
                'Enock' => 'bg-blue-600',
                'Richard' => 'bg-green-600',
                'STK' => 'bg-blue-700'
            ];
            
            foreach ($sites as $site): 
                $data = $salesData[$site];
                $totalAmount = $data['total_amount'] ?? 0;
                $completedAmount = $data['completed_amount'] ?? 0;
                $todayAmount = $data['today_amount'] ?? 0;
                $totalSales = $data['total_sales'] ?? 0;
                
                // Calculate withdrawals and balance
                $totalWithdrawals = $withdrawalsData[$site];
                $balance = $totalAmount - $totalWithdrawals;
            ?>
            <div class="<?php echo $colors[$site]; ?> rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-transform">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-lg font-semibold opacity-90">Site: <?php echo htmlspecialchars($site); ?></h3>
                        <p class="text-3xl font-bold mt-2">UGX <?php echo number_format($totalAmount, 2); ?></p>
                        <p class="text-sm opacity-80 mt-1">Total Revenue (Successful Only)</p>
                    </div>
                    <div class="bg-white/20 rounded-lg p-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
                
                <!-- Withdrawal and Balance Section -->
                <div class="bg-white/10 rounded-lg p-4 mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-semibold">💰 Total Withdrawals</span>
                        <span class="font-bold text-red-200">UGX <?php echo number_format($totalWithdrawals, 2); ?></span>
                    </div>
                    <div class="border-t border-white/20 pt-2 mt-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-semibold">📊 Current Balance</span>
                            <span class="font-bold text-lg text-yellow-200">UGX <?php echo number_format($balance, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="border-t border-white/20 pt-4 mt-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm opacity-80">Completed Sales</span>
                        <span class="font-semibold">UGX <?php echo number_format($completedAmount, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm opacity-80">Today's Successful Sales</span>
                        <span class="font-semibold">UGX <?php echo number_format($todayAmount, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm opacity-80">Successful Transactions</span>
                        <span class="font-semibold"><?php echo $totalSales; ?></span>
                    </div>
                </div>
                
                <!-- Withdraw Button -->
                <a href="withdraw/index.php" class="withdraw-btn block text-center mt-4 py-2 px-4 rounded-lg font-semibold text-white no-underline">
                    Request Withdrawal →
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Payment Logs with Tabs -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-500 via-purple-500 to-green-500">
                <h2 class="text-xl font-bold text-white">Recent Payment Logs</h2>
                <p class="text-sm text-white/90 mt-1">Latest transactions across all sites (All Statuses)</p>
            </div>
            
            <!-- Tabs -->
            <div class="bg-gradient-to-r from-blue-500 via-purple-500 to-green-500 flex">
                <a href="?tab=all" class="tab-button text-white <?php echo $active_tab === 'all' ? 'active' : ''; ?>">
                    All Sites
                </a>
                <a href="?tab=enock" class="tab-button text-white <?php echo $active_tab === 'enock' ? 'active' : ''; ?>">
                    Enock
                </a>
                <a href="?tab=richard" class="tab-button text-white <?php echo $active_tab === 'richard' ? 'active' : ''; ?>">
                    Richard
                </a>
                <a href="?tab=stk" class="tab-button text-white <?php echo $active_tab === 'stk' ? 'active' : ''; ?>">
                    STK
                </a>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Voucher Code</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Timestamp</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Site</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Reference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                No logs found for this filter.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($log['voucher_code'] ?? 'N/A'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-semibold text-gray-900">
                                    <?php echo htmlspecialchars($log['origin_site'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo htmlspecialchars($log['msisdn']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                UGX <?php echo number_format($log['amount'], 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                $statusClass = 'status-' . strtolower($log['status']);
                                ?>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo ucfirst(htmlspecialchars($log['status'])); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 font-mono">
                                <?php echo htmlspecialchars(substr($log['external_ref'], 0, 20)) . '...'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination with Tab Preservation -->
            <?php if ($has_more_logs && $last_log_time_for_next): ?>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-center">
                <a href="?tab=<?php echo urlencode($active_tab); ?>&last_log_time=<?php echo urlencode($last_log_time_for_next); ?>" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-blue-600 rounded-md hover:bg-blue-700 transition-colors inline-block">
                    Next Logs &rarr;
                </a>
            </div>
            <?php elseif ($last_log_time): ?>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-center text-gray-500 text-sm">
                No more logs available.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Auto-refresh script (only on the first page with 'all' tab) -->
    <script>
        <?php if (!$last_log_time && $active_tab === 'all'): ?>
        setTimeout(function() {
            location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>
