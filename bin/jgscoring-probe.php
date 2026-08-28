<?php

/**
 * JG scoring probe — fire ONE scoring call from the CLI and print the result.
 *
 * Exists because the only other way to exercise includes/jgscoring.php is a
 * full funnel submission (Turnstile, timing gate, a stored lead, a LeadProsper
 * post), which is a lot of moving parts to debug one HTTP header through.
 *
 * Usage, from the project root:
 *
 *   php bin/jgscoring-probe.php              # mock — no network, no lead
 *   php bin/jgscoring-probe.php --live       # ⚠ REAL CALL, REAL LEAD AT JG
 *   php bin/jgscoring-probe.php --live --show-request
 *
 * --live is required to reach the network: this endpoint is JG's lead INTAKE,
 * so every live call creates a real lead on their side. There is no dry run.
 * Defaulting to mock means a mistyped command costs nothing.
 *
 * Nothing is written to the database — this calls the client directly and skips
 * submit.php's jgscoring_logs / leads.jgw_* writes entirely. It answers "does
 * the call work", not "does the pipeline work".
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/leadprosper.php';
require $root . '/includes/jgscoring.php';

$argvFlags   = array_slice($argv, 1);
$live        = in_array('--live', $argvFlags, true);
$showRequest = in_array('--show-request', $argvFlags, true);

/* A deliberately obvious test identity — and one JG's quality screen is
   EXPECTED to reject ("Rejected - Lead Quality", no total_debt_included). That
   is the point: it proves the credentials and the payload shape without putting
   a usable lead into JG's pipeline, and it is unmistakable in their CRM.

   So a rejection here is a PASS for auth. To exercise the happy path and see a
   real total_debt_included, override the identity with details that survive a
   quality screen:

     JGPROBE_FIRST=Jane JGPROBE_LAST=Doe JGPROBE_PHONE=+12145551234
     JGPROBE_STREET="4512 Oak Lawn Ave" JGPROBE_EMAIL=jane@example.com
     php bin/jgscoring-probe.php --live

   Never reuse a real consumer's details from a past payload — that posts a
   duplicate of an actual person. */
$row = [
    'debt_amount'     => getenv('JGPROBE_DEBT') ?: '$25,000 - $49,999',
    'behind_payment'  => 'over_30',
    'employment'      => getenv('JGPROBE_EMPLOYMENT') ?: 'employed',
    'income'          => 'Between $30,000 and $100,000',
    'first_name'      => getenv('JGPROBE_FIRST') ?: 'Qatest',
    'last_name'       => getenv('JGPROBE_LAST') ?: 'Fmgprobe',
    'street'          => getenv('JGPROBE_STREET') ?: '1 Test Street',
    'city'            => getenv('JGPROBE_CITY') ?: 'Dallas',
    'state'           => getenv('JGPROBE_STATE') ?: 'TX',
    'zip'             => getenv('JGPROBE_ZIP') ?: '75001',
    'dob'             => getenv('JGPROBE_DOB') ?: '1980-04-12',
    'email'           => getenv('JGPROBE_EMAIL') ?: 'qa+jgprobe@fitzmediagroup.com',
    'phone'           => getenv('JGPROBE_PHONE') ?: '+15125550142',
    'ip'              => '127.0.0.1',
    'trustedform_url' => '',
    'utm_campaign'    => 'fmg_jgscoring_probe',
];

$c = $cfg;
$c['jgscoring']['mode'] = $live ? 'live' : 'mock';
$jg = $c['jgscoring'];

$token  = trim((string) ($jg['token'] ?? ''));
$scheme = trim((string) ($jg['auth_scheme'] ?? ''));
$masked = $token === '' ? '(EMPTY)' : substr($token, 0, 6) . '…' . substr($token, -4) . ' (' . strlen($token) . ' chars)';

echo "mode      : " . $jg['mode'] . ($live ? "  ⚠ this creates a REAL lead at JG\n" : "  (no network, no lead)\n");
echo "endpoint  : " . $jg['endpoint'] . "\n";
echo "auth      : Authorization: " . ($scheme !== '' ? $scheme . ' ' : '') . $masked . "\n";
if ($scheme === '') {
    echo "            ↑ no scheme. If JG answers 401 \"Authentication credentials were\n";
    echo "              not provided\", their API is Django REST Framework and wants\n";
    echo "              JGSCORING_AUTH_SCHEME=Token\n";
}
echo "identity  : {$row['first_name']} {$row['last_name']} <{$row['email']}> {$row['phone']}\n\n";

if ($showRequest) {
    echo "--- request body ---\n";
    echo json_encode(jgscoring_payload($c, $row), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
}

$t0 = microtime(true);
$r  = jgscoring_score($c, $row);
$ms = (int) round((microtime(true) - $t0) * 1000);

if (!empty($r['skip'])) {
    echo "SKIPPED — jgscoring.mode is 'off'. Set JGSCORING_MODE in .env.\n";
    exit(1);
}

echo "--- response ---\n";
echo "http status : {$r['response_status']}  ({$r['duration_ms']}ms, {$ms}ms wall)\n";
echo "error       : " . var_export($r['error'], true) . "\n";
echo "raw body    : " . ($r['response_body'] ?? '(none)') . "\n\n";

echo "--- parsed ---\n";
foreach ([
    'total_debt', 'prequalified', 'accepted', 'disposition', 'credit_rating',
    'jgw_id', 'external_id',
    'estimated_program_length', 'estimated_monthly_payment', 'estimated_biweekly_payment',
] as $k) {
    printf("  %-27s %s\n", $k, var_export($r[$k], true));
}

/* The verdict the caller actually cares about. A 2xx with no
   total_debt_included is NOT a failure — a lead that doesn't prequalify has no
   figure — so the two are reported separately. */
echo "\n";
if ($r['error'] !== null) {
    echo "RESULT: CALL FAILED ({$r['error']}).\n";
    if ($r['response_status'] === 401 || $r['response_status'] === 403) {
        echo "        Auth problem. Check JGSCORING_TOKEN and JGSCORING_AUTH_SCHEME.\n";
    }
    exit(1);
}
echo $r['total_debt'] !== null
    ? "RESULT: OK — verified total debt {$r['total_debt']}. submit.php would post this\n"
      . "        to LeadProsper as total_debt and store it on leads.total_debt.\n"
    : "RESULT: OK — JG answered, but returned no total_debt_included (disposition: "
      . var_export($r['disposition'], true) . "). submit.php would post total_debt=0\n"
      . "        with softpull_returned=0.\n";
