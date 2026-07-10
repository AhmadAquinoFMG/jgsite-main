-- Migration: add the Equifax pull outcome to the `leads` table.
--
-- Run this on an EXISTING database that already has `leads` (created before the
-- equifax_* columns were added to schema.sql). Safe to re-run — every column
-- uses IF NOT EXISTS (MariaDB 10.0.2+ / MySQL 8 support this).
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_equifax.sql
--
-- Fresh databases created from sql/schema.sql already include these columns and
-- do NOT need this migration.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `self_assessed_debt` VARCHAR(64) DEFAULT NULL AFTER `debt_amount`,
    ADD COLUMN IF NOT EXISTS `equifax_mode`      VARCHAR(10)  DEFAULT NULL AFTER `firebase_uid`,
    ADD COLUMN IF NOT EXISTS `equifax_status`    SMALLINT     DEFAULT NULL AFTER `equifax_mode`,
    ADD COLUMN IF NOT EXISTS `equifax_score`     SMALLINT     DEFAULT NULL AFTER `equifax_status`,
    ADD COLUMN IF NOT EXISTS `equifax_decision`  VARCHAR(64)  DEFAULT NULL AFTER `equifax_score`,
    ADD COLUMN IF NOT EXISTS `equifax_error`     VARCHAR(255) DEFAULT NULL AFTER `equifax_decision`,
    ADD COLUMN IF NOT EXISTS `equifax_pulled_at` DATETIME     DEFAULT NULL AFTER `equifax_error`,
    ADD COLUMN IF NOT EXISTS `total_debt`        INT UNSIGNED DEFAULT NULL AFTER `equifax_pulled_at`;
