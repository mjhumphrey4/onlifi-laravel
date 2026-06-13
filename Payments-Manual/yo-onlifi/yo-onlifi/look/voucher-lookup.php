<?php
// Database configuration for this lookup page.
// Keep this pointed at the same tenant/site DB used by FreeRADIUS.
$dbHost = '10.200.1.254';
$dbPort = 3306;
$dbName = 'onlifi_1_1_stk';
$dbUser = 'yo';
$dbPass = 'password';

// Get MAC address from URL parameter (passed from MikroTik login page)
$client_mac = isset($_GET['mac']) ? urldecode(trim($_GET['mac'])) : '';

// Only use the MAC supplied by MikroTik for the requesting device.
$mac_address = $client_mac;

// Initialize response
$response = [];

function voucherLookupLog(string $event, array $context = []): void {
    $safeContext = $context;
    unset($safeContext['db_pass'], $safeContext['password']);

    $line = json_encode([
        'time' => date('Y-m-d H:i:s'),
        'event' => $event,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'context' => $safeContext,
    ], JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        $line = date('Y-m-d H:i:s') . ' ' . $event;
    }

    $logFile = __DIR__ . '/voucher-lookup.log';
    if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log('voucher-lookup: ' . $line);
    }
}

function normalizeLookupMac($value): string {
    $decoded = rawurldecode(rawurldecode(trim((string) $value)));
    return strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $decoded));
}

function getLookupDB(): PDO {
    global $dbHost, $dbPort, $dbName, $dbUser, $dbPass;

    voucherLookupLog('db_connect_attempt', [
        'db_host' => $dbHost,
        'db_port' => $dbPort,
        'db_name' => $dbName,
        'db_user' => $dbUser,
    ]);

    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    voucherLookupLog('db_connect_success', [
        'db_host' => $dbHost,
        'db_port' => $dbPort,
        'db_name' => $dbName,
        'db_user' => $dbUser,
    ]);

    return $pdo;
}

function isValidIdentifier(string $identifier): bool {
    return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
}

function tableColumns(PDO $pdo, string $table, bool $refresh = false): array {
    static $cache = [];

    if (!isValidIdentifier($table)) {
        return [];
    }

    $key = spl_object_hash($pdo) . ':' . $table;
    if (!$refresh && array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $columns = [];
        foreach ($stmt->fetchAll() as $column) {
            $columns[$column['Field']] = $column;
        }
        $cache[$key] = $columns;
    } catch (PDOException $e) {
        $cache[$key] = [];
        voucherLookupLog('schema_check_failed', [
            'table' => $table,
            'error' => $e->getMessage(),
            'sql_state' => $e->getCode(),
        ]);
    }

    return $cache[$key];
}

function tableExists(PDO $pdo, string $table): bool {
    return isValidIdentifier($table) && !empty(tableColumns($pdo, $table));
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    if (!isValidIdentifier($table) || !isValidIdentifier($column)) {
        return false;
    }

    $columns = tableColumns($pdo, $table);
    return isset($columns[$column]);
}

function normalizedMacSql(string $column): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE($column, '')), '%3A', ''), ':', ''), '-', ''), '.', '')";
}

function firstExistingColumn(PDO $pdo, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (columnExists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function successResponseFromRow(array $row): array {
    return [
        'status' => 'success',
        'voucher_code' => $row['voucher_code'] ?? '',
        'voucher_type' => $row['voucher_type'] ?? null,
        'amount' => $row['amount'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'msisdn' => $row['msisdn'] ?? null,
        'email' => $row['email'] ?? null,
        'lookup_source' => $row['lookup_source'] ?? null,
    ];
}

function findVoucherFromActiveRadius(PDO $pdo, string $normalizedMac): ?array {
    if (!tableExists($pdo, 'radacct')) {
        return null;
    }

    $macExpr = normalizedMacSql('ra.callingstationid');
    $joins = '';
    $voucherCodeExpr = 'ra.username';
    $voucherTypeExpr = 'ra.groupname';
    $amountExpr = 'NULL';
    $createdExpr = 'COALESCE(ra.acctupdatetime, ra.acctstarttime)';
    $voucherStatusFilter = '';

    if (tableExists($pdo, 'vouchers')) {
        $codeColumn = firstExistingColumn($pdo, 'vouchers', ['voucher_code', 'code']);
        if ($codeColumn) {
            $joins .= " LEFT JOIN vouchers v ON v.`$codeColumn` = ra.username";
            $voucherCodeExpr = "COALESCE(NULLIF(v.`$codeColumn`, ''), ra.username)";

            if (tableExists($pdo, 'voucher_groups') && columnExists($pdo, 'voucher_groups', 'group_name') && columnExists($pdo, 'vouchers', 'group_id')) {
                $joins .= " LEFT JOIN voucher_groups vg ON vg.id = v.group_id";
                $voucherTypeExpr = columnExists($pdo, 'vouchers', 'profile_name')
                    ? "COALESCE(vg.group_name, v.profile_name, ra.groupname)"
                    : "COALESCE(vg.group_name, ra.groupname)";
            } elseif (columnExists($pdo, 'vouchers', 'profile_name')) {
                $voucherTypeExpr = 'COALESCE(v.profile_name, ra.groupname)';
            } elseif (columnExists($pdo, 'vouchers', 'type')) {
                $voucherTypeExpr = 'COALESCE(v.`type`, ra.groupname)';
            }

            $amountColumn = firstExistingColumn($pdo, 'vouchers', ['price', 'amount']);
            if ($amountColumn) {
                $amountExpr = "v.`$amountColumn`";
            }

            $dateParts = ['ra.acctupdatetime', 'ra.acctstarttime'];
            foreach (['last_used_at', 'first_used_at', 'assigned_date', 'created_at'] as $dateColumn) {
                if (columnExists($pdo, 'vouchers', $dateColumn)) {
                    $dateParts[] = "v.`$dateColumn`";
                }
            }
            $createdExpr = 'COALESCE(' . implode(', ', $dateParts) . ')';

            if (columnExists($pdo, 'vouchers', 'status')) {
                $voucherStatusFilter = "AND (v.`status` IS NULL OR v.`status` NOT IN ('expired', 'disabled'))";
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            $voucherCodeExpr AS voucher_code,
            $voucherTypeExpr AS voucher_type,
            $amountExpr AS amount,
            'success' AS status,
            $createdExpr AS created_at,
            NULL AS msisdn,
            NULL AS email,
            ra.callingstationid AS client_mac,
            'active_radius' AS lookup_source
        FROM radacct ra
        $joins
        WHERE $macExpr = :mac
          AND COALESCE(ra.username, '') != ''
          AND (ra.acctstoptime IS NULL OR CAST(ra.acctstoptime AS CHAR) = '0000-00-00 00:00:00')
          $voucherStatusFilter
        ORDER BY COALESCE(ra.acctupdatetime, ra.acctstarttime) DESC, ra.radacctid DESC
        LIMIT 1
    ");
    $stmt->execute(['mac' => $normalizedMac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function findVoucherFromVouchers(PDO $pdo, string $normalizedMac): ?array {
    if (!tableExists($pdo, 'vouchers')) {
        return null;
    }

    $codeColumn = firstExistingColumn($pdo, 'vouchers', ['voucher_code', 'code']);
    $macColumn = firstExistingColumn($pdo, 'vouchers', ['used_by_mac', 'assigned_mac']);

    if (!$codeColumn || !$macColumn) {
        return null;
    }

    $macExpr = normalizedMacSql("v.`$macColumn`");
    $joins = '';
    $voucherTypeExpr = 'NULL';

    if (tableExists($pdo, 'voucher_groups') && columnExists($pdo, 'voucher_groups', 'group_name') && columnExists($pdo, 'vouchers', 'group_id')) {
        $joins = 'LEFT JOIN voucher_groups vg ON vg.id = v.group_id';
        $voucherTypeExpr = columnExists($pdo, 'vouchers', 'profile_name')
            ? 'COALESCE(vg.group_name, v.profile_name)'
            : 'vg.group_name';
    } elseif (columnExists($pdo, 'vouchers', 'profile_name')) {
        $voucherTypeExpr = 'v.profile_name';
    } elseif (columnExists($pdo, 'vouchers', 'type')) {
        $voucherTypeExpr = 'v.`type`';
    }

    $amountColumn = firstExistingColumn($pdo, 'vouchers', ['price', 'amount']);
    $amountExpr = $amountColumn ? "v.`$amountColumn`" : 'NULL';
    $dateColumn = firstExistingColumn($pdo, 'vouchers', ['last_used_at', 'first_used_at', 'assigned_date', 'created_at']);
    $createdExpr = $dateColumn ? "v.`$dateColumn`" : 'NULL';
    $orderParts = [];
    if ($dateColumn) {
        $orderParts[] = "$createdExpr DESC";
    }
    if (columnExists($pdo, 'vouchers', 'id')) {
        $orderParts[] = 'v.id DESC';
    }
    $orderSql = $orderParts ? implode(', ', $orderParts) : "v.`$codeColumn` DESC";

    $statusFilter = '';
    if (columnExists($pdo, 'vouchers', 'status')) {
        $statusFilter = "AND v.`status` NOT IN ('unused', 'expired', 'disabled')";
    } elseif (columnExists($pdo, 'vouchers', 'used')) {
        $statusFilter = 'AND v.`used` = 1';
    }

    $stmt = $pdo->prepare("
        SELECT
            v.`$codeColumn` AS voucher_code,
            $voucherTypeExpr AS voucher_type,
            $amountExpr AS amount,
            " . (columnExists($pdo, 'vouchers', 'status') ? 'v.`status`' : "'success'") . " AS status,
            $createdExpr AS created_at,
            NULL AS msisdn,
            NULL AS email,
            v.`$macColumn` AS client_mac,
            'vouchers' AS lookup_source
        FROM vouchers v
        $joins
        WHERE $macExpr = :mac
          AND COALESCE(v.`$codeColumn`, '') != ''
          $statusFilter
        ORDER BY $orderSql
        LIMIT 1
    ");
    $stmt->execute(['mac' => $normalizedMac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function findVoucherFromTransactions(PDO $pdo, string $normalizedMac): ?array {
    if (!tableExists($pdo, 'transactions')) {
        return null;
    }

    $macExpr = normalizedMacSql('client_mac');
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
            client_mac,
            'transactions' AS lookup_source
        FROM transactions
        WHERE $macExpr = :mac
          AND voucher_code IS NOT NULL
          AND voucher_code != ''
          AND LOWER(status) IN ('success', 'completed')
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute(['mac' => $normalizedMac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

if (!empty($mac_address)) {
    try {
        voucherLookupLog('lookup_request_start', [
            'raw_mac' => $mac_address,
            'query_mac' => $_GET['mac'] ?? null,
        ]);

        $pdo = getLookupDB();
        $normalized_mac = normalizeLookupMac($mac_address);

        voucherLookupLog('lookup_mac_normalized', [
            'raw_mac' => $mac_address,
            'normalized_mac' => $normalized_mac,
            'normalized_length' => strlen($normalized_mac),
        ]);

        $result = null;
        if ($normalized_mac !== '') {
            voucherLookupLog('lookup_stage_start', ['stage' => 'active_radius']);
            $result = findVoucherFromActiveRadius($pdo, $normalized_mac);
            voucherLookupLog('lookup_stage_done', [
                'stage' => 'active_radius',
                'found' => (bool) $result,
                'voucher_code' => $result['voucher_code'] ?? null,
            ]);

            if (!$result) {
                voucherLookupLog('lookup_stage_start', ['stage' => 'vouchers']);
                $result = findVoucherFromVouchers($pdo, $normalized_mac);
                voucherLookupLog('lookup_stage_done', [
                    'stage' => 'vouchers',
                    'found' => (bool) $result,
                    'voucher_code' => $result['voucher_code'] ?? null,
                ]);
            }

            if (!$result) {
                voucherLookupLog('lookup_stage_start', ['stage' => 'transactions']);
                $result = findVoucherFromTransactions($pdo, $normalized_mac);
                voucherLookupLog('lookup_stage_done', [
                    'stage' => 'transactions',
                    'found' => (bool) $result,
                    'voucher_code' => $result['voucher_code'] ?? null,
                ]);
            }
        } else {
            voucherLookupLog('lookup_invalid_mac', [
                'raw_mac' => $mac_address,
                'normalized_mac' => $normalized_mac,
            ]);
        }
        
        if ($result) {
            $response = successResponseFromRow($result);
            voucherLookupLog('lookup_success', [
                'lookup_source' => $response['lookup_source'] ?? null,
                'voucher_code' => $response['voucher_code'] ?? null,
            ]);
        } else {
            $response['status'] = 'not_found';
            $response['message'] = 'No voucher found for this MAC address';
            voucherLookupLog('lookup_not_found', [
                'normalized_mac' => $normalized_mac,
            ]);
        }
        
    } catch (PDOException $e) {
        $response['status'] = 'error';
        $response['message'] = 'Database connection error. Please try again later.';
        voucherLookupLog('lookup_pdo_error', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } catch (Throwable $e) {
        $response['status'] = 'error';
        $response['message'] = 'Lookup error. Please try again later.';
        voucherLookupLog('lookup_unexpected_error', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }
} else {
    voucherLookupLog('lookup_request_missing_mac', [
        'query_mac' => $_GET['mac'] ?? null,
    ]);
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

        .success-message {
            background: #ecfdf5;
            border-left: 4px solid #10b981;
            border-radius: 8px;
            color: #065f46;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.5;
            margin-bottom: 18px;
            padding: 12px 14px;
            text-align: center;
        }

        .success-message span {
            color: #047857;
            font-weight: 700;
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

                    <div class="success-message">
                        Voucher found for <?php echo htmlspecialchars($mac_address); ?>. Connecting in <span id="connectCountdown">3</span>s...
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
                        <?php if ($response['amount'] !== null && $response['amount'] !== ''): ?>
                        <div class="info-item">
                            <span class="info-label">Amount Paid:</span>
                            <span class="info-value">UGX <?php echo number_format($response['amount'], 0); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($response['msisdn'])): ?>
                        <div class="info-item">
                            <span class="info-label">Phone Number:</span>
                            <span class="info-value"><?php echo htmlspecialchars($response['msisdn']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($response['email'])): ?>
                        <div class="info-item">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($response['email']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($response['created_at']) && strtotime($response['created_at'])): ?>
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
                        <div class="date"><?php $createdTime = strtotime($response['created_at']); echo date('M d, Y', $createdTime) . ' &bull; ' . date('H:i', $createdTime); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="footer-note">
                        Please save this voucher code for your records
                    </div>
                </div>

                <script>
                    let remainingSeconds = 3;
                    const countdownEl = document.getElementById('connectCountdown');
                    const connectTimer = setInterval(() => {
                        remainingSeconds -= 1;
                        if (countdownEl) {
                            countdownEl.textContent = String(Math.max(remainingSeconds, 0));
                        }

                        if (remainingSeconds <= 0) {
                            clearInterval(connectTimer);
                            if (window.parent !== window) {
                                window.parent.postMessage({
                                    type: 'VOUCHER_FOUND',
                                    voucherCode: '<?php echo htmlspecialchars($response['voucher_code']); ?>'
                                }, '*');
                            }
                        }
                    }, 1000);

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
                        <a href="tel:0788770102">0788770102</a> or
                        <a href="tel:0704169987">0704169987</a>
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
