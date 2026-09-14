<?php

/**
 * Portal account management (admin/) — CLI only.
 *
 * The portal has no signup form, no password-reset email and no default
 * account. Every account is minted here, on a machine that already has database
 * credentials. A web-facing signup on pages that expose consumer PII is not
 * something worth owning, and a default password is worse.
 *
 * Usage, from the project root:
 *
 *   php bin/portal-user.php create you@example.com "Your Name"
 *   php bin/portal-user.php create you@example.com "Your Name" --password=secret
 *   php bin/portal-user.php passwd you@example.com          # reset a password
 *   php bin/portal-user.php list
 *   php bin/portal-user.php disable you@example.com
 *   php bin/portal-user.php enable  you@example.com
 *
 * With no --password, a strong one is generated and printed ONCE. It is stored
 * only as a bcrypt hash, so a lost password is reset, never recovered.
 *
 * Requires sql/alter_add_portal.sql to have been run.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require $root . '/includes/logger.php';
require $root . '/includes/db.php';

logger($cfg);

$args    = array_slice($argv, 1);
$command = $args[0] ?? '';

/** Positional args, minus --flags. */
$positional = array_values(array_filter(
    array_slice($args, 1),
    static fn(string $a): bool => !str_starts_with($a, '--')
));

/** Value of --name=value, or null. */
$flag = static function (string $name) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return null;
};

$fail = static function (string $message): never {
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
};

try {
    $pdo = db($cfg);
} catch (Throwable $ex) {
    $fail('cannot connect to the database: ' . $ex->getMessage());
}

/* Fail with something actionable rather than a raw SQL error when the migration
   has not been run — this is the most likely first-run problem. */
try {
    $pdo->query('SELECT 1 FROM portal_users LIMIT 1');
} catch (Throwable $ex) {
    $fail("table `portal_users` is missing. Run:\n"
        . "  mysql -u <user> -p <database> < sql/alter_add_portal.sql");
}

$normaliseEmail = static function (?string $email) use ($fail): string {
    $email = strtolower(trim((string) $email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail('a valid email address is required');
    }
    return $email;
};

switch ($command) {

    case 'create': {
            $email = $normaliseEmail($positional[0] ?? null);
            $name  = trim((string) ($positional[1] ?? ''));
            if ($name === '') {
                $fail('a display name is required: php bin/portal-user.php create <email> "<name>"');
            }

            $stmt = $pdo->prepare('SELECT id FROM portal_users WHERE email = :email');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $fail("an account already exists for {$email} — use `passwd` to reset it");
            }

            $password  = $flag('password');
            $generated = $password === null;
            if ($generated) {
                /* 18 bytes of CSPRNG, base64 without padding — ~24 chars, well
                   beyond anything the login throttle would ever let through. */
                $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Ab'), '=');
            }

            $pdo->prepare(
                'INSERT INTO portal_users (email, name, password_hash) VALUES (:email, :name, :hash)'
            )->execute([
                'email' => $email,
                'name'  => $name,
                'hash'  => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);

            echo "created: {$email} ({$name})\n";
            if ($generated) {
                echo "password: {$password}\n";
                echo "\nThis is shown once and stored only as a hash. Save it now.\n";
            }
            break;
        }

    case 'passwd': {
            $email = $normaliseEmail($positional[0] ?? null);

            $stmt = $pdo->prepare('SELECT id FROM portal_users WHERE email = :email');
            $stmt->execute(['email' => $email]);
            if (!$stmt->fetch()) {
                $fail("no account for {$email}");
            }

            $password  = $flag('password');
            $generated = $password === null;
            if ($generated) {
                $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Ab'), '=');
            }

            $pdo->prepare('UPDATE portal_users SET password_hash = :hash WHERE email = :email')
                ->execute([
                    'hash'  => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                    'email' => $email,
                ]);

            echo "password reset: {$email}\n";
            if ($generated) {
                echo "password: {$password}\n";
                echo "\nThis is shown once and stored only as a hash. Save it now.\n";
            }
            break;
        }

    case 'disable':
    case 'enable': {
            $email  = $normaliseEmail($positional[0] ?? null);
            $active = $command === 'enable' ? 1 : 0;

            $stmt = $pdo->prepare('UPDATE portal_users SET is_active = :active WHERE email = :email');
            $stmt->execute(['active' => $active, 'email' => $email]);
            if ($stmt->rowCount() === 0) {
                /* rowCount is 0 both for "no such account" and for "already in
                   that state" — say so rather than claiming success. */
                echo "no change: {$email} is missing or already "
                    . ($active ? "enabled" : "disabled") . "\n";
                break;
            }
            echo ($active ? 'enabled' : 'disabled') . ": {$email}\n";
            /* A disabled account's open session ends at its next page load —
               auth.php re-reads is_active on every request. */
            break;
        }

    case 'list': {
            $rows = $pdo->query(
                'SELECT id, email, name, is_active, last_login_at, created_at
                   FROM portal_users ORDER BY created_at'
            )->fetchAll();

            if (!$rows) {
                echo "no accounts yet. Create one:\n";
                echo "  php bin/portal-user.php create you@example.com \"Your Name\"\n";
                break;
            }

            printf("%-4s %-34s %-22s %-9s %s\n", 'ID', 'EMAIL', 'NAME', 'STATUS', 'LAST LOGIN');
            foreach ($rows as $r) {
                printf(
                    "%-4s %-34s %-22s %-9s %s\n",
                    $r['id'],
                    $r['email'],
                    substr((string) $r['name'], 0, 22),
                    ((int) $r['is_active'] === 1 ? 'active' : 'disabled'),
                    $r['last_login_at'] ?? '—'
                );
            }
            break;
        }

    default:
        echo "Portal account management\n\n";
        echo "  php bin/portal-user.php create <email> \"<name>\" [--password=…]\n";
        echo "  php bin/portal-user.php passwd <email> [--password=…]\n";
        echo "  php bin/portal-user.php disable <email>\n";
        echo "  php bin/portal-user.php enable  <email>\n";
        echo "  php bin/portal-user.php list\n\n";
        echo "Passwords are generated and printed once unless --password is given.\n";
        exit($command === '' ? 0 : 1);
}
