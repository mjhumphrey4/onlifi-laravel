<?php
// Database configuration
$host = '10.200.1.254';
$dbname = 'onlifi_1_1_stk';
$username = 'yo';
$password = 'password';

// Get MAC address from URL parameter (passed from MikroTik login page)
$client_mac = isset($_GET['mac']) ? urldecode(trim($_GET['mac'])) : '';

// Get MAC address from POST request (when form is submitted)
$mac_address = isset($_POST['mac_address']) ? trim($_POST['mac_address']) : $client_mac;

// Initialize response
$response = [];

if (!empty($mac_address)) {
    try {
        // Create PDO connection
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Normalize MAC address for comparison
        $normalized_mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac_address));
        
        // Query to get the LATEST voucher (most recent by created_at timestamp)
        $stmt = $pdo->prepare("
            SELECT 
                id,
                voucher_code, 
                voucher_type,
                amount,
                status,
                created_at,
                msisdn,
                email,
                client_mac
            FROM transactions 
            WHERE REPLACE(REPLACE(REPLACE(UPPER(REPLACE(client_mac, '%3A', '')), ':', ''), '-', ''), '.', '') = :mac
            AND voucher_code IS NOT NULL
            AND voucher_code != ''
            AND status = 'success'
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        
        $stmt->execute(['mac' => $normalized_mac]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $response['status'] = 'success';
            $response['voucher_code'] = $result['voucher_code'];
            $response['voucher_type'] = $result['voucher_type'];
            $response['amount'] = $result['amount'];
            $response['created_at'] = $result['created_at'];
            $response['msisdn'] = $result['msisdn'];
            $response['email'] = $result['email'];
        } else {
            $response['status'] = 'not_found';
            $response['message'] = 'No voucher found for this MAC address';
        }
        
    } catch (PDOException $e) {
        $response['status'] = 'error';
        $response['message'] = 'Database connection error. Please try again later.';
        error_log('Voucher lookup error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find My Voucher</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #3b82f6 100%);
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 450px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #3b82f6 100%);
            padding: 35px 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .header h2 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 0;
        }
        
        .content {
            padding: 30px;
        }
        
        .mac-info {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .mac-info .label {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 8px;
        }
        
        .mac-info .value {
            color: #667eea;
            font-size: 15px;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            padding: 40px 20px;
        }
        
        .spinner {
            display: inline-block;
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: #6b7280;
            font-size: 14px;
        }
        
        .result {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .success-icon {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .success-icon svg {
            width: 70px;
            height: 70px;
            stroke: #10b981;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        
        .checkmark {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: draw 0.8s ease-out forwards;
        }
        
        @keyframes draw {
            to { stroke-dashoffset: 0; }
        }
        
        .voucher-card {
            background: white;
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 25px;
            margin: 20px 0;
            text-align: center;
        }
        
        .voucher-label {
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .voucher-code {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            letter-spacing: 3px;
            margin: 15px 0 20px 0;
            font-family: 'Courier New', monospace;
        }
        
        .copy-btn {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            border: none;
            padding: 12px 35px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .copy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }
        
        .copy-btn:active {
            transform: translateY(0);
        }
        
        .copy-btn.copied {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .info-grid {
            display: grid;
            gap: 10px;
            margin-top: 25px;
        }
        
        .info-item {
            background: #f9fafb;
            padding: 14px 16px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 600;
        }
        
        .expiry-notice {
            background: #f9fafb;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: center;
            border-left: 3px solid #667eea;
        }
        
        .expiry-notice .label {
            font-size: 11px;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .expiry-notice .date {
            font-size: 13px;
            color: #1f2937;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .footer-note {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #9ca3af;
        }
        
        .not-found, .error-state {
            text-align: center;
            padding: 20px 10px;
        }
        
        .not-found svg, .error-state svg {
            width: 70px;
            height: 70px;
            margin-bottom: 20px;
            stroke: #f59e0b;
        }
        
        .not-found h3, .error-state h3 {
            color: #1f2937;
            font-size: 18px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        
        .not-found p, .error-state p {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .contact-box {
            background: #fef3c7;
            padding: 16px;
            border-radius: 10px;
            margin-top: 20px;
            border-left: 4px solid #f59e0b;
            text-align: left;
        }
        
        .contact-box strong {
            display: block;
            color: #92400e;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .contact-box a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        
        .refresh-btn {
            background: linear-gradient(135deg, #667eea 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .refresh-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .refresh-btn svg {
            width: 14px;
            height: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <h2>🎟️ Your Voucher</h2>
            </div>
        </div>
        
        <div class="content">
            <div class="mac-info">
                <div class="label">Searching for voucher associated with:</div>
                <div class="value"><?php echo htmlspecialchars($mac_address); ?></div>
            </div>
            
            <?php if (empty($response)): ?>
                <div class="loading">
                    <div class="spinner"></div>
                    <div class="loading-text">Searching database...</div>
                </div>
                
            <?php elseif ($response['status'] === 'success'): ?>
                <div class="result">
                    <div class="success-icon">
                        <svg viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="45" style="fill: #d1fae5; stroke: #10b981;"/>
                            <path class="checkmark" d="M30 50 L45 65 L70 35"/>
                        </svg>
                    </div>
                    
                    <div class="voucher-card">
                        <div class="voucher-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            </svg>
                            Voucher Code
                        </div>
                        <div class="voucher-code" id="voucherCode"><?php echo htmlspecialchars($response['voucher_code']); ?></div>
                        <button class="copy-btn" id="copyBtn" onclick="copyVoucher()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <span id="copyText">Copy Code</span>
                        </button>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Voucher Type:</span>
                            <span class="info-value"><?php echo htmlspecialchars($response['voucher_type'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Amount Paid:</span>
                            <span class="info-value">UGX <?php echo number_format($response['amount'], 0); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone Number:</span>
                            <span class="info-value"><?php echo htmlspecialchars($response['msisdn'] ?: 'N/A'); ?></span>
                        </div>
                        <?php if (!empty($response['email'])): ?>
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($response['email']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="expiry-notice">
                        <div class="label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            Purchase Date
                        </div>
                        <div class="date"><?php echo date('M d, Y • H:i', strtotime($response['created_at'])); ?></div>
                    </div>
                    
                    <div class="footer-note">
                        Please save this voucher code for your records
                    </div>
                </div>

                <script>
                    // Send voucher code to parent window
                    if (window.parent !== window) {
                        window.parent.postMessage({
                            type: 'VOUCHER_FOUND',
                            voucherCode: '<?php echo htmlspecialchars($response['voucher_code']); ?>'
                        }, '*');
                    }

                    function copyVoucher() {
                        const code = document.getElementById('voucherCode').textContent;
                        const btn = document.getElementById('copyBtn');
                        const btnText = document.getElementById('copyText');
                        
                        navigator.clipboard.writeText(code).then(() => {
                            btn.classList.add('copied');
                            btnText.textContent = 'Copied!';
                            
                            setTimeout(() => {
                                btn.classList.remove('copied');
                                btnText.textContent = 'Copy Code';
                            }, 2000);
                        });
                    }
                </script>
                
            <?php elseif ($response['status'] === 'not_found'): ?>
                <div class="not-found">
                    <svg viewBox="0 0 100 100" fill="none">
                        <circle cx="50" cy="50" r="45" style="fill: #fef3c7; stroke: #f59e0b; stroke-width: 2;"/>
                        <path d="M50 30 L50 55" style="stroke: #f59e0b; stroke-width: 4; stroke-linecap: round;"/>
                        <circle cx="50" cy="70" r="3" style="fill: #f59e0b;"/>
                    </svg>
                    <h3>No Voucher Found</h3>
                    <p>Uh Oh!!! We don't have any lost vouchers for this Device's address.</p>
                    
                    <div class="contact-box">
                        <strong>Please contact STK support ON:</strong>
                        <a href="tel:0200906013">0200906013</a> or 
                        <a href="tel:0786979317">0786979317</a>
                    </div>
                    
                    <button class="refresh-btn" onclick="location.reload()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                        </svg>
                        Refresh Search
                    </button>
                </div>
                
            <?php else: ?>
                <div class="error-state">
                    <svg viewBox="0 0 100 100" fill="none">
                        <circle cx="50" cy="50" r="45" style="fill: #fee2e2; stroke: #ef4444; stroke-width: 2;"/>
                        <path d="M35 35 L65 65 M65 35 L35 65" style="stroke: #ef4444; stroke-width: 4; stroke-linecap: round;"/>
                    </svg>
                    <h3>Error</h3>
                    <p><?php echo htmlspecialchars($response['message']); ?></p>
                    
                    <button class="refresh-btn" onclick="location.reload()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                        </svg>
                        Try Again
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
