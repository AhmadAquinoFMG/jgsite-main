<?php

/**
 * Portal home — PLACEHOLDER.
 *
 * Exists so login has somewhere to land and the auth flow can be exercised
 * end to end before the real screens are built. It will be replaced by (or
 * redirect to) leads.php once the lead list lands; nothing else should grow
 * here in the meantime.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';

logger($cfg);
$user = portal_require_login($cfg);

$csrf = portal_csrf_token($cfg);
$e    = fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Lead Portal</title>
    <link rel="stylesheet" href="assets/admin.css?v=<?= $e($cfg['asset_version'] ?? '1') ?>">
</head>

<body>

    <header class="topbar">
        <div class="topbar__brand">
            <span class="topbar__mark">JG</span>
            <span class="topbar__title">Lead Portal</span>
        </div>
        <div class="topbar__user">
            <span class="topbar__name"><?= $e($user['name']) ?></span>
            <span class="topbar__email"><?= $e($user['email']) ?></span>
            <form method="post" action="logout.php" class="topbar__logout">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <button class="btn btn--ghost" type="submit">Sign out</button>
            </form>
        </div>
    </header>

    <main class="page">
        <div class="card">
            <h1 class="card__title">Signed in</h1>
            <p class="card__body">
                Authentication is working. The lead list and post log are not
                built yet — this page is a placeholder so the sign-in flow can
                be tested on its own.
            </p>
            <?php if (!empty($user['last_login_at'])): ?>
                <p class="card__meta">Previous sign-in: <?= $e($user['last_login_at']) ?></p>
            <?php endif; ?>
        </div>
    </main>

</body>

</html>
