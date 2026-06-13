<?php

date_default_timezone_set('Africa/Nairobi');

function onlifiEnv($key, $default = null) {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function onlifiCentralDbName() {
    if (defined('CENTRAL_DB_NAME')) return CENTRAL_DB_NAME;
    if (defined('DB_NAME')) return DB_NAME;
    return onlifiEnv('ONLIFI_CENTRAL_DB_NAME', 'payment_mikrotik');
}

function onlifiDefaultDbHost() {
    return defined('DB_HOST') ? DB_HOST : onlifiEnv('ONLIFI_DB_HOST', 'localhost');
}

function onlifiDefaultDbUser() {
    return defined('DB_USER') ? DB_USER : onlifiEnv('ONLIFI_DB_USER', 'yo');
}

function onlifiDefaultDbPass() {
    return defined('DB_PASS') ? DB_PASS : onlifiEnv('ONLIFI_DB_PASS', 'password');
}

function onlifiCentralDb() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . onlifiDefaultDbHost() . ';dbname=' . onlifiCentralDbName() . ';charset=utf8mb4';
    $pdo = new PDO($dsn, onlifiDefaultDbUser(), onlifiDefaultDbPass(), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    onlifiEnsureCentralSchema($pdo);
    return $pdo;
}

function onlifiEnsureCentralSchema(PDO $pdo) {
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_sites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            display_name VARCHAR(120) NOT NULL,
            origin_site VARCHAR(160) NOT NULL,
            db_host VARCHAR(190) NOT NULL DEFAULT 'localhost',
            db_port INT UNSIGNED NULL,
            db_name VARCHAR(190) NOT NULL,
            db_user VARCHAR(190) NOT NULL,
            db_pass VARCHAR(255) NOT NULL,
            tenant_id VARCHAR(120) NULL,
            onlifi_site_id VARCHAR(120) NULL,
            default_profile VARCHAR(120) NULL,
            api_key VARCHAR(120) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sms_sender_id VARCHAR(32) NULL,
            sms_message_category VARCHAR(64) NULL,
            sms_brand_name VARCHAR(120) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_sms_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id INT UNSIGNED NULL,
            site_slug VARCHAR(80) NULL,
            external_ref VARCHAR(120) NULL,
            recipient VARCHAR(32) NOT NULL,
            sender_id VARCHAR(32) NULL,
            message_category VARCHAR(64) NULL,
            message TEXT NOT NULL,
            status VARCHAR(40) NOT NULL,
            provider_message VARCHAR(255) NULL,
            provider_response MEDIUMTEXT NULL,
            provider_cost DECIMAL(12,2) NULL,
            provider_balance DECIMAL(12,2) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sms_site_created (site_slug, created_at),
            INDEX idx_sms_external_ref (external_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id INT UNSIGNED NULL,
            site_slug VARCHAR(80) NULL,
            site_label VARCHAR(120) NOT NULL,
            external_ref VARCHAR(120) NULL,
            transaction_ref VARCHAR(120) NULL,
            transaction_type VARCHAR(40) NOT NULL DEFAULT 'collection',
            phone_number VARCHAR(32) NULL,
            amount DECIMAL(14,2) NOT NULL,
            status VARCHAR(40) NOT NULL,
            response_message TEXT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pt_site_status (site_label, status),
            INDEX idx_pt_type_status (transaction_type, status),
            INDEX idx_pt_external_ref (external_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    onlifiSeedDefaultSites($pdo);
    $done = true;
}

function onlifiSeedDefaultSites(PDO $pdo) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM payment_sites")->fetchColumn();
    if ($count > 0) return;

    $sites = [
        ['enock', 'Enock', 'Bite Tech Network', 'omada'],
        ['richard', 'Richard', 'Richard Network', 'omada'],
        ['stk', 'STK', 'STK WIFI', 'payment_mikrotik'],
        ['remmy', 'Remmy', 'remmy', 'remmy_mikrotik'],
        ['guma', 'Guma', 'guma', 'guma_omada'],
        ['namungoona', 'Namungoona', 'STK Namungoona', 'stk_namungoona'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO payment_sites
            (slug, display_name, origin_site, db_host, db_name, db_user, db_pass, sms_sender_id, sms_message_category, sms_brand_name, api_key)
        VALUES
            (:slug, :display_name, :origin_site, :db_host, :db_name, :db_user, :db_pass, 'ONLIFI', 'customised', 'ONLIFI WiFi', :api_key)
    ");

    foreach ($sites as [$slug, $display, $origin, $dbName]) {
        $stmt->execute([
            ':slug' => $slug,
            ':display_name' => $display,
            ':origin_site' => $origin,
            ':db_host' => onlifiDefaultDbHost(),
            ':db_name' => $dbName,
            ':db_user' => onlifiDefaultDbUser(),
            ':db_pass' => onlifiDefaultDbPass(),
            ':api_key' => bin2hex(random_bytes(24)),
        ]);
    }
}

function onlifiAllSites($activeOnly = false) {
    $sql = "SELECT * FROM payment_sites";
    if ($activeOnly) $sql .= " WHERE active = 1";
    $sql .= " ORDER BY display_name ASC";
    return onlifiCentralDb()->query($sql)->fetchAll();
}

function onlifiFindSite($value, $activeOnly = true) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $where = $activeOnly ? " AND active = 1" : "";
    $stmt = onlifiCentralDb()->prepare("
        SELECT * FROM payment_sites
        WHERE (slug = :v OR display_name = :v OR origin_site = :v)" . $where . "
        LIMIT 1
    ");
    $stmt->execute([':v' => $value]);
    $site = $stmt->fetch();
    if ($site) return $site;

    $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $value));
    $stmt = onlifiCentralDb()->prepare("SELECT * FROM payment_sites WHERE slug = :slug" . $where . " LIMIT 1");
    $stmt->execute([':slug' => trim($slug, '-')]);
    return $stmt->fetch() ?: null;
}

function onlifiSlugFromRequestUri() {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('#^/([a-zA-Z0-9-]+)/(initiate|check_status|ipn|callback|failure|validate|api)\.php$#', $path, $m)) {
        return $m[1];
    }
    return $_GET['site'] ?? $_POST['site'] ?? null;
}

function onlifiCurrentSite(array $requestData = []) {
    if (!empty($GLOBALS['CURRENT_PAYMENT_SITE'])) return $GLOBALS['CURRENT_PAYMENT_SITE'];

    $candidates = [
        onlifiSlugFromRequestUri(),
        $requestData['site'] ?? null,
        $requestData['origin_site'] ?? null,
        $_GET['origin_site'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $site = onlifiFindSite((string)$candidate);
        if ($site) {
            $GLOBALS['CURRENT_PAYMENT_SITE'] = $site;
            return $site;
        }
    }

    return null;
}

function onlifiSitePdo(array $site) {
    static $connections = [];
    $host = $site['db_host'] ?: onlifiDefaultDbHost();
    $port = !empty($site['db_port']) ? ';port=' . (int)$site['db_port'] : '';
    $db = $site['db_name'];
    $user = $site['db_user'] ?: onlifiDefaultDbUser();
    $pass = $site['db_pass'] ?? onlifiDefaultDbPass();
    $key = $host . ':' . $port . ':' . $db . ':' . $user;

    if (!isset($connections[$key])) {
        $dsn = "mysql:host=$host$port;dbname=$db;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connections[$key] = $pdo;
    }

    return $connections[$key];
}

function onlifiFindSiteByTransactionRef($ref) {
    $ref = trim((string)$ref);
    if ($ref === '') return null;

    foreach (onlifiAllSites(true) as $site) {
        try {
            $pdo = onlifiSitePdo($site);
            $stmt = $pdo->prepare("SELECT id FROM transactions WHERE external_ref = ? OR transaction_ref = ? LIMIT 1");
            $stmt->execute([$ref, $ref]);
            if ($stmt->fetch()) {
                $GLOBALS['CURRENT_PAYMENT_SITE'] = $site;
                return $site;
            }
        } catch (Exception $e) {
            error_log('Site transaction lookup failed for ' . ($site['slug'] ?? 'unknown') . ': ' . $e->getMessage());
        }
    }

    return null;
}

function onlifiMainPortalUrl() {
    return rtrim(onlifiEnv('ONLIFI_PAYMENTS_URL', 'https://payments.onlifi.net'), '/');
}

function onlifiSiteBaseUrl(array $site) {
    return onlifiMainPortalUrl() . '/' . rawurlencode($site['slug']) . '/';
}

function onlifiAdminUser() {
    return [
        'password' => defined('ONLIFI_ADMIN_PASSWORD') ? ONLIFI_ADMIN_PASSWORD : onlifiEnv('ONLIFI_ADMIN_PASSWORD', '##12345678Aa'),
        'password_hash' => defined('ONLIFI_ADMIN_PASSWORD_HASH') ? ONLIFI_ADMIN_PASSWORD_HASH : onlifiEnv('ONLIFI_ADMIN_PASSWORD_HASH', ''),
        'name' => 'Administrator',
        'email' => defined('ONLIFI_ADMIN_EMAIL') ? ONLIFI_ADMIN_EMAIL : onlifiEnv('ONLIFI_ADMIN_EMAIL', 'admin@payments.onlifi.net'),
        'role' => 'admin',
        'site' => null,
    ];
}

function onlifiAdminUsername() {
    return defined('ONLIFI_ADMIN_USERNAME') ? ONLIFI_ADMIN_USERNAME : onlifiEnv('ONLIFI_ADMIN_USERNAME', 'admin');
}

function onlifiAdminPasswordMatches(array $user, $password) {
    if (!empty($user['password_hash'])) {
        return password_verify((string)$password, $user['password_hash']);
    }
    return hash_equals((string)$user['password'], (string)$password);
}

function onlifiLedgerWithdrawalSum($siteLabel, $status = 'SUCCEEDED') {
    $stmt = onlifiCentralDb()->prepare("
        SELECT COALESCE(SUM(ABS(amount)), 0) AS total
        FROM payment_transactions
        WHERE transaction_type = 'withdrawal'
          AND site_label = :site
          AND status = :status
    ");
    $stmt->execute([':site' => $siteLabel, ':status' => $status]);
    return (float)($stmt->fetch()['total'] ?? 0);
}

function onlifiRecordWithdrawal(array $data) {
    $stmt = onlifiCentralDb()->prepare("
        INSERT INTO payment_transactions
            (site_id, site_slug, site_label, external_ref, transaction_ref, transaction_type, phone_number, amount, status, response_message, comment, created_at)
        VALUES
            (:site_id, :site_slug, :site_label, :external_ref, :transaction_ref, 'withdrawal', :phone_number, :amount, :status, :response_message, :comment, NOW())
    ");
    $stmt->execute([
        ':site_id' => $data['site_id'] ?? null,
        ':site_slug' => $data['site_slug'] ?? null,
        ':site_label' => $data['site_label'],
        ':external_ref' => $data['external_ref'] ?? null,
        ':transaction_ref' => $data['transaction_ref'] ?? null,
        ':phone_number' => $data['phone_number'] ?? null,
        ':amount' => -abs((float)$data['amount']),
        ':status' => $data['status'],
        ':response_message' => $data['response_message'] ?? null,
        ':comment' => $data['comment'] ?? null,
    ]);
}

function onlifiRecordPaymentTransaction(array $site, array $data) {
    $externalRef = $data['external_ref'] ?? null;
    if (!$externalRef) return;

    $existing = onlifiCentralDb()->prepare("
        SELECT id FROM payment_transactions
        WHERE transaction_type = :transaction_type AND external_ref = :external_ref
        LIMIT 1
    ");
    $existing->execute([
        ':transaction_type' => $data['transaction_type'] ?? 'collection',
        ':external_ref' => $externalRef,
    ]);
    $id = $existing->fetchColumn();

    if ($id) {
        $stmt = onlifiCentralDb()->prepare("
            UPDATE payment_transactions
            SET transaction_ref = :transaction_ref,
                phone_number = :phone_number,
                amount = :amount,
                status = :status,
                response_message = :response_message,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':transaction_ref' => $data['transaction_ref'] ?? null,
            ':phone_number' => $data['phone_number'] ?? null,
            ':amount' => abs((float)($data['amount'] ?? 0)),
            ':status' => $data['status'],
            ':response_message' => $data['response_message'] ?? null,
            ':id' => $id,
        ]);
        return;
    }

    $stmt = onlifiCentralDb()->prepare("
        INSERT INTO payment_transactions
            (site_id, site_slug, site_label, external_ref, transaction_ref, transaction_type, phone_number, amount, status, response_message, comment, created_at)
        VALUES
            (:site_id, :site_slug, :site_label, :external_ref, :transaction_ref, :transaction_type, :phone_number, :amount, :status, :response_message, :comment, NOW())
    ");
    $stmt->execute([
        ':site_id' => $site['id'] ?? null,
        ':site_slug' => $site['slug'] ?? null,
        ':site_label' => $site['display_name'] ?? $site['origin_site'],
        ':external_ref' => $externalRef,
        ':transaction_ref' => $data['transaction_ref'] ?? null,
        ':transaction_type' => $data['transaction_type'] ?? 'collection',
        ':phone_number' => $data['phone_number'] ?? null,
        ':amount' => abs((float)($data['amount'] ?? 0)),
        ':status' => $data['status'],
        ':response_message' => $data['response_message'] ?? null,
        ':comment' => $data['comment'] ?? null,
    ]);
}

function onlifiLogSms(array $data) {
    $stmt = onlifiCentralDb()->prepare("
        INSERT INTO payment_sms_logs
            (site_id, site_slug, external_ref, recipient, sender_id, message_category, message, status, provider_message, provider_response, provider_cost, provider_balance, created_at)
        VALUES
            (:site_id, :site_slug, :external_ref, :recipient, :sender_id, :message_category, :message, :status, :provider_message, :provider_response, :provider_cost, :provider_balance, NOW())
    ");
    $stmt->execute([
        ':site_id' => $data['site_id'] ?? null,
        ':site_slug' => $data['site_slug'] ?? null,
        ':external_ref' => $data['external_ref'] ?? null,
        ':recipient' => $data['recipient'],
        ':sender_id' => $data['sender_id'] ?? null,
        ':message_category' => $data['message_category'] ?? null,
        ':message' => $data['message'],
        ':status' => $data['status'],
        ':provider_message' => $data['provider_message'] ?? null,
        ':provider_response' => $data['provider_response'] ?? null,
        ':provider_cost' => $data['provider_cost'] ?? null,
        ':provider_balance' => $data['provider_balance'] ?? null,
    ]);
}

function onlifiSmsConfig(array $site = null) {
    return [
        'api_key' => onlifiEnv('MAMBOSMS_API_KEY', ''),
        'send_url' => onlifiEnv('MAMBOSMS_SEND_URL', 'https://api-mongolia.mambosms.com/v1/send-sms'),
        'balance_url' => onlifiEnv('MAMBOSMS_BALANCE_URL', 'https://api-mongolia.mambosms.com/v1/accounts/balance'),
        'sender_id' => $site['sms_sender_id'] ?? onlifiEnv('MAMBOSMS_SENDER_ID', 'ONLIFI'),
        'message_category' => $site['sms_message_category'] ?? onlifiEnv('MAMBOSMS_MESSAGE_CATEGORY', 'customised'),
        'brand_name' => $site['sms_brand_name'] ?? onlifiEnv('MAMBOSMS_BRAND_NAME', 'ONLIFI WiFi'),
    ];
}

function onlifiReadInputData() {
    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : [];
    if (!is_array($json)) $json = [];
    return array_merge($json, $_POST);
}
