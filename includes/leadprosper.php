<?php

/**
 * LeadProsper direct-post integration.
 *
 * submit.php calls leadprosper_submit() AFTER the lead is stored (and after the
 * Equifax pull, so the verified total debt is available). Best-effort — same
 * "log & continue" contract as includes/equifax.php: whatever happens is
 * returned as a structured result the caller logs, and the lead submission
 * still succeeds regardless of LeadProsper's outcome.
 *
 * Ported from the proven `tdo` integration and adapted to this funnel's field
 * names (street/city/state/zip instead of a single address, E.164 phone,
 * Y-m-d dob, human-readable debt_amount labels instead of numeric buckets).
 */

if (!function_exists('leadprosper_debt_bucket_amount')) {

    /**
     * Convert a debt_options label (e.g. "$10,000 - $24,999", "Less than $10,000",
     * "More than $100,000") into a single representative dollar amount for
     * LeadProsper's numeric self_assessed_debt field.
     */
    function leadprosper_debt_bucket_amount(string $label): int
    {
        if (!preg_match_all('/[\d,]+/', $label, $m)) {
            return 0;
        }
        $nums = array_map(static fn($n) => (int) str_replace(',', '', $n), $m[0]);
        if (count($nums) === 1) {
            if (stripos($label, 'less') !== false)  return (int) round($nums[0] / 2);
            if (stripos($label, 'more') !== false)  return (int) round($nums[0] * 1.25);
            return $nums[0];
        }
        return (int) round(array_sum($nums) / count($nums));
    }

    /**
     * Build the LeadProsper payload from the stored lead row, the Equifax-verified
     * total debt (if any), and first-touch tracking params. Empty values are
     * dropped so optional fields aren't posted blank.
     *
     * @param array    $cfg       Full config array (reads $cfg['leadprosper']).
     * @param array    $row       The exact row inserted into `leads` (submit.php's $row).
     * @param array    $tracking  Flat map of tracking params (affid, oid, ef_transaction_id, …).
     * @param int|null $totalDebt Equifax-verified total debt, or null if no pull/failed.
     */
    function leadprosper_payload(array $cfg, array $row, array $tracking, ?int $totalDebt): array
    {
        $lp = $cfg['leadprosper'] ?? [];

        // Phone is stored E.164 (+1XXXXXXXXXX) — LeadProsper wants 10 digits.
        $phone = preg_replace('/\D/', '', (string) ($row['phone'] ?? ''));
        if (strlen($phone) === 11 && $phone[0] === '1') {
            $phone = substr($phone, 1);
        }

        $dobIso = (string) ($row['dob'] ?? '');

        $payload = [
            'lp_campaign_id'       => $lp['campaign_id'] ?? '',
            'lp_supplier_id'       => $lp['supplier_id'] ?? '',
            'lp_key'               => $lp['key'] ?? '',
            'first_name'           => $row['first_name'] ?? '',
            'last_name'            => $row['last_name'] ?? '',
            'email'                => $row['email'] ?? '',
            'phone'                => $phone,
            'date_of_birth'        => $dobIso, // already Y-m-d (ISO) — LeadProsper's expected format
            'address'              => $row['street'] ?? '',
            'city'                 => $row['city'] ?? '',
            'state'                => strtoupper((string) ($row['state'] ?? '')),
            'zip_code'             => $row['zip'] ?? '',
            'ip_address'           => $row['ip'] ?? '',
            /* OMITTED, not zeroed, when we have no verified figure. This field
               is what InCharge Debt Solutions qualifies on, and `0` asserts "this
               consumer has no debt" — a disqualification — where absence
               correctly reads as "we don't know". The funnel collects no SSN, so
               an empty Equifax pull is routine, not exceptional. The
               self-reported estimate still ships as self_assessed_debt, and
               softpull_returned tells the buyer which of the two they're looking
               at. (array_filter below drops the '' — and would NOT drop a 0.) */
            'total_debt'           => $totalDebt ?? '',
            'self_assessed_debt'   => leadprosper_debt_bucket_amount((string) ($row['debt_amount'] ?? '')),
            'employed'             => $row['employment'] ?? '',
            'behind_payment'       => $row['behind_payment'] ?? '',
            'trustedform_cert_url' => $row['trustedform_url'] ?? '',
            'jornaya_leadid'       => $row['jornaya_token'] ?? '',
            'tcpa_text'            => $row['consent_text'] ?? '',
            'user_agent'           => $row['user_agent'] ?? '',
            'landing_page_url'     => $row['landing_page_url'] ?? '',
            // NOT sent — no data source in this funnel (it doesn't ask these
            // questions): 'gender' and 'credit_rating' (no score bucket derived
            // from the Equifax pull yet). Add a gender question, or derive
            // credit_rating from $totalDebt's sibling equifax score, if these
            // need to start populating.
        ];

        // Post as a test when the global mode is 'test' OR this specific visit is
        // QA-flagged (?test=fmg_true — see config.php ['test_mode']), so
        // LeadProsper never bills/delivers the lead.
        $isTest = !empty($tracking['is_test']);
        if (($lp['mode'] ?? 'off') === 'test' || $isTest) {
            $payload['lp_action'] = 'test';
        }

        foreach (LEADPROSPER_TRACKING_PARAMS as $key) {
            // NOT empty() — that treats '0' (a real, meaningful false value for
            // fields like softpull_returned) the same as absent and drops it.
            if (isset($tracking[$key]) && $tracking[$key] !== '') {
                $payload[$key] = $tracking[$key];
            }
        }

        /* Everflow's sub1-sub6 ride out in LeadProsper's lp_subid1-6 fields.
           The campaign defines lp_subid*, not sub*, so posting the Everflow
           names verbatim would just be unknown keys — the values have to be
           mapped across to land anywhere.

           An explicit ?lp_subidN= on the landing URL WINS. The mapping only
           fills a slot the URL left empty, so a partner already populating
           lp_subid directly can't have its value silently replaced by an
           Everflow sub that happens to share the index. */
        for ($i = 1; $i <= 6; $i++) {
            $lpKey = 'lp_subid' . $i;
            $sub   = (string) ($row['sub' . $i] ?? '');

            if ($sub !== '' && ($payload[$lpKey] ?? '') === '') {
                $payload[$lpKey] = $sub;
            }
        }

        /* The campaign marks affid REQUIRED, so a QA test URL opened without one
           is rejected outright ("field `affid` is required") before any of the
           field mapping under test is looked at. Substitute the configured QA
           affid — test posts only, and only when the visit genuinely carried no
           affid of its own, so a real one is never overwritten. This touches the
           payload alone: the stored lead keeps its empty affid and Everflow (which
           reads the URL, not this) is not involved either way. */
        if ($isTest && ($payload['affid'] ?? '') === '') {
            $testAffid = trim((string) ($cfg['test_mode']['affid'] ?? ''));
            if ($testAffid !== '') {
                $payload['affid'] = $testAffid;
            }
        }

        return array_filter($payload, static fn($v) => $v !== '' && $v !== null);
    }

    /**
     * Post a lead to LeadProsper. Returns a structured result; never throws.
     *
     * @return array{skip:bool, ok:bool, mode:string, status:int, sent:?string,
     *               response:?string, buyer_total_debt:?int, accepted_buyer:?string,
     *               error:?string, duration_ms:int}
     */
    function leadprosper_submit(array $cfg, array $row, array $tracking, ?int $totalDebt): array
    {
        $lp   = $cfg['leadprosper'] ?? [];
        $mode = $lp['mode'] ?? 'off';

        /* A QA test visit (?test=fmg_true) always posts, and always posts as a
           test — even where the deployment runs LEADPROSPER_MODE=off. A test URL
           that silently sends nothing is the worse failure: there'd be nothing in
           the campaign's lead log to look at, and no way to tell that from a
           mapping bug. Recorded as 'test' too, so leadprosper_logs.mode and
           leads.lp_mode identify the QA rows. */
        if (!empty($tracking['is_test'])) {
            $mode = 'test';
        }

        $result = [
            'skip'             => false,
            'ok'               => false,
            'mode'             => $mode,
            'status'           => 0,
            'sent'             => null,
            'response'         => null,
            // Value the BUYER returned, echoed back to us by LeadProsper's
            // supplier-API-response feature (see leadprosper_buyer_value()).
            'buyer_total_debt' => null,
            'accepted_buyer'   => null,
            'error'            => null,
            'duration_ms'      => 0,
        ];

        if ($mode === 'off') {
            $result['skip'] = true;
            return $result;
        }

        if (($lp['campaign_id'] ?? '') === '' || ($lp['key'] ?? '') === '') {
            $result['skip']  = true;
            $result['error'] = 'LeadProsper not configured (LP_CAMPAIGN_ID / LP_KEY empty).';
            return $result;
        }

        $payload = leadprosper_payload($cfg, $row, $tracking, $totalDebt);
        // Stored for the audit log with the key redacted — never persist the secret.
        $result['sent'] = json_encode(array_merge($payload, ['lp_key' => '***']), JSON_UNESCAPED_SLASHES);

        $body    = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        $t0   = microtime(true);
        $http = leadprosper_http((string) ($lp['endpoint'] ?? 'https://api.leadprosper.io/direct_post'), $body, $headers, (int) ($lp['timeout'] ?? 20));
        $result['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);

        if ($http['error'] !== null) {
            $result['error'] = $http['error'];
            return $result;
        }
        $result['status']   = $http['status'];
        $result['response'] = $http['body'];

        // LeadProsper returns JSON; a 2xx with an accepted/success status is a good lead.
        $decoded  = json_decode((string) $http['body'], true);
        $accepted = is_array($decoded)
            && (
                (isset($decoded['result']) && strtolower((string) $decoded['result']) === 'accepted')
                || (isset($decoded['status']) && in_array(strtolower((string) $decoded['status']), ['accepted', 'success', 'ok'], true))
                || !empty($decoded['success'])
            );
        $result['ok'] = $result['status'] >= 200 && $result['status'] < 300 && ($accepted || $decoded === null);

        /* Buyer-returned values ride on the SAME response as the accept/reject —
           delivery is synchronous — so there's nothing to poll for afterwards.
           Read regardless of $accepted: a buyer can return a figure on a lead
           LeadProsper still classes as rejected, and it costs nothing to look. */
        $result['buyer_total_debt'] = leadprosper_buyer_total_debt(
            $decoded,
            (string) ($lp['buyer_total_debt_key'] ?? '')
        );
        $result['accepted_buyer'] = leadprosper_accepted_buyer($decoded);

        if (!$result['ok'] && $result['error'] === null) {
            $result['error'] = 'http_' . $result['status'];
        }

        return $result;
    }

    /**
     * Pull a buyer-returned value out of a decoded direct_post response.
     *
     * LeadProsper's "Customize Supplier API Response" feature (campaign setting
     * "Pass data from your buyer's API response back to the supplier") extracts a
     * field from the BUYER's response and echoes it back to us under a key we
     * name on the campaign. That is how we obtain JG Wentworth's
     * `total_debt_included` without ever calling JG ourselves — LeadProsper is
     * already making that call, and this hands us the answer.
     *
     * ⚠ LeadProsper documents this as working only for EXCLUSIVE leads sold to a
     * single buyer. On a multi-sell campaign the key may simply never appear;
     * absence is therefore treated as "no value", never as an error.
     *
     * The article doesn't specify where the key lands in the response envelope,
     * so all three plausible shapes are checked: top level, inside each
     * buyers[] entry, and inside a nested response/response_body object. First
     * non-empty match wins.
     */
    function leadprosper_buyer_value($decoded, string $key)
    {
        if (!is_array($decoded) || $key === '') {
            return null;
        }

        $pick = static function ($node) use ($key) {
            return is_array($node) && isset($node[$key]) && $node[$key] !== '' ? $node[$key] : null;
        };

        $found = $pick($decoded);
        if ($found !== null) {
            return $found;
        }
        foreach ((array) ($decoded['buyers'] ?? []) as $buyer) {
            $found = $pick($buyer);
            if ($found !== null) {
                return $found;
            }
            foreach (['response', 'response_body', 'custom_properties'] as $nested) {
                $found = $pick($buyer[$nested] ?? null);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        foreach (['response', 'response_body'] as $nested) {
            $found = $pick($decoded[$nested] ?? null);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * The buyer-returned verified debt figure, as a whole-dollar int, or null.
     * Arrives as a float (11238.0) or a numeric string depending on how
     * LeadProsper serializes it. 0 is a real answer and is preserved.
     */
    function leadprosper_buyer_total_debt($decoded, string $key): ?int
    {
        $raw = leadprosper_buyer_value($decoded, $key);
        return is_numeric($raw) ? (int) round((float) $raw) : null;
    }

    /**
     * Name of the buyer that took the lead, for attributing the value above.
     * Only meaningful on exclusive campaigns (one accepted buyer per lead).
     */
    function leadprosper_accepted_buyer($decoded): ?string
    {
        foreach ((array) ($decoded['buyers'] ?? []) as $buyer) {
            $status = strtolower((string) ($buyer['status'] ?? ''));
            if (in_array($status, ['accepted', 'success', 'ok'], true)) {
                return (string) ($buyer['name'] ?? ($buyer['id'] ?? 'unknown'));
            }
        }
        return null;
    }

    /** Minimal curl wrapper. Returns ['status'=>int, 'body'=>?string, 'error'=>?string]. */
    function leadprosper_http(string $url, string $body, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'curl_unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
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

// First-touch attribution params forwarded to LeadProsper — kept in sync with
// (a) the hidden fields captured in index.php/funnel.js and (b) the exact set
// of fields actually configured on the LeadProsper campaign. affid/oid/
// ef_transaction_id come from Everflow; softpull_returned is computed
// server-side in submit.php (not a posted field).
//
// NOT forwarded: 'fbc'. It is captured (funnel.js reads the _fbc cookie, or
// builds it from the fbclid) and stored, but the LeadProsper campaign has no
// fbc field — so it would be an unknown key on a post that runs inline on every
// submit. It exists for the Meta Conversions API event CallGrid fires off the
// call, which gets it from the redirect params instead (config.php ['redirect']).
// Add it here only if the campaign gains the field.
const LEADPROSPER_TRACKING_PARAMS = [
    'affid', 'oid', 'source_id', 'ef_transaction_id',
    // Everflow's sub1-sub6 are NOT listed here — the campaign has no sub* fields.
    // They are stored on the lead and mapped into lp_subid1-6 by
    // leadprosper_payload() instead.
    'lp_subid1', 'lp_subid2', 'lp_subid3', 'lp_subid4', 'lp_subid5', 'lp_subid6',
    'adv1', 'adv2', 'adv3', 'adv4', 'adv5', 'subid',
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    'utm_creative', 'utm_placement', 'utm_adgroup', 'utm_matchtype',
    'gclid', 'gbraid', 'fbclid', 'fbp', 'fb_adid',
    'ms_placement', 'ms_publisher', 'ttclid',
    'softpull_returned',
];
