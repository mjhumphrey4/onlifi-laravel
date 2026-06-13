<?php

require_once __DIR__ . '/bootstrap.php';

if (currentAdmin()) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = centralDb()->prepare("SELECT * FROM payment_admins WHERE email = ? AND active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_id'] = (int) $admin['id'];
        header('Location: index.php');
        exit;
    }

    $error = 'Invalid admin email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - <?= h(appConfig('app_name')) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-panel">
            <div class="brand-mark">ON</div>
            <h1>Payments Admin</h1>
            <p>Admin-only access for central site payment routing.</p>

            <?php if ($error): ?>
                <div class="notice error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="stack">
                <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
                <label>
                    <span>Email</span>
                    <input type="email" name="email" required autocomplete="username">
                </label>
                <label>
                    <span>Password</span>
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <button class="primary-button" type="submit">Sign in</button>
            </form>
        </section>
    </main>
</body>
</html>
