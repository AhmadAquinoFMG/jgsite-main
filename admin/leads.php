<?php

/**
 * Lead list — the portal's work queue.
 *
 * Filter, scan, click through to a lead. Contact identifiers are masked here
 * and stay masked: reveal happens on the detail page, one field at a time, so
 * a list view can never become a bulk PII dump.
 *
 * Read-only. Nothing on this page writes to a lead.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/mask.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/leadquery.php';

logger($cfg);
$user = portal_require_login($cfg);
$csrf = portal_csrf_token($cfg);
$e    = 'pe';

const PER_PAGE = 50;

$filters = portal_lead_filters($_GET);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * PER_PAGE;

$error  = null;
$total  = 0;
$rows   = [];
$facets = ['affid' => [], 'utm_source' => []];

try {
    $pdo    = db($cfg);
    $total  = portal_lead_count($pdo, $filters);
    $rows   = portal_lead_page($pdo, $filters, PER_PAGE, $offset);
    $facets = portal_lead_facets($pdo);
} catch (Throwable $ex) {
    /* Show the operator something usable rather than a stack trace, and put the
       detail where it belongs. */
    $error = 'Could not load leads.';
    app_log('error', 'portal', 'leads_query_failed', [
        'user_id' => $user['id'],
        'error'   => $ex->getMessage(),
    ]);
}

$pages = (int) max(1, (int) ceil($total / PER_PAGE));

/** Rebuild the current query string with one value changed — used by the pager. */
$urlWith = static function (array $overrides) use ($filters, $page): string {
    $q = array_filter([
        'q'          => $filters['q'],
        'from'       => $filters['from'],
        'to'         => $filters['to'],
        'status'     => $filters['status'],
        'affid'      => $filters['affid'],
        'utm_source' => $filters['utm_source'],
        'show_bots'  => $filters['show_bots'] ? '1' : '',
        'page'       => (string) $page,
    ], static fn($v): bool => $v !== '' && $v !== null);

    return 'leads.php?' . http_build_query(array_merge($q, $overrides));
};

portal_head($cfg, 'Leads');
portal_topbar($user, $csrf, 'leads');
?>

<main class="page page--wide">

    <div class="page__head">
        <div>
            <h1 class="page__title">Leads</h1>
            <p class="page__sub">
                <?= number_format($total) ?> <?= $total === 1 ? 'lead' : 'leads' ?>
                <?= portal_filter_summary($filters) === 'no filter' ? '' : ' matching filter' ?>
            </p>
        </div>
        <a class="btn btn--ghost" href="<?= $e(str_replace('leads.php?', 'export.php?', $urlWith(['page' => 1]))) ?>">Export CSV</a>
    </div>

    <form class="filters" method="get" action="leads.php">
        <div class="filters__row">
            <label class="field field--grow">
                <span class="field__label">Search</span>
                <input class="field__input" type="search" name="q" value="<?= $e($filters['q']) ?>"
                    placeholder="Email, phone, name or lead ID">
            </label>

            <label class="field">
                <span class="field__label">From</span>
                <input class="field__input" type="date" name="from" value="<?= $e($filters['from']) ?>">
            </label>

            <label class="field">
                <span class="field__label">To</span>
                <input class="field__input" type="date" name="to" value="<?= $e($filters['to']) ?>">
            </label>

            <label class="field">
                <span class="field__label">Status</span>
                <select class="field__input" name="status">
                    <option value="">All</option>
                    <?php foreach (['accepted' => 'Accepted', 'rejected' => 'Rejected', 'not_posted' => 'Not posted'] as $v => $label): ?>
                        <option value="<?= $e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Affiliate</span>
                <select class="field__input" name="affid">
                    <option value="">All</option>
                    <?php foreach ($facets['affid'] as $v): ?>
                        <option value="<?= $e($v) ?>" <?= $filters['affid'] === $v ? 'selected' : '' ?>><?= $e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Source</span>
                <select class="field__input" name="utm_source">
                    <option value="">All</option>
                    <?php foreach ($facets['utm_source'] as $v): ?>
                        <option value="<?= $e($v) ?>" <?= $filters['utm_source'] === $v ? 'selected' : '' ?>><?= $e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="filters__row filters__row--actions">
            <label class="check">
                <input type="checkbox" name="show_bots" value="1" <?= $filters['show_bots'] ? 'checked' : '' ?>>
                <span>Include bot-flagged</span>
            </label>
            <div class="filters__buttons">
                <button class="btn btn--primary" type="submit">Apply</button>
                <a class="btn btn--ghost" href="leads.php">Reset</a>
            </div>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <div class="tablewrap">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Received</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Location</th>
                    <th>Debt</th>
                    <th>Affiliate</th>
                    <th>Source</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td class="table__empty" colspan="10">
                            <?= $error === null ? 'No leads match this filter.' : 'Unavailable.' ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <?php $status = portal_lead_status($row); ?>
                    <tr class="table__row" onclick="location.href='lead.php?id=<?= (int) $row['id'] ?>'">
                        <td class="mono"><a class="link" href="lead.php?id=<?= (int) $row['id'] ?>"><?= (int) $row['id'] ?></a></td>
                        <td class="nowrap"><?= $e(portal_datetime($row['created_at'])) ?></td>
                        <td><?= $e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                        <td class="mono"><?= $e(portal_mask_email($row['email'])) ?></td>
                        <td class="mono nowrap"><?= $e(portal_mask_phone($row['phone'])) ?></td>
                        <td class="nowrap"><?= $e(portal_or_dash(trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ' ,'))) ?></td>
                        <td class="nowrap">
                            <?php if ($row['total_debt'] !== null): ?>
                                <?= $e(portal_money((int) $row['total_debt'])) ?>
                                <span class="hint" title="Verified figure source"><?= $e($row['total_debt_source'] ?? '') ?></span>
                            <?php else: ?>
                                <span class="muted"><?= $e(portal_or_dash($row['debt_amount'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= $e(portal_or_dash($row['affid'])) ?></td>
                        <td><?= $e(portal_or_dash($row['utm_source'])) ?></td>
                        <td class="nowrap">
                            <?= portal_badge($status) ?>
                            <?php if ((int) ($row['bot_suspected'] ?? 0) === 1 && $row['bot_reason']): ?>
                                <span class="hint"><?= $e($row['bot_reason']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <span class="pager__info">Page <?= $page ?> of <?= $pages ?></span>
            <div class="pager__links">
                <?php if ($page > 1): ?>
                    <a class="btn btn--ghost" href="<?= $e($urlWith(['page' => $page - 1])) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn--ghost" href="<?= $e($urlWith(['page' => $page + 1])) ?>">Next</a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>

</main>

<?php portal_foot($cfg); ?>
