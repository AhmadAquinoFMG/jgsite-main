-- Migration: add the full attribution field set (matching what's actually
-- configured on the LeadProsper campaign) to the `leads` table.
--
-- Run this on an EXISTING database (safe alongside/after the other alter_*
-- migrations already run — every column uses IF NOT EXISTS):
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_full_attribution.sql
--
-- Fresh databases created from sql/schema.sql already include these columns.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `utm_creative`  VARCHAR(128) DEFAULT NULL AFTER `utm_content`,
    ADD COLUMN IF NOT EXISTS `utm_placement` VARCHAR(128) DEFAULT NULL AFTER `utm_creative`,
    ADD COLUMN IF NOT EXISTS `utm_adgroup`   VARCHAR(128) DEFAULT NULL AFTER `utm_placement`,
    ADD COLUMN IF NOT EXISTS `utm_matchtype` VARCHAR(64)  DEFAULT NULL AFTER `utm_adgroup`,
    ADD COLUMN IF NOT EXISTS `gbraid`        VARCHAR(255) DEFAULT NULL AFTER `gclid`,
    ADD COLUMN IF NOT EXISTS `fbp`           VARCHAR(255) DEFAULT NULL AFTER `fbclid`,
    ADD COLUMN IF NOT EXISTS `fb_adid`       VARCHAR(128) DEFAULT NULL AFTER `fbp`,
    ADD COLUMN IF NOT EXISTS `ms_placement`  VARCHAR(128) DEFAULT NULL AFTER `fb_adid`,
    ADD COLUMN IF NOT EXISTS `ms_publisher`  VARCHAR(128) DEFAULT NULL AFTER `ms_placement`,
    ADD COLUMN IF NOT EXISTS `ttclid`        VARCHAR(255) DEFAULT NULL AFTER `ms_publisher`,
    ADD COLUMN IF NOT EXISTS `subid`         VARCHAR(128) DEFAULT NULL AFTER `ttclid`,
    ADD COLUMN IF NOT EXISTS `source_id`     VARCHAR(64)  DEFAULT NULL AFTER `oid`,
    ADD COLUMN IF NOT EXISTS `lp_subid1`     VARCHAR(128) DEFAULT NULL AFTER `landing_page_url`,
    ADD COLUMN IF NOT EXISTS `lp_subid2`     VARCHAR(128) DEFAULT NULL AFTER `lp_subid1`,
    ADD COLUMN IF NOT EXISTS `lp_subid3`     VARCHAR(128) DEFAULT NULL AFTER `lp_subid2`,
    ADD COLUMN IF NOT EXISTS `lp_subid4`     VARCHAR(128) DEFAULT NULL AFTER `lp_subid3`,
    ADD COLUMN IF NOT EXISTS `lp_subid5`     VARCHAR(128) DEFAULT NULL AFTER `lp_subid4`,
    ADD COLUMN IF NOT EXISTS `adv1`          VARCHAR(128) DEFAULT NULL AFTER `lp_subid5`,
    ADD COLUMN IF NOT EXISTS `adv2`          VARCHAR(128) DEFAULT NULL AFTER `adv1`,
    ADD COLUMN IF NOT EXISTS `adv3`          VARCHAR(128) DEFAULT NULL AFTER `adv2`,
    ADD COLUMN IF NOT EXISTS `adv4`          VARCHAR(128) DEFAULT NULL AFTER `adv3`,
    ADD COLUMN IF NOT EXISTS `adv5`          VARCHAR(128) DEFAULT NULL AFTER `adv4`;
