<?php

declare(strict_types=1);

$localConfig = __DIR__ . '/config.php';
$exampleConfig = __DIR__ . '/config.example.php';
$config = is_file($localConfig) ? require $localConfig : require $exampleConfig;

date_default_timezone_set($config['timezone'] ?? 'Africa/Kampala');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('onlifi_payment_admin');
    session_start();
}

function appConfig(?string $key = null, $default = null)
{
    global $config;
    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function dbFromConfig(array $db): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']),
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function centralDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = dbFromConfig(appConfig('central_db'));
    ensureCentralSchema($pdo);
    return $pdo;
}

function ensureCentralSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_admins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_sites (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            display_name VARCHAR(160) NOT NULL,
            origin_site VARCHAR(160) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            api_key VARCHAR(96) NOT NULL UNIQUE,
            tenant_id BIGINT UNSIGNED NULL,
            onlifi_site_id BIGINT UNSIGNED NULL,
            db_host VARCHAR(190) NULL,
            db_port INT NOT NULL DEFAULT 3306,
            db_name VARCHAR(190) NULL,
            db_user VARCHAR(190) NULL,
            db_pass VARCHAR(255) NULL,
            default_profile VARCHAR(120) NOT NULL DEFAULT 'default',
            sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sms_sender_id VARCHAR(11) NULL,
            sms_message_category VARCHAR(32) NULL,
            sms_brand_name VARCHAR(120) NULL,
            allowed_origins TEXT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            KEY payment_sites_active_slug_index (active, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensureColumns($pdo, 'payment_sites', [
        'sms_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sms_sender_id' => 'VARCHAR(11) NULL',
        'sms_message_category' => 'VARCHAR(32) NULL',
        'sms_brand_name' => 'VARCHAR(120) NULL',
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED NOT NULL,
            transaction_type VARCHAR(32) NOT NULL DEFAULT 'collection',
            provider VARCHAR(32) NOT NULL DEFAULT 'yo',
            external_ref VARCHAR(120) NOT NULL UNIQUE,
            transaction_ref VARCHAR(120) NULL,
            msisdn VARCHAR(32) NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            status_message VARCHAR(255) NULL,
            network_ref VARCHAR(120) NULL,
            origin_site VARCHAR(160) NULL,
            client_mac VARCHAR(64) NULL,
            email VARCHAR(190) NULL,
            voucher_type VARCHAR(120) NULL,
            voucher_code VARCHAR(120) NULL,
            origin_url TEXT NULL,
            payout_account VARCHAR(120) NULL,
            requested_by VARCHAR(190) NULL,
            raw_payload MEDIUMTEXT NULL,
            tenant_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            KEY payment_transactions_site_status_index (site_id, status, created_at),
            KEY payment_transactions_transaction_ref_index (transaction_ref),
            CONSTRAINT payment_transactions_site_fk FOREIGN KEY (site_id) REFERENCES payment_sites(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_sms_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED NOT NULL,
            transaction_id BIGINT UNSIGNED NULL,
            external_ref VARCHAR(120) NULL,
            provider VARCHAR(32) NOT NULL DEFAULT 'mambosms',
            recipient VARCHAR(32) NOT NULL,
            sender_id VARCHAR(11) NULL,
            message_category VARCHAR(32) NULL,
            message TEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            status_message VARCHAR(255) NULL,
            http_code INT NULL,
            provider_status_code INT NULL,
            recipients_count INT NULL,
            message_count INT NULL,
            sms_sent INT NULL,
            sms_cost DECIMAL(14,2) NULL,
            provider_balance DECIMAL(14,2) NULL,
            raw_response MEDIUMTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            KEY payment_sms_logs_site_created_index (site_id, created_at),
            KEY payment_sms_logs_external_ref_index (external_ref),
            CONSTRAINT payment_sms_logs_site_fk FOREIGN KEY (site_id) REFERENCES payment_sites(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->query("SELECT COUNT(*) FROM payment_admins");
    if ((int) $stmt->fetchColumn() === 0) {
        $admin = appConfig('default_admin');
        $insert = $pdo->prepare("
            INSERT INTO payment_admins (name, email, password_hash, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $insert->execute([
            $admin['name'],
            $admin['email'],
            password_hash($admin['password'], PASSWORD_DEFAULT),
        ]);
    }

    $checked = true;
}

function ensureColumns(PDO $pdo, string $table, array $columns): void
{
    $existing = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $column) {
            $existing[$column['Field']] = true;
        }
    } catch (Throwable) {
        return;
    }

    foreach ($columns as $column => $definition) {
        if (!isset($existing[$column])) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

function currentAdmin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    $stmt = centralDb()->prepare("SELECT * FROM payment_admins WHERE id = ? AND active = 1 LIMIT 1");
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    return $admin ?: null;
}

function requireAdmin(): array
{
    $admin = currentAdmin();
    if (!$admin) {
        header('Location: login.php');
        exit;
    }
    return $admin;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(419);
        exit('Invalid form token.');
    }
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'UGX ' . number_format((float) $amount, 0);
}

function normalizeSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?: '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'site-' . random_int(1000, 9999);
}

function requestData(): array
{
    $input = file_get_contents('php://input') ?: '';
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
        }
        return is_array($decoded) ? $decoded : [];
    }
    if ($_POST) {
        return $_POST;
    }
    $data = [];
    parse_str($input, $data);
    return $data;
}

function cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Site-Key, X-Requested-With');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function resolveSiteSlug(?array $data = null): string
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $parts = array_values(array_filter(explode('/', trim($uriPath, '/'))));
    $endpoints = ['initiate.php', 'check_status.php', 'ipn.php', 'callback.php', 'failure.php'];

    foreach ($parts as $i => $part) {
        if (in_array($part, $endpoints, true) && $i > 0) {
            return normalizeSlug($parts[$i - 1]);
        }
    }

    if (isset($_GET['site'])) {
        return normalizeSlug((string) $_GET['site']);
    }

    if ($data && !empty($data['site_slug'])) {
        return normalizeSlug((string) $data['site_slug']);
    }

    return normalizeSlug((string) ($data['origin_site'] ?? 'default'));
}

function siteBySlug(string $slug): ?array
{
    $stmt = centralDb()->prepare("SELECT * FROM payment_sites WHERE slug = ? AND active = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $site = $stmt->fetch();
    return $site ?: null;
}

function requireSite(?array $data = null): array
{
    $slug = resolveSiteSlug($data);
    $site = siteBySlug($slug);
    if (!$site) {
        jsonResponse(['status' => -1, 'errorMessage' => "Unknown or inactive payment site: $slug"], 404);
    }
    return $site;
}

function publicEndpointUrl(array $site, string $endpoint): string
{
    return rtrim((string) appConfig('base_url'), '/') . '/' . rawurlencode($site['slug']) . '/' . $endpoint;
}

function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\s+/', '', str_replace('+', '', $phone));
    if (str_starts_with($phone, '0')) {
        $phone = '256' . substr($phone, 1);
    }
    if (!str_starts_with($phone, '256')) {
        $phone = '256' . $phone;
    }
    return $phone;
}

function normalizeMac($value): ?string
{
    $value = trim((string) $value);
    if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $value, $matches)) {
        return strtoupper(str_replace('-', ':', $matches[0]));
    }
    return $value === '' || str_contains($value, '$(') ? null : substr($value, 0, 64);
}

function tenantDb(array $site): ?PDO
{
    if (empty($site['db_host']) || empty($site['db_name']) || empty($site['db_user'])) {
        return null;
    }

    return dbFromConfig([
        'host' => $site['db_host'],
        'port' => (int) ($site['db_port'] ?: 3306),
        'name' => $site['db_name'],
        'user' => $site['db_user'],
        'pass' => $site['db_pass'] ?? '',
    ]);
}

function centralTransactionByRef(string $ref): ?array
{
    $stmt = centralDb()->prepare("
        SELECT t.*, s.slug, s.display_name, s.onlifi_site_id, s.db_host, s.db_port, s.db_name, s.db_user, s.db_pass, s.default_profile
        FROM payment_transactions t
        JOIN payment_sites s ON s.id = t.site_id
        WHERE t.external_ref = ? OR t.transaction_ref = ?
        LIMIT 1
    ");
    $stmt->execute([$ref, $ref]);
    $tx = $stmt->fetch();
    return $tx ?: null;
}

function insertCentralTransaction(array $site, array $data): int
{
    $stmt = centralDb()->prepare("
        INSERT INTO payment_transactions
            (site_id, transaction_type, provider, external_ref, msisdn, amount, status, status_message,
             origin_site, client_mac, email, voucher_type, origin_url, payout_account, requested_by, raw_payload,
             created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $site['id'],
        $data['transaction_type'] ?? 'collection',
        $data['provider'] ?? 'yo',
        $data['external_ref'],
        $data['msisdn'] ?? null,
        $data['amount'],
        $data['status'] ?? 'pending',
        $data['status_message'] ?? null,
        $data['origin_site'] ?? $site['origin_site'],
        $data['client_mac'] ?? null,
        $data['email'] ?? null,
        $data['voucher_type'] ?? null,
        $data['origin_url'] ?? null,
        $data['payout_account'] ?? null,
        $data['requested_by'] ?? null,
        isset($data['raw_payload']) ? json_encode($data['raw_payload']) : null,
    ]);

    return (int) centralDb()->lastInsertId();
}

function updateCentralTransaction(string $externalRef, array $data): void
{
    $allowed = [
        'transaction_ref', 'status', 'status_message', 'network_ref', 'voucher_code',
        'raw_payload', 'tenant_synced_at', 'updated_at',
    ];
    $sets = [];
    $values = [];
    foreach ($data as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $sets[] = "`$key` = ?";
        $values[] = is_array($value) ? json_encode($value) : $value;
    }
    if (!$sets) {
        return;
    }
    if (!in_array('updated_at', array_keys($data), true)) {
        $sets[] = 'updated_at = NOW()';
    }
    $values[] = $externalRef;
    $stmt = centralDb()->prepare('UPDATE payment_transactions SET ' . implode(', ', $sets) . ' WHERE external_ref = ?');
    $stmt->execute($values);
}

function smsLogs(int $limit = 100): array
{
    $stmt = centralDb()->prepare("
        SELECT l.*, s.display_name, s.slug
        FROM payment_sms_logs l
        JOIN payment_sites s ON s.id = l.site_id
        ORDER BY l.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mirrorToTenant(array $site, array $tx): void
{
    try {
        $pdo = tenantDb($site);
        if (!$pdo) {
            return;
        }

        ensureTenantTransactionSchema($pdo);
        $existing = tenantTransactionByExternalRef($pdo, $tx['external_ref']);
        $siteId = $site['onlifi_site_id'] ?: null;
        $amount = abs((float) $tx['amount']);

        if ($existing) {
            tenantUpdateFiltered($pdo, 'transactions', 'external_ref', $tx['external_ref'], [
                'transaction_ref' => $tx['transaction_ref'] ?? null,
                'status' => $tx['status'],
                'status_message' => $tx['status_message'] ?? null,
                'network_ref' => $tx['network_ref'] ?? null,
                'voucher_code' => $tx['voucher_code'] ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            tenantInsertFiltered($pdo, 'transactions', [
                'external_ref' => $tx['external_ref'],
                'transaction_ref' => $tx['transaction_ref'] ?? null,
                'msisdn' => $tx['msisdn'] ?? null,
                'amount' => $amount,
                'status' => $tx['status'],
                'status_message' => $tx['status_message'] ?? null,
                'network_ref' => $tx['network_ref'] ?? null,
                'origin_site' => $tx['origin_site'] ?? $site['origin_site'],
                'site_id' => $siteId,
                'client_mac' => $tx['client_mac'] ?? null,
                'email' => $tx['email'] ?? null,
                'voucher_type' => $tx['voucher_type'] ?? null,
                'voucher_code' => $tx['voucher_code'] ?? null,
                'origin_url' => $tx['origin_url'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        updateCentralTransaction($tx['external_ref'], ['tenant_synced_at' => date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        logPayment('Tenant mirror skipped', [
            'site' => $site['slug'] ?? null,
            'external_ref' => $tx['external_ref'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}

function ensureTenantTransactionSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            external_ref VARCHAR(120) NOT NULL UNIQUE,
            transaction_ref VARCHAR(120) NULL,
            msisdn VARCHAR(32) NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            status_message VARCHAR(255) NULL,
            network_ref VARCHAR(120) NULL,
            origin_site VARCHAR(160) NULL,
            site_id BIGINT UNSIGNED NULL,
            client_mac VARCHAR(64) NULL,
            email VARCHAR(190) NULL,
            voucher_type VARCHAR(120) NULL,
            voucher_code VARCHAR(120) NULL,
            origin_url TEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            KEY transactions_status_created_at_index (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = tenantColumns($pdo, 'transactions', true);
    $expected = [
        'external_ref' => 'VARCHAR(120) NULL',
        'transaction_ref' => 'VARCHAR(120) NULL',
        'msisdn' => 'VARCHAR(32) NULL',
        'amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
        'status' => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
        'status_message' => 'VARCHAR(255) NULL',
        'network_ref' => 'VARCHAR(120) NULL',
        'origin_site' => 'VARCHAR(160) NULL',
        'site_id' => 'BIGINT UNSIGNED NULL',
        'client_mac' => 'VARCHAR(64) NULL',
        'email' => 'VARCHAR(190) NULL',
        'voucher_type' => 'VARCHAR(120) NULL',
        'voucher_code' => 'VARCHAR(120) NULL',
        'origin_url' => 'TEXT NULL',
        'created_at' => 'TIMESTAMP NULL',
        'updated_at' => 'TIMESTAMP NULL',
    ];

    foreach ($expected as $column => $definition) {
        if (!isset($columns[$column])) {
            try {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN `$column` $definition");
            } catch (Throwable) {
                // Some production DB users are intentionally not allowed to ALTER.
                // Inserts and updates below filter to existing columns where possible.
            }
        }
    }
}

function tenantTransactionByExternalRef(PDO $pdo, string $externalRef): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE external_ref = ? LIMIT 1");
    $stmt->execute([$externalRef]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function handleSuccessfulCollection(string $externalRef, array $updates = []): array
{
    $tx = centralTransactionByRef($externalRef);
    if (!$tx) {
        throw new RuntimeException('Transaction not found.');
    }

    if ($tx['status'] !== 'success') {
        updateCentralTransaction($tx['external_ref'], array_merge([
            'status' => 'success',
            'status_message' => $updates['status_message'] ?? 'Payment successful',
            'network_ref' => $updates['network_ref'] ?? null,
        ], $updates));
    }

    $fresh = centralTransactionByRef($tx['external_ref']);
    $site = siteBySlug($fresh['slug']);
    if (!$site) {
        throw new RuntimeException('Site not found for transaction.');
    }

    if (empty($fresh['voucher_code']) && $fresh['transaction_type'] === 'collection') {
        try {
            $voucher = createTenantVoucher($site, $fresh);
        } catch (Throwable $e) {
            $voucher = null;
            logPayment('Voucher creation skipped', [
                'site' => $site['slug'] ?? null,
                'external_ref' => $fresh['external_ref'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
        if ($voucher) {
            updateCentralTransaction($fresh['external_ref'], ['voucher_code' => $voucher]);
            $fresh['voucher_code'] = $voucher;
        }
    }

    mirrorToTenant($site, $fresh);
    $fresh = centralTransactionByRef($fresh['external_ref']) ?: $fresh;
    try {
        sendTransactionSmsIfEnabled($site, $fresh);
    } catch (Throwable $e) {
        logPayment('SMS send skipped after success', [
            'site' => $site['slug'] ?? null,
            'external_ref' => $fresh['external_ref'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }

    return centralTransactionByRef($fresh['external_ref']) ?: $fresh;
}

function sendTransactionSmsIfEnabled(array $site, array $tx): array
{
    if (empty($site['sms_enabled']) || $tx['transaction_type'] !== 'collection') {
        return ['success' => false, 'skipped' => true, 'message' => 'SMS disabled for this site'];
    }

    $recipient = trim((string) ($tx['msisdn'] ?? ''));
    if ($recipient === '') {
        return ['success' => false, 'skipped' => true, 'message' => 'Missing recipient phone number'];
    }

    $existing = centralDb()->prepare("
        SELECT id, status FROM payment_sms_logs
        WHERE external_ref = ? AND status = 'sent'
        LIMIT 1
    ");
    $existing->execute([$tx['external_ref']]);
    if ($existing->fetch()) {
        return ['success' => true, 'skipped' => true, 'message' => 'SMS already sent'];
    }

    $message = buildTransactionSmsMessage($site, $tx);
    return sendMamboSms($site, $tx, $recipient, $message);
}

function buildTransactionSmsMessage(array $site, array $tx): string
{
    $brand = trim((string) ($site['sms_brand_name'] ?: appConfig('sms.brand_name', 'ONLIFI WiFi')));
    $voucher = trim((string) ($tx['voucher_code'] ?? ''));
    $package = trim((string) ($tx['voucher_type'] ?? ''));

    if ($voucher !== '') {
        $packageText = $package !== '' ? " for $package" : '';
        return "$brand: Your$packageText voucher code is $voucher. Thank you.";
    }

    $amount = number_format((float) $tx['amount'], 0);
    return "$brand: Payment of UGX $amount was received successfully. Thank you.";
}

function sendMamboSms(array $site, array $tx, string $recipient, string $message): array
{
    $sms = appConfig('sms', []);
    $apiKey = trim((string) ($sms['api_key'] ?? ''));
    $sendUrl = trim((string) ($sms['send_url'] ?? 'https://api-mongolia.mambosms.com/v1/send-sms'));
    $senderId = substr(trim((string) ($site['sms_sender_id'] ?: ($sms['sender_id'] ?? 'ONLIFI'))), 0, 11);
    $category = trim((string) ($site['sms_message_category'] ?: ($sms['message_category'] ?? 'customised')));
    $timeout = (int) ($sms['timeout_seconds'] ?? 10);

    if ($apiKey === '') {
        return recordSmsResult($site, $tx, $recipient, $senderId, $category, $message, [
            'success' => false,
            'message' => 'MamboSMS API key is not configured',
            'http_code' => null,
            'response' => null,
        ]);
    }

    $payload = [
        'message' => $message,
        'recipients' => $recipient,
        'message_category' => $category,
        'sender_id' => $senderId,
    ];

    $response = httpJsonRequest('POST', $sendUrl, $payload, [
        'Authorization: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $timeout);

    return recordSmsResult($site, $tx, $recipient, $senderId, $category, $message, $response);
}

function recordSmsResult(array $site, array $tx, string $recipient, string $senderId, string $category, string $message, array $result): array
{
    $response = $result['response'] ?? null;
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $success = !empty($result['success']) && !empty($response['success']);
    $messages = $response['messages'] ?? null;
    $statusMessage = is_array($messages) ? implode(' ', array_map('strval', $messages)) : ($result['message'] ?? null);

    $stmt = centralDb()->prepare("
        INSERT INTO payment_sms_logs
            (site_id, transaction_id, external_ref, provider, recipient, sender_id, message_category, message,
             status, status_message, http_code, provider_status_code, recipients_count, message_count, sms_sent,
             sms_cost, provider_balance, raw_response, created_at, updated_at)
        VALUES
            (?, ?, ?, 'mambosms', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $site['id'],
        $tx['id'] ?? null,
        $tx['external_ref'] ?? null,
        $recipient,
        $senderId,
        $category,
        $message,
        $success ? 'sent' : 'failed',
        substr((string) $statusMessage, 0, 255),
        $result['http_code'] ?? null,
        $response['statusCode'] ?? null,
        $data['recipients_count'] ?? null,
        $data['message_count'] ?? null,
        $data['sms_sent'] ?? null,
        $data['sms_cost'] ?? null,
        $data['new_balance'] ?? null,
        $response !== null ? json_encode($response) : null,
    ]);

    logPayment('MamboSMS result', [
        'site' => $site['slug'] ?? null,
        'external_ref' => $tx['external_ref'] ?? null,
        'success' => $success,
        'message' => $statusMessage,
    ]);

    return [
        'success' => $success,
        'message' => $statusMessage ?: ($success ? 'SMS sent successfully' : 'SMS failed'),
        'response' => $response,
    ];
}

function httpJsonRequest(string $method, string $url, ?array $payload, array $headers, int $timeout): array
{
    $body = $payload !== null ? json_encode($payload) : null;
    if ($payload !== null && $body === false) {
        return ['success' => false, 'message' => 'Could not encode request JSON', 'http_code' => null, 'response' => null];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            return ['success' => false, 'message' => 'HTTP error: ' . $error, 'http_code' => $httpCode, 'response' => null];
        }

        return parseProviderJsonResponse((string) $raw, $httpCode);
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
                break;
            }
        }
    }

    if ($raw === false) {
        return ['success' => false, 'message' => 'HTTP request failed', 'http_code' => $httpCode, 'response' => null];
    }

    return parseProviderJsonResponse((string) $raw, $httpCode);
}

function parseProviderJsonResponse(string $raw, int $httpCode): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'message' => "Provider returned invalid JSON with HTTP $httpCode",
            'http_code' => $httpCode,
            'response' => ['raw' => substr($raw, 0, 500)],
        ];
    }

    $ok = $httpCode >= 200 && $httpCode < 300 && !empty($decoded['success']);
    $messages = $decoded['messages'] ?? [];
    return [
        'success' => $ok,
        'message' => is_array($messages) ? implode(' ', array_map('strval', $messages)) : 'Provider responded',
        'http_code' => $httpCode,
        'response' => $decoded,
    ];
}

function mamboSmsBalance(): array
{
    $sms = appConfig('sms', []);
    $apiKey = trim((string) ($sms['api_key'] ?? ''));
    if ($apiKey === '') {
        return ['success' => false, 'message' => 'MamboSMS API key is not configured', 'balance' => null];
    }

    $result = httpJsonRequest('GET', (string) $sms['balance_url'], null, [
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ], (int) ($sms['timeout_seconds'] ?? 10));

    return [
        'success' => !empty($result['success']),
        'message' => $result['message'],
        'balance' => $result['response']['data']['balance'] ?? null,
        'response' => $result['response'],
    ];
}

function createTenantVoucher(array $site, array $tx): ?string
{
    $pdo = tenantDb($site);
    if (!$pdo) {
        return null;
    }

    if (!tenantTableExists($pdo, 'vouchers') || !tenantTableExists($pdo, 'radcheck') || !tenantTableExists($pdo, 'radreply')) {
        return null;
    }

    $code = generateVoucherCode($pdo);
    $groupId = resolveTenantVoucherGroup($pdo, $site, $tx);
    $now = date('Y-m-d H:i:s');
    $profile = $site['default_profile'] ?: 'default';
    $siteId = $site['onlifi_site_id'] ?: null;

    $columns = tenantColumns($pdo, 'vouchers');
    $data = [
        'voucher_code' => $code,
        'password' => $code,
        'group_id' => $groupId,
        'profile_name' => $profile,
        'validity_hours' => packageHours((string) ($tx['voucher_type'] ?? '')),
        'price' => abs((float) $tx['amount']),
        'site_id' => $siteId,
        'status' => isset($columns['status']) ? 'unused' : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    tenantInsertFiltered($pdo, 'vouchers', $data);

    $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$code]);
    $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$code]);
    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$code, $code]);
    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Session-Timeout', '=', ?)")->execute([$code, (string) (packageHours((string) ($tx['voucher_type'] ?? '')) * 3600)]);

    return $code;
}

function tenantTableExists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SHOW COLUMNS FROM `$table`");
        return true;
    } catch (Throwable) {
        return false;
    }
}

function tenantColumns(PDO $pdo, string $table, bool $refresh = false): array
{
    static $cache = [];
    $key = spl_object_hash($pdo) . ':' . $table;
    if (!$refresh && isset($cache[$key])) {
        return $cache[$key];
    }
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $col) {
        $cols[$col['Field']] = true;
    }
    return $cache[$key] = $cols;
}

function tenantInsertFiltered(PDO $pdo, string $table, array $data): void
{
    $columns = tenantColumns($pdo, $table);
    $fields = [];
    $marks = [];
    $values = [];
    foreach ($data as $key => $value) {
        if ($value !== null && isset($columns[$key])) {
            $fields[] = "`$key`";
            $marks[] = '?';
            $values[] = $value;
        }
    }
    if (!$fields) {
        return;
    }
    $pdo->prepare("INSERT INTO `$table` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $marks) . ")")->execute($values);
}

function tenantUpdateFiltered(PDO $pdo, string $table, string $whereColumn, $whereValue, array $data): void
{
    $columns = tenantColumns($pdo, $table);
    if (!isset($columns[$whereColumn])) {
        return;
    }

    $sets = [];
    $values = [];
    foreach ($data as $key => $value) {
        if (isset($columns[$key])) {
            $sets[] = "`$key` = ?";
            $values[] = $value;
        }
    }

    if (!$sets) {
        return;
    }

    $values[] = $whereValue;
    $pdo->prepare("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$whereColumn` = ?")->execute($values);
}

function resolveTenantVoucherGroup(PDO $pdo, array $site, array $tx): ?int
{
    if (!tenantTableExists($pdo, 'voucher_groups')) {
        return null;
    }

    $price = abs((float) $tx['amount']);
    $stmt = $pdo->prepare("SELECT id FROM voucher_groups WHERE price = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$price]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $data = [
        'group_name' => $tx['voucher_type'] ?: 'Auto ' . (int) $price,
        'description' => 'Auto-created by central payment dashboard',
        'profile_name' => $site['default_profile'] ?: 'default',
        'validity_hours' => packageHours((string) ($tx['voucher_type'] ?? '')),
        'price' => $price,
        'site_id' => $site['onlifi_site_id'] ?: null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    tenantInsertFiltered($pdo, 'voucher_groups', $data);
    return (int) $pdo->lastInsertId();
}

function packageHours(string $type): int
{
    return match (strtolower($type)) {
        '2hours', '2h' => 2,
        '7days', 'week' => 168,
        '30days', 'monthly', '1-month' => 720,
        default => 24,
    };
}

function generateVoucherCode(PDO $pdo): string
{
    for ($i = 0; $i < 1000; $i++) {
        $code = (string) random_int(100000, 999999);
        $stmt = $pdo->prepare("SELECT 1 FROM vouchers WHERE voucher_code = ? LIMIT 1");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique voucher code.');
}

function siteSummaries(): array
{
    $stmt = centralDb()->query("
        SELECT s.*,
            COALESCE(SUM(CASE WHEN t.status = 'success' THEN t.amount ELSE 0 END), 0) AS balance,
            COALESCE(SUM(CASE WHEN t.status = 'success' AND t.transaction_type = 'collection' THEN t.amount ELSE 0 END), 0) AS total_collections,
            COALESCE(ABS(SUM(CASE WHEN t.status = 'success' AND t.transaction_type = 'withdrawal' THEN t.amount ELSE 0 END)), 0) AS total_withdrawals,
            COALESCE(SUM(CASE WHEN t.status = 'success' AND t.transaction_type = 'collection' AND DATE(t.created_at) = CURDATE() THEN t.amount ELSE 0 END), 0) AS today_collections,
            COUNT(t.id) AS transaction_count
        FROM payment_sites s
        LEFT JOIN payment_transactions t ON t.site_id = s.id
        GROUP BY s.id
        ORDER BY s.display_name ASC
    ");
    return $stmt->fetchAll();
}

function recentTransactions(int $limit = 40): array
{
    $stmt = centralDb()->prepare("
        SELECT t.*, s.display_name, s.slug
        FROM payment_transactions t
        JOIN payment_sites s ON s.id = t.site_id
        ORDER BY t.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function logPayment(string $message, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context);
    }
    file_put_contents(__DIR__ . '/logs/payments-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
