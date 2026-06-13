<?php
// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

// Database configuration
$host = 'localhost';
$dbname = 'ice_cakes';
$username = 'yo';
$password = 'password';

try {
    $pdo_ice_cakes = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo_ice_cakes->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Fetch sales data for Ice Cakes site
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

// Fetch logs from ice_cakes database
$stmt = $pdo_ice_cakes->prepare("
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

// Get sales data for Ice Cakes
$salesData = getSalesDataBySite($pdo_ice_cakes, 'STK WIFI');
$totalAmount = number_format($salesData['total_amount'] ?? 0, 2);
$completedAmount = number_format($salesData['completed_amount'] ?? 0, 2);
$todayAmount = number_format($salesData['today_amount'] ?? 0, 2);
$totalSales = $salesData['total_sales'] ?? 0;

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
                <div class="text-right">
                    <div class="text-sm text-white font-semibold">Last Updated</div>
                    <div class="text-blue-100"><?php echo date('h:i:s A'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <!-- Sales Card -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-blue-600 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-transform">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-lg font-semibold opacity-90">Site: Ice Cakes</h3>
                        <p class="text-3xl font-bold mt-2">UGX <?php echo $totalAmount; ?></p>
                        <p class="text-sm opacity-80 mt-1">Total Revenue (Successful Only)</p>
                    </div>
                    <div class="bg-white/20 rounded-lg p-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
                <div class="border-t border-white/20 pt-4 mt-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm opacity-80">Completed Sales</span>
                        <span class="font-semibold">UGX <?php echo $completedAmount; ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm opacity-80">Today's Successful Sales</span>
                        <span class="font-semibold">UGX <?php echo $todayAmount; ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm opacity-80">Successful Transactions</span>
                        <span class="font-semibold"><?php echo $totalSales; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Logs -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-500 via-purple-500 to-green-500">
                <h2 class="text-xl font-bold text-white">Recent Payment Logs</h2>
                <p class="text-sm text-white/90 mt-1">Latest transactions across all sites (All Statuses)</p>
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
                    </tbody>
                </table>
            </div>
            
            <!-- Simple Next Button -->
            <?php if ($has_more_logs && $last_log_time_for_next): ?>
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-center">
                <a href="?last_log_time=<?php echo urlencode($last_log_time_for_next); ?>" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-blue-600 rounded-md hover:bg-blue-700 transition-colors inline-block">
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
