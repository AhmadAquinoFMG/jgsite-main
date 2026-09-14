<?php

/**
 * Cloudflare Turnstile server-side verification.
 *
 * Called from submit.php as part of bot detection (see docs/bot-protection.md).
 * Best-effort in shape (never throws) but its result IS blocking — unlike
 * JG/LeadProsper's "log & continue", a failed verify sets $botReason in
 * submit.php, which routes the lead away from JG/LeadProsper (but still
 * returns {ok:true} to the caller — see submit.php).
 */

if (!function_exists('turnstile_verify')) {

    /**
     * @return array{ok:bool, error:?string, raw:?string}
     */
    function turnstile_verify(array $cfg, string $token, string $remoteIp): array
    {
        $ts = $cfg['turnstile'] ?? [];

        if ($token === '') {
            return ['ok' => false, 'error' => 'missing_token', 'raw' => null];
        }
        if (($ts['secret_key'] ?? '') === '') {
            return ['ok' => false, 'error' => 'not_configured', 'raw' => null];
        }

        $body = http_build_query([
            'secret'   => $ts['secret_key'],
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $http = turnstile_http(
            (string) ($ts['endpoint'] ?? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            $body,
            (int) ($ts['timeout'] ?? 10)
        );

        if ($http['error'] !== null) {
            return ['ok' => false, 'error' => $http['error'], 'raw' => null];
        }

        $decoded = json_decode((string) $http['body'], true);
        $ok = is_array($decoded) && !empty($decoded['success']);

        return [
            'ok'    => $ok,
            'error' => $ok ? null : ('turnstile_' . implode(',', $decoded['error-codes'] ?? ['unknown'])),
            'raw'   => $http['body'],
        ];
    }

    /** Minimal curl wrapper. Returns ['status'=>int, 'body'=>?string, 'error'=>?string]. */
    function turnstile_http(string $url, string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'curl_unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
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
