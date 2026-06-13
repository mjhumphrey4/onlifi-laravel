<?php
$pageTitle = 'Withdrawals';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$userSite = Auth::getUserSite();

$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$itemsPerPage = 15;

$sites = $isAdmin ? ['Enock', 'Richard', 'STK', 'Remmy', 'Guma'] : [$userSite];

$totalWithdrawn = 0;
$pendingWithdrawals = 0;
$allWithdrawals = [];

foreach ($sites as $site) {
    $totalWithdrawn += getTotalWithdrawals($site);
    $pendingWithdrawals += getPendingWithdrawals($site);
    
    $siteWithdrawals = getWithdrawalHistory($site, 1000, 0);
    foreach ($siteWithdrawals as $withdrawal) {
        $withdrawal['site'] = $site;
        $allWithdrawals[] = $withdrawal;
    }
}

usort($allWithdrawals, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$totalCount = count($allWithdrawals);
$totalPages = ceil($totalCount / $itemsPerPage);
$offset = ($currentPage - 1) * $itemsPerPage;
$paginatedWithdrawals = array_slice($allWithdrawals, $offset, $itemsPerPage);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_withdrawal') {
    $amount = floatval($_POST['amount'] ?? 0);
    $phone = $_POST['phone'] ?? '';
    $site = $_POST['site'] ?? $userSite;
    
    if ($amount < 1000) {
        $message = 'Minimum withdrawal amount is UGX 1,000';
        $messageType = 'error';
    } elseif (empty($phone)) {
        $message = 'Phone number is required';
        $messageType = 'error';
    } else {
        $siteData = getSalesDataBySite($site);
        $siteTotal = $siteData['total_amount'] ?? 0;
        $siteWithdrawals = getTotalWithdrawals($site);
        $siteBalance = $siteTotal - $siteWithdrawals;
        
        if ($amount > $siteBalance) {
            $message = 'Insufficient balance. Available: ' . formatCurrency($siteBalance);
            $messageType = 'error';
        } else {
            $db = Database::getInstance();
            $withdrawDb = $db->getWithdrawDb();
            
            $transactionId = 'WD' . time() . rand(1000, 9999);
            
            $stmt = $withdrawDb->prepare('
                INSERT INTO transactions (transaction_id, username, phone_number, amount, status, created_at)
                VALUES (:transaction_id, :username, :phone_number, :amount, :status, :created_at)
            ');
            $stmt->bindValue(':transaction_id', $transactionId, SQLITE3_TEXT);
            $stmt->bindValue(':username', $site, SQLITE3_TEXT);
            $stmt->bindValue(':phone_number', $phone, SQLITE3_TEXT);
            $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
            $stmt->bindValue(':status', 'PENDING', SQLITE3_TEXT);
            $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                $message = 'Withdrawal request submitted successfully! Transaction ID: ' . $transactionId;
                $messageType = 'success';
                header('Location: withdrawals.php?success=1');
                exit;
            } else {
                $message = 'Failed to submit withdrawal request';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['success'])) {
    $message = 'Withdrawal request submitted successfully!';
    $messageType = 'success';
}
?>

<div class="p-4 sm:p-6 lg:p-8">
    <div class="mb-6 sm:mb-8">
        <h1 class="text-2xl sm:text-3xl text-foreground mb-2">Withdrawals</h1>
        <p class="text-sm sm:text-base text-muted-foreground">Track and request withdrawal transactions</p>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-primary/10 border border-primary/20 text-primary' : 'bg-destructive/10 border border-destructive/20 text-destructive'; ?>">
        <p class="text-sm"><?php echo htmlspecialchars($message); ?></p>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
            <p class="text-xs sm:text-sm text-muted-foreground mb-1">Total Withdrawn</p>
            <h3 class="text-2xl sm:text-3xl font-bold text-card-foreground"><?php echo formatCurrency($totalWithdrawn); ?></h3>
        </div>
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
            <p class="text-xs sm:text-sm text-muted-foreground mb-1">Pending Withdrawals</p>
            <h3 class="text-2xl sm:text-3xl font-bold text-yellow-500"><?php echo formatCurrency($pendingWithdrawals); ?></h3>
        </div>
    </div>

    <!-- Request Withdrawal Form -->
    <div class="bg-card border border-border rounded-lg p-4 sm:p-6 mb-6 sm:mb-8">
        <h2 class="text-lg sm:text-xl text-card-foreground mb-4">Request Withdrawal</h2>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="request_withdrawal">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php if ($isAdmin): ?>
                <div>
                    <label for="site" class="block text-sm font-medium text-foreground mb-2">Site</label>
                    <select 
                        id="site" 
                        name="site" 
                        required
                        class="w-full px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                    >
                        <?php foreach ($sites as $site): ?>
                        <option value="<?php echo htmlspecialchars($site); ?>"><?php echo htmlspecialchars($site); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="site" value="<?php echo htmlspecialchars($userSite); ?>">
                <?php endif; ?>
                
                <div>
                    <label for="amount" class="block text-sm font-medium text-foreground mb-2">Amount (UGX)</label>
                    <input 
                        type="number" 
                        id="amount" 
                        name="amount" 
                        min="1000"
                        step="1000"
                        required
                        class="w-full px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                        placeholder="Enter amount"
                    >
                    <p class="text-xs text-muted-foreground mt-1">Minimum: UGX 1,000</p>
                </div>
                
                <div>
                    <label for="phone" class="block text-sm font-medium text-foreground mb-2">Phone Number</label>
                    <input 
                        type="tel" 
                        id="phone" 
                        name="phone" 
                        required
                        pattern="[0-9]{10,12}"
                        class="w-full px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground placeholder-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                        placeholder="256XXXXXXXXX"
                    >
                    <p class="text-xs text-muted-foreground mt-1">Format: 256XXXXXXXXX</p>
                </div>
            </div>
            
            <button 
                type="submit" 
                class="w-full sm:w-auto px-6 py-3 bg-primary text-primary-foreground rounded-lg font-semibold hover:bg-primary/90 transition-all transform hover:scale-[1.02] active:scale-[0.98]"
            >
                Submit Withdrawal Request
            </button>
        </form>
    </div>

    <!-- Withdrawals Table -->
    <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <h2 class="text-lg sm:text-xl text-card-foreground">Withdrawal History</h2>
            <button onclick="window.print()" class="flex items-center justify-center gap-2 px-4 py-2.5 sm:py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors text-sm sm:text-base">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Export
            </button>
        </div>

        <div class="overflow-x-auto -mx-4 sm:mx-0 mb-6">
            <div class="inline-block min-w-full align-middle">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-border">
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">ID</th>
                            <?php if ($isAdmin): ?>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Site</th>
                            <?php endif; ?>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Phone</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Amount</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Status</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paginatedWithdrawals)): ?>
                        <tr>
                            <td colspan="<?php echo $isAdmin ? '6' : '5'; ?>" class="px-6 py-8 text-center text-muted-foreground">
                                No withdrawals found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($paginatedWithdrawals as $tx): ?>
                        <tr class="border-b border-border/50 hover:bg-muted/50 transition-colors">
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-mono">
                                <?php echo htmlspecialchars($tx['transaction_id']); ?>
                            </td>
                            <?php if ($isAdmin): ?>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                                <?php echo htmlspecialchars($tx['site']); ?>
                            </td>
                            <?php endif; ?>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">
                                <?php echo htmlspecialchars($tx['phone_number']); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">
                                <?php echo formatCurrency($tx['amount']); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 whitespace-nowrap">
                                <span class="inline-block px-2 sm:px-3 py-1 rounded-full text-xs capitalize <?php echo getStatusBadgeClass($tx['status']); ?>">
                                    <?php echo htmlspecialchars($tx['status']); ?>
                                </span>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                                <div class="flex flex-col sm:flex-row sm:gap-1">
                                    <span><?php echo date('M d, Y', strtotime($tx['created_at'])); ?></span>
                                    <span class="hidden sm:inline"><?php echo date('h:i A', strtotime($tx['created_at'])); ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-xs sm:text-sm text-muted-foreground text-center sm:text-left">
                Showing <?php echo $offset + 1; ?> to 
                <?php echo min($offset + $itemsPerPage, $totalCount); ?> of <?php echo $totalCount; ?> withdrawals
            </p>
            <div class="flex gap-2 flex-wrap justify-center">
                <?php if ($currentPage > 1): ?>
                <a href="?page=<?php echo $currentPage - 1; ?>" 
                   class="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors flex items-center gap-2 text-xs sm:text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                    <span class="hidden sm:inline">Previous</span>
                    <span class="sm:hidden">Prev</span>
                </a>
                <?php endif; ?>
                
                <div class="flex gap-1">
                    <?php 
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                    <a href="?page=<?php echo $i; ?>" 
                       class="w-9 h-9 sm:w-10 sm:h-10 rounded-lg transition-colors text-xs sm:text-sm flex items-center justify-center <?php echo $currentPage === $i ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                
                <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?php echo $currentPage + 1; ?>" 
                   class="px-3 sm:px-4 py-2 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors flex items-center gap-2 text-xs sm:text-sm">
                    Next
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
