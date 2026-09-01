<?php

/**
 * Portal sign-in.
 *
 * The only page in admin/ that is reachable signed out — everything else calls
 * portal_require_login() and bounces here. There is no signup, no password
 * reset and no default account: accounts are created from the CLI with
 * bin/portal-user.php, which is also how a forgotten password is reset.
 *
 * Failure messages are deliberately identical for "no such account", "wrong
 * password" and "deactivated" (see auth.php). The real reason goes to
 * portal_audit, not to the screen.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';

logger($cfg);
portal_session_start($cfg);

/* Already signed in — don't show a login form to someone who has a session.
   Same target as a successful login below. */
if (portal_current_user($cfg) !== null) {
    header('Location: index.php');
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : null;

    if (!portal_csrf_check($cfg, $token)) {
        /* Usually a stale form from an expired session rather than an attack,
           so the wording points at the fix instead of accusing anyone. */
        $error = 'Your session expired. Please try again.';
        app_log('warning', 'portal', 'login_csrf_failed', ['ip' => portal_client_ip()]);
    } else {
        $result = portal_login(
            $cfg,
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );

        if ($result['ok']) {
            /* Return the operator to whatever they were aiming at before the
               redirect to login. Validated first: an unchecked "next" parameter
               is an open redirect, and this one comes from a session written by
               portal_require_login(), so it must still start with our own admin
               path and carry no scheme or host. */
            $target   = 'index.php';
            $intended = (string) ($_SESSION['portal_intended'] ?? '');
            unset($_SESSION['portal_intended']);

            $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            if ($intended !== ''
                && str_starts_with($intended, $base . '/')
                && !str_contains($intended, '//')
                && !str_contains($intended, "\n")
            ) {
                $target = $intended;
            }

            header('Location: ' . $target);
            exit;
        }

        $error = $result['error'];
    }
}

$csrf = portal_csrf_token($cfg);
$e    = fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/* Never cached, never indexed. The page itself is harmless, but the headers are
   set here as well as in admin/.htaccess so the protection survives a host that
   ignores htaccess overrides. */
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
    <title>Sign in — Lead Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=<?= $e($cfg['asset_version'] ?? '1') ?>">
</head>

<body class="auth-body">

    <main class="auth">
        <div class="auth__card">
            <div class="auth__brand">
                <span class="auth__mark">JG</span>
                <div>
                    <h1 class="auth__title">Lead Portal</h1>
                    <p class="auth__sub">Internal access only</p>
                </div>
            </div>

            <?php if ($error !== null): ?>
                <div class="alert alert--error" role="alert"><?= $e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" class="auth__form" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

                <label class="field">
                    <span class="field__label">Email</span>
                    <input class="field__input" type="email" name="email" required autofocus
                        autocomplete="username" value="<?= $e($_POST['email'] ?? '') ?>">
                </label>

                <label class="field">
                    <span class="field__label">Password</span>
                    <input class="field__input" type="password" name="password" required
                        autocomplete="current-password">
                </label>

                <button class="btn btn--primary" type="submit">Sign in</button>
            </form>

            <p class="auth__note">
                Accounts are issued internally. This portal shows consumer
                personal data — every view is recorded.
            </p>
        </div>
    </main>

</body>

</html>
