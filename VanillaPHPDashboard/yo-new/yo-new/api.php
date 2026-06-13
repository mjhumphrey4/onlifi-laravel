<?php

date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Site-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

function publicRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$site = onlifiCurrentSite();
if (!$site) publicRespond(['error' => 'Unknown or inactive payment site'], 404);

$configuredKey = trim((string)($site['api_key'] ?? ''));
if ($configuredKey !== '') {
    $providedKey = $_SERVER['HTTP_X_SITE_KEY'] ?? $_GET['site_key'] ?? '';
    if (!hash_equals($configuredKey, (string)$providedKey)) {
        publicRespond(['error' => 'Invalid site key'], 401);
    }
}

$action = $_GET['action'] ?? 'balance';
$pdo = getDB();

switch ($action) {
    case 'balance':
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(SUM(telecom_fee), 0) AS telecom_fees,
                COALESCE(SUM(platform_fee), 0) AS platform_fees,
                COUNT(*) AS total_sales
            FROM transactions
            WHERE origin_site = :origin_site AND status = 'success'
        ");
        $stmt->execute([':origin_site' => $site['origin_site']]);
        $row = $stmt->fetch() ?: [];
        $gross = (float)($row['total_amount'] ?? 0);
        $telecomFees = (float)($row['telecom_fees'] ?? 0);
        $platformFees = (float)($row['platform_fees'] ?? 0);
        $netRevenue = $gross - $telecomFees - $platformFees;
        $withdrawn = onlifiLedgerWithdrawalSum($site['display_name'], 'SUCCEEDED');

        publicRespond([
            'site' => [
                'slug' => $site['slug'],
                'display_name' => $site['display_name'],
                'origin_site' => $site['origin_site'],
                'tenant_id' => $site['tenant_id'],
                'onlifi_site_id' => $site['onlifi_site_id'],
            ],
            'currency' => 'UGX',
            'gross_collections' => $gross,
            'telecom_fees' => $telecomFees,
            'platform_fees' => $platformFees,
            'net_revenue' => $netRevenue,
            'withdrawn' => $withdrawn,
            'available_balance' => $netRevenue - $withdrawn,
            'total_sales' => (int)($row['total_sales'] ?? 0),
        ]);

    case 'transactions':
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
        $stmt = $pdo->prepare("
            SELECT id, external_ref, transaction_ref, msisdn, amount, status, voucher_code, created_at, updated_at
            FROM transactions
            WHERE origin_site = :origin_site
            ORDER BY created_at DESC
            LIMIT $limit
        ");
        $stmt->execute([':origin_site' => $site['origin_site']]);
        publicRespond(['transactions' => $stmt->fetchAll()]);

    default:
        publicRespond(['error' => 'Unknown action'], 400);
}

?>
