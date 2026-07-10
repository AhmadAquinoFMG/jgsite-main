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
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/firebase.php';
require __DIR__ . '/includes/equifax.php';

/* --------------------------------------------------------- method guard */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

/* --------------------------------------------------------------- helpers */
$post = fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

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

// SSN — 9 digits (for the Equifax credit pull). Used transiently; never stored
// on the lead. Only its digits are kept, in $ssnDigits.
$ssnDigits = preg_replace('/\D/', '', $post('ssn'));
if ($post('ssn') === '') {
    $errors['ssn'] = 'required';
} elseif (strlen($ssnDigits) !== 9) {
    $errors['ssn'] = 'invalid_ssn';
}

// Radio answers must match a configured option.
if (!in_array($debtAmount, $cfg['debt_options'], true))       $errors['debt_amount'] = 'invalid_option';
if (!in_array($employment, $cfg['employment_options'], true)) $errors['employment']  = 'invalid_option';
if (!in_array($income, $cfg['income_options'], true))         $errors['income']      = 'invalid_option';

if ($errors) {
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
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => ['phone' => 'not_verified']]);
        exit;
    }
    // The verified token's phone must match the submitted number.
    $tokenDigits = preg_replace('/\D/', '', $res['phone_number']);
    if (substr($tokenDigits, -10) !== $phoneDigits) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'errors' => ['phone' => 'not_verified']]);
        exit;
    }
    $phoneVerified = 1;
    $firebaseUid   = $res['uid'];
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
} catch (Throwable $ex) {
    error_log('[submit] lead insert failed: ' . $ex->getMessage());
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
    }
} catch (Throwable $ex) {
    error_log('[submit] equifax step failed (lead ' . $leadId . '): ' . $ex->getMessage());
}

// TODO(next phase): forward the lead to the CRM / LeadProsper here, then decide
// whether a forwarding failure should surface to the visitor or just be logged.

echo json_encode(['ok' => true]);
