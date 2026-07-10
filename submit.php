<?php

/**
 * Lead submission endpoint.
 *
 * Receives the funnel POST (assets/js/funnel.js), then:
 *   1. Validates every field server-side (never trusts the client).
 *   2. Verifies the Firebase phone-auth ID token (skipped when app_env=local
 *      or no Firebase project is configured — dev convenience).
 *   3. Captures TCPA proof-of-consent + attribution meta.
 *   4. Inserts one row into `leads`.
 *   5. Returns JSON: {ok:true} or {ok:false, errors:{field:code}}.
 *
 * Next phase: forward the lead to the CRM / LeadProsper here (see TODO below).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/includes/logger.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/firebase.php';
require __DIR__ . '/includes/equifax.php';

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
$post = fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

app_log('info', 'lead', 'received', ['rid' => $rid]);

// Trusted consumer email domains — mirrors TRUSTED_EMAIL_DOMAINS in funnel.js.
$TRUSTED_EMAIL_DOMAINS = [
    'gmail.com', 'googlemail.com',
    'yahoo.com', 'ymail.com', 'rocketmail.com',
    'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
    'icloud.com', 'me.com', 'mac.com',
    'aol.com',
    'proton.me', 'protonmail.com',
    'comcast.net', 'verizon.net', 'att.net', 'sbcglobal.net', 'cox.net',
];

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
$debtAmount = $post('debt_amount');
$employment = $post('employment');
$income     = $post('income');

$nameRx = "/^[A-Za-z][A-Za-z .'\\-]{0,48}$/";

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

// Email — well-formed local part + trusted domain (mirrors checkEmail in JS).
if ($email === '') {
    $errors['email'] = 'required';
} else {
    $at = strrpos($email, '@');
    if ($at === false || $at < 1 || strpos($email, '@') !== $at) {
        $errors['email'] = 'invalid_email';
    } else {
        $local  = substr($email, 0, $at);
        $domain = strtolower(substr($email, $at + 1));
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._%+\-]*[A-Za-z0-9])?$/', $local)
            || strpos($local, '..') !== false) {
            $errors['email'] = 'invalid_email';
        } elseif (!in_array($domain, $TRUSTED_EMAIL_DOMAINS, true)) {
            $errors['email'] = 'untrusted_domain';
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
if (!in_array($debtAmount, $cfg['debt_options'], true))       $errors['debt_amount'] = 'invalid_option';
if (!in_array($employment, $cfg['employment_options'], true)) $errors['employment']  = 'invalid_option';
if (!in_array($income, $cfg['income_options'], true))         $errors['income']      = 'invalid_option';

if ($errors) {
    // Log which fields failed (names + codes only, never the submitted values).
    app_log('warning', 'lead', 'validation_failed', ['rid' => $rid, 'fields' => $errors]);
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

/* --------------------------------------------- Firebase phone verification */
$phoneVerified = 0;
$firebaseUid   = null;

$projectId  = (string) ($cfg['firebase']['project_id'] ?? '');
$verifyReqd = ($cfg['app_env'] ?? 'production') !== 'local' && $projectId !== '';

if ($verifyReqd) {
    $res = verify_firebase_token($post('id_token'), $projectId);
    if (!$res['ok']) {
        app_log('warning', 'firebase', 'verify_failed', ['rid' => $rid, 'reason' => $res['error'] ?? 'unknown']);
        http_response_code(422);
        // `detail` names the exact verification failure (non-sensitive) to aid
        // debugging; visible in the Network response. Safe to remove later.
        echo json_encode(['ok' => false, 'errors' => ['phone' => 'not_verified'], 'detail' => $res['error'] ?? 'unknown']);
        exit;
    }
    // The verified token's phone must match the submitted number.
    $tokenDigits = preg_replace('/\D/', '', $res['phone_number']);
    if (substr($tokenDigits, -10) !== $phoneDigits) {
        app_log('warning', 'firebase', 'phone_mismatch', ['rid' => $rid]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => ['phone' => 'not_verified']]);
        exit;
    }
    app_log('info', 'firebase', 'verified', ['rid' => $rid]);
    $phoneVerified = 1;
    $firebaseUid   = $res['uid'];
} else {
    app_log('debug', 'firebase', 'verify_skipped', ['rid' => $rid, 'env' => $cfg['app_env'] ?? 'production']);
}

/* --------------------------------------------------------- capture meta */
$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';        // Cloudways sits behind a proxy
$ip  = $xff !== '' ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$row = [
    'debt_amount'     => $debtAmount,
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
    'phone_verified'  => $phoneVerified,
    'firebase_uid'    => $firebaseUid,
    'trustedform_url' => $post('xxTrustedFormCertUrl') ?: null,
    'jornaya_token'   => $post('universal_leadid') ?: null,
    'consent_text'    => $cfg['consent']['tcpa'] ?? null,
    'consent_at'      => date('Y-m-d H:i:s'),
    'ip'              => $ip ?: null,
    'user_agent'      => $userAgent ?: null,
    'utm_source'      => $post('utm_source') ?: null,
    'utm_medium'      => $post('utm_medium') ?: null,
    'utm_campaign'    => $post('utm_campaign') ?: null,
    'utm_term'        => $post('utm_term') ?: null,
    'utm_content'     => $post('utm_content') ?: null,
    'gclid'           => $post('gclid') ?: null,
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
        'rid' => $rid, 'lead_id' => $leadId,
        'state' => $row['state'], 'phone_verified' => $phoneVerified,
    ]);
} catch (Throwable $ex) {
    app_log('error', 'lead', 'insert_failed', ['rid' => $rid, 'error' => $ex->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
    exit;
}

/* ---------------------------------------- Equifax credit report (best-effort)
   Pull the report and log the request/response to equifax_logs. This is "log &
   continue": ANY failure here (Equifax down, bad config, DB write) is swallowed
   so the lead — already stored — still succeeds. */
try {
    $eqLead = array_intersect_key($row, array_flip(
        ['first_name', 'last_name', 'street', 'city', 'state', 'zip']
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

// TODO(next phase): forward the lead to the CRM / LeadProsper here, then decide
// whether a forwarding failure should surface to the visitor or just be logged.

echo json_encode(['ok' => true]);
