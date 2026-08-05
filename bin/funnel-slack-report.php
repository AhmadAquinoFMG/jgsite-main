<?php
/**
 * Funnel drop-off report → Slack.
 *
 * Pulls the JG Wentworth Debt Relief funnel from Umami Cloud and posts a per-step
 * drop-off digest to one or more Slack Incoming Webhooks. Designed for cron, a few
 * times a day.
 *
 * ---------------------------------------------------------------------------
 * The events it reads (all of them exist — don't rename one side only)
 * ---------------------------------------------------------------------------
 *   funnel-landing          index.php, on pageview, with the traffic-source props.
 *   funnel-N-<name>         assets/js/funnel.js, first time step N is shown.
 *   funnel-N-<name>-done    assets/js/funnel.js, when step N validates and advances.
 *   thank_you_view          thank-you.php, on pageview.
 *   call_click              thank-you.php, the CALL NOW tel: link.
 *   funnel-submit           assets/js/funnel.js, on every submit ATTEMPT.
 *
 * Measurement is unique-visitor based (Umami's funnel report) and, for the
 * headline outcome, BEACON-INDEPENDENT so the number is correct immediately:
 *
 *   - Form table: the 9 on-form view steps, all fired via track() on a page that
 *     stays open, so they are the reliable signal. This is pure in-form drop-off.
 *   - Completed is derived from a terminal funnel anchored at entry
 *     (funnel-1-debt-amount → thank_you_view). thank_you_view is a fresh pageview
 *     on the post-submit page, so it cannot be lost to the redirect the way an
 *     in-page submit beacon can.
 *   - funnel-submit is shown only as a secondary "submit attempts" line. It counts
 *     ATTEMPTS, not successes — it fires before the POST resolves, so 422s and
 *     network failures are in there. Never treat it as a conversion count.
 *
 * There is one outcome path: submit.php accepts the lead and funnel.js redirects
 * to thank-you.php (submit.php:438). No offerwall/decline branch exists on this
 * site, so there is no second terminal funnel to report.
 *
 * The "Adv" (advanced) column comes from the -done events. Note that by
 * construction adv(N) ≈ saw(N+1) — advancing renders the next step, which fires
 * its view event — so on steps 1-8 it is mostly a cross-check that both events
 * landed. It carries real information on the LAST step, where "advanced" means the
 * visitor actually pushed Submit rather than merely reaching the phone field.
 *
 * Cost: ~14 Umami API calls per run (1 form funnel + 1 per step for -done + 4
 * outcome funnels).
 *
 * Secrets (real env vars or .env/.env.local at the project root):
 *   UMAMI_API_KEY       required — Umami Cloud API key (x-umami-api-key)
 *   SLACK_WEBHOOK_URLS  required — one or more Slack webhook URLs, comma-separated
 *   UMAMI_WEBSITE_ID    optional — defaults to the JG Wentworth website id
 *
 * Usage:  php bin/funnel-slack-report.php
 * Exit:   0 success · 1 config error · 2 Umami error · 3 Slack post failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
const UMAMI_API_BASE  = 'https://api.umami.is/v1';
const DEFAULT_WEBSITE = '40f1f6d9-80c1-49cf-b6ef-0280ac052f83'; // JG Wentworth (config.php ['umami'])
const REPORT_TZ       = 'America/New_York';
const LOW_SAMPLE_MIN  = 15;   // below this many entrants, flag the numbers as noisy
const HTTP_TIMEOUT    = 20;
const DONE_SUFFIX     = '-done';

// The on-form funnel: ordered [event_name => human label]. Mirrors STEP_NAMES in
// assets/js/funnel.js — the two must stay in lockstep.
const FORM_STEPS = [
    'funnel-1-debt-amount'    => 'Debt amount',
    'funnel-2-behind-payment' => 'Behind on payments',
    'funnel-3-employment'     => 'Employment',
    'funnel-4-income'         => 'Income',
    'funnel-5-name'           => 'Name',
    'funnel-6-address'        => 'Address',
    'funnel-7-dob'            => 'Date of birth',
    'funnel-8-email'          => 'Email',
    'funnel-9-phone'          => 'Phone',
];

// Key events used for the landing and terminal (outcome) funnels.
const EV_LAND     = 'funnel-landing';         // landed on index.php (with source props)
const EV_ENTER    = 'funnel-1-debt-amount';   // funnel entry anchor (step 1 shown)
const EV_COMPLETE = 'thank_you_view';         // reached the pre-qualified page — the conversion
const EV_CALL     = 'call_click';             // clicked the CALL NOW CTA there
const EV_ATTEMPT  = 'funnel-submit';          // submit ATTEMPT (secondary signal, see docblock)

// ---------------------------------------------------------------------------
// Minimal .env loader (no web side effects; real env vars win over files)
// ---------------------------------------------------------------------------
$DOTENV = [];
(function () use (&$DOTENV) {
    $dir = dirname(__DIR__);
    foreach (['.env', '.env.local'] as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);
            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if ($name !== '') {
                $DOTENV[$name] = $value; // .env.local naturally overrides .env (loaded 2nd)
            }
        }
    }
})();

function cfg(string $key, ?string $default = null): ?string
{
    global $DOTENV;
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = $DOTENV[$key] ?? null;
    }
    return ($v === null || $v === false || $v === '') ? $default : $v;
}

function fail(int $code, string $msg): never
{
    fwrite(STDERR, '[funnel_report] ' . $msg . "\n");
    exit($code);
}

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

/**
 * POST to the Umami funnel endpoint. $events is an ordered list of event names.
 * Returns the decoded per-step array (each: value, visitors, previous, dropped,
 * dropoff, remaining).
 */
function umami_funnel(string $apiKey, string $websiteId, array $events, string $startIso, string $endIso): array
{
    $steps = [];
    foreach ($events as $event) {
        $steps[] = ['type' => 'event', 'value' => $event];
    }
    $body = json_encode([
        'websiteId'  => $websiteId,
        'type'       => 'funnel',
        'filters'    => (object) [],
        'parameters' => [
            'startDate' => $startIso,
            'endDate'   => $endIso,
            'window'    => 1,
            'steps'     => $steps,
        ],
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init(UMAMI_API_BASE . '/reports/funnel');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-umami-api-key: ' . $apiKey,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        fail(2, 'Umami request failed: ' . $err);
    }
    if ($code !== 200) {
        fail(2, "Umami funnel returned HTTP $code: $resp");
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        fail(2, 'Umami funnel returned non-array: ' . $resp);
    }
    return $data;
}

/** Unique visitors reaching the LAST step of a 2+-step funnel (the conversion count). */
function terminal_visitors(string $apiKey, string $websiteId, array $events, string $startIso, string $endIso): int
{
    $rows = umami_funnel($apiKey, $websiteId, $events, $startIso, $endIso);
    $last = end($rows);
    return $last ? (int) ($last['visitors'] ?? 0) : 0;
}

/** [firstStepVisitors, lastStepVisitors] — both ends of a funnel in one call. */
function funnel_ends(string $apiKey, string $websiteId, array $events, string $startIso, string $endIso): array
{
    $rows = umami_funnel($apiKey, $websiteId, $events, $startIso, $endIso);
    if (!$rows) {
        return [0, 0];
    }
    $first = reset($rows);
    $last  = end($rows);
    return [(int) ($first['visitors'] ?? 0), (int) ($last['visitors'] ?? 0)];
}

/** POST a Slack Block Kit payload to a webhook. Returns [httpCode, body]. */
function slack_post(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string) $resp];
}

// ---------------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------------
function pct(float $frac): string { return number_format($frac * 100, 1) . '%'; }

function by_event(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r['value']] = $r;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
$apiKey     = cfg('UMAMI_API_KEY');
$websiteId  = cfg('UMAMI_WEBSITE_ID', DEFAULT_WEBSITE);
$webhookRaw = cfg('SLACK_WEBHOOK_URLS');

if (!$apiKey)     { fail(1, 'UMAMI_API_KEY is not set.'); }
if (!$webhookRaw) { fail(1, 'SLACK_WEBHOOK_URLS is not set.'); }

$webhooks = array_values(array_filter(array_map('trim', explode(',', $webhookRaw))));
if (!$webhooks) { fail(1, 'SLACK_WEBHOOK_URLS contained no URLs.'); }

// Window: today since midnight in report TZ → now. Umami accepts UTC ISO (Z).
$tz    = new DateTimeZone(REPORT_TZ);
$now   = new DateTime('now', $tz);
$start = (clone $now)->setTime(0, 0, 0);
$utc   = new DateTimeZone('UTC');
$startIso = (clone $start)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
$endIso   = (clone $now)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
$windowLabel = 'Today ' . $start->format('g:i a') . ' → ' . $now->format('g:i a T') . ' (' . $now->format('M j') . ')';

// --- Pull the on-form funnel (per-step view table + entered) ---
$form    = by_event(umami_funnel($apiKey, $websiteId, array_keys(FORM_STEPS), $startIso, $endIso));
$entered = (int) ($form[EV_ENTER]['visitors'] ?? 0);

// --- Per-step "advanced": visitors who reached step N and then completed it ---
$advanced = [];
foreach (FORM_STEPS as $event => $label) {
    $advanced[$event] = terminal_visitors($apiKey, $websiteId, [$event, $event . DONE_SUFFIX], $startIso, $endIso);
}

// --- Landing → entry, and the terminal outcomes (unique visitors from entry) ---
[$landed, $enteredFromLanding] = funnel_ends($apiKey, $websiteId, [EV_LAND, EV_ENTER], $startIso, $endIso);
$completed = terminal_visitors($apiKey, $websiteId, [EV_ENTER, EV_COMPLETE], $startIso, $endIso);
$called    = terminal_visitors($apiKey, $websiteId, [EV_COMPLETE, EV_CALL], $startIso, $endIso);
$attempts  = terminal_visitors($apiKey, $websiteId, [EV_ENTER, EV_ATTEMPT], $startIso, $endIso);

$startPct      = $landed    > 0 ? $enteredFromLanding / $landed : 0.0;
$completionPct = $entered   > 0 ? $completed / $entered    : 0.0;
$callPct       = $completed > 0 ? $called    / $completed  : 0.0;

// --- Per-step form table + biggest single in-form drop ---
$rows      = [sprintf('%-2s %-19s %5s %6s   %s', '#', 'Step', 'Saw', 'Adv', 'Dropped from prev')];
$biggest   = null;
$prevLabel = null;
$n         = 0;
foreach (FORM_STEPS as $event => $label) {
    $n++;
    $r        = $form[$event] ?? ['visitors' => 0, 'dropped' => 0, 'dropoff' => null];
    $visitors = (int) $r['visitors'];
    $dropped  = (int) $r['dropped'];
    $dropoff  = $r['dropoff']; // fraction or null (first step)
    $adv      = $advanced[$event];

    $dropText = '';
    if ($dropoff !== null) {
        $dropText = sprintf('▼ %d (%s)', $dropped, pct((float) $dropoff));
        if ($biggest === null || $dropped > $biggest['dropped']) {
            $biggest = ['from' => $prevLabel, 'to' => $label, 'dropped' => $dropped, 'dropoff' => (float) $dropoff];
        }
    }
    $rows[]    = sprintf('%-2d %-19s %5d %6d   %s', $n, $label, $visitors, $adv, $dropText);
    $prevLabel = $label;
}
$tableText = "```\n" . implode("\n", $rows) . "\n```";
$lowSample = $entered < LOW_SAMPLE_MIN;

// The final step is where "advanced" earns its keep: saw the phone field vs
// actually pushed Submit. Everything above it is ≈ the next step's view count.
$lastEvent    = array_key_last(FORM_STEPS);
$lastSaw      = (int) ($form[$lastEvent]['visitors'] ?? 0);
$lastAdvanced = $advanced[$lastEvent];

// --- Compose the Slack message (Block Kit) ---
$summaryLines = [];
if ($landed > 0) {
    // Only meaningful once funnel-landing is deployed and recording; before that
    // the event simply isn't there and the line would read 0 → 0.
    $summaryLines[] = sprintf('*Landed:* %d   •   *Started form:* %d (%s)', $landed, $enteredFromLanding, pct($startPct));
}
$summaryLines[] = sprintf('*Entered:* %d   •   *Completed:* %d (%s)', $entered, $completed, pct($completionPct));
$summaryLines[] = sprintf('*Called:* %d (%s of completions)', $called, pct($callPct));
$summaryLines[] = sprintf('*Final step:* %d saw the phone field, %d pushed Submit', $lastSaw, $lastAdvanced);

$blocks = [
    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '📉 Funnel drop-off — JG Wentworth Debt Relief', 'emoji' => true]],
    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $windowLabel]]],
];

if ($lowSample) {
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn',
        'text' => sprintf(':warning: *Low sample* — only %d entered the funnel; percentages are noisy.', $entered)]];
}

$blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn',
    'text' => "*Form steps* (Saw = reached it, Adv = advanced from it)\n" . $tableText]];

if ($biggest && $biggest['dropped'] > 0) {
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn',
        'text' => sprintf(':small_red_triangle_down: *Biggest in-form drop:* %s → %s  (−%d, %s)',
            $biggest['from'], $biggest['to'], $biggest['dropped'], pct($biggest['dropoff']))]];
}

$blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Outcomes*\n" . implode("\n", $summaryLines)]];
$blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn',
    'text' => sprintf('Submit attempts (incl. retries/errors): %d  •  <https://cloud.umami.is/websites/%s|Open in Umami>  •  Debt Relief funnel', $attempts, $websiteId)]]];

$payload = [
    'text'   => sprintf('Funnel: %d entered → %d completed (%s) → %d called', $entered, $completed, pct($completionPct), $called),
    'blocks' => $blocks,
];

// --- Deliver to every webhook ---
// Timestamped run header so the cron log reads as a dated history.
fwrite(STDOUT, sprintf(
    "[funnel_report] %s — landed=%d entered=%d completed=%d called=%d attempts=%d\n",
    $now->format('Y-m-d H:i:s T'), $landed, $entered, $completed, $called, $attempts
));

$failures = 0;
foreach ($webhooks as $i => $url) {
    [$code, $resp] = slack_post($url, $payload);
    $tag = 'webhook #' . ($i + 1);
    if ($code === 200) {
        fwrite(STDOUT, "[funnel_report] $tag: posted OK\n");
    } else {
        $failures++;
        fwrite(STDERR, "[funnel_report] $tag: HTTP $code — $resp\n");
    }
}

exit($failures > 0 ? 3 : 0);
