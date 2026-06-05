<?php
/**
 * Yo Payments Transaction Dashboard
 * Using EXACT original API logic, enhanced with robust error handling and date validation
 */

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

// Configuration
define('YO_USERNAME', '100812171094'); // Set your Yo! Payments username
define('YO_PASSWORD', 'BUid-ZAmO-b2M0-vF6n-CzBK-PBaL-8qJK-6SOf'); // Set your Yo! Payments password
define('YO_MODE', 'production'); // Change to 'production' for live

// Include the Yo API
require_once './YoAPI.php';

// Initialize API - EXACTLY as original
$username = YO_USERNAME;
$password = YO_PASSWORD;
$mode = YO_MODE;
$yoAPI = new YoAPI($username, $password, $mode);

// Get filter parameters
// --- ENHANCEMENT: Validate and sanitize date inputs ---
$rawStartDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days')); // Default to last 7 days
$rawEndDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'outbound';

// Validate dates
$startDate = DateTime::createFromFormat('Y-m-d', $rawStartDate) ? $rawStartDate : date('Y-m-d', strtotime('-7 days'));
$endDate = DateTime::createFromFormat('Y-m-d', $rawEndDate) ? $rawEndDate : date('Y-m-d');

// Ensure end date is not before start date
if (strtotime($endDate) < strtotime($startDate)) {
    $endDate = $startDate; // Or swap them, or show an error
}

// Convert dates to API format - EXACTLY as original
$apiStartDate = $startDate . ' 00:00:00';
$apiEndDate = $endDate . ' 23:59:59';

// Initialize arrays and error flags
$mtnTransactions = [];
$airtelTransactions = [];
$allLatestTransactions = [];
$mtnBalance = 0;
$airtelBalance = 0;
$mtnApiError = null;
$airtelApiError = null;
$generalApiError = null;

/**
 * MTN transactions (excluding charges)
 */
try {
    $response = $yoAPI->ac_get_ministatement($apiStartDate, $apiEndDate, 'SUCCEEDED', 'UGX-MTNMM', 0, 'TRANSACTION');
    if (isset($response['Status']) && $response['Status'] == 'OK') {
        $mtnTransactions = $response['Transactions'] ?? [];
        if (!is_array($mtnTransactions)) {
            $mtnTransactions = [];
            error_log("MTN API returned non-array Transactions: " . print_r($response, true));
        }
    } else {
        $mtnApiError = ($response['Status'] ?? 'UNKNOWN_ERROR') . ": " . ($response['StatusMessage'] ?? 'No message');
        error_log("MTN API Error: " . $mtnApiError);
    }
} catch (Exception $e) {
    $mtnApiError = "Exception during MTN API call: " . $e->getMessage();
    error_log($mtnApiError);
}

/**
 * Airtel transactions (excluding charges)
 */
try {
    $airtel_response = $yoAPI->ac_get_ministatement($apiStartDate, $apiEndDate, 'SUCCEEDED', 'UGX-WTLMM', 0, 'TRANSACTION');
    if (isset($airtel_response['Status']) && $airtel_response['Status'] == 'OK') {
        $airtelTransactions = $airtel_response['Transactions'] ?? [];
        if (!is_array($airtelTransactions)) {
            $airtelTransactions = [];
            error_log("Airtel API returned non-array Transactions: " . print_r($airtel_response, true));
        }
    } else {
        $airtelApiError = ($airtel_response['Status'] ?? 'UNKNOWN_ERROR') . ": " . ($airtel_response['StatusMessage'] ?? 'No message');
        error_log("Airtel API Error: " . $airtelApiError);
    }
} catch (Exception $e) {
    $airtelApiError = "Exception during Airtel API call: " . $e->getMessage();
    error_log($airtelApiError);
}

/**
 * Latest 5 transactions (including charges) for balance
 */
try {
    $general_response = $yoAPI->ac_get_ministatement();
    if (isset($general_response['Status']) && $general_response['Status'] == 'OK') {
        $allLatestTransactions = $general_response['Transactions'] ?? [];
        if (!is_array($allLatestTransactions)) {
            $allLatestTransactions = [];
            error_log("General API returned non-array Transactions: " . print_r($general_response, true));
        }
        // Get balance from latest transaction of each provider
        foreach ($allLatestTransactions as $trans) {
            if (isset($trans['Currency']) && isset($trans['Balance'])) {
                if (strpos($trans['Currency'], 'MTNMM') !== false && $mtnBalance == 0) {
                    $mtnBalance = $trans['Balance'];
                }
                if (strpos($trans['Currency'], 'WTLMM') !== false && $airtelBalance == 0) {
                    $airtelBalance = $trans['Balance'];
                }
            }
        }
    } else {
        $generalApiError = ($general_response['Status'] ?? 'UNKNOWN_ERROR') . ": " . ($general_response['StatusMessage'] ?? 'No message');
        error_log("General API Error: " . $generalApiError);
    }
} catch (Exception $e) {
    $generalApiError = "Exception during General API call: " . $e->getMessage();
    error_log($generalApiError);
}

// Combine all filtered transactions
$allTransactions = array_merge($mtnTransactions, $airtelTransactions);

// Sort by completion date (newest first)
if (!empty($allTransactions)) {
    usort($allTransactions, function ($a, $b) {
        // Check if CompletionDate exists in both transactions
        if (!isset($a['CompletionDate']) || !isset($b['CompletionDate'])) {
            // If CompletionDate is missing, sort based on index (maintain order)
            return 0;
        }
        $timestampA = strtotime($a['CompletionDate']);
        $timestampB = strtotime($b['CompletionDate']);
        // Handle potential strtotime failures (returns false/ -1)
        if ($timestampA === false || $timestampB === false) {
             return 0; // Cannot compare, maintain order
        }
        return $timestampB - $timestampA; // Descending order (newest first)
    });
}

// Separate into outbound and incoming based on GeneralType
$outboundTransactions = [];
$incomingTransactions = [];
$totalOutbound = 0;
$totalIncoming = 0;

foreach ($allTransactions as $transaction) {
    if (isset($transaction['GeneralType'])) {
        if ($transaction['GeneralType'] == 'DEBIT') {
            $outboundTransactions[] = $transaction;
            $totalOutbound += ($transaction['Amount'] ?? 0);
        } elseif ($transaction['GeneralType'] == 'CREDIT') {
            $incomingTransactions[] = $transaction;
            $totalIncoming += ($transaction['Amount'] ?? 0);
        }
    }
}

$netFlow = $totalIncoming - $totalOutbound;

// Helper functions (with minor safety improvements)
function formatAmount($amount) {
    if (!is_numeric($amount)) {
        $amount = 0;
    }
    return number_format($amount, 0, '.', ',');
}

function formatDate($dateString) {
    $timestamp = strtotime($dateString);
    if ($timestamp === false) {
        return 'Invalid Date (' . $dateString . ')';
    }
    return date('d/m/Y, H:i:s', $timestamp);
}

function getProviderFromCurrency($currency) {
    if (!is_string($currency)) {
        return 'Unknown';
    }
    if (strpos($currency, 'MTNMM') !== false) {
        return 'MTN';
    } elseif (strpos($currency, 'WTLMM') !== false) {
        return 'Airtel';
    }
    return 'Unknown';
}

function decodeBase64($encoded) {
    if (!is_string($encoded)) {
        return 'Invalid Data';
    }
    // Validate base64 format before decoding
    if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $encoded)) {
        return 'Invalid Base64 Format';
    }
    $decoded = base64_decode($encoded, true); // strict mode
    if ($decoded === false) {
        return 'Decoding Failed';
    }
    return $decoded;
}

function getDisplayReference($ref) {
    if (!is_string($ref)) {
        return 'N/A';
    }
    if (strlen($ref) > 30) {
        return substr($ref, 0, 30) . '...';
    }
    return $ref;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .balance-card {
            background: linear-gradient(135deg, var(--color-start) 0%, var(--color-end) 100%);
        }
        .mtn-card {
            --color-start: #FFCB05;
            --color-end: #FDB913;
        }
        .airtel-card {
            --color-start: #FF4D4D;
            --color-end: #FF6B6B;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
    <div class="max-w-7xl mx-auto p-6 space-y-6">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900">Transaction Dashboard</h1>
                <p class="text-slate-500 text-sm mt-1">
                    Last updated: <?php echo date('H:i:s'); ?>
                </p>
            </div>
            <button onclick="window.location.reload()"
                    class="px-4 py-2 bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors text-sm font-medium">
                Refresh
            </button>
        </div>

        <!-- Configuration Warning -->
        <?php if(empty(YO_USERNAME) || empty(YO_PASSWORD)): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-yellow-700">
                        <strong>Configuration Required:</strong> Please set YO_USERNAME and YO_PASSWORD at the top of this PHP file.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- API Error Messages -->
        <?php if ($mtnApiError): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700"><strong>MTN API Error:</strong> <?php echo htmlspecialchars($mtnApiError); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($airtelApiError): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700"><strong>Airtel API Error:</strong> <?php echo htmlspecialchars($airtelApiError); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($generalApiError): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700"><strong>General API Error:</strong> <?php echo htmlspecialchars($generalApiError); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>


        <!-- No Transactions Message (only if no errors occurred) -->
        <?php if(!empty(YO_USERNAME) && !empty(YO_PASSWORD) && count($allTransactions) == 0 && !$mtnApiError && !$airtelApiError): ?>
        <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700">
                        <strong>No transactions found for the selected date range.</strong>
                    </p>
                    <p class="text-xs text-blue-600 mt-1">
                        Date Range: <?php echo $startDate; ?> to <?php echo $endDate; ?>
                    </p>
                    <p class="text-xs text-blue-600">
                        Filters Applied: Status=SUCCEEDED, Type=TRANSACTION, Currencies=MTN, Airtel
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>


        <!-- Balance Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- MTN Balance -->
            <div class="balance-card mtn-card rounded-xl shadow-lg p-6 text-white relative overflow-hidden">
                <div class="absolute top-4 right-4 px-3 py-1 bg-white/20 backdrop-blur-sm rounded-full text-xs font-medium">
                    Live
                </div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold">MTN Balance</h3>
                </div>
                <div>
                    <p class="text-sm opacity-90 mb-1">Available Balance</p>
                    <p class="text-4xl font-bold">UGX <?php echo formatAmount($mtnBalance); ?></p>
                </div>
            </div>

            <!-- Airtel/Warid Balance -->
            <div class="balance-card airtel-card rounded-xl shadow-lg p-6 text-white relative overflow-hidden">
                <div class="absolute top-4 right-4 px-3 py-1 bg-white/20 backdrop-blur-sm rounded-full text-xs font-medium">
                    Live
                </div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold">Airtel/Warid Balance</h3>
                </div>
                <div>
                    <p class="text-sm opacity-90 mb-1">Available Balance</p>
                    <p class="text-4xl font-bold">UGX <?php echo formatAmount($airtelBalance); ?></p>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-slate-600 text-sm font-medium">Total Outbound</h3>
                    <div class="w-8 h-8 bg-red-50 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-slate-900">UGX <?php echo formatAmount($totalOutbound); ?></p>
                <p class="text-slate-500 text-xs mt-2">MTN + Airtel Combined</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-slate-600 text-sm font-medium">Total Incoming</h3>
                    <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold text-slate-900">UGX <?php echo formatAmount($totalIncoming); ?></p>
                <p class="text-slate-500 text-xs mt-2">MTN + Airtel Combined</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-slate-600 text-sm font-medium">Net Flow</h3>
                    <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                    </div>
                </div>
                <p class="text-3xl font-bold <?php echo $netFlow >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                    <?php echo $netFlow >= 0 ? '+' : ''; ?>UGX <?php echo formatAmount(abs($netFlow)); ?>
                </p>
                <p class="text-slate-500 text-xs mt-2">Incoming - Outbound</p>
            </div>
        </div>

        <!-- Date Filter -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div class="flex items-center gap-2 text-slate-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span class="font-medium">Filter by Date:</span>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-slate-600">From:</label>
                    <input type="date" name="start_date" value="<?php echo $startDate; ?>"
                           class="px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-slate-900 text-sm">
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-slate-600">To:</label>
                    <input type="date" name="end_date" value="<?php echo $endDate; ?>"
                           class="px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-slate-900 text-sm">
                </div>
                <input type="hidden" name="tab" value="<?php echo $activeTab; ?>">
                <button type="submit"
                        class="px-4 py-2 bg-slate-900 text-white rounded-lg hover:bg-slate-800 transition-colors text-sm font-medium">
                    Apply Filter
                </button>
                <a href="?" class="px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition-colors text-sm font-medium">
                    Clear
                </a>
            </form>
        </div>

        <!-- Transactions Section -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <!-- Tabs -->
            <div class="flex border-b border-slate-200">
                <a href="?start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&tab=outbound"
                   class="flex-1 px-6 py-4 text-sm font-medium transition-colors <?php echo $activeTab == 'outbound' ? 'text-slate-900 border-b-2 border-slate-900 bg-slate-50' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-50'; ?>">
                    Outbound Transactions
                    <span class="ml-2 px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-xs">
                        <?php echo count($outboundTransactions); ?>
                    </span>
                </a>
                <a href="?start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&tab=incoming"
                   class="flex-1 px-6 py-4 text-sm font-medium transition-colors <?php echo $activeTab == 'incoming' ? 'text-slate-900 border-b-2 border-slate-900 bg-slate-50' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-50'; ?>">
                    Incoming Transactions
                    <span class="ml-2 px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-xs">
                        <?php echo count($incomingTransactions); ?>
                    </span>
                </a>
            </div>

            <!-- Transaction List -->
            <div class="p-6">
                <?php
                $displayTransactions = $activeTab == 'outbound' ? $outboundTransactions : $incomingTransactions;

                if(count($displayTransactions) == 0) {
                    echo '<div class="text-center py-12">';
                    echo '<svg class="w-16 h-16 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
                    echo '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
                    echo '<p class="text-slate-500 text-lg">No ' . $activeTab . ' transactions found</p>';
                    echo '<p class="text-slate-400 text-sm mt-2">Try adjusting your date range or check the other tab</p>';
                    echo '</div>';
                } else {
                    echo '<div class="space-y-3">';
                    foreach($displayTransactions as $transaction) {
                        $provider = getProviderFromCurrency($transaction['Currency'] ?? '');
                        $providerColor = $provider == 'MTN' ? 'bg-yellow-100 text-yellow-800' : ($provider == 'Airtel' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800');
                        $amount = $transaction['Amount'] ?? 0;
                        $status = $transaction['TransactionStatus'] ?? 'UNKNOWN';
                        $statusColor = $status == 'SUCCEEDED' ? 'text-green-600' : 'text-red-600';
                        $statusIcon = $status == 'SUCCEEDED' ? '✓' : '✗';

                        // Get beneficiary/sender info
                        $displayName = 'N/A';
                        if(isset($transaction['BeneficiaryMsisdn']) && !empty($transaction['BeneficiaryMsisdn'])) {
                            $displayName = $transaction['BeneficiaryMsisdn'];
                        } elseif(isset($transaction['BeneficiaryBase64']) && !empty($transaction['BeneficiaryBase64'])) {
                            $displayName = decodeBase64($transaction['BeneficiaryBase64']);
                        } elseif(isset($transaction['SenderBase64']) && !empty($transaction['SenderBase64'])) {
                            $displayName = decodeBase64($transaction['SenderBase64']);
                        }

                        $transactionRef = getDisplayReference($transaction['TransactionReference'] ?? '');
                        $completionDate = formatDate($transaction['CompletionDate'] ?? '');

                        echo '<div class="flex items-center justify-between p-4 bg-slate-50 rounded-lg hover:bg-slate-100 transition-colors">';
                        echo '<div class="flex items-center gap-4 flex-1">';

                        // Icon
                        echo '<div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center shadow-sm">';
                        echo '<svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
                        if($activeTab == 'outbound') {
                            echo '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/>';
                        } else {
                            echo '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"/>';
                        }
                        echo '</svg>';
                        echo '</div>';

                        // Details
                        echo '<div class="flex-1">';
                        echo '<div class="flex items-center gap-2 mb-1">';
                        echo '<span class="font-medium text-slate-900">' . htmlspecialchars($displayName) . '</span>';
                        echo '<span class="px-2 py-0.5 rounded text-xs font-medium ' . $providerColor . '">' . $provider . '</span>';
                        echo '</div>';
                        echo '<p class="text-sm text-slate-500">' . htmlspecialchars($transactionRef) . ' • ' . $completionDate . '</p>';
                        echo '</div>';

                        // Amount and Status
                        echo '<div class="text-right">';
                        $amountPrefix = $activeTab == 'outbound' ? '-' : '+';
                        echo '<p class="text-lg font-semibold text-slate-900">' . $amountPrefix . 'UGX ' . formatAmount($amount) . '</p>';
                        echo '<p class="text-sm ' . $statusColor . ' flex items-center justify-end gap-1">';
                        echo '<span>' . $statusIcon . '</span>';
                        echo '<span>' . ucfirst(strtolower($status)) . '</span>';
                        echo '</p>';
                        echo '</div>';

                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </div>

        <!-- Raw Data Debug (Remove in production) -->
        <?php if(isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
        <div class="bg-gray-100 rounded-xl p-4 text-xs">
            <h3 class="font-bold mb-2">Debug Info:</h3>
            <p><strong>Raw Start Date:</strong> <?php echo htmlspecialchars($rawStartDate); ?></p>
            <p><strong>Processed Start Date:</strong> <?php echo htmlspecialchars($startDate); ?></p>
            <p><strong>Raw End Date:</strong> <?php echo htmlspecialchars($rawEndDate); ?></p>
            <p><strong>Processed End Date:</strong> <?php echo htmlspecialchars($endDate); ?></p>
            <p><strong>API Start Date:</strong> <?php echo htmlspecialchars($apiStartDate); ?></p>
            <p><strong>API End Date:</strong> <?php echo htmlspecialchars($apiEndDate); ?></p>
            <p><strong>Total MTN Transactions:</strong> <?php echo count($mtnTransactions); ?></p>
            <p><strong>Total Airtel Transactions:</strong> <?php echo count($airtelTransactions); ?></p>
            <p><strong>Outbound:</strong> <?php echo count($outboundTransactions); ?></p>
            <p><strong>Incoming:</strong> <?php echo count($incomingTransactions); ?></p>
            <details class="mt-2">
                <summary class="cursor-pointer font-bold">View Sample Transaction</summary>
                <pre class="mt-2 bg-white p-2 overflow-auto"><?php if(count($allTransactions) > 0) print_r($allTransactions[0]); ?></pre>
            </details>
            <details class="mt-2">
                <summary class="cursor-pointer font-bold">View Full MTN Response</summary>
                <pre class="mt-2 bg-white p-2 overflow-auto"><?php print_r($response ?? 'Not Called'); ?></pre>
            </details>
            <details class="mt-2">
                <summary class="cursor-pointer font-bold">View Full Airtel Response</summary>
                <pre class="mt-2 bg-white p-2 overflow-auto"><?php print_r($airtel_response ?? 'Not Called'); ?></pre>
            </details>
            <details class="mt-2">
                <summary class="cursor-pointer font-bold">View Full General Response</summary>
                <pre class="mt-2 bg-white p-2 overflow-auto"><?php print_r($general_response ?? 'Not Called'); ?></pre>
            </details>
        </div>
        <?php endif; ?>

    </div>

    <script>
        // Auto-refresh every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes in milliseconds
    </script>
</body>
</html>