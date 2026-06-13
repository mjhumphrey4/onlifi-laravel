<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

cors();

try {
    $site = requireSite($_GET);
    $token = $_SERVER['HTTP_X_SITE_KEY'] ?? ($_GET['api_key'] ?? '');
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
    }

    if (!is_string($token) || !hash_equals((string) $site['api_key'], $token)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $action = $_GET['action'] ?? 'balance';

    if ($action === 'transactions') {
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $stmt = centralDb()->prepare("
            SELECT external_ref, transaction_ref, transaction_type, provider, msisdn, amount, status,
                   status_message, network_ref, origin_site, voucher_type, voucher_code, created_at, updated_at
            FROM payment_transactions
            WHERE site_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, (int) $site['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['site' => $site['slug'], 'transactions' => $stmt->fetchAll()]);
    }

    $stmt = centralDb()->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) AS balance,
            COALESCE(SUM(CASE WHEN status = 'success' AND transaction_type = 'collection' THEN amount ELSE 0 END), 0) AS total_collections,
            COALESCE(ABS(SUM(CASE WHEN status = 'success' AND transaction_type = 'withdrawal' THEN amount ELSE 0 END)), 0) AS total_withdrawals,
            COALESCE(SUM(CASE WHEN status = 'pending' AND transaction_type = 'collection' THEN amount ELSE 0 END), 0) AS pending_collections
        FROM payment_transactions
        WHERE site_id = ?
    ");
    $stmt->execute([(int) $site['id']]);
    $summary = $stmt->fetch() ?: [];

    jsonResponse([
        'site' => [
            'slug' => $site['slug'],
            'display_name' => $site['display_name'],
            'origin_site' => $site['origin_site'],
            'tenant_id' => $site['tenant_id'],
            'onlifi_site_id' => $site['onlifi_site_id'],
        ],
        'balance' => (float) ($summary['balance'] ?? 0),
        'total_collections' => (float) ($summary['total_collections'] ?? 0),
        'total_withdrawals' => (float) ($summary['total_withdrawals'] ?? 0),
        'pending_collections' => (float) ($summary['pending_collections'] ?? 0),
        'as_of' => date(DATE_ATOM),
    ]);
} catch (Throwable $e) {
    logPayment('API error', ['error' => $e->getMessage()]);
    jsonResponse(['error' => 'API request failed'], 500);
}
