-- Migration: add `landing_page_url` to the `leads` table.
--
-- Run this on an EXISTING database (whether or not you've already run
-- alter_leads_add_leadprosper_everflow.sql — safe either way, ADD COLUMN IF NOT
-- EXISTS is idempotent):
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_landing_page_url.sql
--
-- Fresh databases created from sql/schema.sql already include this column.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `landing_page_url` VARCHAR(512) DEFAULT NULL AFTER `ef_transaction_id`;
