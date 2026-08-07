<?php

/**
 * Zapier lead push.
 *
 * Exists because call attribution can't carry the lead itself. CallGrid ties
 * custom tags to a visitor *session*, and the only thing joining a session to a
 * call is the number that was dialed — which needs a number pool. With one
 * static tracking number shared by every visitor there is nothing to join on,
 * so every session-scoped tag on the call webhook resolves empty.
 *
 * So the join moves to Zapier, keyed on the caller's phone number: this posts
 * the lead at submit time, the Zap stores it under `phone`, and the CallGrid
 * call webhook (which carries CallerNumber) looks it back up. Both sides are
 * E.164, so they match without normalising at the Zap end.
 *
 * Best-effort, same "log & continue" contract as includes/leadprosper.php:
 * never throws, returns a structured result the caller logs, and the lead
 * submission succeeds regardless of what happens here.
 */

if (!function_exists('zapier_lead_payload')) {

    /**
     * Build the payload. Deliberately mirrors the CallGrid webhook's field names
     * so the Zap's two sides line up key-for-key; `phone` is the join column.
     */
    function zapier_lead_payload(array $row, int $leadId, ?int $totalDebt): array
    {
        $payload = [
            'lead_id'           => $leadId,
            'phone'             => (string) ($row['phone'] ?? ''),
            'email'             => (string) ($row['email'] ?? ''),
            'first_name'        => (string) ($row['first_name'] ?? ''),
            'last_name'         => (string) ($row['last_name'] ?? ''),
            'dob'               => (string) ($row['dob'] ?? ''),
            'state'             => (string) ($row['state'] ?? ''),
            'zip'               => (string) ($row['zip'] ?? ''),
            'city'              => (string) ($row['city'] ?? ''),
            'behind_payment'    => (string) ($row['behind_payment'] ?? ''),
            'employed'          => (string) ($row['employment'] ?? ''),
            'total_debt'        => $totalDebt,
            'fbclid'            => (string) ($row['fbclid'] ?? ''),
            'fbp'               => (string) ($row['fbp'] ?? ''),
            'fbc'               => (string) ($row['fbc'] ?? ''),
            'client_ip_address' => (string) ($row['ip'] ?? ''),
            'client_user_agent' => (string) ($row['user_agent'] ?? ''),
            'submitted_at'      => date('c'),
        ];

        // Drop empties so a blank never overwrites a good stored value on a
        // re-submit from the same phone number.
        return array_filter($payload, static fn($v) => $v !== '' && $v !== null);
    }

    /**
     * Post the lead to the Zapier catch hook.
     *
     * @return array{skip:bool, ok:bool, status:int, error:?string, duration_ms:int}
     */
    function zapier_send_lead(array $cfg, array $row, int $leadId, ?int $totalDebt): array
    {
        $z      = $cfg['zapier'] ?? [];
        $result = ['skip' => false, 'ok' => false, 'status' => 0, 'error' => null, 'duration_ms' => 0];

        // Unset URL is the off switch — nothing is sent and nothing is logged as
        // a failure, so an environment that hasn't been wired up stays quiet.
        $url = trim((string) ($z['lead_webhook_url'] ?? ''));
        if (!($z['enabled'] ?? false) || $url === '') {
            $result['skip'] = true;
            return $result;
        }

        if (!function_exists('curl_init')) {
            $result['error'] = 'curl_unavailable';
            return $result;
        }

        $body = json_encode(zapier_lead_payload($row, $leadId, $totalDebt), JSON_UNESCAPED_SLASHES);

        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($z['timeout'] ?? 8),
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $result['status']      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
        curl_close($ch);

        if ($errno !== 0) {
            $result['error'] = 'curl_error_' . $errno;
            return $result;
        }

        $result['ok'] = $result['status'] >= 200 && $result['status'] < 300;
        if (!$result['ok']) {
            $result['error'] = 'http_' . $result['status'];
        }

        return $result;
    }
}
