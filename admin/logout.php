<?php

/**
 * Sign out — audits the logout, destroys the session, returns to the form.
 *
 * POST only, with a CSRF token: a GET logout can be triggered by any image tag
 * on any page an operator happens to visit. Annoying rather than dangerous, but
 * it also means a prefetching browser can sign someone out on its own.
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && portal_csrf_check($cfg, (string) ($_POST['csrf'] ?? ''))) {
    portal_logout($cfg);
}

header('Location: login.php');
exit;
