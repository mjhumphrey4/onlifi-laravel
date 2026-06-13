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

// Function to get voucher statistics for Enock (from vouchers2 table)
function getVoucherStatsEnock($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = '2hours' THEN 1 ELSE 0 END) as hours_2,
            SUM(CASE WHEN type = '12hours' THEN 1 ELSE 0 END) as hours_12,
            SUM(CASE WHEN type = '24hours' THEN 1 ELSE 0 END) as hours_24,
            SUM(CASE WHEN type = '7days' THEN 1 ELSE 0 END) as days_7,
            SUM(CASE WHEN type = '30days' THEN 1 ELSE 0 END) as days_30
        FROM vouchers2 
        WHERE used = 0
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to get voucher statistics for Richard (from vouchers_richard table)
function getVoucherStatsRichard($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = '2hours' THEN 1 ELSE 0 END) as hours_2,
            SUM(CASE WHEN type = '12hours' THEN 1 ELSE 0 END) as hours_12,
            SUM(CASE WHEN type = '24hours' THEN 1 ELSE 0 END) as hours_24,
            SUM(CASE WHEN type = '7days' THEN 1 ELSE 0 END) as days_7,
            SUM(CASE WHEN type = '30days' THEN 1 ELSE 0 END) as days_30
        FROM vouchers_richard 
        WHERE used = 0
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to get voucher statistics for STK (from vouchers table in mikrotik DB)
function getVoucherStatsSTK($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = '2hours' THEN 1 ELSE 0 END) as hours_2,
            SUM(CASE WHEN type = '12hours' THEN 1 ELSE 0 END) as hours_12,
            SUM(CASE WHEN type = '24hours' THEN 1 ELSE 0 END) as hours_24,
            SUM(CASE WHEN type = '7days' THEN 1 ELSE 0 END) as days_7,
            SUM(CASE WHEN type = '30days' THEN 1 ELSE 0 END) as days_30
        FROM vouchers 
        WHERE used = 0
    ");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle AJAX request
if (isset($_GET['ajax']) && isset($_GET['site'])) {
    $site = $_GET['site'];
    
    if ($site === 'enock') {
        $stats = getVoucherStatsEnock($pdo);
    } elseif ($site === 'richard') {
        $stats = getVoucherStatsRichard($pdo);
    } elseif ($site === 'stk') {
        $stats = getVoucherStatsSTK($pdo_mikrotik);
    } else {
        echo json_encode(['error' => 'Invalid site']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        '2hours' => intval($stats['hours_2'] ?? 0),
        '12hours' => intval($stats['hours_12'] ?? 0),
        '24hours' => intval($stats['hours_24'] ?? 0),
        '7days' => intval($stats['days_7'] ?? 0),
        '30days' => intval($stats['days_30'] ?? 0)
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Statistics Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .site-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .site-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        .site-card.active {
            border: 3px solid white;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.2);
        }
        .voucher-stat-card {
            transition: all 0.3s;
        }
        .voucher-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Voucher Stock Statistics</h1>
                        <p class="text-blue-100">Monitor the voucher stock to find unused vouchers</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="dashboard.php" class="bg-white/20 hover:bg-white/30 backdrop-blur-sm text-white px-4 py-2 rounded-lg font-semibold transition-all">
                        ← Back to Dashboard
                    </a>
                    <div class="text-right">
                        <div class="text-sm text-white font-semibold">Last Updated</div>
                        <div class="text-blue-100" id="lastUpdated"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-6 py-8">
        <!-- Site Selection Cards -->
        <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Select Site to View Statistics</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Enock Site -->
                <div class="site-card bg-blue-600 rounded-xl shadow-lg p-6 text-white" data-site="enock" onclick="selectSite('enock')">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-2xl font-bold">Enock</h3>
                            <p class="text-blue-100 text-sm mt-1">Bite Tech Network</p>
                        </div>
                        <div class="bg-white/20 rounded-lg p-3">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Richard Site -->
                <div class="site-card bg-green-600 rounded-xl shadow-lg p-6 text-white" data-site="richard" onclick="selectSite('richard')">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-2xl font-bold">Richard</h3>
                            <p class="text-green-100 text-sm mt-1">Richard Network</p>
                        </div>
                        <div class="bg-white/20 rounded-lg p-3">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- STK Site -->
                <div class="site-card bg-blue-700 rounded-xl shadow-lg p-6 text-white" data-site="stk" onclick="selectSite('stk')">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-2xl font-bold">STK</h3>
                            <p class="text-blue-100 text-sm mt-1">STK WIFI</p>
                        </div>
                        <div class="bg-white/20 rounded-lg p-3">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Voucher Statistics Section -->
        <div id="statisticsSection" class="hidden">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-800">
                    <span id="selectedSiteName">Site</span> - Voucher Inventory
                </h2>
                <button onclick="refreshStats()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold transition-all">
                    🔄 Refresh
                </button>
            </div>

            <!-- Loading Spinner -->
            <div id="loadingSpinner" class="hidden">
                <div class="spinner"></div>
                <p class="text-center text-gray-600">Loading voucher statistics...</p>
            </div>

            <!-- Statistics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6" id="statsGrid">
                <!-- 2 Hours -->
                <div class="voucher-stat-card bg-white rounded-xl shadow-lg p-6 border-l-4 border-purple-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-purple-100 rounded-lg p-3">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-purple-600 bg-purple-100 px-2 py-1 rounded">QUICK</span>
                    </div>
                    <h3 class="text-gray-600 text-sm font-semibold mb-2">2 Hours Vouchers</h3>
                    <p class="text-3xl font-bold text-gray-800" id="stat-2hours">-</p>
                    <p class="text-xs text-gray-500 mt-2">Available in stock</p>
                </div>

                <!-- 12 Hours -->
                <div class="voucher-stat-card bg-white rounded-xl shadow-lg p-6 border-l-4 border-blue-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-blue-100 rounded-lg p-3">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-blue-600 bg-blue-100 px-2 py-1 rounded">HALF DAY</span>
                    </div>
                    <h3 class="text-gray-600 text-sm font-semibold mb-2">12 Hours Vouchers</h3>
                    <p class="text-3xl font-bold text-gray-800" id="stat-12hours">-</p>
                    <p class="text-xs text-gray-500 mt-2">Available in stock</p>
                </div>

                <!-- 7 Days -->
                <div class="voucher-stat-card bg-white rounded-xl shadow-lg p-6 border-l-4 border-green-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-green-100 rounded-lg p-3">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-green-600 bg-green-100 px-2 py-1 rounded">WEEKLY</span>
                    </div>
                    <h3 class="text-gray-600 text-sm font-semibold mb-2">7 Days Vouchers</h3>
                    <p class="text-3xl font-bold text-gray-800" id="stat-7days">-</p>
                    <p class="text-xs text-gray-500 mt-2">Available in stock</p>
                </div>

                <!-- 24 Hours -->
                <div class="voucher-stat-card bg-white rounded-xl shadow-lg p-6 border-l-4 border-indigo-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-indigo-100 rounded-lg p-3">
                            <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-indigo-600 bg-indigo-100 px-2 py-1 rounded">FULL DAY</span>
                    </div>
                    <h3 class="text-gray-600 text-sm font-semibold mb-2">24 Hours Vouchers</h3>
                    <p class="text-3xl font-bold text-gray-800" id="stat-24hours">-</p>
                    <p class="text-xs text-gray-500 mt-2">Available in stock</p>
                </div>

                <!-- 30 Days -->
                <div class="voucher-stat-card bg-white rounded-xl shadow-lg p-6 border-l-4 border-orange-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-orange-100 rounded-lg p-3">
                            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-semibold text-orange-600 bg-orange-100 px-2 py-1 rounded">MONTHLY</span>
                    </div>
                    <h3 class="text-gray-600 text-sm font-semibold mb-2">30 Days Vouchers</h3>
                    <p class="text-3xl font-bold text-gray-800" id="stat-30days">-</p>
                    <p class="text-xs text-gray-500 mt-2">Available in stock</p>
                </div>
            </div>

            <!-- Total Summary -->
            <div class="mt-6 bg-gradient-to-r from-blue-600 to-green-600 rounded-xl shadow-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold opacity-90">Total Unused Vouchers</h3>
                        <p class="text-4xl font-bold mt-2" id="totalVouchers">0</p>
                    </div>
                    <div class="bg-white/20 rounded-lg p-4">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-blue-100 text-sm mt-4">Combined inventory across all voucher types</p>
            </div>

            <!-- Low Stock Alert -->
            <div id="lowStockAlert" class="hidden mt-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-6">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-red-500 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="ml-4">
                        <h4 class="text-red-800 font-bold">Low Stock Warning</h4>
                        <p class="text-red-700 text-sm mt-1" id="lowStockMessage"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentSite = null;

        // Update timestamp
        function updateTimestamp() {
            const now = new Date();
            document.getElementById('lastUpdated').textContent = 
                now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        updateTimestamp();
        setInterval(updateTimestamp, 1000);

        function selectSite(site) {
            currentSite = site;
            
            // Update active state
            document.querySelectorAll('.site-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector(`[data-site="${site}"]`).classList.add('active');
            
            // Show statistics section
            document.getElementById('statisticsSection').classList.remove('hidden');
            
            // Update site name
            const siteNames = {
                'enock': 'Enock (Bite Tech Network)',
                'richard': 'Richard (Richard Network)',
                'stk': 'STK (STK WIFI)'
            };
            document.getElementById('selectedSiteName').textContent = siteNames[site];
            
            // Load statistics
            loadStatistics(site);
        }

        function loadStatistics(site) {
            // Show loading state
            document.getElementById('loadingSpinner').classList.remove('hidden');
            document.getElementById('statsGrid').classList.add('loading');
            
            // Fetch real data from database
            fetch(`?ajax=1&site=${site}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error loading statistics: ' + data.error);
                        return;
                    }
                    
                    // Update statistics
                    document.getElementById('stat-2hours').textContent = data['2hours'].toLocaleString();
                    document.getElementById('stat-12hours').textContent = data['12hours'].toLocaleString();
                    document.getElementById('stat-24hours').textContent = data['24hours'].toLocaleString();
                    document.getElementById('stat-7days').textContent = data['7days'].toLocaleString();
                    document.getElementById('stat-30days').textContent = data['30days'].toLocaleString();
                    
                    // Calculate total
                    const total = data['2hours'] + data['12hours'] + data['24hours'] + data['7days'] + data['30days'];
                    document.getElementById('totalVouchers').textContent = total.toLocaleString();
                    
                    // Check for low stock (less than 50 for any category)
                    const lowStockItems = [];
                    if (data['2hours'] < 50) lowStockItems.push('2 Hours');
                    if (data['12hours'] < 50) lowStockItems.push('12 Hours');
                    if (data['24hours'] < 50) lowStockItems.push('24 Hours');
                    if (data['7days'] < 50) lowStockItems.push('7 Days');
                    if (data['30days'] < 50) lowStockItems.push('30 Days');
                    
                    const alertDiv = document.getElementById('lowStockAlert');
                    if (lowStockItems.length > 0) {
                        document.getElementById('lowStockMessage').textContent = 
                            `The following voucher types are running low: ${lowStockItems.join(', ')}. Please restock soon.`;
                        alertDiv.classList.remove('hidden');
                    } else {
                        alertDiv.classList.add('hidden');
                    }
                    
                    // Remove loading state
                    document.getElementById('loadingSpinner').classList.add('hidden');
                    document.getElementById('statsGrid').classList.remove('loading');
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load statistics. Please try again.');
                    document.getElementById('loadingSpinner').classList.add('hidden');
                    document.getElementById('statsGrid').classList.remove('loading');
                });
        }

        function refreshStats() {
            if (currentSite) {
                loadStatistics(currentSite);
            }
        }
    </script>
</body>
</html>
