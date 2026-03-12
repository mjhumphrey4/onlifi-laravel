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

require_once __DIR__ . '/../../config_multitenant.php';

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ONLIFI_SESSION');
    session_start();
}

// Multi-tenant authentication - users managed in central database

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
        case 'STK':     return ['payment_mikrotik', 'STK WIFI'];
        case 'Remmy':   return ['remmy_mikrotik',   'remmy'];
        case 'Guma':    return ['guma_omada',        'guma'];
        case 'Enock':   return ['omada',             'Bite Tech Network'];
        case 'Richard': return ['omada',             'Richard Network'];
        default:        return [null, null];
    }
}

function allSites() {
    return ['Enock', 'Richard', 'STK', 'Remmy', 'Guma'];
}

function userSites($user) {
    // In multi-tenant system, each user has their own database
    // For now, return all sites for admin, empty for regular users
    // This maintains compatibility with the old site-based system
    return $user['role'] === 'admin' ? allSites() : [];
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
    if (empty($_SESSION['user_id'])) fail('Unauthorized', 401);
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? 'user',
        'database_name' => $_SESSION['database_name'] ?? ''
    ];
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
                            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END),0) as month_amount
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

            $result[$site] = [
                'total_amount'   => (float)$row['total_amount'],
                'today_amount'   => (float)$row['today_amount'],
                'week_amount'    => (float)$row['week_amount'],
                'month_amount'   => (float)$row['month_amount'],
                'total_sales'    => (int)$row['total_sales'],
                'withdrawn'      => (float)$withdrawn,
                'pending_withdraw'=> (float)$pending,
                'balance'        => (float)$row['total_amount'] - (float)$withdrawn,
            ];
        }
        respond(['sites' => $result, 'user' => $user]);

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
                $stmt = $pdo->prepare("SELECT id,external_ref,msisdn,amount,status,created_at,origin_site,voucher_code FROM transactions WHERE $where ORDER BY created_at DESC LIMIT 500");
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

        if (!in_array($site, $sites))             fail('Invalid site');
        if ($amount < 1000)                       fail('Minimum withdrawal is UGX 1,000');
        if (!preg_match('/^\d{10,12}$/', $phone)) fail('Invalid phone number format');

        // Check balance against MySQL revenue minus already-succeeded withdrawals
        [$dbname, $origin] = siteDbName($site);
        $pdo = $dbname ? getDb($dbname) : null;
        $totalRevenue = 0;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE origin_site=:o AND status='success'");
                $stmt->execute([':o' => $origin]);
                $totalRevenue = (float)($stmt->fetch(PDO::FETCH_ASSOC)['t'] ?? 0);
            } catch (Exception $e) { error_log($e->getMessage()); }
        }

        $sqlite = getSqlite();
        if (!$sqlite) fail('Withdrawal database unavailable');

        $withdrawn = 0;
        try {
            $s = $sqlite->prepare('SELECT COALESCE(SUM(amount),0) as t FROM transactions WHERE username=:u AND status="SUCCEEDED"');
            $s->bindValue(':u', $site, SQLITE3_TEXT);
            $withdrawn = (float)($s->execute()->fetchArray(SQLITE3_ASSOC)['t'] ?? 0);
        } catch (Exception $e) { error_log($e->getMessage()); }

        $balance = $totalRevenue - $withdrawn;
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
                'INSERT INTO transactions (username, phone_number, amount, status, transaction_reference, response_message, created_at)
                 VALUES (:u, :ph, :am, :st, :ref, :msg, :ca)'
            );
            $ins->bindValue(':u',   $site,               SQLITE3_TEXT);
            $ins->bindValue(':ph',  $phone,              SQLITE3_TEXT);
            $ins->bindValue(':am',  $amount,             SQLITE3_FLOAT);
            $ins->bindValue(':st',  $yoStatus,           SQLITE3_TEXT);
            $ins->bindValue(':ref', $yoRef ?: $txRef,    SQLITE3_TEXT);
            $ins->bindValue(':msg', $yoMessage,          SQLITE3_TEXT);
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

    // ── Import vouchers ───────────────────────────────────────────────────────
    case 'import_vouchers':
        $user  = requireAuth();
        $sites = userSites($user);

        // For multipart uploads the site comes from $_POST
        $site = trim($_POST['site'] ?? ($user['site'] ?? ''));
        if (!$site || !in_array($site, $sites)) fail('Invalid or missing site');

        // Map site → db + table (mirrors the standalone importers)
        $importMap = [
            'Enock'   => ['db' => 'omada',            'table' => 'vouchers2'],
            'Richard' => ['db' => 'omada',            'table' => 'vouchers_richard'],
            'STK'     => ['db' => 'payment_mikrotik', 'table' => 'vouchers'],
            'Remmy'   => ['db' => 'remmy_mikrotik',   'table' => 'vouchers'],
            'Guma'    => ['db' => 'guma_mikrotik',    'table' => 'vouchers'],
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
        $textLower = strtolower($pdfText);
        if      (strpos($textLower, '30days') !== false || strpos($textLower, '30 days') !== false || strpos($textLower, '30 day') !== false) $voucherType = '30days';
        elseif  (strpos($textLower, '7days')  !== false || strpos($textLower, '7 days')  !== false || strpos($textLower, '7 day')  !== false) $voucherType = '7days';
        elseif  (strpos($textLower, '1day')   !== false || strpos($textLower, '1 day')   !== false || preg_match('/\b1d\b/', $textLower)) $voucherType = '24hours';
        elseif  (strpos($textLower, '24hours') !== false || strpos($textLower, '24 hours') !== false || strpos($textLower, '24h') !== false)   $voucherType = '24hours';
        elseif  (strpos($textLower, '12hours') !== false || strpos($textLower, '12 hours') !== false || strpos($textLower, '12h') !== false)   $voucherType = '12hours';
        elseif  (strpos($textLower, '3hours')  !== false || strpos($textLower, '3 hours')  !== false || strpos($textLower, '3h') !== false)    $voucherType = '3hours';
        elseif  (strpos($textLower, '2hours')  !== false || strpos($textLower, '2 hours')  !== false)  $voucherType = '2hours';
        else    $voucherType = '12hours';

        // Skip-pattern list (mirrors the standalone importers)
        $skipPatterns = ['valid','for','limited','usage','counts','count','hours','days','voucher','code','type','www.','http','.com','/','pm','am','lot','net','cashless'];

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $seen     = [];

        $checkStmt  = $pdo->prepare("SELECT id FROM `$tbl` WHERE code = :code LIMIT 1");
        $insertStmt = $pdo->prepare("INSERT IGNORE INTO `$tbl` (code, type) VALUES (:code, :type)");

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
                // Use fetch() — rowCount() is unreliable for SELECT on MySQL PDO
                $checkStmt->execute([':code' => $line]);
                if ($checkStmt->fetch(PDO::FETCH_ASSOC) !== false) {
                    $errors[] = "Code '$line': already exists";
                    $skipped++;
                    continue;
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
            'Enock'   => ['db' => 'omada',            'table' => 'vouchers2'],
            'Richard' => ['db' => 'omada',            'table' => 'vouchers_richard'],
            'STK'     => ['db' => 'payment_mikrotik', 'table' => 'vouchers'],
            'Remmy'   => ['db' => 'remmy_mikrotik',   'table' => 'vouchers'],
            'Guma'    => ['db' => 'guma_omada',       'table' => 'vouchers'],
        ];

        if (!isset($tableMap[$site])) fail('Voucher stock not configured for this site');

        $tbl = $tableMap[$site]['table'];
        $stock = ['2hours' => 0, '12hours' => 0, '24hours' => 0, '7days' => 0, '30days' => 0, 'total' => 0];

        try {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type='2hours'  THEN 1 ELSE 0 END),0) as h2,
                    COALESCE(SUM(CASE WHEN type='3hours'  THEN 1 ELSE 0 END),0) as h3,
                    COALESCE(SUM(CASE WHEN type='12hours' THEN 1 ELSE 0 END),0) as h12,
                    COALESCE(SUM(CASE WHEN type='24hours' THEN 1 ELSE 0 END),0) as h24,
                    COALESCE(SUM(CASE WHEN type='7days'   THEN 1 ELSE 0 END),0) as d7,
                    COALESCE(SUM(CASE WHEN type='30days'  THEN 1 ELSE 0 END),0) as d30
                FROM `$tbl` WHERE used = 0
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stock = [
                '2hours'  => (int)$row['h2'],
                '3hours'  => (int)$row['h3'],
                '12hours' => (int)$row['h12'],
                '24hours' => (int)$row['h24'],
                '7days'   => (int)$row['d7'],
                '30days'  => (int)$row['d30'],
                'total'   => (int)$row['h2'] + (int)$row['h3'] + (int)$row['h12'] + (int)$row['h24'] + (int)$row['d7'] + (int)$row['d30'],
            ];
        } catch (Exception $e) { error_log($e->getMessage()); fail('Failed to query voucher stock: ' . $e->getMessage()); }

        respond(['site' => $site, 'stock' => $stock]);

    default:
        fail('Unknown action', 404);
}
