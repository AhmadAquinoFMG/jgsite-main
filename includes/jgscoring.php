<?php

/**
 * JG Wentworth Lead Scoring (Debt Resolution) — client + result shaper.
 *
 * submit.php calls jgscoring_score() AFTER the lead is stored and BEFORE the
 * LeadProsper direct-post, because the figure it returns
 * (`total_debt_included`) is the verified total debt that rides along on that
 * post. Best-effort: whatever happens (success, HTTP error, timeout, misconfig)
 * comes back as a structured result the caller writes to `jgscoring_logs`, and
 * the lead submission still succeeds ("log & continue"). Same contract as
 * includes/leadprosper.php and includes/zapier.php.
 *
 * This REPLACES the Equifax credit pull as the source of verified total debt.
 * includes/equifax.php is left on disk but dormant (config equifax.mode=off);
 * nothing calls equifax_pull() any more.
 *
 * Modes (config jgscoring.mode):
 *   'off'  → returns ['skip' => true]; caller writes no log row.
 *   'mock' → synthetic response, no network; caller DOES log it. Use this to
 *            verify the payload shape and the downstream wiring without
 *            creating a real lead at JG.
 *   'live' → real POST to config jgscoring.endpoint.
 *
 * ⚠ THIS ENDPOINT IS JG'S LEAD INTAKE, NOT A LOOKUP. Every 'live' call creates
 * a real lead inside JG. That is exactly why the integration was removed once
 * before: JG also sits as a buyer on LeadProsper campaign 35954, so running
 * both delivered the same consumer twice and the paying LeadProsper copy came
 * back "duplicated by buyer". If JG is still an LP buyer, either remove them
 * there or accept the duplicate — see README.md.
 *
 * ⚠ COMPLIANCE: the request body carries the consumer's full identity (name,
 * DOB, address, email, phone) and authorises a credit pull
 * (`ok_to_pull_credit`). The API token travels in the Authorization header, so
 * it is never part of the body we persist.
 */

if (!function_exists('jgscoring_score')) {

    /**
     * Build the intake payload from the stored lead row.
     *
     * The shape mirrors JG's own sample byte for byte, including
     * `employement_status` — that misspelling is THEIR field name, not a typo
     * here. Do not "fix" it: renaming it drops the employment answer silently.
     *
     * Only the keys JG's sample actually contains are sent. Extra keys are
     * probably ignored, but "probably" is not a reason to put unverified fields
     * in front of an endpoint whose rejection costs us the debt figure.
     *
     * @param array $cfg Full config array (reads $cfg['jgscoring']).
     * @param array $row The exact row inserted into `leads` (submit.php's $row).
     */
    function jgscoring_payload(array $cfg, array $row): array
    {
        $jg = $cfg['jgscoring'] ?? [];

        // Phone is stored E.164 (+1XXXXXXXXXX); JG's sample is bare 10 digits.
        $phone = (string) preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
        if (strlen($phone) === 11 && $phone[0] === '1') {
            $phone = substr($phone, 1);
        }

        /* The funnel collects a debt BUCKET, not a figure; JG's sample carries a
           precise self-reported number. The bucket's representative amount is the
           closest honest equivalent — and it is only the INPUT to their
           underwriting anyway: what we keep is `total_debt_included` from the
           response. Same helper LeadProsper's self_assessed_debt uses, so both
           partners are told the same self-reported story. */
        $debtAmount = leadprosper_debt_bucket_amount((string) ($row['debt_amount'] ?? ''));

        /* Enum translations. Our stored keys ('employed', 'Under $30,000') are
           this funnel's, not JG's; the maps in config are the single place where
           the two vocabularies meet. An unmapped value posts empty rather than
           posting our key and risking a 400 that loses the whole call. */
        $employment = (string) ($row['employment'] ?? '');
        $income     = (string) ($row['income'] ?? '');

        return [
            /* Always true: the TCPA/credit-pull consent in config['consent']['tcpa']
               is shown on the form and a snapshot is stored on the lead
               (leads.consent_text / consent_at). A lead that reached this point
               consented; there is no path here that hasn't. */
            'ok_to_pull_credit' => true,
            'first_name'        => (string) ($row['first_name'] ?? ''),
            'last_name'         => (string) ($row['last_name'] ?? ''),
            'email_address'     => (string) ($row['email'] ?? ''),
            'phone_number'      => $phone,
            'date_of_birth'     => (string) ($row['dob'] ?? ''),   // already Y-m-d
            'address'           => (string) ($row['street'] ?? ''),
            'city'              => (string) ($row['city'] ?? ''),
            'state'             => strtoupper((string) ($row['state'] ?? '')),
            'zip'               => (string) ($row['zip'] ?? ''),
            'additional_fields' => [
                // The ad/creative name as the landing URL reported it. utm_content
                // is the fallback because some placements carry the creative there
                // and leave utm_campaign empty.
                'campaign_id'          => (string) ($row['utm_campaign'] ?? $row['utm_content'] ?? ''),
                // Fixed partner identifiers, not the visitor's utm_source — JG uses
                // these three to attribute the lead to us. The visitor's own
                // utm_source is already on the lead row and goes to LeadProsper.
                'utm_source'           => (string) ($jg['utm_source'] ?? ''),
                'lead_source_detail'   => (string) ($jg['lead_source_detail'] ?? ''),
                'lead_source'          => (string) ($jg['lead_source'] ?? ''),
                'income'               => (string) (($jg['income_map'] ?? [])[$income] ?? ''),
                'debt_amount'          => (string) $debtAmount,
                // JG's own misspelling — see the note above.
                'employement_status'   => (string) (($jg['employment_map'] ?? [])[$employment] ?? ''),
                'client_ip'            => (string) ($row['ip'] ?? ''),
                'campaign_source'      => (string) ($jg['campaign_source'] ?? ''),
                'trustedform_cert_url' => (string) ($row['trustedform_url'] ?? ''),
            ],
        ];
    }

    /**
     * Pull the figures we care about out of a decoded JG response.
     *
     * Absence is never an error: JG returns the same envelope for a declined
     * lead as for an accepted one, just with different values, and a lead that
     * doesn't prequalify legitimately has no `total_debt_included`.
     */
    function jgscoring_parse(?array $decoded): array
    {
        $out = [
            'total_debt'    => null,
            'prequalified'  => null,
            'accepted'      => null,
            'disposition'   => null,
            'credit_rating' => null,
            'jgw_id'        => null,
            'external_id'   => null,
            /* Consumer-facing estimates JG computed. No columns of their own —
               they survive in jgscoring_logs.response_body and in the ops log.
               Returned here so a future thank-you-page use needs no re-parse. */
            'estimated_program_length'   => null,
            'estimated_monthly_payment'  => null,
            'estimated_biweekly_payment' => null,
        ];
        if ($decoded === null) {
            return $out;
        }

        // Round rather than truncate: total_debt_included arrives as a float
        // (18958.0) and the column is INT UNSIGNED.
        if (isset($decoded['total_debt_included']) && is_numeric($decoded['total_debt_included'])) {
            $out['total_debt'] = max(0, (int) round((float) $decoded['total_debt_included']));
        }
        foreach (['prequalified', 'accepted'] as $flag) {
            if (isset($decoded[$flag])) {
                $out[$flag] = filter_var($decoded[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        foreach (['disposition', 'credit_rating', 'external_id'] as $str) {
            if (isset($decoded[$str]) && $decoded[$str] !== '') {
                $out[$str] = (string) $decoded[$str];
            }
        }
        // JG's own lead id comes back as a bare `id`.
        if (isset($decoded['id']) && $decoded['id'] !== '') {
            $out['jgw_id'] = (string) $decoded['id'];
        }
        foreach (['estimated_program_length', 'estimated_monthly_payment', 'estimated_biweekly_payment'] as $num) {
            if (isset($decoded[$num]) && is_numeric($decoded[$num])) {
                $out[$num] = (float) $decoded[$num];
            }
        }

        return $out;
    }

    /**
     * POST the lead to JG's Debt Resolution intake and shape the result.
     *
     * Never throws. `error` is NULL only when JG answered 2xx with a decodable
     * body; a usable `total_debt` additionally requires that body to carry
     * `total_debt_included`.
     *
     * @return array{skip:bool, mode:string, request_url:string, request_body:string,
     *               response_status:int, response_body:?string, total_debt:?int,
     *               prequalified:?int, accepted:?int, disposition:?string,
     *               credit_rating:?string, jgw_id:?string, external_id:?string,
     *               estimated_program_length:?float, estimated_monthly_payment:?float,
     *               estimated_biweekly_payment:?float, error:?string, duration_ms:int}
     */
    function jgscoring_score(array $cfg, array $row): array
    {
        $jg   = $cfg['jgscoring'] ?? [];
        $mode = strtolower((string) ($jg['mode'] ?? 'off'));

        if ($mode === 'off') {
            return ['skip' => true];
        }

        $url         = (string) ($jg['endpoint'] ?? '');
        $payload     = jgscoring_payload($cfg, $row);
        $requestBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        $result = [
            'skip'            => false,
            'mode'            => $mode,
            'request_url'     => $url,
            'request_body'    => $requestBody,
            'response_status' => 0,
            'response_body'   => null,
            'error'           => null,
            'duration_ms'     => 0,
        ] + jgscoring_parse(null);

        /* ---- mock: no network, no lead created at JG ---- */
        if ($mode === 'mock') {
            // Mirrors JG's documented envelope so the caller's parse/insert path
            // is exercised for real. total_debt_included is derived from the
            // self-reported bucket so a mock run still produces a plausible
            // figure to follow through the funnel.
            $selfDebt = (float) ($payload['additional_fields']['debt_amount'] ?: 0);
            $mock = [
                'message'                    => 'Success',
                'id'                         => 0,
                'created'                    => date('c'),
                'prequalified'               => $selfDebt >= 10000,
                'prequalified_notes'         => 'mock',
                'DR_Pre_Qual__c'             => $selfDebt >= 10000 ? 'Yes' : 'No',
                'accepted'                   => $selfDebt >= 10000,
                'disposition'                => $selfDebt >= 10000 ? 'Accepted' : 'Rejected',
                'external_id'                => 'mock-' . substr(md5($requestBody), 0, 8),
                'credit_rating'              => 'fair',
                'total_debt_included'        => round($selfDebt * 0.95, 2),
                'estimated_program_length'   => 42,
                'estimated_monthly_payment'  => round($selfDebt * 0.02, 2),
                'estimated_biweekly_payment' => round($selfDebt * 0.0092, 2),
            ];
            $result['response_status'] = 200;
            $result['response_body']   = (string) json_encode($mock, JSON_UNESCAPED_SLASHES);
            return array_merge($result, jgscoring_parse($mock));
        }

        /* ---- live ---- */
        $token = trim((string) ($jg['token'] ?? ''));
        if ($url === '' || $token === '') {
            // Misconfiguration, not an outage — logged as a failed attempt so it
            // is visible in jgscoring_logs rather than looking like mode=off.
            $result['error'] = 'not_configured';
            return $result;
        }
        if (!function_exists('curl_init')) {
            $result['error'] = 'curl_unavailable';
            return $result;
        }

        /* The Authorization value is sent EXACTLY as configured. JG's docs give
           the header name without a scheme, and whether their token wants a
           `Token `/`Bearer ` prefix is account-specific — so the prefix (if any)
           belongs in JGSCORING_TOKEN itself, or in JGSCORING_AUTH_SCHEME when
           you'd rather keep the raw token clean. Guessing a scheme here would
           turn a working token into a silent 401. */
        $scheme = trim((string) ($jg['auth_scheme'] ?? ''));
        $authorization = $scheme !== '' ? $scheme . ' ' . $token : $token;

        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $requestBody,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $authorization,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($jg['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $cerr  = curl_error($ch);
        $result['response_status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result['duration_ms']     = (int) round((microtime(true) - $t0) * 1000);
        curl_close($ch);

        if ($errno !== 0) {
            $result['error'] = 'curl_error_' . $errno . ($cerr !== '' ? ': ' . $cerr : '');
            return $result;
        }

        $result['response_body'] = is_string($raw) ? $raw : null;

        if ($result['response_status'] < 200 || $result['response_status'] >= 300) {
            $result['error'] = 'http_' . $result['response_status'];
            // Still parse: a 4xx from JG can carry a decline envelope, and the
            // fields it does contain are worth keeping.
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $result['error'] = $result['error'] ?? 'bad_json';
            return $result;
        }

        return array_merge($result, jgscoring_parse($decoded));
    }
}
