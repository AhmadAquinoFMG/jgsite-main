<?php

/**
 * Lead detail + post log.
 *
 * Two halves, in the order an operator actually needs them:
 *
 *   1. THE POST LOG — every delivery attempt for this lead, newest first, with
 *      the rejection reason pulled out of the response and the full
 *      request/response one click away. This is the "why didn't this lead go
 *      through" screen, so it sits above the fold rather than under the data.
 *   2. THE LEAD DATA — everything stored, grouped and shown in full.
 *
 * Viewing this page writes a `view_lead` audit row, which is the record of who
 * saw this consumer's details.
 *
 * Read-only: no query here writes to a lead or a log.
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

$leadId = (int) ($_GET['id'] ?? 0);
if ($leadId <= 0) {
    header('Location: leads.php');
    exit;
}

$lead = null;
$logs = [];
$error = null;

try {
    $pdo = db($cfg);

    /* SELECT * on purpose here, unlike the list: this page renders whatever the
       row actually has, and the schema differs between environments (a database
       that has not had every alter_* migration run is missing columns the
       renderer would otherwise hard-code). The field spec below skips anything
       absent instead of erroring. */
    $stmt = $pdo->prepare('SELECT * FROM leads WHERE id = :id');
    $stmt->execute(['id' => $leadId]);
    $lead = $stmt->fetch() ?: null;

    if ($lead !== null) {
        /* One timeline out of every call log, built in includes/postquery.php so
           this page and the portal-wide post log agree on which destinations
           exist and what they are called. */
        $logs = portal_post_lead_logs($pdo, $leadId);

        portal_audit($cfg, 'view_lead', ['lead_id' => $leadId]);
    }
} catch (Throwable $ex) {
    // Same reasoning as leads.php: staff-only page, so the cause is shown
    // rather than hidden behind a round trip to the server's log file.
    $error = 'Could not load this lead: ' . $ex->getMessage();
    app_log('error', 'portal', 'lead_query_failed', [
        'user_id' => $user['id'],
        'lead_id' => $leadId,
        'error'   => $ex->getMessage(),
    ]);
}

/* Field groups. [column => label]; a column the database does not have is
   skipped when rendered, so this list can name everything the schema might
   carry without breaking a lagging environment. */
$groups = [
    'Qualifying' => [
        'debt_amount'        => 'Debt (self-reported)',
        'self_assessed_debt' => 'Self-assessed debt',
        'behind_payment'     => 'Behind on payments',
        'employment'         => 'Employment',
        'income'             => 'Income',
    ],
    'Contact' => [
        'first_name' => 'First name',
        'last_name'  => 'Last name',
        'email'      => 'Email',
        'phone'      => 'Phone',
        'dob'        => 'Date of birth',
        'street'     => 'Street',
        'city'       => 'City',
        'state'      => 'State',
        'zip'        => 'ZIP',
    ],
    'Verified debt' => [
        'total_debt'        => 'Total debt posted',
        'total_debt_source' => 'Figure source',
        'jgw_total_debt'    => 'JG total debt',
        'jgw_prequalified'  => 'JG prequalified',
        'jgw_accepted'      => 'JG accepted',
        'jgw_disposition'   => 'JG disposition',
        'jgw_credit_rating' => 'JG credit rating',
        'jgw_external_id'   => 'JG external ID',
        'jgw_status'        => 'JG HTTP status',
        'jgw_error'         => 'JG error',
        'jgw_scored_at'     => 'JG scored at',
    ],
    'Attribution' => [
        'affid'             => 'Affiliate ID',
        'oid'               => 'Offer ID',
        'source_id'         => 'Source ID',
        'ef_transaction_id' => 'EF transaction ID',
        'sub1'              => 'sub1',
        'sub2'              => 'sub2',
        'sub3'              => 'sub3',
        'sub4'              => 'sub4',
        'sub5'              => 'sub5',
        'sub6'              => 'sub6',
        'utm_source'        => 'utm_source',
        'utm_medium'        => 'utm_medium',
        'utm_campaign'      => 'utm_campaign',
        'utm_term'          => 'utm_term',
        'utm_content'       => 'utm_content',
        'utm_creative'      => 'utm_creative',
        'utm_matchtype'     => 'utm_matchtype',
        'gclid'             => 'gclid',
        'fbclid'            => 'fbclid',
        'fbp'               => 'fbp',
        'fbc'               => 'fbc',
        'ttclid'            => 'ttclid',
        'landing_page_url'  => 'Landing page URL',
    ],
    'Passthrough IDs' => [
        'lp_subid1' => 'lp_subid1',
        'lp_subid2' => 'lp_subid2',
        'lp_subid3' => 'lp_subid3',
        'lp_subid4' => 'lp_subid4',
        'lp_subid5' => 'lp_subid5',
        'lp_subid6' => 'lp_subid6',
    ],
    'Compliance' => [
        'trustedform_url' => 'TrustedForm cert',
        'jornaya_token'   => 'Jornaya token',
        'consent_at'      => 'Consent recorded',
        'consent_text'    => 'Consent text shown',
    ],
    'Request' => [
        'product'        => 'Product',
        'form_name'      => 'Form',
        'ip'             => 'IP',
        'user_agent'     => 'User agent',
        'phone_verified' => 'Phone verified',
        'firebase_uid'   => 'Firebase UID',
        'bot_suspected'  => 'Bot suspected',
        'bot_reason'     => 'Bot reason',
        'submit_nonce'   => 'Submit nonce',
        'created_at'     => 'Received',
    ],
];

portal_head($cfg, $lead ? "Lead {$leadId}" : 'Lead');
portal_topbar($user, $csrf, 'leads');
?>

<main class="page page--wide">

    <?php if ($error !== null): ?>
        <div class="alert alert--error" role="alert"><?= $e($error) ?></div>
        <p><a class="link" href="leads.php">Back to leads</a></p>
    <?php elseif ($lead === null): ?>
        <div class="card">
            <h1 class="card__title">Lead not found</h1>
            <p class="card__body">No lead with ID <?= (int) $leadId ?>.</p>
            <p><a class="link" href="leads.php">Back to leads</a></p>
        </div>
    <?php else: ?>

        <?php $status = portal_lead_status($lead); ?>

        <div class="page__head">
            <div>
                <a class="link link--back" href="leads.php">&larr; Leads</a>
                <h1 class="page__title">
                    Lead <?= (int) $lead['id'] ?>
                    <?= portal_badge($status) ?>
                </h1>
                <p class="page__sub">
                    <?= $e(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))) ?>
                    &middot; received <?= $e(portal_datetime($lead['created_at'] ?? null)) ?>
                </p>
            </div>
        </div>

        <!-- ------------------------------------------------------ post log -->
        <section class="card card--flush">
            <div class="card__head">
                <h2 class="card__title">Post log</h2>
                <span class="card__count">
                    <?= count($logs) ?> attempt<?= count($logs) === 1 ? '' : 's' ?>
                    &middot; <a class="link" href="posts.php?lead=<?= (int) $lead['id'] ?>">in the post log</a>
                </span>
            </div>

            <?php if (!$logs): ?>
                <p class="card__body card__body--pad">
                    No delivery attempts recorded. The lead was stored but never posted —
                    a bot-flagged lead, or an integration switched off at the time.
                </p>
            <?php endif; ?>

            <?php foreach ($logs as $i => $log): ?>
                <?php
                $ps      = portal_post_status($log);
                $summary = $log['source'] === 'leadprosper'
                    ? portal_lp_summary($log['response_body'])
                    : ['status' => null, 'message' => null, 'price' => null, 'buyers' => 0];
                ?>
                <?php /* Anchored so the post log can link to this exact attempt
                         rather than to the top of a lead with nine of them. */ ?>
                <article class="post" id="<?= $e(portal_post_anchor($log)) ?>">
                    <header class="post__head">
                        <div class="post__ident">
                            <span class="post__dest"><?= $e($log['destination']) ?></span>
                            <?= portal_badge($ps) ?>
                            <?php if (($log['mode'] ?? '') !== '' && $log['mode'] !== 'live'): ?>
                                <span class="badge badge--muted"><?= $e($log['mode']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="post__meta">
                            <span><?= $e(portal_datetime($log['created_at'])) ?></span>
                            <?php if ($log['duration_ms'] !== null): ?>
                                <span><?= number_format((int) $log['duration_ms']) ?> ms</span>
                            <?php endif; ?>
                            <span class="mono">HTTP <?= (int) $log['response_status'] ?></span>
                        </div>
                    </header>

                    <?php if ($summary['message'] !== null || $summary['status'] !== null): ?>
                        <?php /* The buyer's own words for why it was rejected — the single
                                 most useful string in the response, so it is lifted out of
                                 the JSON instead of being left for someone to go find. */ ?>
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
                    <?php elseif (!empty($log['error'])): ?>
                        <div class="post__reason"><span class="post__reason-label">Error</span>
                            <span><?= $e($log['error']) ?></span></div>
                    <?php endif; ?>

                    <div class="post__bodies">
                        <?php /* Request and response are distinguished by the rail
                                 colour, the chip, AND the word — not colour alone.
                                 Confusing "what we sent" with "what came back" is
                                 the difference between our bug and theirs. */ ?>
                        <details class="body body--request">
                            <summary class="body__toggle">
                                <span class="body__tag">Sent</span> Request
                            </summary>
                            <pre class="body__pre"><?= $e(portal_format_body($log['request_body'])) ?></pre>
                        </details>

                        <details class="body body--response">
                            <summary class="body__toggle">
                                <span class="body__tag">Received</span> Response
                            </summary>
                            <pre class="body__pre"><?= $e(portal_format_body($log['response_body'])) ?></pre>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- ---------------------------------------------------- lead data -->
        <?php foreach ($groups as $groupLabel => $fields): ?>
            <?php
            // Skip a group entirely when this database has none of its columns.
            $present = array_filter(
                $fields,
                static fn(string $col): bool => array_key_exists($col, $lead),
                ARRAY_FILTER_USE_KEY
            );
            if (!$present) {
                continue;
            }
            ?>
            <section class="card">
                <h2 class="card__title"><?= $e($groupLabel) ?></h2>
                <dl class="datagrid">
                    <?php foreach ($present as $column => $label): ?>
                        <?php
                        $display   = portal_or_dash((string) $lead[$column]);
                        $isLongUrl = in_array($column, ['landing_page_url', 'trustedform_url'], true);
                        ?>
                        <div class="datagrid__item<?= $isLongUrl || $column === 'consent_text' || $column === 'user_agent' ? ' datagrid__item--wide' : '' ?>">
                            <dt class="datagrid__label"><?= $e($label) ?></dt>
                            <dd class="datagrid__value<?= $isLongUrl ? ' datagrid__value--break mono' : '' ?>"><?= $e($display) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>

</main>

<?php portal_foot($cfg); ?>
