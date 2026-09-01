<?php

/**
 * Portal audit trail — who did what, to whose data.
 *
 * Every login attempt, logout, lead view, PII reveal and export writes a row
 * here. Two reasons it exists, and both matter for how it is used:
 *
 *   1. Accountability. The portal masks consumer PII by default and reveals it
 *      server-side specifically so that a reveal is an EVENT that can be
 *      recorded. Client-side masking would put the real value in the page
 *      source and make this table a comfortable fiction.
 *   2. The login throttle reads it. auth.php counts recent 'login_failed' rows
 *      instead of keeping separate attempt counters — the audit row has to be
 *      written for a failure anyway, so it may as well be the source of truth.
 *
 * Failure policy: a write that fails is swallowed AND logged loudly to the ops
 * log. Losing the audit trail must not lock an operator out of the portal
 * mid-incident, but it must never pass silently either — a portal that has
 * quietly stopped auditing looks exactly like one that is auditing fine.
 */

declare(strict_types=1);

if (!function_exists('portal_audit')) {

    /**
     * Write one audit row.
     *
     * @param string $action One of: login, login_failed, login_blocked, logout,
     *                       view_lead, reveal, export.
     * @param array  $opts   user_id, email, lead_id, detail — all optional; the
     *                       actor is filled in from the session when omitted.
     */
    function portal_audit(array $cfg, string $action, array $opts = []): void
    {
        try {
            /* The session may not exist yet (a failed login happens before one),
               so the actor is taken from $opts first and only then from the
               session. */
            $userId = $opts['user_id'] ?? ($_SESSION['portal_user_id'] ?? null);
            $email  = $opts['email']   ?? ($_SESSION['portal_email'] ?? null);

            $row = [
                'user_id'    => $userId !== null ? (int) $userId : null,
                'email'      => $email !== null ? substr((string) $email, 0, 255) : null,
                'action'     => substr($action, 0, 32),
                'lead_id'    => isset($opts['lead_id']) ? (int) $opts['lead_id'] : null,
                'detail'     => isset($opts['detail']) ? substr((string) $opts['detail'], 0, 255) : null,
                'ip'         => substr(portal_client_ip(), 0, 45),
                // Truncated to the column width rather than left to MySQL: in
                // strict mode an over-long value is an error, not a trim, and
                // that error would take the audit row with it.
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ];

            $cols = array_keys($row);
            $sql  = 'INSERT INTO portal_audit (' . implode(', ', $cols) . ') VALUES (:'
                . implode(', :', $cols) . ')';
            db($cfg)->prepare($sql)->execute($row);
        } catch (Throwable $ex) {
            app_log('error', 'portal', 'audit_failed', [
                'action' => $action,
                'error'  => $ex->getMessage(),
            ]);
        }
    }

    /**
     * Best available client IP.
     *
     * REMOTE_ADDR only. The X-Forwarded-For header is deliberately NOT trusted:
     * it is attacker-controlled unless a known proxy sets it, and an audit trail
     * that records a spoofable address is worse than one that records the
     * proxy's. If this ever sits behind a load balancer, resolve the real client
     * here against that proxy's documented header — once, in this one function.
     */
    function portal_client_ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * Count recent rows for an action, by email or by IP. Feeds the login
     * throttle in auth.php.
     *
     * @param string $by 'email' or 'ip'
     */
    function portal_audit_count(array $cfg, string $action, string $by, string $value, int $minutes): int
    {
        if ($value === '') {
            return 0;
        }
        $column = $by === 'ip' ? 'ip' : 'email';
        try {
            $stmt = db($cfg)->prepare(
                "SELECT COUNT(*) FROM portal_audit
                  WHERE action = :action AND {$column} = :value
                    AND created_at >= (NOW() - INTERVAL :minutes MINUTE)"
            );
            /* Bound as an int: INTERVAL will not take a quoted string, and
               emulated prepares are off (includes/db.php), so PDO sends the type
               it is given. */
            $stmt->bindValue(':action', $action, PDO::PARAM_STR);
            $stmt->bindValue(':value', $value, PDO::PARAM_STR);
            $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (Throwable $ex) {
            /* Fail OPEN, not closed. A broken count must not lock every operator
               out of the portal; the failure is logged and the attempt is
               allowed to proceed to the password check, which is still the real
               gate. */
            app_log('error', 'portal', 'throttle_query_failed', ['error' => $ex->getMessage()]);
            return 0;
        }
    }
}
