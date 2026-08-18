<?php

/**
 * JG Wentworth Lead Scoring API — client + logger (no Composer).
 *
 * submit.php calls jgscoring_submit() AFTER the lead is stored and AFTER the
 * Equifax pull, but BEFORE the LeadProsper post — because the figure this
 * returns (`total_debt_included`) is what gets posted to LeadProsper as
 * `total_debt`. JG runs their own credit pull (`ok_to_pull_credit: true`) and
 * their own settleable-debt classification, so their number wins over our
 * Equifax-derived one; ours stays the fallback for when this call is off,
 * fails, or returns nothing usable.
 *
 * Same "log & continue" contract as includes/equifax.php and
 * includes/leadprosper.php: whatever happens (success, HTTP error, timeout,
 * misconfig) is returned as a structured result the caller writes to
 * `jgscoring_logs`, and the lead submission still succeeds.
 *
 * Modes (config jgscoring.mode):
 *   'off'  → returns ['skip' => true]; caller writes no log row (default).
 *   'mock' → returns a synthetic response (no network); caller DOES log it.
 *   'live' → real POST to the scoring endpoint with the API token.
 *
 * ⚠ DUPLICATE LEADS: this endpoint is JG's LEAD INTAKE — the only API they
 * expose — so every call creates a real lead on their side. JG is also a buyer
 * on the LeadProsper campaign, so running both delivers the same consumer twice
 * and LeadProsper's copy (the one that pays) is rejected "duplicated by buyer".
 * The guard in jgscoring_submit() therefore skips the call whenever LeadProsper
 * is posting live, unless JGW_ALLOW_WITH_LEADPROSPER=1 declares that JG has been
 * removed from the LP campaign and is sold direct instead. Ships mode 'off'.
 *
 * ⚠ QA test visits (?test=fmg_true) never call this endpoint — see
 * jgscoring_submit(). The API has no documented test flag, so a QA submission
 * would create a real lead in JG's system.
 */

if (!function_exists('jgscoring_submit')) {

    /**
     * Build the scoring payload. Shape is fixed by JG's API — the field names
     * below (including their spelling) are theirs, not ours.
     *
     * @param array    $cfg        Full config array (reads $cfg['jgscoring']).
     * @param array    $row        The exact row inserted into `leads` (submit.php's $row).
     * @param int|null $debtAmount Debt figure for additional_fields.debt_amount:
     *                             our Equifax-verified unsecured total when the
     *                             pull returned one, else the self-reported
     *                             bucket estimate. Never null in practice, but
     *                             tolerated (posts as '').
     */
    function jgscoring_payload(array $cfg, array $row, ?int $debtAmount): array
    {
        $jg = $cfg['jgscoring'] ?? [];

        // Phone is stored E.164 (+1XXXXXXXXXX); JG wants bare 10 digits.
        $phone = preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
        if (strlen($phone) === 11 && $phone[0] === '1') {
            $phone = substr($phone, 1);
        }

        // Our stored employment key ('employed' | 'unemployed' | 'disability' |
        // 'retired') mapped to JG's vocabulary. Unmapped values pass through
        // rather than being dropped — JG echoes what it can't interpret.
        $employment    = (string) ($row['employment'] ?? '');
        $employmentMap = (array) ($jg['employment_map'] ?? []);
        $employmentOut = $employmentMap[$employment] ?? $employment;

        $additional = [
            'campaign_id'          => (string) ($jg['campaign_id'] ?? 'JG Debt Relief'),
            'utm_source' => (string) ($jg['utm_source'] ?? (
                'LP-' . ['Posted', 'Sent', 'Direct', 'Route'][array_rand(['Posted', 'Sent', 'Direct', 'Route'])] . '-' . mt_rand(1000, 9999)
            )),
            'lead_source_detail'   => (string) ($jg['lead_source_detail'] ?? ''),
            'lead_source'          => (string) ($jg['lead_source'] ?? ''),
            // JG's sample posts income empty even when the funnel collected it,
            // so it stays empty unless jgscoring.send_income is switched on.
            'income'               => !empty($jg['send_income']) ? (string) ($row['income'] ?? '') : '',
            'debt_amount'          => $debtAmount !== null ? (string) $debtAmount : '',
            // [sic] — JG's field really is spelled "employement_status".
            // Correcting it here would just make the value invisible to them.
            'employement_status'   => $employmentOut,
            'client_ip'            => (string) ($row['ip'] ?? ''),
            'campaign_source'      => (string) ($jg['campaign_source'] ?? ''),
            'trustedform_cert_url' => (string) ($row['trustedform_url'] ?? ''),
        ];

        return [
            // Every key JG's schema defines is sent, empty ones included — this
            // is a fixed-shape API, so array_filter() is deliberately NOT used
            // (unlike the LeadProsper payload, where unknown/blank keys are
            // rejected). Missing keys read as absent rather than "no value".
            'additional_fields' => $additional,
            'ok_to_pull_credit' => true,
            'first_name'        => (string) ($row['first_name'] ?? ''),
            'last_name'         => (string) ($row['last_name'] ?? ''),
            'email_address'     => (string) ($row['email'] ?? ''),
            'phone_number'      => $phone,
            'date_of_birth'     => (string) ($row['dob'] ?? ''), // already Y-m-d
            'address'           => (string) ($row['street'] ?? ''),
            'city'              => (string) ($row['city'] ?? ''),
            'state'             => strtoupper((string) ($row['state'] ?? '')),
            'zip'               => (string) ($row['zip'] ?? ''),
        ];
    }

    /**
     * Post the lead to JG's scoring API and parse the result. Never throws.
     *
     * @param array    $cfg        Full config array.
     * @param array    $row        The stored lead row.
     * @param int|null $debtAmount Debt figure to send (see jgscoring_payload()).
     * @param string   $userAgent  The visitor's User-Agent, forwarded so JG sees
     *                             the real client. Falls back to config
     *                             jgscoring.user_agent when empty.
     * @param bool     $isTest     True for a QA test visit — skips the call
     *                             entirely (the API has no test mode).
     *
     * @return array{skip:bool, ok:bool, mode:string, status:int, request_body:?string,
     *               response_body:?string, total_debt:?int, prequalified:?bool,
     *               accepted:?bool, disposition:?string, credit_rating:?string,
     *               jgw_id:?string, external_id:?string, estimated_program_length:?int,
     *               estimated_monthly_payment:?float, error:?string, duration_ms:int}
     */
    function jgscoring_submit(array $cfg, array $row, ?int $debtAmount, string $userAgent = '', bool $isTest = false): array
    {
        $jg   = $cfg['jgscoring'] ?? [];
        $mode = strtolower((string) ($jg['mode'] ?? 'off'));

        $result = [
            'skip'                      => false,
            'ok'                        => false,
            'mode'                      => $mode,
            'status'                    => 0,
            'request_body'              => null,
            'response_body'             => null,
            'total_debt'                => null,
            'prequalified'              => null,
            'accepted'                  => null,
            'disposition'               => null,
            'credit_rating'             => null,
            'jgw_id'                    => null,
            'external_id'               => null,
            'estimated_program_length'  => null,
            'estimated_monthly_payment' => null,
            'error'                     => null,
            'duration_ms'               => 0,
        ];

        if ($mode === 'off') {
            $result['skip'] = true;
            return $result;
        }

        /* A QA test visit must never reach the live endpoint. Unlike
           LeadProsper (lp_action=test) there is no way to tell JG "score this
           but don't keep it", so a test submission would land in their CRM as a
           real lead with a real credit pull attached. Mock mode still runs —
           that's the supported way to exercise this path from a test URL. */
        if ($isTest && $mode !== 'mock') {
            $result['skip']  = true;
            $result['error'] = 'skipped_test_visit';
            return $result;
        }

        /* ---- duplicate-delivery guard ------------------------------------
           This endpoint is JG's LEAD INTAKE, not a scoring lookup: every call
           creates a real lead there (it returns an id, a disposition and a
           credit pull). JG is also a buyer on the LeadProsper campaign, so
           calling it while LeadProsper is delivering live means JG receives the
           same consumer twice — ours first, LeadProsper's ~4s later — and
           rejects the second as a duplicate. The rejected one is the one that
           pays. This happened in production; it is not hypothetical.

           So: refuse to run alongside a live LeadProsper post unless somebody
           has explicitly said the conflict is resolved (JG removed from the LP
           campaign and sold direct instead) via
           JGW_ALLOW_WITH_LEADPROSPER=1. A LeadProsper 'test' post is not
           delivered to buyers, so it can't collide and isn't blocked. */
        if (
            $mode !== 'mock'
            && strtolower((string) ($cfg['leadprosper']['mode'] ?? 'off')) === 'live'
            && empty($jg['allow_with_leadprosper'])
        ) {
            $result['skip']  = true;
            $result['error'] = 'skipped_leadprosper_live_would_duplicate';
            return $result;
        }

        $payload = jgscoring_payload($cfg, $row, $debtAmount);
        $body    = json_encode($payload, JSON_UNESCAPED_SLASHES);
        // The token rides in the Authorization header, not the body, so the
        // stored request body carries no secret — but it IS full PII.
        $result['request_body'] = $body;

        // ---- mock: synthesize a response, no network ----
        if ($mode === 'mock') {
            $result['response_body'] = json_encode([
                'message'                   => 'Success',
                'id'                        => 15909429,
                'created'                   => date('c'),
                'prequalified'              => true,
                'prequalified_notes'        => 'is_pre_qualified returned Yes',
                'DR_Pre_Qual__c'            => 'Yes',
                'accepted'                  => true,
                'disposition'               => 'Accepted',
                'external_id'               => '11fbd9d7-7ae2-40c7-a8a9-50d47671cfbe',
                'credit_rating'             => 'poor',
                'total_debt_included'       => 14079.0,
                'estimated_program_length'  => 36,
                'estimated_monthly_payment' => 327.0,
                'estimated_biweekly_payment' => 150.92,
                '_mock'                     => true,
            ], JSON_UNESCAPED_SLASHES);
            $result['status'] = 200;
            $result['ok']     = true;
            return jgscoring_parse($result);
        }

        // ---- live ----
        $token = (string) ($jg['token'] ?? '');
        if ($token === '') {
            $result['skip']  = true;
            $result['error'] = 'JG scoring not configured (JGW_API_TOKEN empty).';
            return $result;
        }

        $ua = trim($userAgent) !== '' ? trim($userAgent) : (string) ($jg['user_agent'] ?? '');

        $t0   = microtime(true);
        $http = jgscoring_http(
            (string) ($jg['endpoint'] ?? ''),
            $body,
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Token ' . $token,
                'User-Agent: ' . $ua,
            ],
            (int) ($jg['timeout'] ?? 20)
        );
        $result['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);

        $result['status']        = $http['status'];
        $result['response_body'] = $http['body'];

        if ($http['error'] !== null) {
            $result['error'] = $http['error'];
            return $result;
        }

        $result['ok'] = $http['status'] >= 200 && $http['status'] < 300;
        if (!$result['ok']) {
            $result['error'] = 'http_' . $http['status'];
            return $result;
        }

        return jgscoring_parse($result);
    }

    /**
     * The requested convenience entry point: post the lead and hand back just
     * the scored debt figure, or null when there isn't one.
     *
     * Use jgscoring_submit() instead wherever the full result matters — the
     * prequalification flags, credit rating and payment estimates are worth
     * logging, and this wrapper throws them away.
     */
    function jgscoring_submit_lead_and_get_total_debt(array $cfg, array $row, ?int $debtAmount, string $userAgent = '', bool $isTest = false): ?int
    {
        $result = jgscoring_submit($cfg, $row, $debtAmount, $userAgent, $isTest);
        return $result['total_debt'];
    }

    /**
     * Fill the parsed fields of a result from its own response_body. Split out
     * so mock and live share one parser.
     */
    function jgscoring_parse(array $result): array
    {
        $decoded = json_decode((string) $result['response_body'], true);
        if (!is_array($decoded)) {
            $result['error'] = $result['error'] ?? 'unparseable_response';
            return $result;
        }

        $result['total_debt']    = jgscoring_extract_total_debt($decoded);
        $result['prequalified']  = isset($decoded['prequalified']) ? (bool) $decoded['prequalified'] : null;
        $result['accepted']      = isset($decoded['accepted']) ? (bool) $decoded['accepted'] : null;
        $result['disposition']   = isset($decoded['disposition']) ? (string) $decoded['disposition'] : null;
        $result['credit_rating'] = isset($decoded['credit_rating']) ? (string) $decoded['credit_rating'] : null;
        $result['jgw_id']        = isset($decoded['id']) ? (string) $decoded['id'] : null;
        $result['external_id']   = isset($decoded['external_id']) ? (string) $decoded['external_id'] : null;

        if (isset($decoded['estimated_program_length']) && is_numeric($decoded['estimated_program_length'])) {
            $result['estimated_program_length'] = (int) $decoded['estimated_program_length'];
        }
        if (isset($decoded['estimated_monthly_payment']) && is_numeric($decoded['estimated_monthly_payment'])) {
            $result['estimated_monthly_payment'] = (float) $decoded['estimated_monthly_payment'];
        }

        /* A 2xx that scored nothing is not a usable result for our purposes —
           flag it so the caller's fallback is visible in the logs rather than
           looking like a silent success. The lead itself was still accepted by
           JG; only the debt figure is missing. */
        if ($result['total_debt'] === null && $result['error'] === null) {
            $result['error'] = 'no_total_debt_in_response';
        }

        return $result;
    }

    /**
     * Scored debt from a decoded response. `total_debt_included` is the
     * documented field; `total_debt` is accepted as an alias in case the
     * response shape varies by product. Comes back as a float (14079.0) and is
     * rounded to whole dollars. Zero is a real answer (nothing settleable) and
     * is preserved, not treated as absent.
     */
    function jgscoring_extract_total_debt($decoded): ?int
    {
        if (!is_array($decoded)) {
            return null;
        }
        foreach (['total_debt_included', 'total_debt'] as $key) {
            if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                return (int) round((float) $decoded[$key]);
            }
        }
        return null;
    }

    /** Minimal curl wrapper. Returns ['status'=>int, 'body'=>?string, 'error'=>?string]. */
    function jgscoring_http(string $url, string $body, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'curl_unavailable'];
        }
        if ($url === '') {
            return ['status' => 0, 'body' => null, 'error' => 'no_endpoint'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        $cerr   = $errno !== 0 ? ('curl_error_' . $errno) : null;
        curl_close($ch);
        return [
            'status' => $status,
            'body'   => $resp === false ? null : (string) $resp,
            'error'  => $cerr,
        ];
    }
}
