-- Migration: add `bot_suspected` / `bot_reason` to the `leads` table.
--
-- Run this on an EXISTING database:
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_bot_flags.sql
--
-- Fresh databases created from sql/schema.sql already include these columns.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `bot_suspected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_at`,
    ADD COLUMN IF NOT EXISTS `bot_reason` VARCHAR(32) DEFAULT NULL AFTER `bot_suspected`;
