-- Migration: add Everflow attribution + LeadProsper outcome to the `leads` table,
-- and create the leadprosper_logs audit table.
--
-- Run this on an EXISTING database that already has `leads` (created before
-- these columns were added to schema.sql). Safe to re-run — every column uses
-- IF NOT EXISTS (MariaDB 10.0.2+ / MySQL 8 support this).
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_leadprosper_everflow.sql
--
-- Fresh databases created from sql/schema.sql already include these and do NOT
-- need this migration.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `fbclid`           VARCHAR(255) DEFAULT NULL AFTER `gclid`,
    ADD COLUMN IF NOT EXISTS `affid`            VARCHAR(64)  DEFAULT NULL AFTER `fbclid`,
    ADD COLUMN IF NOT EXISTS `oid`              VARCHAR(64)  DEFAULT NULL AFTER `affid`,
    ADD COLUMN IF NOT EXISTS `ef_transaction_id` VARCHAR(128) DEFAULT NULL AFTER `oid`,
    ADD COLUMN IF NOT EXISTS `lp_mode`          VARCHAR(10)  DEFAULT NULL AFTER `ef_transaction_id`,
    ADD COLUMN IF NOT EXISTS `lp_status`        SMALLINT     DEFAULT NULL AFTER `lp_mode`,
    ADD COLUMN IF NOT EXISTS `lp_accepted`      TINYINT(1)   DEFAULT NULL AFTER `lp_status`,
    ADD COLUMN IF NOT EXISTS `lp_error`         VARCHAR(255) DEFAULT NULL AFTER `lp_accepted`,
    ADD COLUMN IF NOT EXISTS `lp_posted_at`     DATETIME     DEFAULT NULL AFTER `lp_error`;

CREATE TABLE IF NOT EXISTS `leadprosper_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`         BIGINT UNSIGNED DEFAULT NULL,
    `mode`            VARCHAR(10)  NOT NULL DEFAULT 'live',
    `request_body`    LONGTEXT     DEFAULT NULL,
    `response_status` SMALLINT     DEFAULT NULL,
    `response_body`   LONGTEXT     DEFAULT NULL,
    `accepted`        TINYINT(1)   DEFAULT NULL,
    `error`           VARCHAR(255) DEFAULT NULL,
    `duration_ms`     INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_lead_id`    (`lead_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_leadprosper_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
