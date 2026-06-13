<?php

require_once __DIR__ . '/bootstrap.php';

$admin = requireAdmin();
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_site') {
            $id = (int) ($_POST['id'] ?? 0);
            $slug = normalizeSlug((string) ($_POST['slug'] ?? $_POST['display_name'] ?? 'site'));
            $apiKey = trim((string) ($_POST['api_key'] ?? '')) ?: bin2hex(random_bytes(32));
            $active = !empty($_POST['active']) ? 1 : 0;
            $values = [
                $slug,
                trim((string) $_POST['display_name']),
                trim((string) ($_POST['origin_site'] ?: $_POST['display_name'])),
                $active,
                $apiKey,
                $_POST['tenant_id'] !== '' ? (int) $_POST['tenant_id'] : null,
                $_POST['onlifi_site_id'] !== '' ? (int) $_POST['onlifi_site_id'] : null,
                trim((string) $_POST['db_host']) ?: null,
                (int) ($_POST['db_port'] ?: 3306),
                trim((string) $_POST['db_name']) ?: null,
                trim((string) $_POST['db_user']) ?: null,
                (string) ($_POST['db_pass'] ?? ''),
                trim((string) ($_POST['default_profile'] ?: 'default')),
                trim((string) ($_POST['allowed_origins'] ?? '')) ?: null,
                trim((string) ($_POST['notes'] ?? '')) ?: null,
            ];

            if ($id > 0) {
                $values[] = $id;
                centralDb()->prepare("
                    UPDATE payment_sites
                    SET slug = ?, display_name = ?, origin_site = ?, active = ?, api_key = ?, tenant_id = ?,
                        onlifi_site_id = ?, db_host = ?, db_port = ?, db_name = ?, db_user = ?, db_pass = ?,
                        default_profile = ?, allowed_origins = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute($values);
                $notice = 'Site updated.';
            } else {
                centralDb()->prepare("
                    INSERT INTO payment_sites
                        (slug, display_name, origin_site, active, api_key, tenant_id, onlifi_site_id,
                         db_host, db_port, db_name, db_user, db_pass, default_profile, allowed_origins, notes,
                         created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ")->execute($values);
                $notice = 'Site created.';
            }
        }

        if ($action === 'withdrawal') {
            $site = siteBySlug(normalizeSlug((string) ($_POST['site_slug'] ?? '')));
            if (!$site) {
                throw new RuntimeException('Select a valid site.');
            }

            $amount = abs((float) ($_POST['amount'] ?? 0));
            if ($amount <= 0) {
                throw new RuntimeException('Withdrawal amount must be greater than zero.');
            }

            $ref = 'WD_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $signedAmount = -1 * $amount;
            insertCentralTransaction($site, [
                'transaction_type' => 'withdrawal',
                'provider' => 'admin',
                'external_ref' => $ref,
                'amount' => $signedAmount,
                'status' => 'success',
                'status_message' => trim((string) ($_POST['note'] ?? 'Admin recorded withdrawal')),
                'origin_site' => $site['origin_site'],
                'payout_account' => trim((string) ($_POST['payout_account'] ?? '')),
                'requested_by' => $admin['email'],
            ]);
            $notice = 'Withdrawal recorded as a negative ledger transaction.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = centralDb()->prepare("SELECT * FROM payment_sites WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$sites = siteSummaries();
$recent = recentTransactions(50);
$totals = [
    'balance' => array_sum(array_map(fn ($s) => (float) $s['balance'], $sites)),
    'collections' => array_sum(array_map(fn ($s) => (float) $s['total_collections'], $sites)),
    'withdrawals' => array_sum(array_map(fn ($s) => (float) $s['total_withdrawals'], $sites)),
    'today' => array_sum(array_map(fn ($s) => (float) $s['today_collections'], $sites)),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(appConfig('app_name')) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="topbar">
        <div>
            <div class="eyebrow">Admin only</div>
            <h1>Onlifi Payments Dashboard</h1>
            <p>Central reusable YoPayments routing by site path.</p>
        </div>
        <nav>
            <span><?= h($admin['name']) ?></span>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main class="page">
        <?php if ($notice): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <section class="summary-grid">
            <article><span>Available Balance</span><strong><?= money($totals['balance']) ?></strong></article>
            <article><span>Total Collections</span><strong><?= money($totals['collections']) ?></strong></article>
            <article><span>Total Withdrawals</span><strong><?= money($totals['withdrawals']) ?></strong></article>
            <article><span>Today</span><strong><?= money($totals['today']) ?></strong></article>
        </section>

        <section class="section">
            <div class="section-heading">
                <h2>Sites</h2>
                <span><?= count($sites) ?> configured</span>
            </div>
            <div class="site-grid">
                <?php foreach ($sites as $site): ?>
                    <article class="site-card">
                        <div class="site-card-head">
                            <div>
                                <h3><?= h($site['display_name']) ?></h3>
                                <code>/<?= h($site['slug']) ?>/initiate.php</code>
                            </div>
                            <span class="pill <?= $site['active'] ? 'ok' : 'muted' ?>"><?= $site['active'] ? 'Active' : 'Inactive' ?></span>
                        </div>
                        <div class="site-money"><?= money($site['balance']) ?></div>
                        <p>Collections <?= money($site['total_collections']) ?> · Withdrawals <?= money($site['total_withdrawals']) ?></p>
                        <dl>
                            <div><dt>Today</dt><dd><?= money($site['today_collections']) ?></dd></div>
                            <div><dt>Transactions</dt><dd><?= (int) $site['transaction_count'] ?></dd></div>
                            <div><dt>DB</dt><dd><?= h($site['db_name'] ?: 'Central only') ?></dd></div>
                        </dl>
                        <a class="text-link" href="?edit=<?= (int) $site['id'] ?>">Edit site assignment</a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="two-column">
            <article class="panel">
                <h2><?= $editing ? 'Edit Site' : 'Create Site' ?></h2>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_site">
                    <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
                    <label><span>Site name</span><input name="display_name" required value="<?= h($editing['display_name'] ?? '') ?>"></label>
                    <label><span>Path slug</span><input name="slug" required value="<?= h($editing['slug'] ?? '') ?>" placeholder="site-name"></label>
                    <label><span>Origin site label</span><input name="origin_site" value="<?= h($editing['origin_site'] ?? '') ?>"></label>
                    <label><span>Tenant ID</span><input name="tenant_id" inputmode="numeric" value="<?= h($editing['tenant_id'] ?? '') ?>"></label>
                    <label><span>Onlifi site ID</span><input name="onlifi_site_id" inputmode="numeric" value="<?= h($editing['onlifi_site_id'] ?? '') ?>"></label>
                    <label><span>Default profile</span><input name="default_profile" value="<?= h($editing['default_profile'] ?? 'default') ?>"></label>
                    <label><span>DB host</span><input name="db_host" value="<?= h($editing['db_host'] ?? '') ?>" placeholder="10.200.1.254"></label>
                    <label><span>DB port</span><input name="db_port" inputmode="numeric" value="<?= h($editing['db_port'] ?? '3306') ?>"></label>
                    <label><span>DB name</span><input name="db_name" value="<?= h($editing['db_name'] ?? '') ?>"></label>
                    <label><span>DB user</span><input name="db_user" value="<?= h($editing['db_user'] ?? '') ?>"></label>
                    <label><span>DB password</span><input name="db_pass" type="password" value="<?= h($editing['db_pass'] ?? '') ?>"></label>
                    <label><span>Allowed origins</span><input name="allowed_origins" value="<?= h($editing['allowed_origins'] ?? '') ?>" placeholder="https://site.example"></label>
                    <label class="wide"><span>API key</span><input name="api_key" value="<?= h($editing['api_key'] ?? '') ?>" placeholder="Auto-generated if blank"></label>
                    <label class="wide"><span>Notes</span><textarea name="notes"><?= h($editing['notes'] ?? '') ?></textarea></label>
                    <label class="check"><input type="checkbox" name="active" value="1" <?= (!$editing || $editing['active']) ? 'checked' : '' ?>> <span>Accept payment requests for this site</span></label>
                    <button class="primary-button" type="submit"><?= $editing ? 'Update Site' : 'Create Site' ?></button>
                </form>
            </article>

            <article class="panel">
                <h2>Record Withdrawal</h2>
                <form method="post" class="stack">
                    <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="withdrawal">
                    <label>
                        <span>Site</span>
                        <select name="site_slug" required>
                            <option value="">Choose site</option>
                            <?php foreach ($sites as $site): ?>
                                <option value="<?= h($site['slug']) ?>"><?= h($site['display_name']) ?> · <?= money($site['balance']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>Amount</span><input name="amount" inputmode="decimal" required></label>
                    <label><span>Payout account</span><input name="payout_account" placeholder="2567... or bank reference"></label>
                    <label><span>Note</span><textarea name="note"></textarea></label>
                    <button class="secondary-button" type="submit">Save Withdrawal</button>
                </form>
            </article>
        </section>

        <section class="section panel">
            <div class="section-heading">
                <h2>Recent Transactions</h2>
                <span>Collections and withdrawals share one signed ledger</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Time</th>
                        <th>Site</th>
                        <th>Type</th>
                        <th>Phone</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Voucher</th>
                        <th>Reference</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="8" class="empty">No transactions yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recent as $tx): ?>
                        <tr>
                            <td><?= h(date('M d, H:i', strtotime($tx['created_at']))) ?></td>
                            <td><?= h($tx['display_name']) ?></td>
                            <td><?= h($tx['transaction_type']) ?></td>
                            <td><?= h($tx['msisdn'] ?: '-') ?></td>
                            <td class="<?= (float) $tx['amount'] < 0 ? 'negative' : 'positive' ?>"><?= money($tx['amount']) ?></td>
                            <td><span class="pill <?= h($tx['status']) ?>"><?= h($tx['status']) ?></span></td>
                            <td><?= h($tx['voucher_code'] ?: '-') ?></td>
                            <td><code><?= h(substr($tx['external_ref'], 0, 22)) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
