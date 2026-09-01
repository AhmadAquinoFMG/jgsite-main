-- Migration: portal tables (admin/ — internal lead + post-log viewer).
--
-- Run on an EXISTING database (idempotent — CREATE TABLE IF NOT EXISTS):
--
--   mysql -u <user> -p <database> < sql/alter_add_portal.sql
--
-- Then create the first account, which is the only way in (there is no
-- self-signup and no default password):
--
--   php bin/portal-user.php create you@example.com "Your Name"
--
-- These two tables are the ONLY things the portal writes. Lead data is read
-- exclusively — nothing in admin/ updates or deletes a lead, a log row, or
-- anything else in the pipeline.


-- ---------------------------------------------------------------------------
-- Portal accounts.
--
-- Internal staff only. Accounts are minted from the CLI (bin/portal-user.php),
-- never from the web: a public signup form on a page that exposes consumer PII
-- is not a thing we want to own.
--
-- `password_hash` holds a PHP password_hash() bcrypt digest — 60 chars today,
-- sized to 255 because the column must survive a future algorithm change
-- (argon2id digests are longer) without a migration.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portal_users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(255) NOT NULL,
    `name`          VARCHAR(128) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    -- Deactivate rather than DELETE: portal_audit references user_id, and the
    -- trail is worth more than the row. auth.php re-checks this on every
    -- request, so flipping it to 0 ends an open session at the next page load.
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at` DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Portal audit trail.
--
-- Every login (success AND failure), logout, lead view and CSV export lands
-- here. Two jobs:
--
--   1. Answer "who looked at this consumer's data, and when". Page-level: a
--      view_lead row says an operator opened that lead, not which fields they
--      read.
--   2. Feed the login throttle. admin/includes/auth.php counts recent
--      `login_failed` rows for an email/IP instead of keeping a second table
--      of attempt counters; the audit row has to be written either way.
--
-- NO FOREIGN KEY ON `lead_id`, deliberately. `leads` cascades deletes to its
-- log tables, which is right for those — they are the lead's own records. It is
-- wrong here: erasing a consumer (a deletion request) must not also erase the
-- evidence of who viewed them beforehand. The id is kept as a plain number and
-- may outlive the row it points at.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portal_audit` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- NULL when the actor is unknown: a failed login against an address that
    -- matches no account still gets a row.
    `user_id`    BIGINT UNSIGNED DEFAULT NULL,
    -- The address as TYPED at login. Kept beside user_id because that is the
    -- only identifying thing a failed attempt gives us.
    `email`      VARCHAR(255) DEFAULT NULL,
    -- 'login' | 'login_failed' | 'login_blocked' | 'logout'
    -- | 'view_lead' | 'export'
    `action`     VARCHAR(32)  NOT NULL,
    `lead_id`    BIGINT UNSIGNED DEFAULT NULL,
    -- Free text scoped to the action: the filter and row count of an export,
    -- why a login failed.
    `detail`     VARCHAR(255) DEFAULT NULL,
    `ip`         VARCHAR(45)  DEFAULT NULL,   -- IPv4 or IPv6
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_user`       (`user_id`, `created_at`),
    KEY `idx_lead`       (`lead_id`, `created_at`),
    -- The throttle's query: failures for one email (or one IP) in the last N
    -- minutes. Composite so it is an index range scan, not a filesort.
    KEY `idx_throttle`   (`action`, `email`, `created_at`),
    KEY `idx_throttle_ip` (`action`, `ip`, `created_at`),
    CONSTRAINT `fk_portal_audit_user` FOREIGN KEY (`user_id`)
        REFERENCES `portal_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
