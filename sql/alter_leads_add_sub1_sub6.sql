-- Migration: add `sub1`..`sub6` to the `leads` table.
--
-- Everflow click sub-parameters, captured from the landing URL the same way the
-- utm_* params are (assets/js/funnel.js -> hidden fields in index.php ->
-- submit.php) and forwarded to LeadProsper via LEADPROSPER_TRACKING_PARAMS.
--
-- Only sub1-sub6 are stored. Everflow's click accepts sub1-sub10, but the other
-- four have no consumer here; add them the same way if that changes.
--
-- Values are affiliate-supplied free text with no known format, hence
-- VARCHAR(255) rather than the tighter sizes used for the id-shaped columns.
--
-- MUST be run BEFORE deploying the code change: submit.php builds its INSERT
-- from the keys of $row, so the new fields make every insert fail until these
-- columns exist.
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_sub1_sub6.sql
--
-- Fresh databases created from sql/schema.sql already include these columns.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `sub1` VARCHAR(255) DEFAULT NULL AFTER `source_id`,
    ADD COLUMN IF NOT EXISTS `sub2` VARCHAR(255) DEFAULT NULL AFTER `sub1`,
    ADD COLUMN IF NOT EXISTS `sub3` VARCHAR(255) DEFAULT NULL AFTER `sub2`,
    ADD COLUMN IF NOT EXISTS `sub4` VARCHAR(255) DEFAULT NULL AFTER `sub3`,
    ADD COLUMN IF NOT EXISTS `sub5` VARCHAR(255) DEFAULT NULL AFTER `sub4`,
    ADD COLUMN IF NOT EXISTS `sub6` VARCHAR(255) DEFAULT NULL AFTER `sub5`;
