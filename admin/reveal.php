<?php

/**
 * Reveal one masked value — the only endpoint that returns unmasked PII.
 *
 * The pages never render a masked value's real content, so this is the sole
 * path to it, which is what makes the audit trail meaningful: one row here per
 * value actually looked at, rather than a page view that may or may not have
 * involved reading someone's phone number.
 *
 * POST + CSRF, and every request is audited BEFORE the value is returned — an
 * audit write that fails must not be a way to read data unrecorded.
 *
 * Two shapes of `field`:
 *   email | phone | street | dob   → that column of the lead
 *   log:<destination>:<id>:request|response → a raw call body
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/mask.php';

logger($cfg);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* Signed out gets JSON, not the usual redirect: this is called by fetch(), and
   a 302 to an HTML login page would surface as an unparseable response. */
$user = portal_current_user($cfg);
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Reload and sign in again.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!portal_csrf_check($cfg, (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid token. Reload the page.']);
    exit;
}

$leadId = (int) ($_POST['lead'] ?? 0);
$field  = (string) ($_POST['field'] ?? '');

if ($leadId <= 0 || $field === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

/* The log destinations this endpoint will read, mapped to their tables. A fixed
   map, not the submitted string: the table name reaches the SQL text, so it can
   only ever be one of these three literals. */
$logTables = [
    'leadprosper' => 'leadprosper_logs',
    'jg_scoring'  => 'jgscoring_logs',
    'equifax'     => 'equifax_logs',
];

try {
    $pdo = db($cfg);

    if (str_starts_with($field, 'log:')) {
        // log:<destination>:<id>:<request|response>
        $parts = explode(':', $field);
        if (count($parts) !== 4) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad_request']);
            exit;
        }
        [, $dest, $logId, $which] = $parts;

        if (!isset($logTables[$dest]) || !in_array($which, ['request', 'response'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown_field']);
            exit;
        }
        $table  = $logTables[$dest];
        $column = $which === 'request' ? 'request_body' : 'response_body';

        portal_audit($cfg, 'reveal', [
            'lead_id' => $leadId,
            'detail'  => "{$dest} #{$logId} {$which} body",
        ]);

        /* Scoped by lead_id as well as the log's own id: without it, a guessed
           log id would return a body belonging to a different consumer. */
        $stmt = $pdo->prepare("SELECT {$column} AS v FROM {$table} WHERE id = :id AND lead_id = :lead");
        $stmt->execute(['id' => (int) $logId, 'lead' => $leadId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            exit;
        }

        app_log('info', 'portal', 'reveal', [
            'user_id' => $user['id'], 'lead_id' => $leadId, 'field' => $field,
        ]);
        echo json_encode(['ok' => true, 'value' => portal_format_body($row['v'], false)]);
        exit;
    }

    /* A lead column. Restricted to the masked set — an arbitrary column name
       here would turn this into a read-anything endpoint. */
    if (!in_array($field, portal_maskable_fields(), true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown_field']);
        exit;
    }

    portal_audit($cfg, 'reveal', ['lead_id' => $leadId, 'detail' => $field]);

    $stmt = $pdo->prepare("SELECT {$field} AS v FROM leads WHERE id = :id");
    $stmt->execute(['id' => $leadId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    app_log('info', 'portal', 'reveal', [
        'user_id' => $user['id'], 'lead_id' => $leadId, 'field' => $field,
    ]);
    echo json_encode(['ok' => true, 'value' => (string) $row['v']]);
} catch (Throwable $ex) {
    app_log('error', 'portal', 'reveal_failed', [
        'user_id' => $user['id'], 'lead_id' => $leadId, 'field' => $field,
        'error'   => $ex->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not read that value.']);
}
