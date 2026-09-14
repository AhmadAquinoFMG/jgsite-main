-- Adds the direct JG Wentworth Lead Scoring integration (includes/jgscoring.php):
-- the per-lead outcome columns and the jgscoring_logs audit table.
--
-- HISTORY: this integration was removed once and has now been RESTORED as the
-- source of verified total debt, replacing the Equifax pull. The reason it was
-- pulled the first time still stands as a warning, not as a blocker: the
-- endpoint is JG's lead INTAKE, so every live call creates a lead at JG, and
-- with JG also a buyer on the LeadProsper campaign the consumer was delivered
-- twice — the paying LeadProsper copy came back "duplicated by buyer". Keep JG
-- off that campaign while jgscoring.mode=live.
--
-- What these columns mean: `leads.total_debt` is the debt figure actually posted
-- downstream (JG's total_debt_included), `leads.total_debt_source` says which
-- source produced it ('jgw' | 'buyer'; 'equifax' on historical rows), and
-- `leads.jgw_*` denormalize the scoring outcome for per-lead visibility.
--
-- Idempotent: safe to run against a database that already has some of these.
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_jgscoring.sql

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `total_debt_source`  VARCHAR(10)  DEFAULT NULL AFTER `total_debt`,
    ADD COLUMN IF NOT EXISTS `jgw_mode`           VARCHAR(10)  DEFAULT NULL AFTER `total_debt_source`,
    ADD COLUMN IF NOT EXISTS `jgw_status`         SMALLINT     DEFAULT NULL AFTER `jgw_mode`,
    ADD COLUMN IF NOT EXISTS `jgw_total_debt`     INT UNSIGNED DEFAULT NULL AFTER `jgw_status`,
    ADD COLUMN IF NOT EXISTS `jgw_prequalified`   TINYINT(1)   DEFAULT NULL AFTER `jgw_total_debt`,
    ADD COLUMN IF NOT EXISTS `jgw_accepted`       TINYINT(1)   DEFAULT NULL AFTER `jgw_prequalified`,
    ADD COLUMN IF NOT EXISTS `jgw_disposition`    VARCHAR(64)  DEFAULT NULL AFTER `jgw_accepted`,
    ADD COLUMN IF NOT EXISTS `jgw_credit_rating`  VARCHAR(32)  DEFAULT NULL AFTER `jgw_disposition`,
    ADD COLUMN IF NOT EXISTS `jgw_external_id`    VARCHAR(64)  DEFAULT NULL AFTER `jgw_credit_rating`,
    ADD COLUMN IF NOT EXISTS `jgw_error`          VARCHAR(255) DEFAULT NULL AFTER `jgw_external_id`,
    ADD COLUMN IF NOT EXISTS `jgw_scored_at`      DATETIME     DEFAULT NULL AFTER `jgw_error`;

CREATE TABLE IF NOT EXISTS `jgscoring_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`         BIGINT UNSIGNED DEFAULT NULL,
    `mode`            VARCHAR(10)  NOT NULL DEFAULT 'live',   -- 'mock' | 'live'
    `request_body`    LONGTEXT     DEFAULT NULL,              -- ⚠ full PII (no token — that's a header)
    `response_status` SMALLINT     DEFAULT NULL,              -- HTTP status (0 = no response)
    `response_body`   LONGTEXT     DEFAULT NULL,
    `total_debt`      INT UNSIGNED DEFAULT NULL,              -- parsed total_debt_included
    `prequalified`    TINYINT(1)   DEFAULT NULL,
    `accepted`        TINYINT(1)   DEFAULT NULL,
    `credit_rating`   VARCHAR(32)  DEFAULT NULL,
    `jgw_id`          VARCHAR(64)  DEFAULT NULL,              -- JG's own lead id
    `external_id`     VARCHAR(64)  DEFAULT NULL,
    `error`           VARCHAR(255) DEFAULT NULL,              -- NULL = usable result
    `duration_ms`     INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_lead_id`    (`lead_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_jgscoring_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
