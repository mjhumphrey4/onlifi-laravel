<?php
// voucher_helper.php
// Auto-create OnLiFi vouchers and RADIUS rows after successful payment.

require_once 'config.php';
require_once 'sms_helper.php';

function assignVoucherToTransaction($externalRef, $pdo = null) {
    $result = createPaidVouchers($externalRef, 1, $pdo);

    return [
        'success' => $result['success'],
        'voucherCode' => $result['voucherCodes'][0] ?? null,
        'sms' => $result['sms'] ?? null,
        'error' => $result['error'] ?? null,
    ];
}

function assignTwoVouchersToTransaction($externalRef, $pdo = null) {
    $result = createPaidVouchers($externalRef, 2, $pdo);

    return [
        'success' => $result['success'],
        'voucherCodes' => $result['voucherCodes'] ?? null,
        'sms' => $result['sms'] ?? null,
        'error' => $result['error'] ?? null,
    ];
}

function createPaidVouchers(string $externalRef, int $count, ?PDO $pdo = null): array {
    $closeConnection = false;

    if (!$pdo) {
        $pdo = getDB();
        $closeConnection = true;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE external_ref = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$externalRef]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            throw new Exception('Transaction not found.');
        }

        if ($transaction['status'] !== 'success') {
            throw new Exception('Transaction is not successful yet.');
        }

        if (!empty($transaction['voucher_code'])) {
            $pdo->commit();
            $codes = [$transaction['voucher_code']];
            $sms = sendTransactionVoucherSms($pdo, $transaction, $codes);
            return ['success' => true, 'voucherCodes' => $codes, 'sms' => $sms];
        }

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = createVoucherAndRadiusRows($pdo, $transaction);
        }

        updateTransaction($pdo, $externalRef, [
            'voucher_code' => $codes[0],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();

        $transaction['voucher_code'] = $codes[0] ?? null;
        if (function_exists('logIPN')) {
            logIPN("Voucher database rows committed for external_ref: $externalRef - Codes: " . implode(', ', $codes), 'VOUCHER_COMMITTED');
        }
        $sms = sendTransactionVoucherSms($pdo, $transaction, $codes);

        return ['success' => true, 'voucherCodes' => $codes, 'sms' => $sms];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['success' => false, 'voucherCodes' => null, 'error' => $e->getMessage()];
    } finally {
        if ($closeConnection) {
            $pdo = null;
        }
    }
}

function sendTransactionVoucherSms(PDO $pdo, array $transaction, array $codes): array {
    $externalRef = (string) ($transaction['external_ref'] ?? '');
    $msisdn = trim((string) ($transaction['msisdn'] ?? ''));
    $codes = array_values(array_filter($codes, function ($code) {
        return trim((string) $code) !== '';
    }));

    if ($externalRef === '' || !$codes) {
        return ['success' => false, 'message' => 'Missing transaction reference or voucher code'];
    }

    $hasSmsColumns = columnExists($pdo, 'transactions', 'sms_sent_at')
        && columnExists($pdo, 'transactions', 'sms_status');

    if ($hasSmsColumns) {
        $fresh = fetchTransactionBy($pdo, 'external_ref', $externalRef);
        if (!empty($fresh['sms_sent_at'])) {
            return ['success' => true, 'message' => 'SMS already sent', 'skipped' => true];
        }
        if (!empty($fresh['sms_status'])) {
            $message = $fresh['sms_status'] === 'sending'
                ? 'SMS already being sent'
                : 'SMS already attempted';
            return ['success' => true, 'message' => $message, 'skipped' => true];
        }
        if ($fresh && empty($msisdn)) {
            $msisdn = trim((string) ($fresh['msisdn'] ?? ''));
        }
    }

    if ($msisdn === '') {
        updateTransaction($pdo, $externalRef, [
            'sms_status' => 'failed',
            'sms_error' => 'Missing customer phone number',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['success' => false, 'message' => 'Missing customer phone number'];
    }

    if ($hasSmsColumns) {
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET sms_status = 'sending',
                sms_error = NULL,
                updated_at = ?
            WHERE external_ref = ?
              AND sms_sent_at IS NULL
              AND sms_status IS NULL
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $externalRef]);

        if ($stmt->rowCount() === 0) {
            return ['success' => true, 'message' => 'SMS already claimed by another request', 'skipped' => true];
        }
    }

    try {
        $packageName = trim((string) ($transaction['voucher_type'] ?? ''));
        if (count($codes) >= 2 && function_exists('sendTwoVouchersSMS')) {
            $result = sendTwoVouchersSMS($msisdn, $codes, $packageName);
        } elseif (function_exists('sendVoucherSMS')) {
            $result = sendVoucherSMS($msisdn, $codes[0], $packageName);
        } else {
            $result = ['success' => false, 'message' => 'SMS helper is not loaded'];
        }
    } catch (Throwable $e) {
        $result = ['success' => false, 'message' => 'SMS error: ' . $e->getMessage()];
    }

    $success = !empty($result['success']);
    try {
        updateTransaction($pdo, $externalRef, [
            'sms_sent_at' => $success ? date('Y-m-d H:i:s') : null,
            'sms_status' => $success ? 'sent' : 'failed',
            'sms_error' => $success ? null : substr((string) ($result['message'] ?? 'SMS sending failed'), 0, 255),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        if (function_exists('logSmsEvent')) {
            logSmsEvent("Could not update SMS status for $externalRef: " . $e->getMessage(), 'SMS_ERROR');
        }
    }

    return $result;
}

function createVoucherAndRadiusRows(PDO $pdo, array $transaction): string {
    $package = resolvePackage($pdo, $transaction);
    $code = generateSixDigitCode($pdo);
    $now = date('Y-m-d H:i:s');
    $siteId = $transaction['site_id'] ?? ONLIFI_SITE_ID;

    $voucher = [
        'voucher_code' => $code,
        'password' => $code,
        'group_id' => $package['group_id'],
        'profile_name' => $package['profile_name'],
        'validity_hours' => $package['validity_hours'],
        'validity_minutes' => $package['validity_minutes'],
        'data_limit_mb' => $package['data_limit_mb'],
        'speed_limit_kbps' => $package['speed_limit_kbps'],
        'price' => $package['price'],
        'site_id' => $siteId,
        'status' => voucherSupportsReserved($pdo) ? 'reserved' : 'unused',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    insertRow($pdo, 'vouchers', $voucher);
    syncRadius($pdo, $code, $package);

    return $code;
}

function resolvePackage(PDO $pdo, array $transaction): array {
    $amount = (float) $transaction['amount'];
    $voucherType = trim((string) ($transaction['voucher_type'] ?? ''));
    $siteId = $transaction['site_id'] ?? ONLIFI_SITE_ID;

    $baseSql = "SELECT * FROM voucher_groups WHERE price = ?";
    $baseParams = [$amount];

    if ($voucherType !== '') {
        $baseSql .= " AND group_name LIKE ?";
        $baseParams[] = "%$voucherType%";
    }

    if (columnExists($pdo, 'voucher_groups', 'site_id')) {
        $baseSql .= " AND (site_id = ? OR site_id IS NULL)";
        $baseParams[] = $siteId;
    }

    $manualSql = $baseSql;
    $manualParams = $baseParams;
    $manualConditions = [];

    if (columnExists($pdo, 'voucher_groups', 'created_by')) {
        $manualConditions[] = "created_by = ?";
        $manualParams[] = 'manual-payment';
    }

    if (columnExists($pdo, 'voucher_groups', 'description')) {
        $manualConditions[] = "description LIKE ?";
        $manualParams[] = '%Auto-created by manual payment%';
    }

    if ($manualConditions) {
        $manualSql .= " AND (" . implode(' OR ', $manualConditions) . ")";
    }

    $manualSql .= " ORDER BY id ASC LIMIT 1";
    $stmt = $pdo->prepare($manualSql);
    $stmt->execute($manualParams);
    $group = $stmt->fetch();

    if (!$group) {
        $configuredSql = $baseSql . " ORDER BY id ASC LIMIT 1";
        $stmt = $pdo->prepare($configuredSql);
        $stmt->execute($baseParams);
        $configuredGroup = $stmt->fetch();

        $defaults = mergePackageWithDefaults($configuredGroup ?: [], $voucherType, $amount);
        $groupData = [
            'group_name' => $defaults['group_name'],
            'description' => 'Auto-created by manual payment',
            'profile_name' => $defaults['profile_name'] ?? ONLIFI_DEFAULT_PROFILE,
            'validity_hours' => $defaults['validity_hours'] ?? 24,
            'validity_minutes' => $defaults['validity_minutes'] ?? null,
            'data_limit_mb' => $defaults['data_limit_mb'] ?? null,
            'speed_limit_kbps' => $defaults['speed_limit_kbps'] ?? null,
            'price' => $amount,
            'site_id' => $siteId,
            'created_by' => 'manual-payment',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        insertRow($pdo, 'voucher_groups', $groupData);
        $groupData['id'] = $pdo->lastInsertId();
        $group = $groupData;
    }

    $group = mergePackageWithDefaults($group, $voucherType, $amount);

    return [
        'group_id' => (int) $group['id'],
        'profile_name' => $group['profile_name'] ?: ONLIFI_DEFAULT_PROFILE,
        'validity_hours' => (int) ($group['validity_hours'] ?? 24),
        'validity_minutes' => isset($group['validity_minutes']) ? $group['validity_minutes'] : null,
        'data_limit_mb' => isset($group['data_limit_mb']) ? $group['data_limit_mb'] : null,
        'speed_limit_kbps' => isset($group['speed_limit_kbps']) ? $group['speed_limit_kbps'] : null,
        'price' => (float) $group['price'],
    ];
}

function mergePackageWithDefaults(array $group, string $voucherType, float $amount): array {
    $defaults = packageDefaults($voucherType, $amount);

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $group) || $group[$key] === null || $group[$key] === '') {
            $group[$key] = $value;
        }
    }

    if (packageTypeHasExactDuration($voucherType)) {
        $group['validity_hours'] = $defaults['validity_hours'];
        $group['validity_minutes'] = $defaults['validity_minutes'];
    }

    return $group;
}

function packageTypeHasExactDuration(string $voucherType): bool {
    return packageDefaults($voucherType, 0)['exact_duration'] ?? false;
}

function packageDefaults(string $voucherType, float $amount): array {
    $key = strtolower(trim($voucherType));
    $map = [
        '2hours' => ['group_name' => '2hours', 'profile_name' => '2hours', 'validity_hours' => 2, 'validity_minutes' => 120, 'exact_duration' => true],
        '2h' => ['group_name' => '2hours', 'profile_name' => '2hours', 'validity_hours' => 2, 'validity_minutes' => 120, 'exact_duration' => true],
        '3hours' => ['group_name' => '3hours', 'profile_name' => '3hours', 'validity_hours' => 3, 'validity_minutes' => 180, 'exact_duration' => true],
        '3h' => ['group_name' => '3hours', 'profile_name' => '3hours', 'validity_hours' => 3, 'validity_minutes' => 180, 'exact_duration' => true],
        '12hours' => ['group_name' => '12hours', 'profile_name' => '12hours', 'validity_hours' => 12, 'validity_minutes' => 720, 'exact_duration' => true],
        '12h' => ['group_name' => '12hours', 'profile_name' => '12hours', 'validity_hours' => 12, 'validity_minutes' => 720, 'exact_duration' => true],
        'daily' => ['group_name' => 'Daily', 'profile_name' => 'Daily', 'validity_hours' => 24, 'validity_minutes' => 1440, 'exact_duration' => true],
        '24hours' => ['group_name' => '24hours', 'profile_name' => '24hours', 'validity_hours' => 24, 'validity_minutes' => 1440, 'exact_duration' => true],
        '24h' => ['group_name' => '24hours', 'profile_name' => '24hours', 'validity_hours' => 24, 'validity_minutes' => 1440, 'exact_duration' => true],
        '7days' => ['group_name' => '7days', 'profile_name' => '7days', 'validity_hours' => 168, 'validity_minutes' => 10080, 'exact_duration' => true],
        'week' => ['group_name' => '7days', 'profile_name' => '7days', 'validity_hours' => 168, 'validity_minutes' => 10080, 'exact_duration' => true],
        '30days' => ['group_name' => '30days', 'profile_name' => '30days', 'validity_hours' => 720, 'validity_minutes' => 43200, 'exact_duration' => true],
        'monthly' => ['group_name' => '30days', 'profile_name' => '30days', 'validity_hours' => 720, 'validity_minutes' => 43200, 'exact_duration' => true],
        '1-month' => ['group_name' => '30days', 'profile_name' => '30days', 'validity_hours' => 720, 'validity_minutes' => 43200, 'exact_duration' => true],
    ];

    return $map[$key] ?? [
        'group_name' => $voucherType ?: ('Auto_' . (int) $amount),
        'profile_name' => ONLIFI_DEFAULT_PROFILE,
        'validity_hours' => 24,
        'validity_minutes' => null,
        'exact_duration' => false,
    ];
}

function insertRow(PDO $pdo, string $table, array $data): void {
    if (!tableExists($pdo, $table)) {
        throw new Exception("Required table `$table` does not exist.");
    }

    $columns = [];
    $marks = [];
    $values = [];

    foreach ($data as $column => $value) {
        if (columnExists($pdo, $table, $column)) {
            $columns[] = "`$column`";
            $marks[] = '?';
            $values[] = $value;
        }
    }

    if (!$columns) {
        throw new Exception("Table `$table` has none of the expected columns.");
    }

    $stmt = $pdo->prepare("INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $marks) . ")");
    $stmt->execute($values);
}

function syncRadius(PDO $pdo, string $code, array $package): void {
    $sessionTimeout = !empty($package['validity_minutes'])
        ? max(60, (int) $package['validity_minutes'] * 60)
        : max(60, (int) $package['validity_hours'] * 3600);

    $stmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ?");
    $stmt->execute([$code]);

    $stmt = $pdo->prepare("DELETE FROM radreply WHERE username = ?");
    $stmt->execute([$code]);

    $stmt = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $stmt->execute([$code, $code]);

    $replies = [
        ['Session-Timeout', '=', (string) $sessionTimeout],
        ['Idle-Timeout', '=', '900'],
        ['Acct-Interim-Interval', '=', '300'],
    ];

    if (!empty($package['speed_limit_kbps'])) {
        $speed = (int) $package['speed_limit_kbps'] . 'k/' . (int) $package['speed_limit_kbps'] . 'k';
        $replies[] = ['Mikrotik-Rate-Limit', '=', $speed];
    }

    if (!empty($package['data_limit_mb'])) {
        $bytes = (int) $package['data_limit_mb'] * 1048576;
        $replies[] = ['Mikrotik-Total-Limit', '=', (string) ($bytes % 4294967296)];
        $replies[] = ['Mikrotik-Total-Limit-Gigawords', '=', (string) intdiv($bytes, 4294967296)];
    }

    $stmt = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ?, ?)");
    foreach ($replies as $reply) {
        $stmt->execute([$code, $reply[0], $reply[1], $reply[2]]);
    }
}

function generateSixDigitCode(PDO $pdo): string {
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        $code = (string) random_int(100000, 999999);

        $stmt = $pdo->prepare("SELECT 1 FROM vouchers WHERE voucher_code = ? LIMIT 1");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT 1 FROM radcheck WHERE username = ? LIMIT 1");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    throw new Exception('Could not generate a unique voucher code.');
}

function voucherSupportsReserved(PDO $pdo): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM vouchers LIKE 'status'");
    $column = $stmt->fetch();

    return isset($column['Type']) && strpos($column['Type'], "'reserved'") !== false;
}
?>
