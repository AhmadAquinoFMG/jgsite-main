<?php

/**
 * Equifax Consumer Credit Report — client + logger (no Composer).
 *
 * submit.php calls equifax_pull() AFTER the lead is stored. The call is
 * best-effort: whatever happens (success, HTTP error, timeout, misconfig) is
 * returned as a structured result the caller writes to `equifax_logs`, and the
 * lead submission still succeeds ("log & continue").
 *
 * Modes (config equifax.mode):
 *   'off'  → returns ['skip' => true]; caller writes no log row.
 *   'mock' → returns a synthetic response (no network); caller DOES log it.
 *   'live' → real OAuth2 client-credentials token, then the credit-report POST.
 *
 * DEBT SCOPE: Equifax has no request-level filter for account types — the
 * report always comes back with every trade line — so the "only unsecured, no
 * student loans" rule is applied when the total is extracted from the response
 * (equifax_trade_is_unsecured()). The production pull always enforces this;
 * there is no environment override that can send all-debt downstream.
 *
 * ⚠ The request body contains the SSN + identity and the response contains the
 * raw credit report. Both are returned for logging; set config equifax.redact
 * to mask the SSN in the stored request body.
 *
 * NOTE: The exact token/report endpoint paths and request schema are governed
 * by your Equifax contract — the shapes below follow Equifax's developer API
 * conventions and are the single place to adjust when integrating for real.
 */

if (!function_exists('equifax_pull')) {

    /** Mask all but the last 4 digits of the given SSN wherever it appears. */
    function equifax_redact_ssn(string $json, string $ssn): string
    {
        if (strlen($ssn) !== 9) {
            return $json;
        }
        return str_replace($ssn, 'XXXXX' . substr($ssn, -4), $json);
    }

    /**
     * Mask the account identifiers (member/security/customer codes) in a stored
     * request body — they identify our Equifax account and must never be
     * persisted with the lead. Always applied (independent of the SSN toggle).
     */
    function equifax_redact_secrets(string $json, array $eq): string
    {
        foreach (['member_number', 'security_code', 'customer_code'] as $k) {
            $v = (string) ($eq[$k] ?? '');
            if ($v !== '') {
                $json = str_replace($v, '***', $json);
            }
        }
        return $json;
    }

    /**
     * @param array  $cfg  Full config array.
     * @param array  $lead The validated lead (first_name, last_name, street,
     *                     city, state, zip, dob [Y-m-d]).
     * @param string $ssn  9-digit SSN (digits only).
     * @return array {skip?:bool, mode,request_url,request_body,response_status,
     *                response_body,score,decision,error,duration_ms}
     */
    function equifax_pull(array $cfg, array $lead, string $ssn): array
    {
        $eq   = $cfg['equifax'] ?? [];
        $mode = $eq['mode'] ?? 'off';

        if ($mode === 'off') {
            return ['skip' => true];
        }

        // ---- build the OneView credit-report request (matches tdo) ----
        $url = ($eq['base_url'] ?? '') . ($eq['product_path'] ?? '/business/oneview/consumer-credit/v1/reports/credit-report');

        // OneView wants DOB as MMDDYYYY; the funnel provides it as YYYY-MM-DD.
        $dobRaw = (string) ($lead['dob'] ?? '');
        $dobTs  = $dobRaw !== '' ? strtotime($dobRaw) : false;
        $consumers = [
            'name' => [[
                'identifier' => 'current',
                'firstName'  => (string) ($lead['first_name'] ?? ''),
                'lastName'   => (string) ($lead['last_name'] ?? ''),
            ]],
            'addresses' => [[
                'identifier' => 'current',
                'streetName' => trim((string) ($lead['street'] ?? '')),
                'city'       => (string) ($lead['city'] ?? ''),
                'state'      => strtoupper((string) ($lead['state'] ?? '')),
                'zip'        => (string) ($lead['zip'] ?? ''),
            ]],
            'dateOfBirth' => $dobTs !== false ? date('mdY', $dobTs) : '',
        ];
        // SSN is optional for this funnel; include only when present.
        if ($ssn !== '') {
            $consumers['socialNum'] = [['identifier' => 'current', 'number' => $ssn]];
        }

        $creditReportConfig = array_filter([
            'memberNumber'            => $eq['member_number'] ?? '',
            'securityCode'            => $eq['security_code'] ?? '',
            'customerCode'            => $eq['customer_code'] ?? '',
            'ECOAInquiryType'         => $eq['ecoa_inquiry_type'] ?? 'Individual',
            'multipleReportIndicator' => $eq['multiple_report_indicator'] ?? '1',
            'codeDescriptionRequired' => true,
        ], static fn($v) => $v !== '' && $v !== null);
        if (($eq['model_id'] ?? '') !== '') {
            $creditReportConfig['models'] = [['identifier' => $eq['model_id']]];
        }

        $requestArr = [
            'consumers' => $consumers,
            'customerReferenceIdentifier' => (string) ($lead['email'] ?? ''),
            'customerConfiguration' => [
                'equifaxUSConsumerCreditReport' => $creditReportConfig,
            ],
        ];
        $requestBody = json_encode($requestArr, JSON_UNESCAPED_SLASHES);

        // What we STORE: redact SSN + account secrets (member/security/customer
        // codes identify our Equifax account and must never be persisted).
        $storedRequestBody = $requestBody;
        if (!empty($eq['redact'])) {
            $storedRequestBody = equifax_redact_ssn($storedRequestBody, $ssn);
        }
        $storedRequestBody = equifax_redact_secrets($storedRequestBody, $eq);

        $result = [
            'skip'         => false,
            'mode'         => $mode,
            'request_url'  => $url,
            'request_body' => $storedRequestBody,
        ];

        // ---- mock: synthesize a response, no network ----
        // The trade list deliberately mixes qualifying and disqualifying lines
        // so the unsecured filter is exercised locally: the two unsecured cards
        // (18,400 + 9,050) plus the medical line (7,050) total 34,500 — the
        // mortgage, auto and student loan are dropped.
        if ($mode === 'mock') {
            $mockResponse = json_encode([
                'consumers' => ['equifaxUSConsumerCreditReport' => [[
                    'models' => [['type' => 'Score', 'score' => '0742']],
                    'trades' => [
                        ['accountType' => ['code' => '18', 'description' => 'Credit Card'],       'balanceAmount' => 18400],
                        ['accountType' => ['code' => '01', 'description' => 'Unsecured'],         'balanceAmount' => 9050],
                        ['accountType' => ['code' => '23', 'description' => 'Medical Debt'],      'balanceAmount' => 7050],
                        ['accountType' => ['code' => '26', 'description' => 'Real Estate Mortgage'], 'balanceAmount' => 214000],
                        ['accountType' => ['code' => '3A', 'description' => 'Auto Loan'],         'balanceAmount' => 21750],
                        ['accountType' => ['code' => '12', 'description' => 'Education Loan'],    'balanceAmount' => 38200],
                    ],
                    'identityScanAlerts' => [],
                ]]],
                '_mock' => true,
            ], JSON_UNESCAPED_SLASHES);
            $mockDebt = equifax_extract_total_debt(json_decode($mockResponse, true));
            return $result + [
                'response_status' => 200,
                'response_body'   => $mockResponse,
                'score'           => 742,
                'decision'        => 'mock',
                'total_debt'      => $mockDebt,
                'error'           => null,
                'duration_ms'     => 0,
            ];
        }

        // ---- live: OAuth2 token, then credit-report POST ----
        $token = equifax_token($cfg, $err);
        if ($token === null) {
            return $result + [
                'response_status' => 0,
                'response_body'   => null,
                'score'           => null,
                'decision'        => null,
                'total_debt'      => null,
                'error'           => 'auth_failed: ' . $err,
                'duration_ms'     => 0,
            ];
        }

        $t0 = microtime(true);
        $http = equifax_http('POST', $url, $requestBody, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], (int) ($eq['timeout'] ?? 15), (string) ($eq['ca_bundle'] ?? ''));
        $duration = (int) round((microtime(true) - $t0) * 1000);

        $score     = null;
        $decision  = null;
        $totalDebt = null;
        if ($http['status'] >= 200 && $http['status'] < 300 && $http['body']) {
            $parsed    = json_decode($http['body'], true);
            $score     = equifax_extract_score($parsed);
            $totalDebt = equifax_extract_total_debt($parsed);
        }

        return $result + [
            'response_status' => $http['status'],
            'response_body'   => $http['body'],
            'score'           => $score,
            'decision'        => $decision,
            'total_debt'      => $totalDebt,
            'error'           => $http['error'] ?: ($http['status'] >= 400 ? 'http_' . $http['status'] : null),
            'duration_ms'     => $duration,
        ];
    }

    /**
     * Verified total debt from a decoded report — UNSECURED trade lines only,
     * with student/education loans excluded (see equifax_trade_is_unsecured()).
     * That's the only debt a debt-relief program can actually settle, so it's
     * the only figure this funnel reports downstream.
     *
     * Returns null when no qualifying trade line carried a balance.
     */
    function equifax_extract_total_debt($decoded): ?int
    {
        if (!is_array($decoded)) {
            return null;
        }
        [$sum, $found] = equifax_sum_trade_balances($decoded, true);
        return $found ? (int) round($sum) : null;
    }

    /**
     * Recursively sum trade-line balances anywhere in the report (under any
     * trades/tradelines/accounts list). With $unsecuredOnly, each trade must
     * pass equifax_trade_is_unsecured() to count. Returns [sum, foundAny].
     * @return array{0:float,1:bool}
     */
    function equifax_sum_trade_balances($node, bool $unsecuredOnly = true): array
    {
        $sum = 0.0;
        $found = false;
        if (!is_array($node)) {
            return [$sum, $found];
        }
        foreach ($node as $key => $value) {
            if (is_string($key)
                && in_array(strtolower($key), ['trades', 'tradelines', 'accounts'], true)
                && is_array($value)) {
                foreach ($value as $trade) {
                    if (!is_array($trade)) {
                        continue;
                    }
                    if ($unsecuredOnly && !equifax_trade_is_unsecured($trade)) {
                        continue;
                    }
                    $balance = $trade['balanceAmount'] ?? ($trade['balance'] ?? ($trade['currentBalance'] ?? null));
                    if (is_numeric($balance) && (float) $balance > 0) {
                        $sum += (float) $balance;
                        $found = true;
                    }
                }
            } elseif (is_array($value)) {
                [$childSum, $childFound] = equifax_sum_trade_balances($value, $unsecuredOnly);
                $sum += $childSum;
                $found = $found || $childFound;
            }
        }
        return [$sum, $found];
    }

    /**
     * Normalize a report field that may be a bare code ("18") or a
     * {code, description} object (what codeDescriptionRequired=true returns).
     * @return array{0:string,1:string} [UPPERCASE code, lowercase description]
     */
    function equifax_code_pair($field): array
    {
        if (is_array($field)) {
            $code = (string) ($field['code'] ?? ($field['identifier'] ?? ''));
            $desc = (string) ($field['description'] ?? ($field['value'] ?? ''));
            return [strtoupper(trim($code)), strtolower(trim($desc))];
        }
        return [strtoupper(trim((string) $field)), ''];
    }

    /**
     * Does this trade line represent UNSECURED, non-student debt?
     *
     * Fail-CLOSED by design: a trade counts only when it is positively
     * identified as unsecured. Anything secured, student, or unrecognizable is
     * left out — under-reporting the total is far safer here than shipping a
     * "verified debt" figure padded with a mortgage the program can't touch.
     *
     * Three gates, in order:
     *   1. Student/education → always out, whatever else the line says.
     *   2. Secured account types (mortgage, HELOC, auto, lease, timeshare,
     *      manufactured housing, secured/partially-secured notes) → out.
     *   3. Must then match an unsecured account-type code, an unsecured
     *      description keyword, or — when the type is unknown — a revolving /
     *      open / line-of-credit portfolio type.
     *
     * Codes are Equifax's standard account-type set; descriptions are matched
     * too because sandbox/production payloads aren't consistent about which of
     * the pair they populate.
     */
    function equifax_trade_is_unsecured(array $trade): bool
    {
        [$code, $desc] = equifax_code_pair(
            $trade['accountType'] ?? ($trade['accountTypeCode'] ?? '')
        );
        if ($desc === '') {
            $desc = strtolower((string) ($trade['accountTypeDescription'] ?? ''));
        }

        // Every scrap of free text on the line, for keyword matching: the type
        // description, the creditor name, and any narrative codes.
        $text = $desc . ' ' . strtolower((string) ($trade['customerName'] ?? ($trade['creditorName'] ?? '')));
        foreach ((array) ($trade['narrativeCodes'] ?? []) as $narrative) {
            [, $nDesc] = equifax_code_pair($narrative);
            $text .= ' ' . $nDesc;
        }

        // ---- 1. student / education loans — excluded unconditionally ----
        // 12 = Education loan. Keywords catch servicer-labelled lines that
        // report under a generic installment type.
        $studentCodes = ['12'];
        $studentWords = ['student', 'education', 'educational', 'sallie mae', 'navient', 'nelnet', 'mohela', 'fedloan', 'perkins', 'stafford', 'sofi student'];
        if (in_array($code, $studentCodes, true) || equifax_text_has($text, $studentWords)) {
            return false;
        }

        // ---- 2. secured / collateralized — excluded ----
        $securedCodes = [
            '00', // auto
            '02', // secured
            '03', // partially secured
            '04', // home improvement
            '05', // FHA real estate
            '08', // real estate mortgage
            '09', // mobile home
            '11', // recreational merchandise
            '13', // lease
            '17', // manufactured housing
            '19', // FHA home improvement
            '21', // home equity
            '22', // secured home improvement
            '25', // VA real estate
            '26', // conventional real estate mortgage
            '27', // auto lease
            '29', // rental agreement
            '3A', // auto loan
            '5A', // recreational vehicle
            '6B', // second mortgage (alt)
            '7A', // home equity line of credit
            '8A', // second mortgage
            '9A', // time share loan
        ];
        $securedWords = ['mortgage', 'real estate', 'home equity', 'heloc', 'home improvement', 'auto', 'vehicle', 'lease', 'secured', 'collateral', 'mobile home', 'manufactured housing', 'recreational', 'time share', 'timeshare', 'boat'];
        if (in_array($code, $securedCodes, true) || equifax_text_has($text, $securedWords)) {
            return false;
        }

        // ---- 3. positively unsecured? ----
        $unsecuredCodes = [
            '01', // unsecured
            '07', // charge account
            '0A', // note loan
            '10', // business loan (personally guaranteed, unsecured)
            '15', // check credit / line of credit
            '18', // credit card
            '20', // note loan with comaker
            '23', // medical debt
            '37', // combined credit plan
            '47', // debt consolidation / credit line
        ];
        if (in_array($code, $unsecuredCodes, true)) {
            return true;
        }
        if (equifax_text_has($text, ['credit card', 'charge account', 'unsecured', 'line of credit', 'personal loan', 'note loan', 'medical', 'installment loan - unsecured', 'flexible spending'])) {
            return true;
        }

        // Unknown/absent account type: fall back to the portfolio type —
        // revolving, open and line-of-credit portfolios are unsecured by
        // definition. Installment (I) and mortgage (M) are not, so they stay
        // out rather than being guessed at.
        if ($code === '') {
            [$pCode, $pDesc] = equifax_code_pair(
                $trade['portfolioType'] ?? ($trade['portfolioTypeCode'] ?? '')
            );
            if ($pDesc === '') {
                $pDesc = strtolower((string) ($trade['portfolioTypeDescription'] ?? ''));
            }
            if (in_array($pCode, ['R', 'C', 'O'], true)
                || equifax_text_has($pDesc, ['revolving', 'line of credit', 'open'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when $haystack contains any of the (lowercase) $needles at a word
     * BOUNDARY START. Leading boundary only, so "auto" still matches
     * "automobile" and "lease" matches "leases" — while "secured" does NOT
     * match "unsecured", which a plain substring test would get backwards and
     * silently drop the single most important account type.
     */
    function equifax_text_has(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($needle, '/') . '/', $haystack) === 1) {
                return true;
            }
        }
        return false;
    }

    /** OAuth2 client-credentials token. Returns access token or null (sets $err). */
    function equifax_token(array $cfg, ?string &$err = null): ?string
    {
        $eq  = $cfg['equifax'] ?? [];
        $url = ($eq['base_url'] ?? '') . ($eq['token_path'] ?? '/v2/oauth/token');
        // Send `scope` ONLY when configured — this account 400s on an explicit
        // scope and issues a token only when it's omitted.
        $params = ['grant_type' => 'client_credentials'];
        if (($eq['scope'] ?? '') !== '') {
            $params['scope'] = $eq['scope'];
        }
        $body = http_build_query($params);
        $http = equifax_http('POST', $url, $body, [
            'Authorization: Basic ' . base64_encode(($eq['api_key'] ?? '') . ':' . ($eq['api_secret'] ?? '')),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], (int) ($eq['timeout'] ?? 20), (string) ($eq['ca_bundle'] ?? ''));

        if ($http['status'] < 200 || $http['status'] >= 300) {
            $err = $http['error'] ?: ('token_http_' . $http['status']);
            return null;
        }
        $data = json_decode((string) $http['body'], true);
        if (empty($data['access_token'])) {
            $err = 'no_access_token';
            return null;
        }
        return (string) $data['access_token'];
    }

    /**
     * Minimal curl wrapper. Returns ['status'=>int, 'body'=>?string, 'error'=>?string].
     *
     * @param string $caBundle Absolute path to a CA-chain PEM to pin trust to
     *                         (config equifax.ca_bundle / EQUIFAX_CA_BUNDLE). Empty
     *                         string uses curl's default system CA bundle. SSL
     *                         verification (VERIFYPEER/VERIFYHOST) is always ON
     *                         regardless — this only selects which chain is trusted.
     */
    function equifax_http(string $method, string $url, string $body, array $headers, int $timeout, string $caBundle = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'curl_unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($caBundle !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr   = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        return [
            'status' => $status,
            'body'   => $resp === false ? null : (string) $resp,
            'error'  => $cerr,
        ];
    }

    /** Best-effort credit-score extraction from a decoded report; null if absent. */
    function equifax_extract_score($parsed): ?int
    {
        if (!is_array($parsed)) return null;
        $found = null;
        array_walk_recursive($parsed, function ($v, $k) use (&$found) {
            if ($found === null && strtolower((string) $k) === 'score' && is_numeric($v)) {
                $found = (int) $v;
            }
        });
        return $found;
    }
}
