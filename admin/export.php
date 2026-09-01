<?php

/**
 * CSV export of the current lead filter.
 *
 * Shares portal_lead_filters()/portal_lead_where() with leads.php, so an export
 * is always exactly the rows on screen — a separate query here would drift from
 * the table it claims to represent.
 *
 * Every export is audited before a byte is written. It is still the one action
 * that takes consumer data out of the portal's reach entirely — once a CSV is
 * on someone's disk there is no further record of who reads it.
 *
 * Streamed rather than buffered — a filter matching every lead should not have
 * to fit in memory first.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/leadquery.php';

logger($cfg);
$user = portal_require_login($cfg);

$filters = portal_lead_filters($_GET);

/* Hard ceiling. Not a paranoia limit — an unbounded export of a table that
   grows forever is a slow way to take the database down, and nobody has ever
   wanted a 400k-row CSV on purpose. Narrow the date range instead. */
const EXPORT_MAX = 5000;

try {
    $pdo   = db($cfg);
    $total = portal_lead_count($pdo, $filters);
    $rows  = portal_lead_page($pdo, $filters, EXPORT_MAX, 0);
} catch (Throwable $ex) {
    app_log('error', 'portal', 'export_failed', [
        'user_id' => $user['id'], 'error' => $ex->getMessage(),
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Export failed. The error has been logged.\n";
    exit;
}

/* Audited BEFORE a byte is sent: a download that dies halfway still happened as
   far as the consumer's data is concerned. */
portal_audit($cfg, 'export', [
    'lead_id' => null,
    'detail'  => sprintf(
        '%d rows (of %d), filter: %s',
        count($rows),
        $total,
        portal_filter_summary($filters)
    ),
]);
/* Logged at warning, not info: an export is worth noticing in the ops trail. */
app_log('warning', 'portal', 'export', [
    'user_id' => $user['id'],
    'rows'    => count($rows),
    'filter'  => portal_filter_summary($filters),
]);

$filename = sprintf('leads-%s.csv', date('Ymd-His'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');

/* UTF-8 BOM: without it Excel reads the file as the system codepage and mangles
   any accented name. Every other reader ignores it. */
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'lead_id', 'received', 'first_name', 'last_name', 'email', 'phone',
    'city', 'state', 'zip', 'debt_self_reported', 'total_debt', 'debt_source',
    'affid', 'source_id', 'sub1', 'utm_source', 'utm_campaign',
    'lp_status', 'lp_accepted', 'lp_accepted_buyer', 'lp_error', 'lp_posted_at',
    'jgw_status', 'jgw_disposition', 'status', 'bot_suspected', 'bot_reason',
]);

foreach ($rows as $row) {
    $status = portal_lead_status($row);

    fputcsv($out, [
        $row['id'],
        $row['created_at'],
        $row['first_name'],
        $row['last_name'],
        $row['email'],
        $row['phone'],
        $row['city'],
        $row['state'],
        $row['zip'],
        $row['debt_amount'],
        $row['total_debt'],
        $row['total_debt_source'],
        $row['affid'],
        $row['source_id'],
        $row['sub1'],
        $row['utm_source'],
        $row['utm_campaign'],
        $row['lp_status'],
        $row['lp_accepted'],
        $row['lp_accepted_buyer'],
        $row['lp_error'],
        $row['lp_posted_at'],
        $row['jgw_status'],
        $row['jgw_disposition'],
        $status['label'],
        $row['bot_suspected'],
        $row['bot_reason'],
    ]);
}

fclose($out);
