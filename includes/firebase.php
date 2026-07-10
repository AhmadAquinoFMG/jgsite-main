<?php

/**
 * Firebase ID-token verification — native PHP, no Admin SDK / Composer.
 *
 * The browser signs the user in with Firebase Phone Auth (SMS OTP) and receives
 * a short-lived ID token (a JWT). We cannot trust a client "is_verified" flag,
 * so submit.php passes that token here and we verify it the same way the Admin
 * SDK does:
 *
 *   1. Header: alg must be RS256 and carry a `kid`.
 *   2. Signature: RS256 over "header.payload", checked against Google's public
 *      x509 certificate for that `kid`
 *      (https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com).
 *   3. Claims: `aud` == project id, `iss` == https://securetoken.google.com/<project>,
 *      `exp` in the future, `iat`/`auth_time` in the past, `sub` non-empty.
 *
 * Certs are cached to a temp file (~1h) so we don't fetch on every submit.
 *
 * verify_firebase_token() returns:
 *   ['ok' => true,  'uid' => '…', 'phone_number' => '+1…']
 *   ['ok' => false, 'error' => '<reason>']
 */

if (!function_exists('verify_firebase_token')) {

    function fb_b64url_decode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($s, true) ?: '';
    }

    /**
     * Fetch Google's securetoken x509 certs ({kid => PEM}), cached ~1h.
     * @param string|null $err Out-param: set to a short diagnostic when the
     *                         fetch yields no certs (curl error, HTTP status,
     *                         empty body, or bad JSON).
     */
    function fb_google_certs(?string &$err = null): array
    {
        $err   = null;
        // Google's secure-token x509 certs. NOTE the path is 'robot' (singular);
        // 'robots' (plural) 404s — that was the certs_unavailable:bad_json:http_404
        // root cause. The literal '@' is fine (this exact URL is what works).
        $url   = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
        $cache = sys_get_temp_dir() . '/fb_securetoken_certs.json';

        if (is_readable($cache) && (time() - filemtime($cache) < 3600)) {
            $cached = json_decode((string) file_get_contents($cache), true);
            if (is_array($cached) && $cached) {
                return $cached;
            }
        }

        $body = '';
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                // Advertise + transparently decode gzip/deflate. A compressed
                // response left undecoded is a common cause of "bad_json".
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            // Use a bundled CA file when the host has none configured (a common
            // cause of an empty fetch → unknown_key on managed hosts).
            $ca = defined('FB_CA_BUNDLE') ? FB_CA_BUNDLE : (__DIR__ . '/cacert.pem');
            if (is_readable($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
            $body   = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $cerr   = curl_errno($ch) ? ('curl_' . curl_errno($ch) . ':' . curl_error($ch)) : '';
            curl_close($ch);
            if ($body === '' && $err === null) {
                $err = $cerr ?: ('http_' . $status);
            }
        } else {
            $err = 'no_curl';
        }
        if ($body === '' && ini_get('allow_url_fopen')) {
            $body = (string) @file_get_contents($url, false, stream_context_create([
                'http'  => ['timeout' => 8, 'header' => 'Accept: application/json'],
                'https' => ['timeout' => 8, 'header' => 'Accept: application/json'],
            ]));
            if ($body !== '') {
                $err = null;
            }
        }

        $certs = json_decode($body, true);
        if (!is_array($certs) || !$certs) {
            if ($err === null) {
                // Include HTTP status + a short, whitespace-collapsed snippet of
                // the body so a non-JSON response (WAF/proxy page, error text) is
                // identifiable. The endpoint is public, so the snippet is not secret.
                $snip = substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 80);
                $err = ($body === '')
                    ? 'empty_body:http_' . $status
                    : 'bad_json:http_' . $status . ':' . $snip;
            }
            // fall back to a stale cache if the fetch failed
            if (is_readable($cache)) {
                $stale = json_decode((string) file_get_contents($cache), true);
                if (is_array($stale) && $stale) {
                    return $stale;
                }
            }
            return [];
        }

        @file_put_contents($cache, json_encode($certs));
        return $certs;
    }

    /**
     * @param string $idToken   The Firebase ID token from the client.
     * @param string $projectId Firebase project id (config firebase.project_id).
     * @return array {ok:bool, uid?:string, phone_number?:string, error?:string}
     */
    function verify_firebase_token(string $idToken, string $projectId): array
    {
        if ($projectId === '') {
            return ['ok' => false, 'error' => 'no_project_id'];
        }
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return ['ok' => false, 'error' => 'malformed_token'];
        }
        [$h64, $p64, $s64] = $parts;

        $header  = json_decode(fb_b64url_decode($h64), true);
        $payload = json_decode(fb_b64url_decode($p64), true);
        $sig     = fb_b64url_decode($s64);

        if (!is_array($header) || !is_array($payload)) {
            return ['ok' => false, 'error' => 'malformed_token'];
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return ['ok' => false, 'error' => 'bad_algorithm'];
        }

        // ---- signature ----
        $certErr = null;
        $certs = fb_google_certs($certErr);
        if (!$certs) {
            // Couldn't fetch Google's certs at all — distinct from a token whose
            // key id simply isn't in a valid set. The reason pinpoints the host issue.
            return ['ok' => false, 'error' => 'certs_unavailable:' . ($certErr ?: 'unknown')];
        }
        $pem = $certs[$header['kid']] ?? null;
        if (!$pem) {
            return ['ok' => false, 'error' => 'unknown_key'];
        }
        $pubKey = openssl_pkey_get_public($pem);
        if ($pubKey === false) {
            return ['ok' => false, 'error' => 'bad_cert'];
        }
        $ok = openssl_verify($h64 . '.' . $p64, $sig, $pubKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return ['ok' => false, 'error' => 'bad_signature'];
        }

        // ---- claims ----
        $now = time();
        $skew = 60; // small clock-skew tolerance
        if (($payload['aud'] ?? '') !== $projectId) {
            return ['ok' => false, 'error' => 'bad_audience'];
        }
        if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . $projectId) {
            return ['ok' => false, 'error' => 'bad_issuer'];
        }
        if (($payload['exp'] ?? 0) <= $now - $skew) {
            return ['ok' => false, 'error' => 'expired'];
        }
        if (($payload['iat'] ?? 0) > $now + $skew) {
            return ['ok' => false, 'error' => 'issued_in_future'];
        }
        if (empty($payload['sub'])) {
            return ['ok' => false, 'error' => 'no_subject'];
        }

        return [
            'ok'           => true,
            'uid'          => (string) $payload['sub'],
            'phone_number' => (string) ($payload['phone_number'] ?? ''),
        ];
    }
}
