<?php
// Database configuration
$host = 'localhost';
$dbname = 'remmy_mikrotik';
$username = 'yo';
$password = 'password';

try {
    $pdo_remmy = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo_remmy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Initialize SQLite for withdrawals
try {
    $withdrawDb = new SQLite3(__DIR__ . '/withdraw/withdrawals.db');
} catch(Exception $e) {
    die("SQLite connection failed: " . $e->getMessage());
}

// Function to get total withdrawals for Remmy
function getTotalWithdrawals($withdrawDb, $username) {
    $stmt = $withdrawDb->prepare('SELECT SUM(amount) as total FROM transactions WHERE username = :username AND status = "SUCCEEDED"');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row['total'] ?? 0;
}

// Fetch sales data for Remmy site
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

// --- PAGINATION SETUP ---
$per_page = 20;
$last_log_time = $_GET['last_log_time'] ?? null;

// --- FETCH LOGS LOGIC ---
if ($last_log_time) {
    $sql_condition = "AND created_at < :last_log_time";
    $params = [':last_log_time' => $last_log_time];
} else {
    $sql_condition = "";
    $params = [];
}

// Fetch logs from remmy_mikrotik database
$stmt = $pdo_remmy->prepare("
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
    WHERE origin_site = 'remmy'
    $sql_condition
    ORDER BY created_at DESC 
    LIMIT :limit
");
$stmt->bindValue(':limit', $per_page + 1, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$all_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$has_more_logs = count($all_logs) > $per_page;
$logs = array_slice($all_logs, 0, $per_page);

$last_log_time_for_next = '';
if (!empty($logs)) {
    $last_log_time_for_next = end($logs)['created_at'];
}

// Get sales data for Remmy
$salesData = getSalesDataBySite($pdo_remmy, 'remmy');

// Get withdrawal data for Remmy user
$totalWithdrawals = getTotalWithdrawals($withdrawDb, 'Remmy');

// Calculate balance
$totalAmount = $salesData['total_amount'] ?? 0;
$balance = $totalAmount - $totalWithdrawals;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remmy Sales Dashboard</title>
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
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-r from-purple-600 to-pink-600 shadow-lg">
        <div class="max-w-7xl mx-auto px-6 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="bg-white/20 backdrop-blur-sm rounded-lg p-2">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">remmy - Yo Payments</h1>
                        <p class="text-purple-100">Real-time Voucher Sales Monitor</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="withdraw/index.php" class="bg-white/20 hover:bg-white/30 backdrop-blur-sm text-white px-4 py-2 rounded-lg font-semibold transition-all">
                        💸 Withdrawal Dashboard
                    </a>
                    <div class="text-right">
                        <div class="text-sm text-white font-semibold">Last Updated</div>
                        <div class="text-purple-100"><?php echo date('h:i:s A'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <!-- Sales Card -->
        <div class="mb-8">
            <?php 
                $totalAmountFormatted = number_format($totalAmount, 2);
                $completedAmount = number_format($salesData['completed_amount'] ?? 0, 2);
                $todayAmount = number_format($salesData['today_amount'] ?? 0, 2);
                $totalSales = $salesData['total_sales'] ?? 0;
            ?>
            <div class="bg-gradient-to-br from-purple-600 to-pink-600 rounded-xl shadow-lg p-8 text-white">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h3 class="text-2xl font-semibold opacity-90">remmy</h3>
                        <p class="text-5xl font-bold mt-4">UGX <?php echo $totalAmountFormatted; ?></p>
                        <p class="text-lg opacity-80 mt-2">Total Revenue (Successful Only)</p>
                    </div>
                    <div class="bg-white/20 rounded-lg p-4">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
                
                <!-- Withdrawal and Balance Section -->
                <div class="bg-white/10 backdrop-blur-sm rounded-lg p-5 mb-6">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-base font-semibold flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Total Withdrawals
                        </span>
                        <span class="font-bold text-xl text-red-200">UGX <?php echo number_format($totalWithdrawals, 2); ?></span>
                    </div>
                    <div class="border-t border-white/30 pt-3 mt-3">
                        <div class="flex justify-between items-center">
                            <span class="text-base font-semibold flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                                Current Balance
                            </span>
                            <span class="font-bold text-2xl text-yellow-200">UGX <?php echo number_format($balance, 2); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="border-t border-white/20 pt-6 mt-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <span class="text-sm opacity-80 block mb-1">Completed Sales</span>
                            <span class="text-2xl font-semibold">UGX <?php echo $completedAmount; ?></span>
                        </div>
                        <div>
                            <span class="text-sm opacity-80 block mb-1">Today's Successful Sales</span>
                            <span class="text-2xl font-semibold">UGX <?php echo $todayAmount; ?></span>
                        </div>
                        <div>
                            <span class="text-sm opacity-80 block mb-1">Successful Transactions</span>
                            <span class="text-2xl font-semibold"><?php echo $totalSales; ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Withdraw Button -->
                <a href="withdraw/index.php" class="withdraw-btn block text-center mt-6 py-3 px-6 rounded-lg font-semibold text-white no-underline text-lg">
                    Request Withdrawal →
                </a>
            </div>
        </div>

        <!-- Payment Logs -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-purple-600 to-pink-600">
                <h2 class="text-xl font-bold text-white">Recent Payment Logs</h2>
                <p class="text-sm text-white/90 mt-1">Latest transactions for remmy (All Statuses)</p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Voucher Code</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Timestamp</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Reference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                No transactions found
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
            
            <!-- Simple Next Button -->
            <?php if ($has_more_logs && $last_log_time_for_next): ?>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-center">
                <a href="?last_log_time=<?php echo urlencode($last_log_time_for_next); ?>" class="px-4 py-2 text-sm font-medium text-white bg-purple-600 border border-purple-600 rounded-md hover:bg-purple-700 transition-colors inline-block">
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

    <!-- Auto-refresh script -->
    <script>
        <?php if (!$last_log_time): ?>
        setTimeout(function() {
            location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>
