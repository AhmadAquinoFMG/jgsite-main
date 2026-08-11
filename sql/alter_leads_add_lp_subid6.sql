-- Migration: add `lp_subid6` to the `leads` table.
--
-- Sixth LeadProsper passthrough sub-id. The table already carried lp_subid1-5;
-- a sixth is needed because Everflow's sub1-sub6 are mapped onto lp_subid1-6
-- when the lead is posted (includes/leadprosper.php, leadprosper_payload()).
--
-- Like its siblings it is also captured directly from ?lp_subid6= on the
-- landing URL, and an explicit URL value takes precedence over the mapped
-- Everflow sub.
--
-- MUST be run BEFORE deploying the code change: submit.php builds its INSERT
-- from the keys of $row, so the new field makes every insert fail until this
-- column exists.
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_lp_subid6.sql
--
-- Fresh databases created from sql/schema.sql already include this column.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `lp_subid6` VARCHAR(128) DEFAULT NULL AFTER `lp_subid5`;
