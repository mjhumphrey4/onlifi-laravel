<?php
$pageTitle = 'Analyze Performance';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$userSite = Auth::getUserSite();

$viewMode = $_GET['view'] ?? 'week';
$selectedSite = $_GET['site'] ?? ($isAdmin ? 'Enock' : $userSite);

$sites = $isAdmin ? ['Enock', 'Richard', 'STK', 'Remmy', 'Guma'] : [$userSite];

$days = $viewMode === 'week' ? 7 : 30;
$performanceData = getDailyPerformance($selectedSite, $days);

$totalAmount = 0;
$totalTransactions = 0;
$bestDay = ['date' => '', 'amount' => 0];

foreach ($performanceData as $day) {
    $totalAmount += $day['amount'];
    $totalTransactions += $day['transactions'];
    
    if ($day['amount'] > $bestDay['amount']) {
        $bestDay = [
            'date' => $day['date'],
            'amount' => $day['amount']
        ];
    }
}

$avgAmount = count($performanceData) > 0 ? $totalAmount / count($performanceData) : 0;

$chartLabels = [];
$chartData = [];
foreach ($performanceData as $day) {
    $chartLabels[] = date('M d', strtotime($day['date']));
    $chartData[] = $day['amount'];
}
?>

<div class="p-4 sm:p-6 lg:p-8">
    <div class="mb-6 sm:mb-8">
        <h1 class="text-2xl sm:text-3xl text-foreground mb-2">Analyze Performance</h1>
        <p class="text-sm sm:text-base text-muted-foreground">Track your daily and weekly earnings performance</p>
    </div>

    <!-- View Mode Toggle and Site Selector -->
    <div class="flex flex-col sm:flex-row gap-4 mb-6">
        <div class="flex gap-2">
            <a href="?view=week&site=<?php echo urlencode($selectedSite); ?>" 
               class="flex-1 sm:flex-initial px-4 sm:px-6 py-2.5 sm:py-2 rounded-lg transition-colors text-sm sm:text-base <?php echo $viewMode === 'week' ? 'bg-primary text-primary-foreground' : 'bg-card border border-border text-card-foreground hover:border-primary/50'; ?>">
                Week View
            </a>
            <a href="?view=month&site=<?php echo urlencode($selectedSite); ?>" 
               class="flex-1 sm:flex-initial px-4 sm:px-6 py-2.5 sm:py-2 rounded-lg transition-colors text-sm sm:text-base <?php echo $viewMode === 'month' ? 'bg-primary text-primary-foreground' : 'bg-card border border-border text-card-foreground hover:border-primary/50'; ?>">
                Month View
            </a>
        </div>
        
        <?php if ($isAdmin): ?>
        <select 
            onchange="window.location.href='?view=<?php echo $viewMode; ?>&site=' + this.value"
            class="px-4 py-2.5 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
        >
            <?php foreach ($sites as $site): ?>
            <option value="<?php echo htmlspecialchars($site); ?>" <?php echo $selectedSite === $site ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($site); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
            <p class="text-xs sm:text-sm text-muted-foreground mb-1"><?php echo $viewMode === 'week' ? 'Week Total' : 'Month Total'; ?></p>
            <h3 class="text-2xl sm:text-3xl font-bold text-card-foreground"><?php echo formatCurrency($totalAmount); ?></h3>
        </div>
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
            <p class="text-xs sm:text-sm text-muted-foreground mb-1">Daily Average</p>
            <h3 class="text-2xl sm:text-3xl font-bold text-card-foreground"><?php echo formatCurrency($avgAmount); ?></h3>
        </div>
        <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
            <p class="text-xs sm:text-sm text-muted-foreground mb-1">Best Day</p>
            <h3 class="text-2xl sm:text-3xl font-bold text-primary"><?php echo $bestDay['date'] ? date('M d', strtotime($bestDay['date'])) : 'N/A'; ?></h3>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1"><?php echo formatCurrency($bestDay['amount']); ?></p>
        </div>
    </div>

    <!-- Chart -->
    <div class="bg-card border border-border rounded-lg p-4 sm:p-6 mb-6 sm:mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <h2 class="text-lg sm:text-xl text-card-foreground flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="text-sm sm:text-xl"><?php echo $viewMode === 'week' ? 'Last 7 Days' : 'Last 30 Days'; ?></span>
            </h2>
        </div>

        <div class="h-64 sm:h-80">
            <canvas id="performanceChart"></canvas>
        </div>
    </div>

    <!-- Performance Calendar Grid -->
    <div class="bg-card border border-border rounded-lg p-4 sm:p-6">
        <h2 class="text-lg sm:text-xl text-card-foreground mb-4 sm:mb-6">
            <?php echo $viewMode === 'week' ? 'Weekly Performance Calendar' : 'Monthly Performance Calendar'; ?>
        </h2>
        
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3 sm:gap-4">
            <?php foreach ($performanceData as $day): 
                $isToday = $day['date'] === date('Y-m-d');
                $performance = $day['amount'] > $avgAmount ? 'above' : ($day['amount'] == 0 ? 'none' : 'below');
            ?>
            <div class="aspect-square rounded-lg p-3 sm:p-4 border <?php echo $isToday ? 'border-primary bg-primary/10' : 'border-border bg-muted/30'; ?> hover:border-primary/50 transition-colors flex flex-col justify-between">
                <div>
                    <p class="text-xs text-muted-foreground"><?php echo date('D', strtotime($day['date'])); ?></p>
                    <p class="text-sm text-card-foreground mt-1"><?php echo date('d', strtotime($day['date'])); ?></p>
                </div>
                <div class="mt-2">
                    <p class="text-base sm:text-lg <?php echo $performance === 'above' ? 'text-primary' : ($performance === 'none' ? 'text-muted-foreground' : 'text-card-foreground'); ?>">
                        <?php echo $day['amount'] > 0 ? formatCurrency($day['amount']) : '—'; ?>
                    </p>
                    <?php if ($performance === 'above'): ?>
                    <p class="text-xs text-primary mt-1">↑ Above avg</p>
                    <?php elseif ($performance === 'below' && $day['amount'] > 0): ?>
                    <p class="text-xs text-muted-foreground mt-1">↓ Below avg</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('performanceChart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($chartLabels); ?>,
        datasets: [{
            label: 'Amount (UGX)',
            data: <?php echo json_encode($chartData); ?>,
            backgroundColor: '#10B981',
            borderColor: '#10B981',
            borderWidth: 1,
            borderRadius: 8,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#1E3A5F',
                titleColor: '#E8F4F8',
                bodyColor: '#E8F4F8',
                borderColor: 'rgba(52, 211, 153, 0.1)',
                borderWidth: 1,
                padding: 12,
                displayColors: false,
                callbacks: {
                    label: function(context) {
                        return 'UGX ' + context.parsed.y.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(52, 211, 153, 0.1)',
                    drawBorder: false
                },
                ticks: {
                    color: '#94A3B8',
                    callback: function(value) {
                        return 'UGX ' + value.toLocaleString();
                    }
                }
            },
            x: {
                grid: {
                    display: false,
                    drawBorder: false
                },
                ticks: {
                    color: '#94A3B8'
                }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
