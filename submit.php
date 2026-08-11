<?php

/**
 * Lead submission endpoint.
 *
 * Receives the funnel POST (assets/js/funnel.js), then:
 *   1. Validates every field server-side (never trusts the client).
 *   2. Captures TCPA proof-of-consent + attribution meta.
 *   3. Inserts one row into `leads`.
 *   4. Returns JSON: {ok:true} or {ok:false, errors:{field:code}}.
 *
 * After storing, best-effort (log & continue) side calls: an Equifax credit
 * pull (includes/equifax.php) and a LeadProsper direct-post (includes/leadprosper.php).
 * Neither can fail the submission — the lead is already stored by that point.
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/includes/logger.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/equifax.php';
require __DIR__ . '/includes/leadprosper.php';
require __DIR__ . '/includes/turnstile.php';
require __DIR__ . '/includes/redirect.php';
require __DIR__ . '/includes/zapier.php';

logger($cfg); // initialise the operational file logger

// Correlate every log line for this submission. random_bytes keeps it unique
// without leaking anything; falls back gracefully if the CSPRNG is unavailable.
try {
    $rid = bin2hex(random_bytes(6));
} catch (Throwable $e) {
    $rid = substr(md5((string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 12);
}

/* --------------------------------------------------------- method guard */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

/* --------------------------------------------------------------- helpers */

/**
 * Fold decorative Unicode back to plain letters — the server half of the
 * sanitiser in assets/js/funnel.js (see the long note there).
 *
 * A name pasted as "𝓈𝒶𝓂𝓅𝓁𝑒" is Mathematical Alphanumeric Symbols, not a font,
 * and would otherwise be stored and forwarded to Equifax/LeadProsper verbatim.
 * NFKC maps every such variant (𝓈, ｓ, ⓢ) back to "s" and leaves genuinely
 * accented letters alone, so José stays José.
 *
 * ext-intl is optional on shared hosts, so Normalizer is guarded: without it
 * the styled forms are still REJECTED by $nameRx below (they are Script=Common,
 * not Latin) — they just aren't repaired into something acceptable first.
 */
$fold = static function (string $v): string {
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($v, Normalizer::FORM_KC);
        if (is_string($n)) {
            $v = $n;
        }
    }
    // NFKC leaves these alone; the client rewrites them, so we must match.
    // Each preg_replace returns null on malformed UTF-8 — keep the input then
    // and let validation reject it rather than blanking the field.
    $v = preg_replace('/[\x{2018}\x{2019}\x{02BC}]/u', "'", $v) ?? $v;  // curly apostrophes
    $v = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $v) ?? $v;         // en/em dashes
    $v = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $v) ?? $v; // zero-width
    return $v;
};

$post = fn(string $k): string => $fold(trim((string) ($_POST[$k] ?? '')));

app_log('info', 'lead', 'received', ['rid' => $rid]);

/* --------------------------------------------------------- bot detection */
// Aggregates all three signals below (honeypot / timing / Turnstile). When
// set, the lead is still stored (flagged) but never reaches Equifax/LeadProsper,
// and the response still looks like a normal success — see the bottom of this
// file and docs/bot-protection.md for the full rationale.
$botReason = null;

if ($post('website') !== '') {
    $botReason = 'honeypot';
}

$renderedAt = (int) $post('form_rendered_at');
if (
    $botReason === null && $renderedAt > 0
    && (time() - $renderedAt) < (int) ($cfg['timing_min_seconds'] ?? 4)
) {
    $botReason = 'timing';
}

if ($botReason === null && !empty($cfg['turnstile']['enabled'])) {
    $ts = turnstile_verify($cfg, $post('cf-turnstile-response'), $_SERVER['REMOTE_ADDR'] ?? '');
    if (empty($ts['ok'])) {
        $botReason = 'turnstile';
    }
}

/**
 * Validate DOB (MM/DD/YYYY, real calendar date, age >= 18).
 * @return array{code:?string, iso:?string} code=null on success; iso is Y-m-d.
 */
$checkDob = function (string $v): array {
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m)) {
        return ['code' => 'incomplete', 'iso' => null];
    }
    [, $mo, $da, $yr] = array_map('intval', $m);
    if (!checkdate($mo, $da, $yr) || $yr < 1900) {
        return ['code' => 'out_of_range', 'iso' => null];
    }
    $dob = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $yr, $mo, $da));
    $now = new DateTimeImmutable('today');
    if ($dob > $now) {
        return ['code' => 'out_of_range', 'iso' => null];
    }
    $age = $now->diff($dob)->y;
    if ($age < 18) {
        return ['code' => 'underage', 'iso' => null];
    }
    return ['code' => null, 'iso' => $dob->format('Y-m-d')];
};

/* ------------------------------------------------------------ validation */
$errors = [];

$firstName  = $post('first_name');
$lastName   = $post('last_name');
$street     = $post('street');
$city       = $post('city');
$state      = strtoupper($post('state'));
$zip        = $post('zip');
$dobRaw     = $post('dob');
$email      = $post('email');
$phoneRaw   = $post('phone');
$debtAmount    = $post('debt_amount');
$behindPayment = $post('behind_payment');
$employment    = $post('employment');
$income        = $post('income');

// Accented letters are real names (José, Ñuñez, Łukasz), so this matches by
// SCRIPT rather than A–Z. That is also what keeps the decorative codepoints out:
// "𝓈𝒶𝓂" is Script=Common, not Latin, so it fails here even on a host without
// ext-intl to fold it, and on any client that skipped the browser entirely.
// \p{M} allows the combining accents that ride on a preceding letter — never as
// the first character. Keep in step with RX.name in assets/js/funnel.js.
$nameRx = "/^\\p{Latin}[\\p{Latin}\\p{M} .'\\-]{0,48}$/u";

if ($firstName === '')                       $errors['first_name'] = 'required';
elseif (!preg_match($nameRx, $firstName))    $errors['first_name'] = 'invalid_format';

if ($lastName === '')                        $errors['last_name'] = 'required';
elseif (!preg_match($nameRx, $lastName))     $errors['last_name'] = 'invalid_format';

if ($street === '')                          $errors['street'] = 'required';
elseif (mb_strlen($street) < 4)              $errors['street'] = 'too_short';

if ($city === '')                            $errors['city'] = 'required';
elseif (mb_strlen($city) < 2)                $errors['city'] = 'too_short';

if ($state === '')                           $errors['state'] = 'required';
elseif (!isset($cfg['states'][$state]))      $errors['state'] = 'invalid_format';

if ($zip === '')                             $errors['zip'] = 'required';
elseif (!preg_match('/^\d{5}$/', $zip))      $errors['zip'] = 'invalid_format';

// DOB
$dobIso = null;
if ($dobRaw === '') {
    $errors['dob'] = 'required';
} else {
    $d = $checkDob($dobRaw);
    if ($d['code'] !== null) $errors['dob'] = $d['code'];
    else                     $dobIso = $d['iso'];
}

// Email — well-formed local part + well-formed domain (mirrors checkEmail in JS).
// Any domain is accepted (no trusted-domain restriction).
if ($email === '') {
    $errors['email'] = 'required';
} else {
    $at = strrpos($email, '@');
    if ($at === false || $at < 1 || strpos($email, '@') !== $at) {
        $errors['email'] = 'invalid_email';
    } else {
        $local  = substr($email, 0, $at);
        $domain = strtolower(substr($email, $at + 1));
        if (
            !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._%+\-]*[A-Za-z0-9])?$/', $local)
            || strpos($local, '..') !== false
        ) {
            $errors['email'] = 'invalid_email';
        } elseif (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,}$/', $domain)) {
            $errors['email'] = 'invalid_email';
        }
    }
}

// Phone — 10 digits; stored E.164 (+1XXXXXXXXXX).
$phoneDigits = preg_replace('/\D/', '', $phoneRaw);
$phoneE164   = '';
if ($phoneRaw === '') {
    $errors['phone'] = 'required';
} elseif (strlen($phoneDigits) !== 10) {
    $errors['phone'] = 'invalid_length';
} else {
    $phoneE164 = '+1' . $phoneDigits;
}

// SSN is no longer collected in the funnel. If a value is ever posted (e.g. a
// future re-add), pass its digits through to Equifax; otherwise this is empty
// and the credit pull runs without an SSN. Never validated or stored on the lead.
$ssnDigits = preg_replace('/\D/', '', $post('ssn'));

// Radio answers must match a configured option.
if (!in_array($debtAmount, $cfg['debt_options'], true))              $errors['debt_amount']    = 'invalid_option';
if (!array_key_exists($behindPayment, $cfg['behind_payment_options'])) $errors['behind_payment'] = 'invalid_option';
if (!array_key_exists($employment, $cfg['employment_options']))      $errors['employment']     = 'invalid_option';
if (!in_array($income, $cfg['income_options'], true))                $errors['income']         = 'invalid_option';

if ($errors) {
    // Log which fields failed (names + codes only, never the submitted values).
    app_log('warning', 'lead', 'validation_failed', ['rid' => $rid, 'fields' => $errors]);
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

/* --------------------------------------------------------- capture meta */
$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';        // Cloudways sits behind a proxy
$ip  = $xff !== '' ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$canonical_host = $_SERVER['HTTP_HOST'] ?? 'https://www.jgdebtrelief.com/';

$row = [
    'debt_amount'        => $debtAmount,
    'self_assessed_debt' => $debtAmount, // the visitor's self-reported figure
    'behind_payment'  => $behindPayment,
    'employment'      => $employment,
    'income'          => $income,
    'first_name'      => $firstName,
    'last_name'       => $lastName,
    'street'          => $street,
    'city'            => $city,
    'state'           => $state,
    'zip'             => $zip,
    'dob'             => $dobIso,
    'email'           => $email,
    'phone'           => $phoneE164,
    'product'         => $post('product') ?: null,
    'form_name'       => $post('form_name') ?: null,
    'trustedform_url' => $post('xxTrustedFormCertUrl') ?: null,
    'jornaya_token'   => $post('universal_leadid') ?: null,
    'consent_text'    => $cfg['consent']['tcpa'] ?? null,
    'consent_at'      => date('Y-m-d H:i:s'),
    'ip'              => $ip ?: null,
    'user_agent'      => $userAgent ?: null,
    'bot_suspected'   => $botReason !== null ? 1 : 0,
    'bot_reason'      => $botReason,
    'utm_source'      => $post('utm_source') ?: null,
    'utm_medium'      => $post('utm_medium') ?: null,
    'utm_campaign'    => $post('utm_campaign') ?: null,
    'utm_term'        => $post('utm_term') ?: null,
    'utm_content'     => $post('utm_content') ?: null,
    'utm_creative'    => $post('utm_creative') ?: null,
    'utm_placement'   => $post('utm_placement') ?: null,
    'utm_adgroup'     => $post('utm_adgroup') ?: null,
    'utm_matchtype'   => $post('utm_matchtype') ?: null,
    'gclid'           => $post('gclid') ?: null,
    'gbraid'          => $post('gbraid') ?: null,
    'fbclid'          => $post('fbclid') ?: null,
    'fbp'             => $post('fbp') ?: null,
    'fbc'             => $post('fbc') ?: null,
    'fb_adid'         => $post('fb_adid') ?: null,
    'ms_placement'    => $post('ms_placement') ?: null,
    'ms_publisher'    => $post('ms_publisher') ?: null,
    'ttclid'          => $post('ttclid') ?: null,
    'subid'           => $post('subid') ?: null,
    'affid'           => $post('affid') ?: null,
    'oid'             => $post('oid') ?: null,
    'source_id'       => $post('source_id') ?: null,
    'sub1'            => $post('sub1') ?: null,
    'sub2'            => $post('sub2') ?: null,
    'sub3'            => $post('sub3') ?: null,
    'sub4'            => $post('sub4') ?: null,
    'sub5'            => $post('sub5') ?: null,
    'ef_transaction_id' => $post('ef_transaction_id') ?: null,
    'lp_subid1'       => $post('sub1') ?: null,
    'lp_subid2'       => $post('sub2') ?: null,
    'lp_subid3'       => $post('sub3') ?: null,
    'lp_subid4'       => $post('sub4') ?: null,
    'lp_subid5'       => $post('sub5') ?: null,
    'adv1'            => $post('adv1') ?: null,
    'adv2'            => $post('adv2') ?: null,
    'adv3'            => $post('adv3') ?: null,
    'adv4'            => $post('adv4') ?: null,
    'adv5'            => $post('adv5') ?: null,
    'landing_page_url'  => $canonical_host
];

/* -------------------------------------------------------------- persist */
try {
    $cols = array_keys($row);
    $sql  = 'INSERT INTO leads (' . implode(', ', $cols) . ') VALUES (:'
        . implode(', :', $cols) . ')';
    $pdo  = db($cfg);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($row);
    $leadId = (int) $pdo->lastInsertId();
    app_log('info', 'lead', 'stored', [
        'rid' => $rid,
        'lead_id' => $leadId,
        'state' => $row['state'],
    ]);
} catch (Throwable $ex) {
    app_log('error', 'lead', 'insert_failed', [
        'rid'   => $rid,
        'error' => $ex->getMessage(),
        'file'  => $ex->getFile(),
        'line'  => $ex->getLine(),
    ]);
    http_response_code(500);
    // rid always ships (safe to expose — no PII) so the client-visible error
    // can be matched to the full trace in logs/*.log. The raw exception detail
    // is included too, but ONLY outside production — never leak DB/host info
    // (connection strings, table names) to a real visitor.
    $body = ['ok' => false, 'error' => 'server_error', 'rid' => $rid];
    if (($cfg['app_env'] ?? 'production') !== 'production') {
        $body['detail'] = $ex->getMessage();
    }
    echo json_encode($body);
    exit;
}

/* ---------------------------------------- Equifax credit report (best-effort)
   Pull the report and log the request/response to equifax_logs. This is "log &
   continue": ANY failure here (Equifax down, bad config, DB write) is swallowed
   so the lead — already stored — still succeeds. */
$verifiedTotalDebt = null; // read by the LeadProsper post below
if ($botReason === null) {
    try {
        $eqLead = array_intersect_key($row, array_flip(
            ['first_name', 'last_name', 'street', 'city', 'state', 'zip', 'email']
        ));
        $eqLead['dob'] = $dobIso;

        $eq = equifax_pull($cfg, $eqLead, $ssnDigits);
        if (empty($eq['skip'])) {
            $log = [
                'lead_id'         => $leadId,
                'mode'            => $eq['mode'],
                'request_url'     => $eq['request_url'],
                'request_body'    => $eq['request_body'],
                'response_status' => $eq['response_status'],
                'response_body'   => $eq['response_body'],
                'score'           => $eq['score'],
                'decision'        => $eq['decision'],
                'error'           => $eq['error'],
                'duration_ms'     => $eq['duration_ms'],
            ];
            $cols = array_keys($log);
            $sql  = 'INSERT INTO equifax_logs (' . implode(', ', $cols) . ') VALUES (:'
                . implode(', :', $cols) . ')';
            db($cfg)->prepare($sql)->execute($log);

            // Denormalize the outcome onto the lead row for quick per-lead visibility
            // (the full request/response bodies stay in equifax_logs). No SSN here.
            db($cfg)->prepare(
                'UPDATE leads SET equifax_mode = :mode, equifax_status = :status,
                    equifax_score = :score, equifax_decision = :decision,
                    equifax_error = :error, equifax_pulled_at = :pulled_at,
                    total_debt = :total_debt
             WHERE id = :id'
            )->execute([
                'mode'       => $eq['mode'],
                'status'     => $eq['response_status'],
                'score'      => $eq['score'],
                'decision'   => $eq['decision'],
                'error'      => $eq['error'],
                'pulled_at'  => date('Y-m-d H:i:s'),
                'total_debt' => $eq['total_debt'] ?? null,
                'id'         => $leadId,
            ]);
            $verifiedTotalDebt = is_numeric($eq['total_debt'] ?? null) ? (int) $eq['total_debt'] : null;

            // Ops log: outcome only — no SSN, no request/response bodies (those live
            // in equifax_logs). Correlated to the lead via rid + lead_id.
            app_log($eq['error'] ? 'error' : 'info', 'equifax', 'pull', [
                'rid'      => $rid,
                'lead_id'  => $leadId,
                'mode'     => $eq['mode'],
                'status'   => $eq['response_status'],
                'score'    => $eq['score'],
                'duration' => $eq['duration_ms'],
                'error'    => $eq['error'],
            ]);
        } else {
            app_log('debug', 'equifax', 'skipped', ['rid' => $rid, 'lead_id' => $leadId]);
        }
    } catch (Throwable $ex) {
        app_log('error', 'equifax', 'step_failed', ['rid' => $rid, 'lead_id' => $leadId, 'error' => $ex->getMessage()]);
    }
}

/* ---------------------------------------- LeadProsper direct-post (best-effort)
   Post the lead and log the request/response to leadprosper_logs. Same "log &
   continue" contract as Equifax above — a forwarding failure is logged but
   never surfaced to the visitor; the lead is already stored. */
if ($botReason === null) {
    try {
        $tracking = array_intersect_key($row, array_flip(LEADPROSPER_TRACKING_PARAMS));
        // Not a posted field — reflects whether OUR OWN Equifax pull above (not an
        // upstream one) returned a usable verified total debt.
        $tracking['softpull_returned'] = $verifiedTotalDebt !== null ? '1' : '0';

        $lp = leadprosper_submit($cfg, $row, $tracking, $verifiedTotalDebt);
        if (empty($lp['skip'])) {
            db($cfg)->prepare(
                'INSERT INTO leadprosper_logs (lead_id, mode, request_body, response_status, response_body, accepted, error, duration_ms)
             VALUES (:lead_id, :mode, :request_body, :response_status, :response_body, :accepted, :error, :duration_ms)'
            )->execute([
                'lead_id'         => $leadId,
                'mode'            => $lp['mode'],
                'request_body'    => $lp['sent'],
                'response_status' => $lp['status'],
                'response_body'   => $lp['response'],
                'accepted'        => $lp['ok'] ? 1 : 0,
                'error'           => $lp['error'],
                'duration_ms'     => $lp['duration_ms'],
            ]);

            db($cfg)->prepare(
                'UPDATE leads SET lp_mode = :mode, lp_status = :status, lp_accepted = :accepted,
                    lp_error = :error, lp_posted_at = :posted_at
             WHERE id = :id'
            )->execute([
                'mode'      => $lp['mode'],
                'status'    => $lp['status'],
                'accepted'  => $lp['ok'] ? 1 : 0,
                'error'     => $lp['error'],
                'posted_at' => date('Y-m-d H:i:s'),
                'id'        => $leadId,
            ]);

            app_log($lp['error'] ? 'error' : 'info', 'leadprosper', 'post', [
                'rid'      => $rid,
                'lead_id'  => $leadId,
                'mode'     => $lp['mode'],
                'status'   => $lp['status'],
                'accepted' => $lp['ok'],
                'duration' => $lp['duration_ms'],
                'error'    => $lp['error'],
            ]);
        } else {
            app_log('debug', 'leadprosper', 'skipped', ['rid' => $rid, 'lead_id' => $leadId, 'reason' => $lp['error']]);
        }
    } catch (Throwable $ex) {
        app_log('error', 'leadprosper', 'step_failed', ['rid' => $rid, 'lead_id' => $leadId, 'error' => $ex->getMessage()]);
    }
}

/* ---------------------------------------- Zapier lead push (best-effort)
   Posts the lead so the CallGrid call webhook can be joined back to it on the
   caller's phone number. Runs after the Equifax pull so the verified total debt
   rides along, and outside the bot guard's block for the same reason the rest
   of this file skips suspected bots — see below.

   Same log & continue contract as the two steps above: a Zapier outage must
   never cost us a lead that is already stored. */
if ($botReason === null) {
    try {
        $zap = zapier_send_lead($cfg, $row, $leadId, $verifiedTotalDebt);
        if ($zap['skip']) {
            app_log('debug', 'zapier', 'skipped', ['rid' => $rid, 'lead_id' => $leadId]);
        } else {
            app_log($zap['ok'] ? 'info' : 'error', 'zapier', 'lead_push', [
                'rid'      => $rid,
                'lead_id'  => $leadId,
                'status'   => $zap['status'],
                'duration' => $zap['duration_ms'],
                'error'    => $zap['error'],
            ]);
        }
    } catch (Throwable $ex) {
        app_log('error', 'zapier', 'step_failed', ['rid' => $rid, 'lead_id' => $leadId, 'error' => $ex->getMessage()]);
    }
}

/* ---------------------------------------- estimated savings (thank-you page)
   40% of the debt used for the LeadProsper post: the Equifax-verified total
   when the pull succeeded, otherwise the same self-reported bucket estimate
   LeadProsper itself falls back to (leadprosper_debt_bucket_amount()). */
$debtForSavings   = $verifiedTotalDebt ?? leadprosper_debt_bucket_amount((string) $row['debt_amount']);
$estimatedSavings = (int) round($debtForSavings * 0.4);

// Handed to thank-you.php via session, not the redirect URL, so the visitor
// can't edit/replay it by hand. Persists across reloads of thank-you.php;
// index.php clears it when the funnel is started over.
$_SESSION['prequal_savings'] = $estimatedSavings;

/* ---------------------------------------- Everflow conversion handoff
   thank-you.php fires the Everflow conversion, and needs the affid to know
   which offer it belongs to (see includes/everflow.php). Passed through the
   session for the same reason as the savings figure — out of the URL, so a
   conversion can't be re-pointed at another offer by hand.

   Deliberately one-shot: thank-you.php unsets these after emitting the snippet,
   so a reload or bookmark of the thank-you page can't fire a second conversion
   for the same lead. No affid means no key set, and no conversion. */
if (($row['affid'] ?? null) !== null && trim((string) $row['affid']) !== '') {
    $_SESSION['ef_conversion'] = [
        'affid'          => (string) $row['affid'],
        'transaction_id' => (string) ($row['ef_transaction_id'] ?? ''),
    ];
}

if ($botReason !== null) {
    app_log('warning', 'lead', 'bot_suspected', ['rid' => $rid, 'lead_id' => $leadId, 'reason' => $botReason]);
}

/* ---------------------------------------- redirect URL (built server-side)
   The consumer's answers reach the redirect only after passing through here:
   validated, normalised, stored. redirect_build_url() appends them from $row —
   the server's copy — so the query string can't carry anything the client made
   up, and the values are the canonical ones (E.164 phone, ISO dob, int debt).

   funnel.js follows the `redirect` it gets back rather than hardcoding the
   destination; the Location header below covers the no-JS native POST, which
   would otherwise land the visitor on raw JSON. */

/* Two values the redirect can forward that aren't posted fields, so they were
   never in $row: the row's own id, and the Equifax-verified total debt. Added
   here — after the insert and after the credit pull — purely so the config map
   can name them like any other field. Nothing downstream re-reads $row.

   total_debt stays null when the pull didn't return a usable figure; the
   builder drops empty values, so the param is simply absent rather than
   carrying the self-reported bucket estimate under a "verified" name. */
$row['lead_id']    = $leadId;
$row['total_debt'] = $verifiedTotalDebt;

$redirectUrl = redirect_build_url($row, $cfg['redirect'] ?? []);

app_log('info', 'lead', 'redirect_built', [
    'rid'     => $rid,
    'lead_id' => $leadId,
    // Path and param names only — the values are the consumer's PII and have no
    // business in a log file.
    'target'  => explode('?', $redirectUrl)[0],
    'params'  => array_keys($cfg['redirect']['params'] ?? []),
]);

$wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

if (!$wantsJson) {
    // 303: turn the POST into a GET so a back/refresh can't re-submit the lead.
    http_response_code(303);
    header('Location: ' . $redirectUrl);
    exit;
}

echo json_encode(['ok' => true, 'redirect' => $redirectUrl]);
