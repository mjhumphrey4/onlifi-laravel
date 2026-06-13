<?php
$pageTitle = 'Transactions';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$userSite = Auth::getUserSite();

$activeTab = $_GET['tab'] ?? 'all';
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$searchQuery = $_GET['search'] ?? '';
$itemsPerPage = 15;

$filters = [
    'limit' => $itemsPerPage,
    'offset' => ($currentPage - 1) * $itemsPerPage,
    'search' => $searchQuery
];

if ($activeTab !== 'all' && $activeTab !== 'no-voucher') {
    $filters['status'] = $activeTab;
}

if (!$isAdmin && $userSite) {
    $filters['site'] = $userSite;
}

$transactions = getTransactions($filters);
$totalCount = getTransactionCount($filters);
$totalPages = ceil($totalCount / $itemsPerPage);

if ($activeTab === 'no-voucher') {
    $transactions = array_filter($transactions, function($tx) {
        return $tx['status'] === 'success' && empty($tx['voucher_code']);
    });
}
?>

<div class="p-4 sm:p-6 lg:p-8">
    <div class="mb-6 sm:mb-8">
        <h1 class="text-2xl sm:text-3xl text-foreground mb-2">Transactions</h1>
        <p class="text-sm sm:text-base text-muted-foreground">View and manage all your transactions</p>
    </div>

    <!-- Tabs and Filters -->
    <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
        <!-- Tabs -->
        <div class="flex gap-2 mb-4 sm:mb-6 border-b border-border pb-4 overflow-x-auto scrollbar-hide">
            <?php 
            $tabs = [
                'all' => 'All',
                'success' => 'Success',
                'pending' => 'Pending',
                'failed' => 'Failed',
                'no-voucher' => 'No Voucher'
            ];
            foreach ($tabs as $tabKey => $tabLabel): 
            ?>
            <a href="?tab=<?php echo $tabKey; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" 
               class="px-3 sm:px-6 py-2 rounded-lg capitalize transition-colors whitespace-nowrap text-xs sm:text-sm <?php echo $activeTab === $tabKey ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'; ?>">
                <?php echo $tabLabel; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search Bar -->
        <form method="GET" action="" class="mb-6">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    type="text"
                    name="search"
                    placeholder="Search by phone, reference, voucher code..."
                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                    class="w-full pl-10 pr-4 py-2.5 sm:py-2 bg-input-background border border-border rounded-lg text-foreground placeholder-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary text-sm sm:text-base"
                />
            </div>
        </form>

        <!-- Table -->
        <div class="overflow-x-auto -mx-4 sm:mx-0 mb-6">
            <div class="inline-block min-w-full align-middle">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-border">
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">ID</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Phone</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Reference</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Amount</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Site</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Status</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Date</th>
                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">Voucher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-8 text-center text-muted-foreground">
                                No transactions found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                        <tr class="border-b border-border/50 hover:bg-muted/50 transition-colors">
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">
                                #<?php echo htmlspecialchars(substr($tx['id'], 0, 8)); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">
                                <?php echo htmlspecialchars($tx['msisdn']); ?>
                            </td>
                            <td class="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap font-mono">
                                <?php echo htmlspecialchars(substr($tx['external_ref'], 0, 12)) . '...'; ?>
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
                                <div class="flex flex-col sm:flex-row sm:gap-1">
                                    <span><?php echo date('M d, Y', strtotime($tx['created_at'])); ?></span>
                                    <span class="hidden sm:inline"><?php echo date('h:i A', strtotime($tx['created_at'])); ?></span>
                                </div>
                            </td>
                            <td class="py-3 px-2 sm:px-4 whitespace-nowrap">
                                <?php if (!empty($tx['voucher_code'])): ?>
                                    <span class="text-primary text-xs sm:text-sm">✓</span>
                                <?php else: ?>
                                    <span class="text-muted-foreground text-xs sm:text-sm">—</span>
                                <?php endif; ?>
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
                Showing <?php echo (($currentPage - 1) * $itemsPerPage) + 1; ?> to 
                <?php echo min($currentPage * $itemsPerPage, $totalCount); ?> of <?php echo $totalCount; ?> transactions
            </p>
            <div class="flex gap-2 flex-wrap justify-center">
                <?php if ($currentPage > 1): ?>
                <a href="?tab=<?php echo $activeTab; ?>&page=<?php echo $currentPage - 1; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" 
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
                    <a href="?tab=<?php echo $activeTab; ?>&page=<?php echo $i; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" 
                       class="w-9 h-9 sm:w-10 sm:h-10 rounded-lg transition-colors text-xs sm:text-sm flex items-center justify-center <?php echo $currentPage === $i ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>
                
                <?php if ($currentPage < $totalPages): ?>
                <a href="?tab=<?php echo $activeTab; ?>&page=<?php echo $currentPage + 1; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" 
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
