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

        // ---- build the credit-report request (contract-specific shape) ----
        $url = ($eq['base_url'] ?? '') . '/business/consumer-credit/v1/reports';
        [$y, $m, $d] = array_pad(explode('-', (string) ($lead['dob'] ?? '')), 3, '');
        $requestArr = [
            'consumers' => [
                'name' => [[
                    'identifier' => 'current',
                    'firstName'  => $lead['first_name'] ?? '',
                    'lastName'   => $lead['last_name'] ?? '',
                ]],
                'socialNum' => [[
                    'identifier' => 'current',
                    'number'     => $ssn,
                ]],
                'dateOfBirth' => sprintf('%s-%s-%s', $m, $d, $y), // MM-DD-YYYY
                'addresses'   => [[
                    'identifier'   => 'current',
                    'houseNumber'  => '',
                    'streetName'   => $lead['street'] ?? '',
                    'city'         => $lead['city'] ?? '',
                    'state'        => $lead['state'] ?? '',
                    'zip'          => $lead['zip'] ?? '',
                ]],
            ],
            'customerConfiguration' => [
                'equifaxUSConsumerCreditReport' => [
                    'memberNumber' => $eq['member_number'] ?? '',
                    'securityCode' => $eq['security_code'] ?? '',
                    'models'       => [['identifier' => 'Score']],
                ],
            ],
        ];
        $requestBody = json_encode($requestArr, JSON_UNESCAPED_SLASHES);

        // Redact the SSN in what we STORE (the wire request still uses the real one).
        $storedRequestBody = !empty($eq['redact'])
            ? equifax_redact_ssn($requestBody, $ssn)
            : $requestBody;

        $result = [
            'skip'         => false,
            'mode'         => $mode,
            'request_url'  => $url,
            'request_body' => $storedRequestBody,
        ];

        // ---- mock: synthesize a response, no network ----
        if ($mode === 'mock') {
            $mockResponse = json_encode([
                'consumers' => ['equifaxUSConsumerCreditReport' => [[
                    'models' => [['type' => 'Score', 'score' => '0742']],
                    'identityScanAlerts' => [],
                ]]],
                '_mock' => true,
            ], JSON_UNESCAPED_SLASHES);
            return $result + [
                'response_status' => 200,
                'response_body'   => $mockResponse,
                'score'           => 742,
                'decision'        => 'mock',
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
                'error'           => 'auth_failed: ' . $err,
                'duration_ms'     => 0,
            ];
        }

        $t0 = microtime(true);
        $http = equifax_http('POST', $url, $requestBody, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], (int) ($eq['timeout'] ?? 15));
        $duration = (int) round((microtime(true) - $t0) * 1000);

        $score    = null;
        $decision = null;
        if ($http['status'] >= 200 && $http['status'] < 300 && $http['body']) {
            $parsed = json_decode($http['body'], true);
            $score  = equifax_extract_score($parsed);
        }

        return $result + [
            'response_status' => $http['status'],
            'response_body'   => $http['body'],
            'score'           => $score,
            'decision'        => $decision,
            'error'           => $http['error'] ?: ($http['status'] >= 400 ? 'http_' . $http['status'] : null),
            'duration_ms'     => $duration,
        ];
    }

    /** OAuth2 client-credentials token. Returns access token or null (sets $err). */
    function equifax_token(array $cfg, ?string &$err = null): ?string
    {
        $eq  = $cfg['equifax'] ?? [];
        $url = ($eq['base_url'] ?? '') . '/v2/oauth/token';
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => $eq['scope'] ?? '',
        ]);
        $http = equifax_http('POST', $url, $body, [
            'Authorization: Basic ' . base64_encode(($eq['client_id'] ?? '') . ':' . ($eq['client_secret'] ?? '')),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], (int) ($eq['timeout'] ?? 15));

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

    /** Minimal curl wrapper. Returns ['status'=>int, 'body'=>?string, 'error'=>?string]. */
    function equifax_http(string $method, string $url, string $body, array $headers, int $timeout): array
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
        ]);
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
