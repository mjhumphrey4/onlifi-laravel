<?php
ob_start();
require_once __DIR__ . '/config/auth.php';

if (isset($_SESSION['user'])) {
    ob_end_clean();
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (Auth::login($username, $password)) {
        session_write_close();
        ob_end_clean();
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Yo Payments Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --background: #0A1628;
            --foreground: #E8F4F8;
            --card: #1E3A5F;
            --primary: #10B981;
            --primary-foreground: #0A1628;
            --muted: #1A2F4A;
            --muted-foreground: #94A3B8;
            --destructive: #EF4444;
            --border: rgba(52, 211, 153, 0.1);
            --input-background: #1A2F4A;
        }
        
        body {
            background: linear-gradient(135deg, #0A1628 0%, #1E3A5F 100%);
            color: var(--foreground);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
        }
        
        .bg-card { background-color: var(--card); }
        .bg-primary { background-color: var(--primary); }
        .bg-input { background-color: var(--input-background); }
        .text-primary { color: var(--primary); }
        .text-primary-foreground { color: var(--primary-foreground); }
        .text-muted-foreground { color: var(--muted-foreground); }
        .text-destructive { color: var(--destructive); }
        .border-border { border-color: var(--border); }
        
        .hover\:bg-primary\/90:hover { background-color: rgba(16, 185, 129, 0.9); }
        
        .login-card {
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .input-focus:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #10B981 0%, #34D399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo and Title -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-primary rounded-2xl mb-4">
                <svg class="w-10 h-10 text-primary-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <h1 class="text-3xl font-bold gradient-text mb-2">PayDash</h1>
            <p class="text-muted-foreground">Yo Payments Financial Dashboard</p>
        </div>
        
        <!-- Login Card -->
        <div class="bg-card border border-border rounded-2xl p-8 login-card">
            <h2 class="text-2xl font-bold text-foreground mb-6">Welcome Back</h2>
            
            <?php if ($error): ?>
            <div class="mb-6 p-4 bg-destructive/10 border border-destructive/20 rounded-lg">
                <p class="text-destructive text-sm"><?php echo htmlspecialchars($error); ?></p>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="space-y-6">
                <div>
                    <label for="username" class="block text-sm font-medium text-foreground mb-2">Username</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required
                        class="w-full px-4 py-3 bg-input border border-border rounded-lg text-foreground placeholder-muted-foreground input-focus transition-all"
                        placeholder="Enter your username"
                        autocomplete="username"
                    >
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-foreground mb-2">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                        class="w-full px-4 py-3 bg-input border border-border rounded-lg text-foreground placeholder-muted-foreground input-focus transition-all"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                    >
                </div>
                
                <button 
                    type="submit" 
                    class="w-full py-3 bg-primary text-primary-foreground rounded-lg font-semibold hover:bg-primary/90 transition-all transform hover:scale-[1.02] active:scale-[0.98]"
                >
                    Sign In
                </button>
            </form>
            
            <!-- Demo Credentials -->
            <div class="mt-6 p-4 bg-muted/30 border border-border rounded-lg">
                <p class="text-xs text-muted-foreground mb-2 font-semibold">Demo Credentials:</p>
                <div class="space-y-1 text-xs text-muted-foreground">
                    <p><span class="text-primary">admin</span> / password (All sites)</p>
                    <p><span class="text-primary">enock</span> / password (Enock site)</p>
                    <p><span class="text-primary">richard</span> / password (Richard site)</p>
                    <p><span class="text-primary">stk</span> / password (STK site)</p>
                    <p><span class="text-primary">remmy</span> / password (Remmy site)</p>
                    <p><span class="text-primary">guma</span> / password (Guma site)</p>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="mt-8 text-center text-sm text-muted-foreground">
            <p>&copy; <?php echo date('Y'); ?> Yo Payments. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
