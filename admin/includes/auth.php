<?php

/**
 * Portal authentication — session handling, login, logout, CSRF, throttling.
 *
 * The whole admin area is behind this. Every page except login.php starts with:
 *
 *     require __DIR__ . '/includes/auth.php';
 *     $user = portal_require_login($cfg);
 *
 * Design notes that are easy to get wrong later:
 *
 *  • SEPARATE SESSION. index.php, submit.php and thank-you.php all call
 *    session_start() on this same domain under the default PHPSESSID. The
 *    portal uses its own cookie name and scopes the cookie to the admin
 *    directory, so the two never collide — an operator with the funnel open in
 *    another tab does not get logged out, and the funnel's session does not
 *    carry an admin identity around the public site.
 *
 *  • THE SESSION HOLDS AN ID, NOT A USER. Every request re-reads the row, so
 *    deactivating an account (`is_active = 0`) ends any open session at the
 *    next page load rather than whenever the cookie happens to expire.
 *
 *  • TIMING. Login compares a password hash even when no account matched, so a
 *    wrong address and a wrong password take the same time. Without it the
 *    response time tells an attacker which addresses are real.
 */

declare(strict_types=1);

if (!function_exists('portal_session_start')) {

    /**
     * Start the portal session with hardened cookie settings.
     *
     * Safe to call repeatedly; does nothing if a session is already active.
     */
    function portal_session_start(array $cfg): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $p = $cfg['portal'] ?? [];

        /* Scope the cookie to the admin directory rather than hardcoding
           '/admin': in local development the project often sits in a subfolder
           (localhost/jg-main/admin/…), where a fixed path would silently never
           match and the login would appear to succeed and then bounce. */
        $dir  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))), '/');
        $path = ($dir === '' ? '' : $dir) . '/';

        $secure = !empty($p['cookie_secure'])
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');

        session_name((string) ($p['session_name'] ?? 'JGPORTALSESS'));
        session_set_cookie_params([
            'lifetime' => 0,            // browser-session cookie; expiry is enforced server-side below
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,         // never readable from JavaScript
            'samesite' => 'Strict',     // no cross-site sends at all; the portal has no external entry points
        ]);
        session_start();
    }

    /**
     * The signed-in user, or null.
     *
     * Enforces both timeouts and re-reads the account on every call, so a
     * deactivated or deleted user cannot keep browsing on a live cookie.
     */
    function portal_current_user(array $cfg): ?array
    {
        portal_session_start($cfg);

        $userId = $_SESSION['portal_user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        $p    = $cfg['portal'] ?? [];
        $now  = time();
        $idle = (int) ($p['idle_timeout'] ?? 3600);
        $max  = (int) ($p['max_lifetime'] ?? 43200);

        $lastSeen  = (int) ($_SESSION['portal_last_seen'] ?? 0);
        $startedAt = (int) ($_SESSION['portal_started_at'] ?? 0);

        if (($idle > 0 && $lastSeen > 0 && ($now - $lastSeen) > $idle)
            || ($max > 0 && $startedAt > 0 && ($now - $startedAt) > $max)
        ) {
            portal_session_destroy();
            return null;
        }

        try {
            $stmt = db($cfg)->prepare(
                'SELECT id, email, name, is_active, last_login_at FROM portal_users WHERE id = :id'
            );
            $stmt->execute(['id' => (int) $userId]);
            $user = $stmt->fetch();
        } catch (Throwable $ex) {
            app_log('error', 'portal', 'user_lookup_failed', ['error' => $ex->getMessage()]);
            return null;
        }

        if (!$user || (int) $user['is_active'] !== 1) {
            portal_session_destroy();
            return null;
        }

        $_SESSION['portal_last_seen'] = $now;
        return $user;
    }

    /**
     * Gate a page. Redirects to the login form and never returns if signed out.
     *
     * The requested path is remembered so login can send the operator back to
     * where they were aiming instead of dumping everyone on the index.
     */
    function portal_require_login(array $cfg): array
    {
        $user = portal_current_user($cfg);
        if ($user !== null) {
            return $user;
        }
        $_SESSION['portal_intended'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        header('Location: login.php');
        exit;
    }

    /**
     * Verify credentials and open a session.
     *
     * @return array{ok:bool, error:?string} error is a message safe to display.
     */
    function portal_login(array $cfg, string $email, string $password): array
    {
        portal_session_start($cfg);
        $email = strtolower(trim($email));
        $p     = $cfg['portal'] ?? [];

        $maxAttempts = (int) ($p['max_attempts'] ?? 5);
        $lockout     = (int) ($p['lockout_minutes'] ?? 15);

        /* Throttle on the address AND on the source IP. Address alone lets one
           host walk a list of accounts; IP alone lets a botnet grind one
           account. Both are counted out of portal_audit's login_failed rows. */
        if ($maxAttempts > 0) {
            $byEmail = portal_audit_count($cfg, 'login_failed', 'email', $email, $lockout);
            $byIp    = portal_audit_count($cfg, 'login_failed', 'ip', portal_client_ip(), $lockout);
            if ($byEmail >= $maxAttempts || $byIp >= $maxAttempts) {
                portal_audit($cfg, 'login_blocked', [
                    'email'  => $email,
                    'detail' => sprintf('throttled: %d by email, %d by ip, window %dm', $byEmail, $byIp, $lockout),
                ]);
                app_log('warning', 'portal', 'login_blocked', ['email' => $email, 'ip' => portal_client_ip()]);
                return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . $lockout . ' minutes.'];
            }
        }

        try {
            $stmt = db($cfg)->prepare(
                'SELECT id, email, name, password_hash, is_active FROM portal_users WHERE email = :email'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
        } catch (Throwable $ex) {
            app_log('error', 'portal', 'login_query_failed', ['error' => $ex->getMessage()]);
            return ['ok' => false, 'error' => 'Sign-in is unavailable right now.'];
        }

        /* Always run a hash comparison, even with no account, so the response
           time does not reveal which addresses exist. The dummy is a real bcrypt
           digest of a value nothing can match. */
        $hash = $user['password_hash']
            ?? '$2y$12$usesomesillystringfor.eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $passwordOk = password_verify($password, $hash);

        if (!$user || !$passwordOk || (int) $user['is_active'] !== 1) {
            /* One message for every failure mode. Distinguishing "no such
               account" from "wrong password" from "deactivated" hands out a
               free account-enumeration oracle; the specific reason goes to the
               audit trail, where it is useful and not public. */
            $reason = !$user ? 'no_account' : (!$passwordOk ? 'bad_password' : 'inactive');
            portal_audit($cfg, 'login_failed', [
                'user_id' => $user['id'] ?? null,
                'email'   => $email,
                'detail'  => $reason,
            ]);
            app_log('warning', 'portal', 'login_failed', [
                'email' => $email, 'reason' => $reason, 'ip' => portal_client_ip(),
            ]);
            return ['ok' => false, 'error' => 'Incorrect email or password.'];
        }

        /* New session id on privilege change — otherwise a session id planted
           before login (session fixation) stays valid after it. */
        session_regenerate_id(true);

        $_SESSION['portal_user_id']    = (int) $user['id'];
        $_SESSION['portal_email']      = $user['email'];
        $_SESSION['portal_name']       = $user['name'];
        $_SESSION['portal_started_at'] = time();
        $_SESSION['portal_last_seen']  = time();

        try {
            db($cfg)->prepare('UPDATE portal_users SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $user['id']]);
        } catch (Throwable $ex) {
            // Cosmetic column — a failure here is not worth refusing the login.
            app_log('warning', 'portal', 'last_login_update_failed', ['error' => $ex->getMessage()]);
        }

        portal_audit($cfg, 'login', ['user_id' => (int) $user['id'], 'email' => $user['email']]);
        app_log('info', 'portal', 'login', ['user_id' => (int) $user['id'], 'email' => $user['email']]);

        return ['ok' => true, 'error' => null];
    }

    /** Audit the logout, then tear the session down. */
    function portal_logout(array $cfg): void
    {
        portal_session_start($cfg);
        if (!empty($_SESSION['portal_user_id'])) {
            portal_audit($cfg, 'logout');
            app_log('info', 'portal', 'logout', ['user_id' => $_SESSION['portal_user_id']]);
        }
        portal_session_destroy();
    }

    /** Clear session data, the cookie, and the session file. */
    function portal_session_destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * CSRF token for this session, minted on first use.
     *
     * SameSite=Strict already blocks the cross-site POST this defends against,
     * but it is one browser setting away from being the only defence — and the
     * token costs nothing.
     */
    function portal_csrf_token(array $cfg): string
    {
        portal_session_start($cfg);
        if (empty($_SESSION['portal_csrf'])) {
            $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['portal_csrf'];
    }

    /** Constant-time check of a submitted CSRF token. */
    function portal_csrf_check(array $cfg, ?string $token): bool
    {
        portal_session_start($cfg);
        $known = (string) ($_SESSION['portal_csrf'] ?? '');
        return $known !== '' && is_string($token) && hash_equals($known, $token);
    }
}
