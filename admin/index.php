<?php

/**
 * Portal entry point — sends signed-in users to the lead list.
 *
 * Kept as a redirect rather than a dashboard: there is one screen worth landing
 * on, and a summary page nobody asked for would be one more thing to keep
 * truthful. portal_require_login() bounces signed-out visitors to login.php.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';

logger($cfg);
portal_require_login($cfg);

header('Location: leads.php');
exit;
