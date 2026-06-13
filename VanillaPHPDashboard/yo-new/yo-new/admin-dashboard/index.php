<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$userSite = Auth::getUserSite();

$sites = $isAdmin ? ['Enock', 'Richard', 'STK', 'Remmy', 'Guma'] : [$userSite];

$salesData = [];
$withdrawalsData = [];
$totalEarnings = 0;
$totalWithdrawals = 0;
$todayEarnings = 0;

foreach ($sites as $site) {
    $data = getSalesDataBySite($site);
    $salesData[$site] = $data;
    
    $totalEarnings += $data['total_amount'] ?? 0;
    $todayEarnings += $data['today_amount'] ?? 0;
    
    $withdrawals = getTotalWithdrawals($site);
    $withdrawalsData[$site] = $withdrawals;
    $totalWithdrawals += $withdrawals;
}

$balance = $totalEarnings - $totalWithdrawals;

$recentTransactions = getTransactions(['limit' => 10]);
?>

<div class="p-4 sm:p-6 lg:p-8">
    <div class="mb-6 sm:mb-8">
        <h1 class="text-2xl sm:text-3xl text-foreground mb-2">Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</h1>
        <p class="text-sm sm:text-base text-muted-foreground">Here's what's happening with your account today.</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">
        <!-- Today's Earnings -->
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <p class="text-xs sm:text-sm text-muted-foreground mb-1">Today's Earnings</p>
                    <h3 class="text-2xl sm:text-3xl font-bold text-card-foreground"><?php echo formatCurrency($todayEarnings); ?></h3>
                </div>
                <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-primary">↑ Live updates</span>
            </div>
        </div>

        <!-- Total Earnings -->
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <p class="text-xs sm:text-sm text-muted-foreground mb-1">Total Earnings</p>
                    <h3 class="text-2xl sm:text-3xl font-bold text-card-foreground"><?php echo formatCurrency($totalEarnings); ?></h3>
                </div>
                <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">All time revenue</span>
            </div>
        </div>

        <!-- Total Withdrawals -->
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <p class="text-xs sm:text-sm text-muted-foreground mb-1">Total Withdrawals</p>
                    <h3 class="text-2xl sm:text-3xl font-bold text-card-foreground"><?php echo formatCurrency($totalWithdrawals); ?></h3>
                </div>
                <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                    </svg>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-primary font-semibold">Balance: <?php echo formatCurrency($balance); ?></span>
            </div>
        </div>
    </div>

    <!-- Site Performance Cards (for admin) -->
    <?php if ($isAdmin): ?>
    <div class="mb-6 sm:mb-8">
        <h2 class="text-lg sm:text-xl text-foreground mb-4">Site Performance</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 sm:gap-6">
            <?php 
            $colors = [
                'Enock' => ['bg' => 'bg-blue-600', 'text' => 'text-blue-600'],
                'Richard' => ['bg' => 'bg-green-600', 'text' => 'text-green-600'],
                'STK' => ['bg' => 'bg-purple-600', 'text' => 'text-purple-600'],
                'Remmy' => ['bg' => 'bg-orange-600', 'text' => 'text-orange-600'],
                'Guma' => ['bg' => 'bg-teal-600', 'text' => 'text-teal-600']
            ];
            
            foreach ($sites as $site): 
                $data = $salesData[$site];
                $siteTotal = $data['total_amount'] ?? 0;
                $siteToday = $data['today_amount'] ?? 0;
                $siteWithdrawals = $withdrawalsData[$site];
                $siteBalance = $siteTotal - $siteWithdrawals;
                $color = $colors[$site];
            ?>
            <div class="<?php echo $color['bg']; ?> rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-transform">
                <h3 class="text-lg font-semibold opacity-90 mb-3">Site: <?php echo htmlspecialchars($site); ?></h3>
                <p class="text-3xl font-bold mb-2"><?php echo formatCurrency($siteTotal); ?></p>
                <p class="text-sm opacity-80 mb-4">Total Revenue</p>
                
                <div class="bg-white/10 rounded-lg p-3 mb-3">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-semibold">Today's Sales</span>
                        <span class="font-bold"><?php echo formatCurrency($siteToday); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-semibold">Withdrawals</span>
                        <span class="font-bold text-red-200"><?php echo formatCurrency($siteWithdrawals); ?></span>
                    </div>
                </div>
                
                <div class="border-t border-white/20 pt-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-semibold">Balance</span>
                        <span class="font-bold text-lg text-yellow-200"><?php echo formatCurrency($siteBalance); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Transactions -->
    <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <h2 class="text-lg sm:text-xl text-card-foreground">Recent Transactions</h2>
            <a href="transactions.php" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors text-sm">
                View All
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>

        <div class="overflow-x-auto -mx-4 sm:mx-0">
            <div class="inline-block min-w-full align-middle">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-border">
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">ID</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Phone</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Amount</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Site</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Status</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTransactions)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted-foreground">
                                No transactions found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recentTransactions as $tx): ?>
                        <tr class="border-b border-border/50 hover:bg-muted/50 transition-colors">
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">
                                #<?php echo htmlspecialchars(substr($tx['id'], 0, 8)); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">
                                <?php echo htmlspecialchars($tx['msisdn']); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">
                                <?php echo formatCurrency($tx['amount']); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                                <?php echo htmlspecialchars($tx['origin_site']); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 whitespace-nowrap">
                                <span class="inline-block px-2 sm:px-3 py-1 rounded-full text-xs capitalize <?php echo getStatusBadgeClass($tx['status']); ?>">
                                    <?php echo htmlspecialchars($tx['status']); ?>
                                </span>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                                <?php echo date('M d, h:i A', strtotime($tx['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 60 seconds
setTimeout(function() {
    location.reload();
}, 60000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
