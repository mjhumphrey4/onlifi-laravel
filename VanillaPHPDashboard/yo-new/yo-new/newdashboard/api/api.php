<?php
date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('PAYDASH_SESSION');
    session_start();
}

// ─── Users ────────────────────────────────────────────────────────────────────
$USERS = [
    'admin'      => ['password' => '##12345678Aa', 'name' => 'Administrator',     'email' => 'admin@yopayments.com',         'role' => 'admin', 'site' => null],
    'enock'      => ['password' => 'bite@25',      'name' => 'Enock',             'email' => 'enock@bitetechnetwork.com',    'role' => 'user',  'site' => 'Enock'],
    'richard'    => ['password' => '0700738027',   'name' => 'Richard',           'email' => 'richard@bitetechnetwork.com',  'role' => 'user',  'site' => 'Richard'],
    'stk'        => ['password' => '##12345678Aa', 'name' => 'STK Admin',         'email' => 'stk@onlustech.com',            'role' => 'user',  'site' => 'STK'],
    'remmy'      => ['password' => '0703538708',   'name' => 'Remmy',             'email' => 'remmy@rankenwifi.com',         'role' => 'user',  'site' => 'Remmy'],
    'guma'       => ['password' => '0701480842',   'name' => 'Guma',              'email' => 'guma@omada.com',               'role' => 'user',  'site' => 'Guma'],
    'namungoona' => ['password' => '##12345678Aa', 'name' => 'STK Namungoona',    'email' => 'namungoona@onlustech.com',     'role' => 'user',  'site' => 'Namungoona'],
];

// ─── DB helpers ───────────────────────────────────────────────────────────────
$DB_HOST = 'localhost';
$DB_USER = 'yo';
$DB_PASS = 'password';
$connections = [];

function getDb($dbname) {
    global $connections, $DB_HOST, $DB_USER, $DB_PASS;
    if (!isset($connections[$dbname])) {
        try {
            $pdo = new PDO("mysql:host=$DB_HOST;dbname=$dbname;charset=utf8mb4", $DB_USER, $DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connections[$dbname] = $pdo;
        } catch (PDOException $e) {
            error_log("DB [$dbname] failed: " . $e->getMessage());
            $connections[$dbname] = null;
        }
    }
    return $connections[$dbname];
}

function getSqlite() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new SQLite3(__DIR__ . '/../../withdraw/withdrawals.db');
        } catch (Exception $e) {
            error_log("SQLite failed: " . $e->getMessage());
            $db = false;
        }
    }
    return $db ?: null;
}

function siteDbName($site) {
    switch ($site) {
        case 'STK':        return ['payment_mikrotik', 'STK WIFI'];
        case 'Remmy':      return ['remmy_mikrotik',   'remmy'];
        case 'Guma':       return ['guma_omada',       'guma'];
        case 'Enock':      return ['omada',            'Bite Tech Network'];
        case 'Richard':    return ['omada',            'Richard Network'];
        case 'Namungoona': return ['stk_namungoona',   'STK Namungoona'];
        default:           return [null, null];
    }
}

function allSites() {
    return ['Enock', 'Richard', 'STK', 'Remmy', 'Guma', 'Namungoona'];
}

function userSites($user) {
    return $user['role'] === 'admin' ? allSites() : [$user['site']];
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($msg, $code = 400) {
    respond(['error' => $msg], $code);
}

function requireAuth() {
    if (empty($_SESSION['user'])) fail('Unauthorized', 401);
    return $_SESSION['user'];
}

// ─── Router ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Parse JSON body
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($body, $_POST);
}

switch ($action) {

    // ── Auth ──────────────────────────────────────────────────────────────────
    case 'login':
        global $USERS;
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if (isset($USERS[$username]) && $USERS[$username]['password'] === $password) {
            $u = $USERS[$username];
            $_SESSION['user'] = [
                'username' => $username,
                'name'     => $u['name'],
                'email'    => $u['email'],
                'role'     => $u['role'],
                'site'     => $u['site'],
            ];
            respond(['ok' => true, 'user' => $_SESSION['user']]);
        }
        fail('Invalid username or password', 401);

    case 'logout':
        session_destroy();
        respond(['ok' => true]);

    case 'me':
        if (empty($_SESSION['user'])) respond(['user' => null]);
        respond(['user' => $_SESSION['user']]);

    // ── Dashboard stats ───────────────────────────────────────────────────────
    case 'stats':
        $user  = requireAuth();
        $sites = userSites($user);
        $result = [];

        foreach ($sites as $site) {
            [$dbname, $origin] = siteDbName($site);
            $pdo = $dbname ? getDb($dbname) : null;
            $row = ['total_amount' => 0, 'today_amount' => 0, 'week_amount' => 0, 'month_amount' => 0, 'total_sales' => 0];
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT
                            COUNT(*) as total_sales,
                            COALESCE(SUM(amount),0) as total_amount,
                            COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN amount ELSE 0 END),0) as today_amount,
                            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN amount ELSE 0 END),0) as week_amount,
                            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END),0) as month_amount,
                            COALESCE(SUM(telecom_fee),0) as total_fees,
                            COALESCE(SUM(platform_fee),0) as total_platform_fees
                        FROM transactions WHERE origin_site=:o AND status='success'
                    ");
                    $stmt->execute([':o' => $origin]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
                } catch (Exception $e) { error_log($e->getMessage()); }
            }

            $sqlite = getSqlite();
            $withdrawn = 0;
            $pending   = 0;
            if ($sqlite) {
                try {
                    $s = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username=:u AND status="SUCCEEDED"');
                    $s->bindValue(':u', $site, SQLITE3_TEXT);
                    $withdrawn = $s->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0;

                    $s2 = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username=:u AND status="PENDING"');
                    $s2->bindValue(':u', $site, SQLITE3_TEXT);
                    $pending = $s2->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0;
                } catch (Exception $e) { error_log($e->getMessage()); }
            }

            $totalFees = (float)($row['total_fees'] ?? 0);
            $platformFees = (float)($row['total_platform_fees'] ?? 0);
            $netRevenue = (float)$row['total_amount'] - $totalFees - $platformFees;
            
            $result[$site] = [
                'total_amount'   => (float)$row['total_amount'],
                'today_amount'   => (float)$row['today_amount'],
                'week_amount'    => (float)$row['week_amount'],
                'month_amount'   => (float)$row['month_amount'],
                'total_sales'    => (int)$row['total_sales'],
                'total_fees'     => $totalFees,
                'platform_fees'  => $platformFees,
                'net_revenue'    => $netRevenue,
                'withdrawn'      => (float)$withdrawn,
                'pending_withdraw'=> (float)$pending,
                'balance'        => $netRevenue - (float)$withdrawn,
            ];
        }

        // Admin withdrawal stats (platform fees withdrawals)
        $adminWithdrawn = 0;
        $adminPending = 0;
        if ($user['role'] === 'admin' && $sqlite) {
            try {
                $s = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username="ADMIN" AND status="SUCCEEDED"');
                $adminWithdrawn = (float)($s->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0);

                $s2 = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username="ADMIN" AND status="PENDING"');
                $adminPending = (float)($s2->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0);
            } catch (Exception $e) { error_log($e->getMessage()); }
        }

        respond(['sites' => $result, 'user' => $user, 'admin_withdrawn' => $adminWithdrawn, 'admin_pending' => $adminPending]);

    // ── Transactions ──────────────────────────────────────────────────────────
    case 'transactions':
        $user   = requireAuth();
        $sites  = userSites($user);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $site   = $_GET['site'] ?? '';

        // Restrict site to user's allowed sites
        if ($site && !in_array($site, $sites)) $site = '';
        $targetSites = $site ? [$site] : $sites;

        $all = [];
        foreach ($targetSites as $s) {
            [$dbname, $origin] = siteDbName($s);
            $pdo = $dbname ? getDb($dbname) : null;
            if (!$pdo) continue;
            try {
                $where = "origin_site=:o";
                $params = [':o' => $origin];
                if ($status && $status !== 'all') { $where .= " AND status=:st"; $params[':st'] = $status; }
                if ($search) { $where .= " AND (msisdn LIKE :q OR external_ref LIKE :q OR voucher_code LIKE :q)"; $params[':q'] = "%$search%"; }
                $stmt = $pdo->prepare("SELECT id,external_ref,msisdn,amount,status,created_at,origin_site,voucher_code,telecom_fee,platform_fee FROM transactions WHERE $where ORDER BY created_at DESC LIMIT 500");
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) { $r['site_label'] = $s; }
                $all = array_merge($all, $rows);
            } catch (Exception $e) { error_log($e->getMessage()); }
        }

        usort($all, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
        $total = count($all);
        $paged = array_slice($all, $offset, $limit);

        respond(['transactions' => $paged, 'total' => $total, 'page' => $page, 'limit' => $limit]);

    // ── Withdrawals list ──────────────────────────────────────────────────────
    case 'withdrawals':
        $user  = requireAuth();
        $sites = userSites($user);
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
        $offset= ($page - 1) * $limit;

        $sqlite = getSqlite();
        $all = [];
        if ($sqlite) {
            foreach ($sites as $s) {
                try {
                    $stmt = $sqlite->prepare('SELECT * FROM transactions WHERE username=:u ORDER BY created_at DESC');
                    $stmt->bindValue(':u', $s, SQLITE3_TEXT);
                    $res = $stmt->execute();
                    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                        $row['site_label'] = $s;
                        $all[] = $row;
                    }
                } catch (Exception $e) { error_log($e->getMessage()); }
            }
            // Include ADMIN withdrawals for admin users
            if ($user['role'] === 'admin') {
                try {
                    $stmt = $sqlite->prepare('SELECT * FROM transactions WHERE username="ADMIN" ORDER BY created_at DESC');
                    $res = $stmt->execute();
                    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                        $row['site_label'] = 'ADMIN (Platform Fees)';
                        $all[] = $row;
                    }
                } catch (Exception $e) { error_log($e->getMessage()); }
            }
        }

        usort($all, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
        $total = count($all);
        $paged = array_slice($all, $offset, $limit);

        respond(['withdrawals' => $paged, 'total' => $total, 'page' => $page, 'limit' => $limit]);

    // ── Request withdrawal ────────────────────────────────────────────────────
    case 'request_withdrawal':
        $user   = requireAuth();
        $sites  = userSites($user);
        $amount = (float)($body['amount'] ?? 0);
        $phone  = trim($body['phone'] ?? '');
        $site   = $body['site'] ?? ($user['site'] ?? '');
        $comment = isset($body['comment']) && $body['comment'] !== null ? trim($body['comment']) : null;
        $isAdminWithdrawal = !empty($body['is_admin_withdrawal']) && $user['role'] === 'admin';

        // Handle admin withdrawal (platform fees)
        if ($isAdminWithdrawal || $site === 'ADMIN') {
            if ($user['role'] !== 'admin') fail('Only admin can withdraw platform fees');
            $site = 'ADMIN';
        } else {
            if (!in_array($site, $sites)) fail('Invalid site');
        }

        if ($amount < 1000)                       fail('Minimum withdrawal is UGX 1,000');
        if (!preg_match('/^\d{10,12}$/', $phone)) fail('Invalid phone number format');

        $sqlite = getSqlite();
        if (!$sqlite) fail('Withdrawal database unavailable');

        // Check balance based on withdrawal type
        if ($site === 'ADMIN') {
            // Admin withdrawal: balance is total platform fees minus admin withdrawals
            $totalPlatformFees = 0;
            foreach (allSites() as $s) {
                [$dbname, $origin] = siteDbName($s);
                $pdo = $dbname ? getDb($dbname) : null;
                if ($pdo) {
                    try {
                        $stmt = $pdo->prepare("SELECT COALESCE(SUM(platform_fee),0) as total FROM transactions WHERE origin_site=:o AND status='success'");
                        $stmt->execute([':o' => $origin]);
                        $totalPlatformFees += (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
                    } catch (Exception $e) { error_log($e->getMessage()); }
                }
            }

            $adminWithdrawn = 0;
            try {
                $s = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username="ADMIN" AND status="SUCCEEDED"');
                $adminWithdrawn = (float)($s->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0);
            } catch (Exception $e) { error_log($e->getMessage()); }

            $balance = $totalPlatformFees - $adminWithdrawn;
        } else {
            // Regular site withdrawal
            [$dbname, $origin] = siteDbName($site);
            $pdo = $dbname ? getDb($dbname) : null;
            $totalRevenue = 0;
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as total, COALESCE(SUM(telecom_fee),0) as fees, COALESCE(SUM(platform_fee),0) as platform_fees FROM transactions WHERE origin_site=:o AND status='success'");
                    $stmt->execute([':o' => $origin]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $totalRevenue = (float)($result['total'] ?? 0) - (float)($result['fees'] ?? 0) - (float)($result['platform_fees'] ?? 0);
                } catch (Exception $e) { error_log($e->getMessage()); }
            }

            $withdrawn = 0;
            try {
                $s = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username=:u AND status="SUCCEEDED"');
                $s->bindValue(':u', $site, SQLITE3_TEXT);
                $withdrawn = (float)($s->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0);
            } catch (Exception $e) { error_log($e->getMessage()); }

            $balance = $totalRevenue - $withdrawn;
        }

        if ($amount > $balance) fail('Insufficient balance. Available: UGX ' . number_format($balance, 0));

        // Call YoAPI — same credentials and logic as withdraw/process_withdrawal.php
        require_once __DIR__ . '/../../withdraw/YoAPI.php';

        $yoUsername  = '100812171094';
        $yoPassword  = 'BUid-ZAmO-b2M0-vF6n-CzBK-PBaL-8qJK-6SOf';
        $yoMode      = 'production';
        $privateKey  = __DIR__ . '/../../withdraw/private_key.pem';
        $narrative   = 'Withdrawal from PayDash - ' . date('Y-m-d H:i:s');
        $extRef      = date('YmdHis') . rand(1, 100);

        $yoStatus  = 'FAILED';
        $yoRef     = '';
        $yoMessage = '';

        try {
            $yoAPI = new YoAPI($yoUsername, $yoPassword, $yoMode);
            $yoAPI->set_external_reference($extRef);
            $yoAPI->set_private_key_file_location($privateKey);
            $yoAPI->set_public_key_authentication_nonce($extRef);
            $yoAPI->generate_public_key_authentication_signature($phone, $amount, $narrative);
            $response = $yoAPI->ac_withdraw_funds($phone, $amount, $narrative);

            $yoRef     = $response['TransactionReference'] ?? '';
            $yoMessage = $response['StatusMessage'] ?? '';

            if (($response['TransactionStatus'] ?? '') === 'SUCCEEDED') {
                $yoStatus = 'SUCCEEDED';
            } elseif (isset($response['StatusMessage']) &&
                      stripos($response['StatusMessage'], 'This transaction requires extra authorization') !== false) {
                // Yo returns this when the payout is queued for authorization — treat as succeeded
                $yoStatus = 'SUCCEEDED';
                $yoRef    = $yoRef ?: ('AUTH-' . time());
            } else {
                $yoStatus = 'FAILED';
            }
        } catch (Exception $e) {
            $yoStatus  = 'FAILED';
            $yoMessage = $e->getMessage();
            error_log('YoAPI withdrawal error: ' . $e->getMessage());
        }

        // Persist result to SQLite
        $txRef = 'WD' . date('YmdHis') . rand(100, 999);
        try {
            $ins = $sqlite->prepare(
                'INSERT INTO transactions (username, phone_number, amount, status, transaction_reference, response_message, comment, created_at)
                 VALUES (:u, :ph, :am, :st, :ref, :msg, :cmt, :ca)'
            );
            $ins->bindValue(':u',   $site,               SQLITE3_TEXT);
            $ins->bindValue(':ph',  $phone,              SQLITE3_TEXT);
            $ins->bindValue(':am',  $amount,             SQLITE3_FLOAT);
            $ins->bindValue(':st',  $yoStatus,           SQLITE3_TEXT);
            $ins->bindValue(':ref', $yoRef ?: $txRef,    SQLITE3_TEXT);
            $ins->bindValue(':msg', $yoMessage,          SQLITE3_TEXT);
            $ins->bindValue(':cmt', $comment,            SQLITE3_TEXT);
            $ins->bindValue(':ca',  date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $ins->execute();
        } catch (Exception $e) {
            error_log('SQLite insert error: ' . $e->getMessage());
        }

        if ($yoStatus === 'SUCCEEDED') {
            respond(['ok' => true, 'transaction_id' => $yoRef ?: $txRef, 'message' => 'Withdrawal processed successfully. Funds will be deposited shortly.']);
        } else {
            fail('Withdrawal failed: ' . ($yoMessage ?: 'Unknown error from payment provider'));
        }

    // ── Performance / daily data ──────────────────────────────────────────────
    case 'performance':
        $user  = requireAuth();
        $sites = userSites($user);
        $site  = $_GET['site'] ?? ($user['site'] ?? $sites[0]);
        $days  = (int)($_GET['days'] ?? 7);
        if (!in_array($site, $sites)) $site = $sites[0];

        [$dbname, $origin] = siteDbName($site);
        $pdo = $dbname ? getDb($dbname) : null;
        $data = [];

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    SELECT DATE(created_at) as date,
                           COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as amount,
                           COUNT(*) as transactions
                    FROM transactions
                    WHERE origin_site=:o AND created_at >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
                    GROUP BY DATE(created_at) ORDER BY date ASC
                ");
                $stmt->execute([':o' => $origin, ':d' => $days]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { error_log($e->getMessage()); }
        }

        // Fill missing days with zeros
        $filled = [];
        $start  = new DateTime("-{$days} days");
        $end    = new DateTime('today');
        $byDate = array_column($data, null, 'date');
        for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $filled[] = [
                'date'         => $key,
                'day'          => $d->format('D'),
                'day_num'      => (int)$d->format('j'),
                'amount'       => (float)($byDate[$key]['amount'] ?? 0),
                'transactions' => (int)($byDate[$key]['transactions'] ?? 0),
            ];
        }

        respond(['data' => $filled, 'site' => $site]);

    // ── Voucher Type Analytics ─────────────────────────────────────────────────
    case 'voucher_analytics':
        $user  = requireAuth();
        $sites = userSites($user);
        $site  = $_GET['site'] ?? '';
        $period = $_GET['period'] ?? 'today'; // today, week, month
        
        // If no site specified, aggregate all user's sites
        $targetSites = $site && in_array($site, $sites) ? [$site] : $sites;
        
        // Determine date filter based on period
        switch ($period) {
            case 'week':
                $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            default:
                $dateFilter = "DATE(created_at) = CURDATE()";
        }
        
        $voucherData = [];
        $hourlyData = [];
        $totalAmount = 0;
        $totalTransactions = 0;
        $totalFees = 0;
        
        foreach ($targetSites as $s) {
            [$dbname, $origin] = siteDbName($s);
            $pdo = $dbname ? getDb($dbname) : null;
            if (!$pdo) continue;
            
            try {
                // Get voucher type breakdown
                $stmt = $pdo->prepare("
                    SELECT 
                        COALESCE(voucher_type, 'Unknown') as voucher_type,
                        COUNT(*) as count,
                        COALESCE(SUM(amount), 0) as total_amount,
                        COALESCE(SUM(telecom_fee), 0) as total_fees
                    FROM transactions 
                    WHERE origin_site = :o AND status = 'success' AND $dateFilter
                    GROUP BY voucher_type
                    ORDER BY total_amount DESC
                ");
                $stmt->execute([':o' => $origin]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rows as $row) {
                    $type = $row['voucher_type'] ?: 'Unknown';
                    if (!isset($voucherData[$type])) {
                        $voucherData[$type] = ['count' => 0, 'amount' => 0, 'fees' => 0];
                    }
                    $voucherData[$type]['count'] += (int)$row['count'];
                    $voucherData[$type]['amount'] += (float)$row['total_amount'];
                    $voucherData[$type]['fees'] += (float)$row['total_fees'];
                    $totalAmount += (float)$row['total_amount'];
                    $totalTransactions += (int)$row['count'];
                    $totalFees += (float)$row['total_fees'];
                }
                
                // Get hourly/daily breakdown for chart
                if ($period === 'today') {
                    $stmt2 = $pdo->prepare("
                        SELECT 
                            HOUR(created_at) as time_unit,
                            COUNT(*) as count,
                            COALESCE(SUM(amount), 0) as amount
                        FROM transactions 
                        WHERE origin_site = :o AND status = 'success' AND DATE(created_at) = CURDATE()
                        GROUP BY HOUR(created_at)
                        ORDER BY time_unit ASC
                    ");
                } else {
                    $stmt2 = $pdo->prepare("
                        SELECT 
                            DATE(created_at) as time_unit,
                            COUNT(*) as count,
                            COALESCE(SUM(amount), 0) as amount
                        FROM transactions 
                        WHERE origin_site = :o AND status = 'success' AND $dateFilter
                        GROUP BY DATE(created_at)
                        ORDER BY time_unit ASC
                    ");
                }
                $stmt2->execute([':o' => $origin]);
                $timeRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($timeRows as $row) {
                    $unit = (string)$row['time_unit'];
                    if (!isset($hourlyData[$unit])) {
                        $hourlyData[$unit] = ['count' => 0, 'amount' => 0];
                    }
                    $hourlyData[$unit]['count'] += (int)$row['count'];
                    $hourlyData[$unit]['amount'] += (float)$row['amount'];
                }
            } catch (Exception $e) { error_log($e->getMessage()); }
        }
        
        // Format voucher data for response
        $formattedVouchers = [];
        foreach ($voucherData as $type => $data) {
            $formattedVouchers[] = [
                'type' => $type,
                'count' => $data['count'],
                'amount' => $data['amount'],
                'fees' => $data['fees'],
                'percentage' => $totalAmount > 0 ? round(($data['amount'] / $totalAmount) * 100, 1) : 0
            ];
        }
        
        // Sort by amount descending
        usort($formattedVouchers, fn($a, $b) => $b['amount'] <=> $a['amount']);
        
        // Format time series data
        $formattedTimeSeries = [];
        if ($period === 'today') {
            // Fill all 24 hours
            for ($h = 0; $h < 24; $h++) {
                $formattedTimeSeries[] = [
                    'label' => sprintf('%02d:00', $h),
                    'count' => $hourlyData[(string)$h]['count'] ?? 0,
                    'amount' => $hourlyData[(string)$h]['amount'] ?? 0
                ];
            }
        } else {
            // Sort by date and format
            ksort($hourlyData);
            foreach ($hourlyData as $date => $data) {
                $formattedTimeSeries[] = [
                    'label' => date('M j', strtotime($date)),
                    'date' => $date,
                    'count' => $data['count'],
                    'amount' => $data['amount']
                ];
            }
        }
        
        respond([
            'vouchers' => $formattedVouchers,
            'timeSeries' => $formattedTimeSeries,
            'summary' => [
                'totalAmount' => $totalAmount,
                'totalTransactions' => $totalTransactions,
                'totalFees' => $totalFees,
                'netAmount' => $totalAmount - $totalFees
            ],
            'period' => $period,
            'site' => $site ?: 'all'
        ]);

    // ── Import vouchers ───────────────────────────────────────────────────────
    case 'import_vouchers':
        $user  = requireAuth();
        $sites = userSites($user);

        // For multipart uploads the site comes from $_POST
        $site = trim($_POST['site'] ?? ($user['site'] ?? ''));
        if (!$site || !in_array($site, $sites)) fail('Invalid or missing site');

        // Map site → db + table (mirrors the standalone importers)
        $importMap = [
            'Enock'      => ['db' => 'omada',            'table' => 'vouchers2'],
            'Richard'    => ['db' => 'omada',            'table' => 'vouchers_richard'],
            'STK'        => ['db' => 'payment_mikrotik', 'table' => 'vouchers'],
            'Remmy'      => ['db' => 'remmy_mikrotik',   'table' => 'vouchers'],
            'Guma'       => ['db' => 'guma_omada',       'table' => 'vouchers'],
            'Namungoona' => ['db' => 'stk_namungoona',   'table' => 'vouchers'],
        ];

        if (!isset($importMap[$site])) fail('Import not configured for this site');

        $cfg = $importMap[$site];
        $pdo = getDb($cfg['db']);
        if (!$pdo) fail('Database unavailable for this site');
        $tbl = $cfg['table'];

        // Validate uploaded file
        if (empty($_FILES['pdfFile']) || $_FILES['pdfFile']['error'] !== UPLOAD_ERR_OK)
            fail('No PDF file uploaded or upload error: ' . ($_FILES['pdfFile']['error'] ?? 'none'));

        $tmpPath = $_FILES['pdfFile']['tmp_name'];

        // Extract text via pdftotext
        $output = [];
        exec("pdftotext " . escapeshellarg($tmpPath) . " -", $output, $exitCode);
        if (empty($output)) fail('Could not extract text from PDF. Ensure pdftotext is installed.');
        $pdfText = implode("\n", $output);

        // Detect voucher type from full document text
        // Uses regex with word boundaries to avoid substring false-positives
        // (e.g. '12h' inside '24hours', '2hours' inside '12hours', '3h' inside '23h')
        $textLower = strtolower($pdfText);
        if      (preg_match('/\b30\s*days?\b/', $textLower))                                           $voucherType = '30days';
        elseif  (preg_match('/\b7\s*days?\b/', $textLower))                                            $voucherType = '7days';
        elseif  (preg_match('/\b24\s*h(ou?rs?|rs?)?\b/', $textLower)
              || preg_match('/\b1\s*day\b/', $textLower)
              || preg_match('/\b1d\b/', $textLower))                                                   $voucherType = '24hours';
        elseif  (preg_match('/\b23\s*h(ou?rs?|rs?)?\b/', $textLower))                                 $voucherType = '23hours';
        elseif  (preg_match('/\b12\s*h(ou?rs?|rs?)?\b/', $textLower))                                 $voucherType = '12hours';
        elseif  (preg_match('/\b4\s*h(ou?rs?|rs?)?\b/', $textLower))                                  $voucherType = '4hours';
        elseif  (preg_match('/\b3\s*h(ou?rs?|rs?)?\b/', $textLower))                                  $voucherType = '3hours';
        elseif  (preg_match('/\b2\s*h(ou?rs?|rs?)?\b/', $textLower))                                  $voucherType = '2hours';
        else    $voucherType = '12hours'; // fallback — unknown PDF format

        // Skip-pattern list (mirrors the standalone importers)
        $skipPatterns = ['valid','for','limited','usage','counts','count','hours','days','voucher','code','type','www.','http','.com','/','pm','am','lot','net','cashless'];

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $seen     = [];

        // Guma uses different column names: voucher_code and voucher_type instead of code and type
        // Check for unused vouchers only - allow re-import if voucher was used
        if ($site === 'Guma') {
            $checkStmt  = $pdo->prepare("SELECT id, status FROM `$tbl` WHERE voucher_code = :code LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT IGNORE INTO `$tbl` (voucher_code, voucher_type) VALUES (:code, :type)");
        } else {
            $checkStmt  = $pdo->prepare("SELECT id, used FROM `$tbl` WHERE code = :code LIMIT 1");
            $insertStmt = $pdo->prepare("INSERT IGNORE INTO `$tbl` (code, type) VALUES (:code, :type)");
        }

        foreach (explode("\n", $pdfText) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip-pattern check
            $lineLower = strtolower($line);
            $skip = false;
            foreach ($skipPatterns as $p) { if (strpos($lineLower, $p) !== false) { $skip = true; break; } }
            if ($skip) continue;

            // Must look like a voucher code: 4-6 digits OR alphanumeric ≥4 chars
            if (!preg_match('/^\d{4,6}$/', $line) && !preg_match('/^[A-Z0-9\-]{4,}$/i', $line)) continue;

            if (in_array($line, $seen)) continue;
            $seen[] = $line;

            try {
                // Check if voucher exists and if it's unused
                $checkStmt->execute([':code' => $line]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing !== false) {
                    // For Guma: status='available' means unused, status='used' means used
                    // For others: used=0 means unused, used=1 means used
                    $isUnused = ($site === 'Guma') 
                        ? ($existing['status'] === 'available' || $existing['status'] === 'Available')
                        : ($existing['used'] == 0);
                    
                    if ($isUnused) {
                        // Skip only if voucher exists and is unused
                        $errors[] = "Code '$line': already exists (unused)";
                        $skipped++;
                        continue;
                    }
                    // If voucher is used, allow re-import (will be inserted as new row)
                }
                
                $insertStmt->execute([':code' => $line, ':type' => $voucherType]);
                // INSERT IGNORE returns 0 affected rows for duplicates (safety net)
                if ($pdo->lastInsertId()) {
                    $imported++;
                } else {
                    $errors[] = "Code '$line': already exists (duplicate)";
                    $skipped++;
                }
            } catch (PDOException $e) {
                $errors[]  = "Code '$line': " . $e->getMessage();
                $skipped++;
            }
        }

        respond([
            'ok'            => true,
            'imported'      => $imported,
            'skipped'       => $skipped,
            'type_detected' => $voucherType,
            'errors'        => array_slice($errors, 0, 20),
        ]);

    // ── Voucher stock ─────────────────────────────────────────────────────────
    case 'voucher_stock':
        $user  = requireAuth();
        $sites = userSites($user);
        $site  = $_GET['site'] ?? ($user['site'] ?? $sites[0]);
        if (!in_array($site, $sites)) fail('Invalid site');

        [$dbname,] = siteDbName($site);
        $pdo = $dbname ? getDb($dbname) : null;
        if (!$pdo) fail('Database unavailable for this site');

        // Each site stores vouchers in a different table
        $tableMap = [
            'Enock'      => ['db' => 'omada',            'table' => 'vouchers2'],
            'Richard'    => ['db' => 'omada',            'table' => 'vouchers_richard'],
            'STK'        => ['db' => 'payment_mikrotik', 'table' => 'vouchers'],
            'Remmy'      => ['db' => 'remmy_mikrotik',   'table' => 'vouchers'],
            'Guma'       => ['db' => 'guma_omada',       'table' => 'vouchers'],
            'Namungoona' => ['db' => 'stk_namungoona',   'table' => 'vouchers'],
        ];

        if (!isset($tableMap[$site])) fail('Voucher stock not configured for this site');

        $tbl = $tableMap[$site]['table'];
        $stock = ['2hours' => 0, '3hours' => 0, '4hours' => 0, '12hours' => 0, '23hours' => 0, '24hours' => 0, '7days' => 0, '30days' => 0, 'total' => 0];

        try {
            // Guma uses different column names: voucher_type instead of type, status='available' instead of used=0
            if ($site === 'Guma') {
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN voucher_type='2hours'  THEN 1 ELSE 0 END),0) as h2,
                        COALESCE(SUM(CASE WHEN voucher_type='3hours'  THEN 1 ELSE 0 END),0) as h3,
                        COALESCE(SUM(CASE WHEN voucher_type='4hours'  THEN 1 ELSE 0 END),0) as h4,
                        COALESCE(SUM(CASE WHEN voucher_type='12hours' THEN 1 ELSE 0 END),0) as h12,
                        COALESCE(SUM(CASE WHEN voucher_type='23hours' THEN 1 ELSE 0 END),0) as h23,
                        COALESCE(SUM(CASE WHEN voucher_type='24hours' THEN 1 ELSE 0 END),0) as h24,
                        COALESCE(SUM(CASE WHEN voucher_type='7days'   THEN 1 ELSE 0 END),0) as d7,
                        COALESCE(SUM(CASE WHEN voucher_type='30days'  THEN 1 ELSE 0 END),0) as d30
                    FROM `$tbl` WHERE status = 'available'
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN type='2hours'  THEN 1 ELSE 0 END),0) as h2,
                        COALESCE(SUM(CASE WHEN type='3hours'  THEN 1 ELSE 0 END),0) as h3,
                        COALESCE(SUM(CASE WHEN type='4hours'  THEN 1 ELSE 0 END),0) as h4,
                        COALESCE(SUM(CASE WHEN type='12hours' THEN 1 ELSE 0 END),0) as h12,
                        COALESCE(SUM(CASE WHEN type='23hours' THEN 1 ELSE 0 END),0) as h23,
                        COALESCE(SUM(CASE WHEN type='24hours' THEN 1 ELSE 0 END),0) as h24,
                        COALESCE(SUM(CASE WHEN type='7days'   THEN 1 ELSE 0 END),0) as d7,
                        COALESCE(SUM(CASE WHEN type='30days'  THEN 1 ELSE 0 END),0) as d30
                    FROM `$tbl` WHERE used = 0
                ");
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stock = [
                '2hours'  => (int)$row['h2'],
                '3hours'  => (int)$row['h3'],
                '4hours'  => (int)$row['h4'],
                '12hours' => (int)$row['h12'],
                '23hours' => (int)$row['h23'],
                '24hours' => (int)$row['h24'],
                '7days'   => (int)$row['d7'],
                '30days'  => (int)$row['d30'],
                'total'   => (int)$row['h2'] + (int)$row['h3'] + (int)$row['h4'] + (int)$row['h12'] + (int)$row['h23'] + (int)$row['h24'] + (int)$row['d7'] + (int)$row['d30'],
            ];
        } catch (Exception $e) { error_log($e->getMessage()); fail('Failed to query voucher stock: ' . $e->getMessage()); }

        respond(['site' => $site, 'stock' => $stock]);

    // ── Monitor vouchers ──────────────────────────────────────────────────────
    case 'monitor_vouchers':
        $user  = requireAuth();
        $sites = userSites($user);
        $site  = $_GET['site'] ?? ($user['site'] ?? $sites[0]);
        if (!in_array($site, $sites)) fail('Invalid site');

        [$dbname,] = siteDbName($site);
        $pdo = $dbname ? getDb($dbname) : null;
        if (!$pdo) fail('Database unavailable for this site');

        $tableMap = [
            'Enock'      => ['db' => 'omada',            'table' => 'vouchers2'],
            'Richard'    => ['db' => 'omada',            'table' => 'vouchers_richard'],
            'STK'        => ['db' => 'payment_mikrotik', 'table' => 'vouchers'],
            'Remmy'      => ['db' => 'remmy_mikrotik',   'table' => 'vouchers'],
            'Guma'       => ['db' => 'guma_omada',       'table' => 'vouchers'],
            'Namungoona' => ['db' => 'stk_namungoona',   'table' => 'vouchers'],
        ];

        if (!isset($tableMap[$site])) fail('Voucher monitoring not configured for this site');

        $tbl = $tableMap[$site]['table'];
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $type   = $_GET['type'] ?? 'all';
        $search = $_GET['search'] ?? '';

        try {
            // Build WHERE clause for unused vouchers
            if ($site === 'Guma') {
                $where = "status = 'available'";
                $typeCol = 'voucher_type';
                $codeCol = 'voucher_code';
            } else {
                $where = "used = 0";
                $typeCol = 'type';
                $codeCol = 'code';
            }

            $params = [];
            if ($type && $type !== 'all') {
                $where .= " AND $typeCol = :type";
                $params[':type'] = $type;
            }
            if ($search) {
                $where .= " AND $codeCol LIKE :search";
                $params[':search'] = "%$search%";
            }

            // Get total count
            $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM `$tbl` WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get paginated vouchers
            $stmt = $pdo->prepare("SELECT id, $codeCol as code, $typeCol as type FROM `$tbl` WHERE $where ORDER BY id DESC LIMIT :limit OFFSET :offset");
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            respond([
                'vouchers' => $vouchers,
                'total'    => $total,
                'page'     => $page,
                'limit'    => $limit,
                'site'     => $site,
            ]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            fail('Failed to fetch vouchers: ' . $e->getMessage());
        }

    // ── Delete vouchers ───────────────────────────────────────────────────────
    case 'delete_vouchers':
        $user  = requireAuth();
        $sites = userSites($user);
        $site  = $body['site'] ?? '';
        $ids   = $body['ids'] ?? [];

        if (!$site || !in_array($site, $sites)) fail('Invalid site');
        if (!is_array($ids) || empty($ids)) fail('No voucher IDs provided');

        [$dbname,] = siteDbName($site);
        $pdo = $dbname ? getDb($dbname) : null;
        if (!$pdo) fail('Database unavailable for this site');

        $tableMap = [
            'Enock'      => ['db' => 'omada',            'table' => 'vouchers2'],
            'Richard'    => ['db' => 'omada',            'table' => 'vouchers_richard'],
            'STK'        => ['db' => 'payment_mikrotik', 'table' => 'vouchers'],
            'Remmy'      => ['db' => 'remmy_mikrotik',   'table' => 'vouchers'],
            'Guma'       => ['db' => 'guma_omada',       'table' => 'vouchers'],
            'Namungoona' => ['db' => 'stk_namungoona',   'table' => 'vouchers'],
        ];

        if (!isset($tableMap[$site])) fail('Voucher deletion not configured for this site');

        $tbl = $tableMap[$site]['table'];
        $deleted = 0;

        try {
            $pdo->beginTransaction();

            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id <= 0) continue;

                $stmt = $pdo->prepare("DELETE FROM `$tbl` WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
                $deleted += $stmt->rowCount();
            }

            $pdo->commit();
            respond(['ok' => true, 'deleted' => $deleted]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            fail('Failed to delete vouchers: ' . $e->getMessage());
        }

    // ── Captive Page Templates ───────────────────────────────────────────────
    case 'get_captive_template':
        $user = requireAuth();
        $site = $user['site'] ?? null;
        
        if (!$site) fail('No site assigned to user');
        
        $fileMap = [
            'Richard'    => 'richard.html',
            'Guma'       => 'guma-omada.html',
            'STK'        => 'stk.html',
            'Remmy'      => 'remmy.html',
            'Enock'      => 'namungoona.html',
            'Namungoona' => 'namungoona.html',
        ];
        
        if (!isset($fileMap[$site])) fail('No template configured for this site');
        
        $filename = $fileMap[$site];
        $filepath = __DIR__ . '/../captivepage/' . $filename;
        
        if (!file_exists($filepath)) fail('Template file not found');
        
        $content = file_get_contents($filepath);
        respond(['ok' => true, 'filename' => $filename, 'content' => $content]);

    case 'save_captive_template':
        $user = requireAuth();
        $site = $user['site'] ?? null;
        
        if (!$site) fail('No site assigned to user');
        
        $body = json_decode(file_get_contents('php://input'), true);
        $content = $body['content'] ?? '';
        
        if (empty($content)) fail('No content provided');
        
        $fileMap = [
            'Richard'    => 'richard.html',
            'Guma'       => 'guma-omada.html',
            'STK'        => 'stk.html',
            'Remmy'      => 'remmy.html',
            'Enock'      => 'namungoona.html',
            'Namungoona' => 'namungoona.html',
        ];
        
        if (!isset($fileMap[$site])) fail('No template configured for this site');
        
        $filename = $fileMap[$site];
        $filepath = __DIR__ . '/../captivepage/' . $filename;
        
        // Create backup
        if (file_exists($filepath)) {
            $backupPath = __DIR__ . '/../captivepage/backups/';
            if (!is_dir($backupPath)) mkdir($backupPath, 0755, true);
            copy($filepath, $backupPath . $filename . '.' . date('Y-m-d_His') . '.bak');
        }
        
        // Save new content
        $result = file_put_contents($filepath, $content);
        
        if ($result === false) fail('Failed to save template');
        
        respond(['ok' => true, 'filename' => $filename, 'bytes' => $result]);

    default:
        fail('Unknown action', 404);
}
