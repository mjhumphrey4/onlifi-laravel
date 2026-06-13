<?php
ob_start();
require_once __DIR__ . '/../config/auth.php';
Auth::check();
$user = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Dashboard'; ?> - Yo Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --background: #0A1628;
            --foreground: #E8F4F8;
            --card: #1E3A5F;
            --card-foreground: #E8F4F8;
            --primary: #10B981;
            --primary-foreground: #0A1628;
            --secondary: #2D5A7B;
            --muted: #1A2F4A;
            --muted-foreground: #94A3B8;
            --destructive: #EF4444;
            --border: rgba(52, 211, 153, 0.1);
            --input-background: #1A2F4A;
            --sidebar: #0F2744;
            --sidebar-foreground: #E8F4F8;
            --sidebar-accent: #1A2F4A;
            --sidebar-border: rgba(52, 211, 153, 0.15);
        }
        
        body {
            background-color: var(--background);
            color: var(--foreground);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .bg-background { background-color: var(--background); }
        .bg-card { background-color: var(--card); }
        .bg-primary { background-color: var(--primary); }
        .bg-primary\/10 { background-color: rgba(16, 185, 129, 0.1); }
        .bg-muted { background-color: var(--muted); }
        .bg-muted\/30 { background-color: rgba(26, 47, 74, 0.3); }
        .bg-sidebar { background-color: var(--sidebar); }
        .bg-sidebar-accent { background-color: var(--sidebar-accent); }
        .bg-destructive\/10 { background-color: rgba(239, 68, 68, 0.1); }
        
        .text-foreground { color: var(--foreground); }
        .text-card-foreground { color: var(--card-foreground); }
        .text-primary { color: var(--primary); }
        .text-primary-foreground { color: var(--primary-foreground); }
        .text-muted-foreground { color: var(--muted-foreground); }
        .text-sidebar-foreground { color: var(--sidebar-foreground); }
        .text-destructive { color: var(--destructive); }
        .text-yellow-500 { color: #EAB308; }
        
        .border-border { border-color: var(--border); }
        .border-sidebar-border { border-color: var(--sidebar-border); }
        
        .hover\:bg-sidebar-accent:hover { background-color: var(--sidebar-accent); }
        .hover\:bg-primary\/90:hover { background-color: rgba(16, 185, 129, 0.9); }
        .hover\:bg-muted\/50:hover { background-color: rgba(26, 47, 74, 0.5); }
        .hover\:bg-muted\/80:hover { background-color: rgba(26, 47, 74, 0.8); }
        
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        
        .mobile-menu-overlay { backdrop-filter: blur(4px); }
        
        @media (max-width: 1024px) {
            .sidebar-mobile {
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
            }
            .sidebar-mobile.open {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body class="bg-background">
    <div class="flex h-screen overflow-hidden">
        <!-- Mobile Menu Overlay -->
        <div id="mobileOverlay" class="fixed inset-0 bg-black/50 z-40 lg:hidden hidden mobile-menu-overlay" onclick="toggleMobileMenu()"></div>
        
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar-mobile fixed lg:static inset-y-0 left-0 z-50 w-64 bg-sidebar border-r border-sidebar-border flex flex-col">
            <!-- Close button for mobile -->
            <button onclick="toggleMobileMenu()" class="lg:hidden absolute top-4 right-4 p-2 text-sidebar-foreground hover:bg-sidebar-accent rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            
            <div class="p-6 border-b border-sidebar-border">
                <h1 class="text-2xl font-bold text-primary">PayDash</h1>
                <p class="text-sm text-sidebar-foreground/70 mt-1">Financial Dashboard</p>
            </div>
            
            <nav class="flex-1 p-4 overflow-y-auto">
                <ul class="space-y-2">
                    <li>
                        <a href="index.php" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $currentPage === 'index' ? 'bg-primary text-primary-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent'; ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                            </svg>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="transactions.php" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $currentPage === 'transactions' ? 'bg-primary text-primary-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent'; ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            <span>Transactions</span>
                        </a>
                    </li>
                    <li>
                        <a href="withdrawals.php" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $currentPage === 'withdrawals' ? 'bg-primary text-primary-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent'; ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                            <span>Withdrawals</span>
                        </a>
                    </li>
                    <li>
                        <a href="performance.php" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors <?php echo $currentPage === 'performance' ? 'bg-primary text-primary-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent'; ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                            </svg>
                            <span>Analyze Performance</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <!-- User Info -->
            <div class="p-4 border-t border-sidebar-border">
                <div class="flex items-center gap-3 px-4 py-3 bg-sidebar-accent rounded-lg">
                    <div class="w-10 h-10 bg-primary rounded-full flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-primary-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <div class="overflow-hidden flex-1">
                        <p class="text-sm text-sidebar-foreground truncate font-medium"><?php echo htmlspecialchars($user['name']); ?></p>
                        <p class="text-xs text-sidebar-foreground/60 truncate"><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>
                </div>
                <a href="logout.php" class="mt-2 flex items-center justify-center gap-2 px-4 py-2 bg-destructive/10 text-destructive rounded-lg hover:bg-destructive/20 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Logout
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="flex-1 overflow-auto w-full">
            <!-- Mobile Header -->
            <div class="lg:hidden sticky top-0 z-30 bg-sidebar border-b border-sidebar-border px-4 py-3 flex items-center justify-between">
                <button onclick="toggleMobileMenu()" class="p-2 text-sidebar-foreground hover:bg-sidebar-accent rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <h1 class="text-lg font-bold text-primary">PayDash</h1>
                <div class="w-10"></div>
            </div>
            
            <script>
                function toggleMobileMenu() {
                    const sidebar = document.getElementById('sidebar');
                    const overlay = document.getElementById('mobileOverlay');
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('hidden');
                }
            </script>
