<?php
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

// Fetch sales data by site
function getSalesDataBySite($pdo, $siteName) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_sales,
            SUM(amount) as total_amount,
            SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as completed_amount,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END) as today_amount
        FROM transactions 
        WHERE origin_site = :site
    ");
    $stmt->execute(['site' => $siteName]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


// --- PAGINATION SETUP (Cursor-based for Next Button) ---
$per_page = 20; // Number of logs per page
// Get the timestamp of the last log from the previous page (if any)
$last_log_time = $_GET['last_log_time'] ?? null;
// --- END PAGINATION SETUP ---

// --- FETCH LOGS LOGIC (Modified for Cursor-based Fetch) ---
if ($last_log_time) {
    // Fetch logs older than the last log from the previous page
    $sql_condition = "WHERE created_at < :last_log_time";
    $params = [':last_log_time' => $last_log_time];
} else {
    // Fetch the very first set of logs (most recent)
    $sql_condition = ""; // No condition needed for the first page
    $params = [];
}

// Fetch recent payment logs from omada database with cursor condition
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
    $sql_condition
    ORDER BY created_at DESC 
    LIMIT :limit
");
$stmt1->bindValue(':limit', $per_page + 1, PDO::PARAM_INT); // Fetch one extra to check if more exist
foreach ($params as $key => $value) {
    $stmt1->bindValue($key, $value);
}
$stmt1->execute();
$logs1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent payment logs from mikrotik database with cursor condition
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
    $sql_condition
    ORDER BY created_at DESC 
    LIMIT :limit
");
$stmt2->bindValue(':limit', $per_page + 1, PDO::PARAM_INT); // Fetch one extra to check if more exist
foreach ($params as $key => $value) {
    $stmt2->bindValue($key, $value);
}
$stmt2->execute();
$logs2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Merge and sort logs (Keep the existing logic)
$all_logs = array_merge($logs1, $logs2);
usort($all_logs, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Check if there are more logs than we need to display (using the extra fetched log)
$has_more_logs = count($all_logs) > $per_page;
// Slice the array to get only the logs for the current page
$logs = array_slice($all_logs, 0, $per_page);

// Determine the timestamp for the *last* log shown on this page (for the next button)
$last_log_time_for_next = '';
if (!empty($logs)) {
    $last_log_time_for_next = end($logs)['created_at']; // Get the 'created_at' value of the last element in the $logs array
}
// --- END FETCH LOGS LOGIC ---

// Get sales data for each site (This part remains unchanged)
$sites = ['Enock', 'Richard', 'STK'];
$dbSites = [
    'Enock' => 'Bite Tech Network',
    'Richard' => 'Richard Network',
    'STK' => 'STK WIFI'
];

$salesData = [];
foreach ($sites as $site) {
    if ($site === 'STK') {
        $salesData[$site] = getSalesDataBySite($pdo_mikrotik, $dbSites[$site]); // Use mikrotik DB for STK
    } else {
        $salesData[$site] = getSalesDataBySite($pdo, $dbSites[$site]); // Use main DB for others
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Sales Tracking Dashboard</title>
    <script src="https://cdn.tailwindcss.com  "></script> <!-- Removed extra space -->
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
                        <h1 class="text-2xl font-bold text-white">Bite Tech X Yo Payments Tracking</h1>
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
                $totalAmount = number_format($data['total_amount'] ?? 0, 2);
                $completedAmount = number_format($data['completed_amount'] ?? 0, 2);
                $todayAmount = number_format($data['today_amount'] ?? 0, 2);
                $totalSales = $data['total_sales'] ?? 0;
            ?>
            <div class="<?php echo $colors[$site]; ?> rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-transform">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-lg font-semibold opacity-90">Site: <?php echo htmlspecialchars($site); ?></h3>
                        <p class="text-3xl font-bold mt-2">UGX <?php echo $totalAmount; ?></p>
                        <p class="text-sm opacity-80 mt-1">Total Revenue</p>
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
                        <span class="text-sm opacity-80">Today's Sales</span>
                        <span class="font-semibold">UGX <?php echo $todayAmount; ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm opacity-80">Total Transactions</span>
                        <span class="font-semibold"><?php echo $totalSales; ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Payment Logs -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-500 via-purple-500 to-green-500">
                <h2 class="text-xl font-bold text-white">Recent Payment Logs</h2>
                <p class="text-sm text-white/90 mt-1">Latest transactions across all sites</p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID</th>
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
                                #<?php echo htmlspecialchars($log['id']); ?>
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
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-center"> <!-- Added text-center class -->
                <a href="?last_log_time=<?php echo urlencode($last_log_time_for_next); ?>" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-blue-600 rounded-md hover:bg-blue-700 transition-colors inline-block"> <!-- Changed to inline-block for button appearance -->
                    Next Logs &rarr;
                </a>
            </div>
            <?php elseif ($last_log_time): ?>
            <!-- Optional: Show a message when you reach the end, but only if you're not on the first page -->
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-center text-gray-500 text-sm"> <!-- Added text-center and styling classes -->
                No more logs available.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Auto-refresh script (only on the first page, i.e., when last_log_time is not set) -->
    <script>
        <?php if (!$last_log_time): ?>
        // Refresh page every 60 seconds to show updated data (only on first page)
        setTimeout(function() {
            location.reload();
        }, 60000);
        <?php endif; ?>
    </script>
</body>
</html>