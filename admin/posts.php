<?php

/**
 * Post log — every delivery attempt the pipeline has made, newest first.
 *
 * The lead list answers "who came in"; this answers "what happened to them on
 * the way out", across all leads at once. It is the screen for the questions
 * that are about the integration rather than about one consumer: is LeadProsper
 * rejecting everything since 3pm, did the scoring call start timing out, how
 * many posts today were test-mode.
 *
 * Each attempt carries what it carries on the lead page — the outcome, the
 * buyer's own reason, and the full request and response, collapsed. Diagnosing a
 * rejection means comparing what we sent with what came back, and having to open
 * a lead per attempt to do it turns one question into twenty page loads.
 *
 * Because the payloads are LONGTEXT (an entire credit report, on the legacy
 * Equifax rows), the page is smaller than the lead list — 25 attempts — and the
 * bodies are fetched only for the rows being rendered (portal_post_page()).
 *
 * Read-only. Nothing on this page writes anywhere.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/postquery.php';

logger($cfg);
$user = portal_require_login($cfg);
$csrf = portal_csrf_token($cfg);
$e    = 'pe';

const POSTS_PER_PAGE = 25;

$filters = portal_post_filters($_GET);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * POSTS_PER_PAGE;

$error = null;
$total = 0;
$rows  = [];
$modes = [];

try {
    $pdo   = db($cfg);
    $total = portal_post_count($pdo, $filters);
    $rows  = portal_post_page($pdo, $filters, POSTS_PER_PAGE, $offset);
    $modes = portal_post_modes($pdo);
} catch (Throwable $ex) {
    /* Same call as leads.php: the real message goes on screen. This page is
       behind a login, only staff see it, and "Unknown column 'accepted'" is
       worth vastly more than "could not load" plus a trip to the server log. */
    $error = 'Could not load the post log: ' . $ex->getMessage();
    app_log('error', 'portal', 'posts_query_failed', [
        'user_id' => $user['id'],
        'error'   => $ex->getMessage(),
    ]);
}

$pages = (int) max(1, (int) ceil($total / POSTS_PER_PAGE));

/** Rebuild the current query string with one value changed — used by the pager. */
$urlWith = static function (array $overrides) use ($filters, $page): string {
    $q = array_filter([
        'source'  => $filters['source'],
        'outcome' => $filters['outcome'],
        'mode'    => $filters['mode'],
        'from'    => $filters['from'],
        'to'      => $filters['to'],
        'lead'    => $filters['lead'] > 0 ? (string) $filters['lead'] : '',
        'page'    => (string) $page,
    ], static fn($v): bool => $v !== '' && $v !== null);

    return 'posts.php?' . http_build_query(array_merge($q, $overrides));
};

portal_head($cfg, 'Post log');
portal_topbar($user, $csrf, 'posts');
?>

<main class="page page--wide">

    <div class="page__head">
        <div>
            <h1 class="page__title">Post log</h1>
            <p class="page__sub">
                <?= number_format($total) ?> <?= $total === 1 ? 'attempt' : 'attempts' ?>
                <?= portal_post_filter_summary($filters) === 'no filter' ? '' : ' matching filter' ?>
            </p>
        </div>
    </div>

    <form class="filters" method="get" action="posts.php">
        <div class="filters__row">
            <label class="field">
                <span class="field__label">Destination</span>
                <select class="field__input" name="source">
                    <option value="">All</option>
                    <?php foreach (portal_post_sources() as $key => $src): ?>
                        <option value="<?= $e($key) ?>" <?= $filters['source'] === $key ? 'selected' : '' ?>>
                            <?= $e($src['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Outcome</span>
                <select class="field__input" name="outcome">
                    <option value="">All</option>
                    <?php
                    /* "Failed" is separate from "rejected" on purpose: a lead the
                       buyer turned down is a data problem, one that never got a
                       response is ours. */
                    $outcomes = [
                        'accepted' => 'Accepted',
                        'rejected' => 'Rejected',
                        'failed'   => 'Failed / no response',
                    ];
                    ?>
                    <?php foreach ($outcomes as $v => $label): ?>
                        <option value="<?= $e($v) ?>" <?= $filters['outcome'] === $v ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Mode</span>
                <select class="field__input" name="mode">
                    <option value="">All</option>
                    <?php /* Built from the values in the tables: the integrations do not
                             agree on a vocabulary — the Equifax rows say 'production'
                             where LeadProsper says 'live'. */ ?>
                    <?php foreach ($modes as $v): ?>
                        <option value="<?= $e($v) ?>" <?= $filters['mode'] === $v ? 'selected' : '' ?>><?= $e(ucfirst($v)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Lead ID</span>
                <input class="field__input" type="number" name="lead" min="1" inputmode="numeric"
                    value="<?= $filters['lead'] > 0 ? (int) $filters['lead'] : '' ?>" placeholder="Any">
            </label>

            <label class="field">
                <span class="field__label">From</span>
                <input class="field__input" type="date" name="from" value="<?= $e($filters['from']) ?>">
            </label>

            <label class="field">
                <span class="field__label">To</span>
                <input class="field__input" type="date" name="to" value="<?= $e($filters['to']) ?>">
            </label>
        </div>

        <div class="filters__row filters__row--actions">
            <span class="hint">
                Attempts are logged by the pipeline as they happen — a lead with no row here was never posted.
            </span>
            <div class="filters__buttons">
                <button class="btn btn--primary" type="submit">Apply</button>
                <a class="btn btn--ghost" href="posts.php">Reset</a>
            </div>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <section class="card card--flush">
        <div class="card__head">
            <h2 class="card__title">Attempts</h2>
            <span class="card__count">
                <?php if ($pages > 1): ?>
                    Showing <?= number_format($offset + 1) ?>&ndash;<?= number_format(min($offset + POSTS_PER_PAGE, $total)) ?>
                    of <?= number_format($total) ?>
                <?php else: ?>
                    <?= number_format(count($rows)) ?> shown
                <?php endif; ?>
            </span>
        </div>

        <?php if (!$rows): ?>
            <p class="card__body card__body--pad">
                <?= $error === null
                    ? 'No delivery attempts match this filter.'
                    : 'Unavailable — see the error above.' ?>
            </p>
        <?php endif; ?>

        <?php foreach ($rows as $row): ?>
            <?php
            $ps      = portal_post_status($row);
            $summary = $row['source'] === 'leadprosper'
                ? portal_lp_summary($row['response_body'])
                : ['status' => null, 'message' => null, 'price' => null, 'buyers' => 0];
            $leadId  = (int) ($row['lead_id'] ?? 0);
            /* Straight to this attempt on the lead page, where the lead's own data
               sits beside it. lead_id is nullable — a log row can outlive the lead
               it belonged to. */
            $href    = $leadId > 0
                ? 'lead.php?id=' . $leadId . '#' . portal_post_anchor($row)
                : '';
            ?>
            <article class="post">
                <header class="post__head">
                    <div class="post__ident">
                        <?php if ($href !== ''): ?>
                            <a class="link post__lead mono" href="<?= $e($href) ?>">Lead <?= $leadId ?></a>
                        <?php else: ?>
                            <span class="post__lead mono muted">No lead</span>
                        <?php endif; ?>
                        <span class="post__dest"><?= $e($row['destination']) ?></span>
                        <?= portal_badge($ps) ?>
                        <?php if (($row['mode'] ?? '') !== '' && $row['mode'] !== 'live'): ?>
                            <span class="badge badge--muted"><?= $e($row['mode']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="post__meta">
                        <span><?= $e(portal_datetime($row['created_at'])) ?></span>
                        <?php if ($row['duration_ms'] !== null): ?>
                            <span><?= number_format((int) $row['duration_ms']) ?> ms</span>
                        <?php endif; ?>
                        <span class="mono">HTTP <?= (int) $row['response_status'] ?></span>
                    </div>
                </header>

                <?php if ($summary['message'] !== null || $summary['status'] !== null): ?>
                    <?php /* The buyer's own words for why it was rejected, lifted out
                             of the JSON so it can be read without expanding
                             anything. Same treatment as the lead page. */ ?>
                    <div class="post__reason">
                        <?php if ($summary['status'] !== null): ?>
                            <span class="post__reason-label"><?= $e($summary['status']) ?></span>
                        <?php endif; ?>
                        <?php if ($summary['message'] !== null): ?>
                            <span><?= $e($summary['message']) ?></span>
                        <?php endif; ?>
                        <?php if ($summary['price'] !== null && $summary['price'] > 0): ?>
                            <span class="post__price">$<?= $e(number_format($summary['price'], 2)) ?></span>
                        <?php endif; ?>
                        <?php if ($summary['buyers'] > 0): ?>
                            <span class="hint"><?= (int) $summary['buyers'] ?> buyer<?= $summary['buyers'] === 1 ? '' : 's' ?> evaluated</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php /* Not a LeadProsper row, or a response with no message in it:
                             fall back to whatever the log itself recorded. */ ?>
                    <?php $detail = portal_post_detail($row); ?>
                    <?php if ($detail !== ''): ?>
                        <div class="post__reason">
                            <span class="post__reason-label"><?= empty($row['error']) ? 'Result' : 'Error' ?></span>
                            <span><?= $e($detail) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="post__bodies">
                    <?php /* Request and response are distinguished by the rail colour,
                             the chip, AND the word — not colour alone. Confusing "what
                             we sent" with "what came back" is the difference between
                             our bug and theirs.

                             Collapsed by default: twenty-five expanded payloads would
                             bury the timeline this page exists to show. */ ?>
                    <details class="body body--request">
                        <summary class="body__toggle">
                            <span class="body__tag">Sent</span> Request
                        </summary>
                        <pre class="body__pre"><?= $e(portal_format_body($row['request_body'])) ?></pre>
                    </details>

                    <details class="body body--response">
                        <summary class="body__toggle">
                            <span class="body__tag">Received</span> Response
                        </summary>
                        <pre class="body__pre"><?= $e(portal_format_body($row['response_body'])) ?></pre>
                    </details>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

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
