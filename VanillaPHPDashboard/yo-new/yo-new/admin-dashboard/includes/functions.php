<?php
require_once __DIR__ . '/../config/database.php';

function _queryPdo($pdo, $sql, $params = []) {
    if ($pdo === null) return [];
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Query failed: " . $e->getMessage() . " SQL: $sql");
        return [];
    }
}

function _queryPdoScalar($pdo, $sql, $params = [], $col = 'count') {
    if ($pdo === null) return 0;
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row[$col] ?? 0;
    } catch (Exception $e) {
        error_log("Scalar query failed: " . $e->getMessage());
        return 0;
    }
}

function _getSiteConnection($siteName) {
    $db = Database::getInstance();
    switch ($siteName) {
        case 'STK':    return [$db->getMikrotikConnection(), 'STK WIFI'];
        case 'Remmy':  return [$db->getRemmyConnection(),   'remmy'];
        case 'Guma':   return [$db->getGumaConnection(),    'guma'];
        case 'Enock':  return [$db->getConnection(),        'Bite Tech Network'];
        case 'Richard':return [$db->getConnection(),        'Richard Network'];
        default:       return [$db->getConnection(),        $siteName];
    }
}

function getSalesDataBySite($siteName) {
    [$pdo, $dbSiteName] = _getSiteConnection($siteName);
    if ($pdo === null) {
        return ['total_sales' => 0, 'total_amount' => 0, 'completed_amount' => 0,
                'today_amount' => 0, 'week_amount' => 0, 'month_amount' => 0];
    }
    $rows = _queryPdo($pdo, "
        SELECT 
            COUNT(*) as total_sales,
            SUM(amount) as total_amount,
            SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as completed_amount,
            SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'success' THEN amount ELSE 0 END) as today_amount,
            SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'success' THEN amount ELSE 0 END) as week_amount,
            SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'success' THEN amount ELSE 0 END) as month_amount
        FROM transactions 
        WHERE origin_site = :site AND status = 'success'
    ", [':site' => $dbSiteName]);
    return $rows[0] ?? ['total_sales' => 0, 'total_amount' => 0, 'completed_amount' => 0,
                        'today_amount' => 0, 'week_amount' => 0, 'month_amount' => 0];
}

function getTotalWithdrawals($username) {
    $withdrawDb = Database::getInstance()->getWithdrawDb();
    if ($withdrawDb === null) return 0;
    try {
        $stmt = $withdrawDb->prepare('SELECT SUM(amount) as total FROM transactions WHERE username = :u AND status = "SUCCEEDED"');
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row['total'] ?? 0;
    } catch (Exception $e) { return 0; }
}

function getPendingWithdrawals($username) {
    $withdrawDb = Database::getInstance()->getWithdrawDb();
    if ($withdrawDb === null) return 0;
    try {
        $stmt = $withdrawDb->prepare('SELECT SUM(amount) as total FROM transactions WHERE username = :u AND status = "PENDING"');
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row['total'] ?? 0;
    } catch (Exception $e) { return 0; }
}

function _fetchSiteLogs($pdo, $whereOrigin, $extraWhere, $params, $limit, $offset) {
    if ($pdo === null) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, external_ref, msisdn, amount, status, created_at, origin_site, voucher_code
            FROM transactions WHERE {$whereOrigin} {$extraWhere}
            ORDER BY created_at DESC LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("fetchSiteLogs error: " . $e->getMessage());
        return [];
    }
}

function _countSiteLogs($pdo, $whereOrigin, $extraWhere, $params) {
    if ($pdo === null) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM transactions WHERE {$whereOrigin} {$extraWhere}");
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) { return 0; }
}

function getTransactions($filters = []) {
    $db = Database::getInstance();
    $limit  = (int)($filters['limit']  ?? 20);
    $offset = (int)($filters['offset'] ?? 0);
    $status = $filters['status'] ?? null;
    $site   = $filters['site']   ?? null;
    $search = $filters['search'] ?? null;

    $extra  = '';
    $params = [];
    if ($status && $status !== 'all') {
        $extra .= " AND status = :status";
        $params[':status'] = $status;
    }
    if ($search) {
        $extra .= " AND (msisdn LIKE :search OR external_ref LIKE :search OR voucher_code LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    $all = [];

    if (!$site || in_array($site, ['Enock', 'Richard'])) {
        $originWhere = "origin_site IN ('Bite Tech Network','Richard Network')";
        if ($site === 'Enock')   $originWhere = "origin_site = 'Bite Tech Network'";
        if ($site === 'Richard') $originWhere = "origin_site = 'Richard Network'";
        $all = array_merge($all, _fetchSiteLogs($db->getConnection(), $originWhere, $extra, $params, $limit, $offset));
    }
    if (!$site || $site === 'STK') {
        $all = array_merge($all, _fetchSiteLogs($db->getMikrotikConnection(), "origin_site = 'STK WIFI'", $extra, $params, $limit, $offset));
    }
    if (!$site || $site === 'Remmy') {
        $all = array_merge($all, _fetchSiteLogs($db->getRemmyConnection(), "origin_site = 'remmy'", $extra, $params, $limit, $offset));
    }
    if (!$site || $site === 'Guma') {
        $all = array_merge($all, _fetchSiteLogs($db->getGumaConnection(), "origin_site = 'guma'", $extra, $params, $limit, $offset));
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    return array_slice($all, 0, $limit);
}

function getTransactionCount($filters = []) {
    $db     = Database::getInstance();
    $status = $filters['status'] ?? null;
    $site   = $filters['site']   ?? null;
    $search = $filters['search'] ?? null;

    $extra  = '';
    $params = [];
    if ($status && $status !== 'all') {
        $extra .= " AND status = :status";
        $params[':status'] = $status;
    }
    if ($search) {
        $extra .= " AND (msisdn LIKE :search OR external_ref LIKE :search OR voucher_code LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    $count = 0;
    if (!$site || in_array($site, ['Enock', 'Richard'])) {
        $originWhere = "origin_site IN ('Bite Tech Network','Richard Network')";
        if ($site === 'Enock')   $originWhere = "origin_site = 'Bite Tech Network'";
        if ($site === 'Richard') $originWhere = "origin_site = 'Richard Network'";
        $count += _countSiteLogs($db->getConnection(), $originWhere, $extra, $params);
    }
    if (!$site || $site === 'STK') {
        $count += _countSiteLogs($db->getMikrotikConnection(), "origin_site = 'STK WIFI'", $extra, $params);
    }
    if (!$site || $site === 'Remmy') {
        $count += _countSiteLogs($db->getRemmyConnection(), "origin_site = 'remmy'", $extra, $params);
    }
    if (!$site || $site === 'Guma') {
        $count += _countSiteLogs($db->getGumaConnection(), "origin_site = 'guma'", $extra, $params);
    }
    return $count;
}

function getWithdrawalHistory($username, $limit = 15, $offset = 0) {
    $withdrawDb = Database::getInstance()->getWithdrawDb();
    if ($withdrawDb === null) return [];
    try {
        $stmt = $withdrawDb->prepare('
            SELECT * FROM transactions WHERE username = :u
            ORDER BY created_at DESC LIMIT :lim OFFSET :off
        ');
        $stmt->bindValue(':u',   $username, SQLITE3_TEXT);
        $stmt->bindValue(':lim', $limit,    SQLITE3_INTEGER);
        $stmt->bindValue(':off', $offset,   SQLITE3_INTEGER);
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }
        return $rows;
    } catch (Exception $e) { return []; }
}

function getWithdrawalCount($username) {
    $withdrawDb = Database::getInstance()->getWithdrawDb();
    if ($withdrawDb === null) return 0;
    try {
        $stmt = $withdrawDb->prepare('SELECT COUNT(*) as c FROM transactions WHERE username = :u');
        $stmt->bindValue(':u', $username, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) { return 0; }
}

function getDailyPerformance($site, $days = 7) {
    [$pdo, $dbSiteName] = _getSiteConnection($site);
    if ($pdo === null) return [];
    return _queryPdo($pdo, "
        SELECT 
            DATE(created_at) as date,
            SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as amount,
            COUNT(*) as transactions
        FROM transactions 
        WHERE origin_site = :site
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", [':site' => $dbSiteName, ':days' => $days]);
}

function formatCurrency($amount) {
    return 'UGX ' . number_format((float)$amount, 0);
}

function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'success':
        case 'succeeded':
            return 'bg-primary/10 text-primary';
        case 'pending':
            return 'bg-yellow-500/10 text-yellow-500';
        case 'failed':
            return 'bg-destructive/10 text-destructive';
        default:
            return 'bg-muted text-muted-foreground';
    }
}
