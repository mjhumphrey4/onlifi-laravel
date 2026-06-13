<?php

// Set custom session save path to current directory
$sessionPath = __DIR__ . '/sessions';
if (!file_exists($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}
ini_set('session.save_path', $sessionPath);

session_start();

// Database initialization
function initDB() {
    $db = new SQLite3(__DIR__ . '/withdrawals.db');
    
    // Create users table
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Create transactions table
    $db->exec('CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        phone_number TEXT NOT NULL,
        amount REAL NOT NULL,
        status TEXT NOT NULL,
        transaction_reference TEXT,
        response_message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Insert default users if not exists (password: SecurePass@2024!)
    $defaultPassword = password_hash('SecurePass@2024!', PASSWORD_BCRYPT);
    $users = ['Enock', 'Richard', 'STK', 'Guma', 'Kigoma', 'Remmy'];
    
    foreach ($users as $user) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO users (username, password) VALUES (:username, :password)');
        $stmt->bindValue(':username', $user, SQLITE3_TEXT);
        $stmt->bindValue(':password', $defaultPassword, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    return $db;
}

$db = initDB();

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = $username;
        // Don't redirect, just let the page reload naturally to show the logged-in state
        // The page will automatically show the withdrawal form since $_SESSION['user'] is now set
    } else {
        $error = 'Invalid username or password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle withdrawal
if (isset($_POST['withdraw']) && isset($_SESSION['user'])) {
    $phone = '256' . preg_replace('/[^0-9]/', '', $_POST['phone']);
    $amount = floatval($_POST['amount']);
    $selectedSite = $_POST['selected_site'] ?? $_SESSION['user'];
    
    // Store the selected site in session for tracking
    $_SESSION['selected_site'] = $selectedSite;
    
    if (strlen($phone) != 12 || $amount <= 0) {
        $withdrawError = 'Invalid phone number or amount';
    } else {
        // Call the withdrawal API
        $response = processWithdrawal($phone, $amount);
        
        // Save transaction to database with the selected site as username
        $stmt = $db->prepare('INSERT INTO transactions (username, phone_number, amount, status, transaction_reference, response_message) 
                              VALUES (:username, :phone, :amount, :status, :ref, :msg)');
        $stmt->bindValue(':username', $selectedSite, SQLITE3_TEXT);
        $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
        $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $stmt->bindValue(':status', $response['status'], SQLITE3_TEXT);
        $stmt->bindValue(':ref', $response['reference'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':msg', $response['message'], SQLITE3_TEXT);
        $stmt->execute();
        
        if ($response['status'] == 'SUCCEEDED') {
            $successMsg = $response['display_message'];
        } else {
            $withdrawError = 'Withdrawal failed: ' . $response['message'];
        }
    }
}

function processWithdrawal($phone, $amount) {
    // Prepare data to send to withdrawal processor
    $data = [
        'msisdn' => $phone,
        'amount' => $amount
    ];
    
    // Build the full URL to process_withdrawal.php
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF']);
    $url = $protocol . '://' . $host . $path . '/process_withdrawal.php';
    
    // Use cURL to call the withdrawal processor
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Check for cURL errors
    if ($curlError) {
        return [
            'status' => 'FAILED',
            'message' => 'Connection error: ' . $curlError,
            'reference' => null,
            'display_message' => 'Connection error: ' . $curlError
        ];
    }
    
    // Check HTTP status
    if ($httpCode != 200) {
        return [
            'status' => 'FAILED',
            'message' => 'HTTP error: ' . $httpCode,
            'reference' => null,
            'display_message' => 'HTTP error: ' . $httpCode
        ];
    }
    
    $result = json_decode($response, true);
    
    if ($result && isset($result['status'])) {
        // Check if the message indicates pending authorization - treat as SUCCEEDED
        if (isset($result['message']) && 
            stripos($result['message'], 'This transaction requires extra authorization') !== false) {
            
            return [
                'status' => 'SUCCEEDED',
                'message' => $result['message'],
                'reference' => $result['reference'] ?? 'AUTH-' . time(),
                'display_message' => 'Withdrawal successful! Awaiting authorization and depositing in 5 minutes!! Reference: ' . ($result['reference'] ?? 'AUTH-' . time())
            ];
        }
        
        // Return the original response with display message
        return [
            'status' => $result['status'],
            'message' => $result['message'] ?? 'Unknown response',
            'reference' => $result['reference'] ?? null,
            'display_message' => $result['status'] == 'SUCCEEDED' 
                ? 'Withdrawal successful, Now awaiting approval and depositing in 5 minutes!! Reference: ' . ($result['reference'] ?? 'N/A')
                : 'Withdrawal failed: ' . ($result['message'] ?? 'Unknown error')
        ];
    }
    
    return [
        'status' => 'FAILED',
        'message' => 'Invalid response from processor: ' . substr($response, 0, 100),
        'reference' => null,
        'display_message' => 'Invalid response from processor'
    ];
}

// Fetch transactions for logged-in user
$transactions = [];
if (isset($_SESSION['user'])) {
    // For Enock, show transactions from both Enock and Kigoma sites
    if ($_SESSION['user'] === 'Enock') {
        $stmt = $db->prepare('SELECT * FROM transactions WHERE username IN ("Enock", "Kigoma") ORDER BY created_at DESC LIMIT 20');
        $result = $stmt->execute();
    } else {
        $stmt = $db->prepare('SELECT * FROM transactions WHERE username = :username ORDER BY created_at DESC LIMIT 20');
        $stmt->bindValue(':username', $_SESSION['user'], SQLITE3_TEXT);
        $result = $stmt->execute();
    }
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $transactions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(14, 165, 233, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }
        
        .container {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            position: relative;
            z-index: 1;
        }
        
        .header {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.3) 0%, rgba(14, 165, 233, 0.3) 100%);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(59, 130, 246, 0.3);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            text-shadow: 0 2px 10px rgba(59, 130, 246, 0.5);
        }
        
        .content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #e2e8f0;
        }
        
        input[type="text"],
        input[type="password"],
        input[type="number"],
        select {
            width: 100%;
            padding: 12px;
            background: rgba(30, 41, 59, 0.6);
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-radius: 8px;
            font-size: 16px;
            color: #e2e8f0;
            transition: all 0.3s;
        }
        
        input::placeholder {
            color: #64748b;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: rgba(59, 130, 246, 0.8);
            background: rgba(30, 41, 59, 0.8);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);
        }
        
        select {
            cursor: pointer;
        }
        
        select option {
            background: #1e293b;
            color: #e2e8f0;
        }
        
        .phone-input {
            display: flex;
            align-items: center;
        }
        
        .phone-prefix {
            background: rgba(59, 130, 246, 0.2);
            padding: 12px 15px;
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-right: none;
            border-radius: 8px 0 0 8px;
            font-weight: 600;
            color: #3b82f6;
            backdrop-filter: blur(10px);
        }
        
        .phone-input input {
            border-radius: 0 8px 8px 0;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.8) 0%, rgba(14, 165, 233, 0.8) 100%);
            color: white;
            border: 1px solid rgba(59, 130, 246, 0.5);
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.4);
            background: linear-gradient(135deg, rgba(59, 130, 246, 1) 0%, rgba(14, 165, 233, 1) 100%);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.4);
        }
        
        .alert-warning {
            background: rgba(234, 179, 8, 0.2);
            color: #fde047;
            border: 1px solid rgba(234, 179, 8, 0.4);
        }
        
        .transactions {
            margin-top: 40px;
        }
        
        .transactions h2 {
            margin-bottom: 20px;
            color: #e2e8f0;
            text-shadow: 0 2px 10px rgba(59, 130, 246, 0.3);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(10px);
            border-radius: 8px;
            overflow: hidden;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
            color: #e2e8f0;
        }
        
        th {
            background: rgba(59, 130, 246, 0.2);
            font-weight: 600;
            color: #93c5fd;
        }
        
        tr:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-succeeded {
            background: rgba(34, 197, 94, 0.3);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.5);
        }
        
        .status-pending_authorization {
            background: rgba(234, 179, 8, 0.3);
            color: #fde047;
            border: 1px solid rgba(234, 179, 8, 0.5);
        }
        
        .status-failed {
            background: rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.5);
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: auto;
            padding: 8px 20px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .user-info {
            margin-top: 10px;
            font-size: 14px;
            opacity: 0.9;
        }
        
        small {
            color: #94a3b8;
            display: block;
            margin-top: 5px;
        }
        
        .balance-card {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(5, 150, 105, 0.2) 100%);
            border: 2px solid rgba(16, 185, 129, 0.4);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.15);
            text-align: center;
        }
        
        .balance-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #6ee7b7;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .balance-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        
        .balance-amount {
            font-size: 48px;
            font-weight: 800;
            color: #10b981;
            text-shadow: 0 2px 20px rgba(16, 185, 129, 0.5);
            letter-spacing: -1px;
            line-height: 1.2;
        }
        
        .balance-details {
            display: none;
        }
        
        .loading-spinner {
            color: #6ee7b7;
            font-size: 18px;
        }
        
        .site-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .site-enock {
            background: rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.5);
        }
        
        .site-kigoma {
            background: rgba(14, 165, 233, 0.3);
            color: #7dd3fc;
            border: 1px solid rgba(14, 165, 233, 0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💸 Withdrawal Dashboard</h1>
            <?php if (isset($_SESSION['user'])): ?>
                <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user']); ?></strong></div>
                <a href="?logout=1"><button class="logout-btn">Logout</button></a>
            <?php else: ?>
                <p>Secure Login Required</p>
            <?php endif; ?>
        </div>
        
        <div class="content">
            <?php if (!isset($_SESSION['user'])): ?>
                <!-- Login Form -->
                <?php if (isset($error)): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Username</label>
                        <select name="username" required>
                            <option value="">Select User</option>
                            <option value="Enock">Enock</option>
                            <option value="Richard">Richard</option>
                            <option value="STK">STK</option>
                            <option value="Guma">Guma</option>
                            <option value="Remmy">Remmy</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required placeholder="Enter password">
                        <small>Contact Humphrey for your account password</small>
                    </div>
                    
                    <button type="submit" name="login">Login</button>
                </form>
            <?php else: ?>
                <!-- Withdrawal Form -->
                <?php if (isset($withdrawError)): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($withdrawError); ?></div>
                <?php endif; ?>
                
                <?php if (isset($successMsg)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
                <?php endif; ?>
                
                <!-- Balance Display -->
                <div class="balance-card" id="balanceCard">
                    <div class="balance-header">
                        <svg class="balance-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Available to Withdraw</span>
                    </div>
                    <div class="balance-amount" id="balanceAmount">
                        <div class="loading-spinner">Loading...</div>
                    </div>
                </div>
                
                <form method="POST" id="withdrawForm">
                    <?php if ($_SESSION['user'] === 'Enock'): ?>
                    <div class="form-group">
                        <label>Select Site</label>
                        <select name="selected_site" id="siteSelector" required>
                            <option value="Enock">Enock Site (Bite Tech Network)</option>
                            <option value="Kigoma">New Kigoma Site (Bite Tech Network)</option>
                        </select>
                        <small>Choose which site to withdraw from</small>
                    </div>
                    <?php elseif ($_SESSION['user'] === 'STK'): ?>
                    <div class="form-group">
                        <label>Select Site</label>
                        <select name="selected_site" id="siteSelector" required>
                            <option value="STK">STK Site (STK WIFI)</option>
                            <option value="Guma">Guma Site (Profile)</option>
                        </select>
                        <small>Choose which site to withdraw from</small>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="selected_site" id="siteSelector" value="<?php echo htmlspecialchars($_SESSION['user']); ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Receiver Phone Number</label>
                        <div class="phone-input">
                            <span class="phone-prefix">+256</span>
                            <input type="text" name="phone" required placeholder="7XXXXXXXX" pattern="[0-9]{9}" maxlength="9">
                        </div>
                        <small>Enter 9 digits (e.g., 774123456)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Amount (UGX)</label>
                        <input type="number" name="amount" id="amountInput" required placeholder="Enter amount" min="1000" step="100">
                        <small id="amountError" class="error-text" style="display: none; color: #fca5a5;">Amount exceeds available balance!</small>
                    </div>
                    
                    <button type="submit" name="withdraw">Process Withdrawal</button>
                </form>
                
                <!-- Transaction History -->
                <?php if (count($transactions) > 0): ?>
                    <div class="transactions">
                        <h2>Recent Transactions</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <?php if ($_SESSION['user'] === 'Enock'): ?>
                                    <th>Site</th>
                                    <?php endif; ?>
                                    <th>Phone</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $tx): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i', strtotime($tx['created_at'])); ?></td>
                                        <?php if ($_SESSION['user'] === 'Enock'): ?>
                                        <td>
                                            <span class="site-badge site-<?php echo strtolower($tx['username']); ?>">
                                                <?php echo htmlspecialchars($tx['username']); ?>
                                            </span>
                                        </td>
                                        <?php endif; ?>
                                        <td>+<?php echo htmlspecialchars($tx['phone_number']); ?></td>
                                        <td>UGX <?php echo number_format($tx['amount']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($tx['status']); ?>">
                                                <?php echo htmlspecialchars($tx['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($tx['transaction_reference'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isset($_SESSION['user'])): ?>
    <script>
        let currentBalance = 0;
        
        // Function to fetch balance
        function fetchBalance() {
            const siteSelector = document.getElementById('siteSelector');
            const site = siteSelector ? siteSelector.value : '<?php echo htmlspecialchars($_SESSION['user']); ?>';
            
            fetch('get_balance.php?site=' + encodeURIComponent(site))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentBalance = data.balance;
                        
                        // Update balance display with formatted amount
                        document.getElementById('balanceAmount').innerHTML = 
                            'UGX ' + new Intl.NumberFormat('en-UG', {
                                minimumFractionDigits: 0,
                                maximumFractionDigits: 0
                            }).format(data.balance);
                    } else {
                        document.getElementById('balanceAmount').innerHTML = 
                            '<span style="color: #fca5a5; font-size: 20px;">Error loading</span>';
                    }
                })
                .catch(error => {
                    console.error('Error fetching balance:', error);
                    document.getElementById('balanceAmount').innerHTML = 
                        '<span style="color: #fca5a5; font-size: 20px;">Error loading</span>';
                });
        }
        
        // Validate amount against balance
        function validateAmount() {
            const amountInput = document.getElementById('amountInput');
            const amountError = document.getElementById('amountError');
            const amount = parseFloat(amountInput.value);
            
            if (amount > currentBalance) {
                amountError.style.display = 'block';
                amountInput.style.borderColor = 'rgba(239, 68, 68, 0.8)';
                return false;
            } else {
                amountError.style.display = 'none';
                amountInput.style.borderColor = 'rgba(59, 130, 246, 0.3)';
                return true;
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            fetchBalance();
            
            // Listen for site selector changes (for Enock)
            const siteSelector = document.getElementById('siteSelector');
            if (siteSelector && siteSelector.tagName === 'SELECT') {
                siteSelector.addEventListener('change', fetchBalance);
            }
            
            // Validate amount on input
            const amountInput = document.getElementById('amountInput');
            if (amountInput) {
                amountInput.addEventListener('input', validateAmount);
            }
            
            // Validate on form submit
            const withdrawForm = document.getElementById('withdrawForm');
            if (withdrawForm) {
                withdrawForm.addEventListener('submit', function(e) {
                    if (!validateAmount()) {
                        e.preventDefault();
                        alert('The withdrawal amount exceeds your available balance!');
                    }
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
