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
 * Events are named after the DATA THE STEP COLLECTS, never its position, so a name
 * still means the same thing after a step is inserted, moved or dropped. Per-FIELD
 * names are also what makes them countable: Umami groups by event NAME, so one
 * shared event carrying a step number as a property could not be broken out.
 *
 *   event_view_landing          index.php, on pageview, with traffic-source props.
 *   event_view_<field>          funnel.js, first time that step is shown.
 *   event_engage_<field>        funnel.js, first focus of one of its inputs.
 *   event_<field>_complete      funnel.js, step validated and visitor advanced.
 *   event_abandon_<field>       funnel.js, visitor left the page on that step.
 *   event_resume_<field>        funnel.js, visitor came BACK to that step.
 *   event_view_thank_you        thank-you.php, on pageview.
 *   event_call_click            thank-you.php, the CALL NOW tel: link.
 *   event_submit_attempt        funnel.js, on every submit ATTEMPT.
 *
 * ---------------------------------------------------------------------------
 * Why the headline numbers are trustworthy
 * ---------------------------------------------------------------------------
 *   - Form table: the 9 event_view_<field> steps, all fired via track() on a page
 *     that stays open, so they are the reliable signal. Pure in-form drop-off.
 *   - Completed comes from a terminal funnel anchored at entry (event_view_debt_amount
 *     → event_view_thank_you). event_view_thank_you is a fresh pageview on the
 *     post-submit page, so it cannot be lost to the redirect the way an in-page
 *     submit beacon can.
 *   - event_submit_attempt is shown only as a secondary line. It counts ATTEMPTS,
 *     not successes — it fires before the POST resolves, so 422s and network
 *     failures are in there. Never read it as a conversion count.
 *
 * There is one outcome path: submit.php accepts the lead and funnel.js redirects to
 * thank-you.php (submit.php:438). No offerwall/decline branch exists on this site,
 * so there is no second terminal funnel to report.
 *
 * Columns, and what each is actually measuring:
 *   Saw   unique visitors who reached the step        (funnel report)
 *   Adv   of those, how many advanced from it         (funnel report)
 *   Left  event_abandon_<field> total fires           (event totals)
 *   Back  event_resume_<field> total fires            (event totals)
 * Saw/Adv are unique VISITORS; Left/Back are event COUNTS, so don't read
 * Left as a share of Saw. Back counts every return trip on purpose — repeated
 * returns to the same field are the signal that the field itself is the problem.
 *
 * Note that adv(N) ≈ saw(N+1) by construction: advancing renders the next step,
 * which fires its view event. On steps 1-8 the Adv column is mostly a cross-check
 * that both events landed; it carries real information on the LAST step, where
 * "advanced" means the visitor actually pushed Submit rather than merely reaching
 * the phone field.
 *
 * Cost: ~16 Umami API calls per run (2 for the form funnel — /reports/funnel accepts
 * at most 8 steps and the form has 9, so it is pulled in chunks, see
 * umami_funnel_chained() — plus 1 per step for _complete, 4 outcome funnels and
 * 1 event-totals call covering all abandon/resume names).
 *
 * Secrets (real env vars or .env/.env.local at the project root):
 *   UMAMI_API_KEY       required — Umami Cloud API key (x-umami-api-key)
 *   SLACK_WEBHOOK_URL   required — one or more Slack webhook URLs, comma-separated
 *                                  (SLACK_WEBHOOK_URLS is accepted as an alias)
 *   UMAMI_WEBSITE_ID    optional — defaults to DEFAULT_WEBSITE below, which must
 *                                  match config.php ['umami']['website_id']
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
const DEFAULT_WEBSITE = '40f1f6d9-80c1-49cf-b6ef-0280ac052f83'; // must match config.php ['umami']['website_id']
const REPORT_TZ       = 'America/New_York';
const LOW_SAMPLE_MIN  = 15;   // below this many entrants, flag the numbers as noisy
const HTTP_TIMEOUT    = 20;

// Hard cap enforced by /reports/funnel — more than this and the whole request is
// rejected with `Too big: expected array to have <=8 items`. Our form is 9 steps,
// so every full-funnel pull goes through umami_funnel_chained() below.
const UMAMI_MAX_FUNNEL_STEPS = 8;

// The on-form funnel, in order: [field slug => human label]. The slugs mirror
// STEP_FIELDS in assets/js/funnel.js — the two must stay in lockstep. Event names
// are derived from these by the ev_* helpers below.
const FORM_STEPS = [
    'debt_amount'    => 'Debt amount',
    'behind_payment' => 'Behind on payments',
    'employment'     => 'Employment',
    'income'         => 'Income',
    'name'           => 'Name',
    'address'        => 'Address',
    'dob'            => 'Date of birth',
    'email'          => 'Email',
    'phone'          => 'Phone',
];

// Landing and terminal (outcome) events.
const EV_LAND     = 'event_view_landing';    // landed on index.php (with source props)
const EV_COMPLETE = 'event_view_thank_you';  // reached the pre-qualified page — the conversion
const EV_CALL     = 'event_call_click';      // clicked the CALL NOW CTA there
const EV_ATTEMPT  = 'event_submit_attempt';  // submit ATTEMPT (secondary signal, see docblock)

// Event names for a step, derived from its field slug. One place to change if the
// taxonomy ever shifts.
function ev_view(string $field): string     { return 'event_view_' . $field; }
function ev_complete(string $field): string { return 'event_' . $field . '_complete'; }
function ev_abandon(string $field): string  { return 'event_abandon_' . $field; }
function ev_resume(string $field): string   { return 'event_resume_' . $field; }

/** The funnel entry anchor: the view event of the first step. */
function ev_enter(): string { return ev_view((string) array_key_first(FORM_STEPS)); }

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

function warn(string $msg): void
{
    fwrite(STDERR, '[funnel_report] WARN ' . $msg . "\n");
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

/**
 * Same contract as umami_funnel(), but works for funnels longer than the API's
 * 8-step ceiling. Under the limit it is a straight pass-through.
 *
 * Over it, the funnel is walked in chunks, each one re-anchored so the numbers stay
 * comparable with the first chunk's:
 *
 *   chunk 1 : e1 e2 e3 e4 e5 e6 e7 e8
 *   chunk 2 : e1 e8 | e9 …                (anchor, hand-off, then up to 6 new steps)
 *
 * The anchor (e1) keeps every chunk measuring the same population — visitors who
 * entered the funnel — and the hand-off (the previous chunk's last step) keeps the
 * ordering constraint across the seam. The intermediate steps e2…e7 are safe to omit
 * because the form is strictly sequential: nobody reaches e8 without passing them.
 *
 * The prefix rows are dropped from the result, and the first row after a seam has its
 * previous/dropped/dropoff/remaining recomputed against the STITCHED table rather than
 * against its own chunk. Chunk 2's e8 count can come out a shade higher than chunk 1's
 * (it isn't gated on e2…e7), and reusing it would let the table show a step gaining
 * visitors. Recomputing keeps the report monotonic and self-consistent.
 */
function umami_funnel_chained(string $apiKey, string $websiteId, array $events, string $startIso, string $endIso): array
{
    $events = array_values($events);
    if (count($events) <= UMAMI_MAX_FUNNEL_STEPS) {
        return umami_funnel($apiKey, $websiteId, $events, $startIso, $endIso);
    }

    $rows = umami_funnel(
        $apiKey, $websiteId, array_slice($events, 0, UMAMI_MAX_FUNNEL_STEPS), $startIso, $endIso
    );
    $entrants = (int) ($rows[0]['visitors'] ?? 0);
    $next     = UMAMI_MAX_FUNNEL_STEPS;

    while ($next < count($events)) {
        // Anchor + hand-off. They coincide only if a chunk would carry a single new
        // step off the very first event, which can't happen here, but guard anyway so
        // a duplicated step never eats one of the eight slots.
        $prefix = [$events[0]];
        if ($events[$next - 1] !== $events[0]) {
            $prefix[] = $events[$next - 1];
        }
        $take  = UMAMI_MAX_FUNNEL_STEPS - count($prefix);
        $chunk = array_merge($prefix, array_slice($events, $next, $take));

        $new = array_slice(
            umami_funnel($apiKey, $websiteId, $chunk, $startIso, $endIso),
            count($prefix)
        );
        if (!$new) {
            warn(sprintf('funnel chunk starting at "%s" returned no rows; table truncated there.', $events[$next]));
            break;
        }

        // Re-base the seam row on the last stitched row.
        $prevVisitors  = (int) (end($rows)['visitors'] ?? 0);
        $seamVisitors  = min((int) ($new[0]['visitors'] ?? 0), $prevVisitors);
        $new[0]['visitors']  = $seamVisitors;
        $new[0]['previous']  = $prevVisitors;
        $new[0]['dropped']   = $prevVisitors - $seamVisitors;
        $new[0]['dropoff']   = $prevVisitors > 0 ? ($prevVisitors - $seamVisitors) / $prevVisitors : null;
        $new[0]['remaining'] = $entrants > 0 ? $seamVisitors / $entrants : 0;

        // Steps after the seam are already chained correctly within their own chunk;
        // only `remaining` needs re-basing, since the chunk computed it against e1's
        // count in that chunk rather than against the report's entrant count.
        for ($i = 1; $i < count($new); $i++) {
            $new[$i]['remaining'] = $entrants > 0 ? ((int) ($new[$i]['visitors'] ?? 0)) / $entrants : 0;
        }

        $rows  = array_merge($rows, $new);
        $next += $take;
    }

    return $rows;
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

/**
 * Per-event totals for the window: [eventName => count]. One call covers every
 * event name, which is why the abandon/resume columns are nearly free.
 *
 * These are total event COUNTS, not unique visitors — fine for abandon (fires at
 * most once per pageview) and deliberate for resume (every return trip counts).
 * Degrades to [] on error rather than killing the digest: those two columns are
 * secondary, and the run should still post the drop-off table.
 */
function umami_event_counts(string $apiKey, string $websiteId, int $startMs, int $endMs): array
{
    $url = sprintf(
        '%s/websites/%s/metrics?type=event&startAt=%d&endAt=%d',
        UMAMI_API_BASE, rawurlencode($websiteId), $startMs, $endMs
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['x-umami-api-key: ' . $apiKey],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        warn('event totals request failed (Left/Back columns will read 0): ' . $err);
        return [];
    }
    if ($code !== 200) {
        warn("event totals returned HTTP $code (Left/Back columns will read 0): $resp");
        return [];
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        warn('event totals returned non-array (Left/Back columns will read 0).');
        return [];
    }

    $out = [];
    foreach ($data as $row) {
        if (is_array($row) && isset($row['x'])) {
            $out[(string) $row['x']] = (int) ($row['y'] ?? 0);
        }
    }
    return $out;
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
$apiKey    = cfg('UMAMI_API_KEY');
$websiteId = cfg('UMAMI_WEBSITE_ID', DEFAULT_WEBSITE);

// SLACK_WEBHOOK_URL is this project's key (see .env.production.example) and already
// holds a comma-separated list. SLACK_WEBHOOK_URLS is accepted as an alias so a
// plural spelling in someone's env doesn't silently produce a report that never posts.
$webhookRaw = cfg('SLACK_WEBHOOK_URL') ?? cfg('SLACK_WEBHOOK_URLS');

if (!$apiKey)     { fail(1, 'UMAMI_API_KEY is not set.'); }
if (!$webhookRaw) { fail(1, 'SLACK_WEBHOOK_URL is not set.'); }

$webhooks = array_values(array_filter(array_map('trim', explode(',', $webhookRaw))));
if (!$webhooks) { fail(1, 'SLACK_WEBHOOK_URL contained no URLs.'); }

// Window: today since midnight in report TZ → now. Umami's funnel endpoint takes
// UTC ISO (Z); the metrics endpoint takes unix ms.
$tz    = new DateTimeZone(REPORT_TZ);
$now   = new DateTime('now', $tz);
$start = (clone $now)->setTime(0, 0, 0);
$utc   = new DateTimeZone('UTC');
$startIso = (clone $start)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
$endIso   = (clone $now)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
$windowLabel = 'Today ' . $start->format('g:i a') . ' → ' . $now->format('g:i a T') . ' (' . $now->format('M j') . ')';

$enterEvent = ev_enter();

// --- Pull the on-form funnel (per-step view table + entered) ---
$viewEvents = array_map('ev_view', array_keys(FORM_STEPS));
$form       = by_event(umami_funnel_chained($apiKey, $websiteId, $viewEvents, $startIso, $endIso));
$entered    = (int) ($form[$enterEvent]['visitors'] ?? 0);

// --- Per-step "advanced": visitors who reached step N and then completed it ---
$advanced = [];
foreach (FORM_STEPS as $fieldSlug => $label) {
    $advanced[$fieldSlug] = terminal_visitors(
        $apiKey, $websiteId, [ev_view($fieldSlug), ev_complete($fieldSlug)], $startIso, $endIso
    );
}

// --- Abandon / resume totals (one call for every event name) ---
$eventTotals = umami_event_counts(
    $apiKey, $websiteId, $start->getTimestamp() * 1000, $now->getTimestamp() * 1000
);

// --- Landing → entry, and the terminal outcomes (unique visitors from entry) ---
[$landed, $enteredFromLanding] = funnel_ends($apiKey, $websiteId, [EV_LAND, $enterEvent], $startIso, $endIso);
$completed = terminal_visitors($apiKey, $websiteId, [$enterEvent, EV_COMPLETE], $startIso, $endIso);
$called    = terminal_visitors($apiKey, $websiteId, [EV_COMPLETE, EV_CALL], $startIso, $endIso);
$attempts  = terminal_visitors($apiKey, $websiteId, [$enterEvent, EV_ATTEMPT], $startIso, $endIso);

$startPct      = $landed    > 0 ? $enteredFromLanding / $landed : 0.0;
$completionPct = $entered   > 0 ? $completed / $entered    : 0.0;
$callPct       = $completed > 0 ? $called    / $completed  : 0.0;

// --- Per-step form table + biggest single in-form drop / worst abandonment ---
$rows      = [sprintf('%-2s %-19s %5s %5s %5s %5s  %s', '#', 'Step', 'Saw', 'Adv', 'Left', 'Back', 'Dropped from prev')];
$biggest   = null;
$worstLeft = null;
$prevLabel = null;
$n         = 0;
foreach (FORM_STEPS as $fieldSlug => $label) {
    $n++;
    $r        = $form[ev_view($fieldSlug)] ?? ['visitors' => 0, 'dropped' => 0, 'dropoff' => null];
    $visitors = (int) $r['visitors'];
    $dropped  = (int) $r['dropped'];
    $dropoff  = $r['dropoff']; // fraction or null (first step)
    $adv      = $advanced[$fieldSlug];
    $left     = $eventTotals[ev_abandon($fieldSlug)] ?? 0;
    $back     = $eventTotals[ev_resume($fieldSlug)]  ?? 0;

    $dropText = '';
    if ($dropoff !== null) {
        $dropText = sprintf('▼ %d (%s)', $dropped, pct((float) $dropoff));
        if ($biggest === null || $dropped > $biggest['dropped']) {
            $biggest = ['from' => $prevLabel, 'to' => $label, 'dropped' => $dropped, 'dropoff' => (float) $dropoff];
        }
    }
    if ($left > 0 && ($worstLeft === null || $left > $worstLeft['left'])) {
        $worstLeft = ['label' => $label, 'left' => $left];
    }

    $rows[]    = sprintf('%-2d %-19s %5d %5d %5d %5d  %s', $n, $label, $visitors, $adv, $left, $back, $dropText);
    $prevLabel = $label;
}
$tableText = "```\n" . implode("\n", $rows) . "\n```";
$lowSample = $entered < LOW_SAMPLE_MIN;

// The final step is where "advanced" earns its keep: saw the phone field vs
// actually pushed Submit. Everything above it is ≈ the next step's view count.
$lastField    = (string) array_key_last(FORM_STEPS);
$lastSaw      = (int) ($form[ev_view($lastField)]['visitors'] ?? 0);
$lastAdvanced = $advanced[$lastField];

// --- Compose the Slack message (Block Kit) ---
$summaryLines = [];
if ($landed > 0) {
    // Only meaningful once event_view_landing is deployed and recording; before
    // that the event simply isn't there and the line would read 0 → 0.
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

$blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Form steps*\n" . $tableText]];
$blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn',
    'text' => 'Saw = reached it · Adv = advanced from it (unique visitors) · Left = abandoned there · Back = returned to it (event counts)']]];

if ($biggest && $biggest['dropped'] > 0) {
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn',
        'text' => sprintf(':small_red_triangle_down: *Biggest in-form drop:* %s → %s  (−%d, %s)',
            $biggest['from'], $biggest['to'], $biggest['dropped'], pct($biggest['dropoff']))]];
}

if ($worstLeft) {
    $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn',
        'text' => sprintf(':door: *Most abandoned field:* %s  (%d left from here)', $worstLeft['label'], $worstLeft['left'])]];
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
