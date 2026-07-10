<?php

/**
 * Database access — a lazy PDO singleton for MySQL/MariaDB (Cloudways).
 *
 * No Composer/ORM: uses PHP's built-in PDO. Credentials come from config.php
 * ($cfg['db'], fed by env()). Call db() to get the shared connection; it is
 * created on first use and reused thereafter.
 *
 *   $pdo = db();
 *   $stmt = $pdo->prepare('INSERT INTO leads (...) VALUES (...)');
 *
 * Errors throw PDOException (ERRMODE_EXCEPTION) so callers can catch and return
 * a clean JSON error instead of leaking a stack trace.
 */

if (!function_exists('db')) {
    /**
     * @param array|null $cfg The full config array (from config.php). Required on
     *                        the first call; cached for subsequent calls.
     */
    function db(?array $cfg = null): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        if ($cfg === null || empty($cfg['db'])) {
            throw new RuntimeException('db(): database config not provided on first call.');
        }

        $d = $cfg['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $d['host'] ?? '127.0.0.1',
            $d['port'] ?? '3306',
            $d['name'] ?? '',
            $d['charset'] ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }
}
