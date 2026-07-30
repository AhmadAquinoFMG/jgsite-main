-- Migration: add `behind_payment` to the `leads` table.
--
-- Run this on an EXISTING database:
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_behind_payment.sql
--
-- Fresh databases created from sql/schema.sql already include this column.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `behind_payment` VARCHAR(64) DEFAULT NULL AFTER `self_assessed_debt`;
