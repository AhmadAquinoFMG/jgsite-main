-- Migration: widen the LeadProsper/advertiser passthrough id columns from
-- VARCHAR(128) to VARCHAR(255).
--
-- Why: `lp_subid1`-`lp_subid6` receive the values of `sub1`-`sub6`, which are
-- VARCHAR(255) (includes/leadprosper.php maps the Everflow subs across when the
-- landing URL left an lp_subid slot empty). A sub value between 129 and 255
-- characters therefore fitted its own column but not its destination — and
-- under the server's default STRICT_TRANS_TABLES that throws on INSERT, which
-- submit.php can only turn into a 500. The lead was lost outright because of a
-- tracking param.
--
-- `subid` and `adv1`-`adv5` are the same kind of value from the same source
-- (affiliate/advertiser-supplied free text, no known format) and are widened
-- with them so the whole passthrough family is one consistent size.
--
-- Widening only: no existing value can fail to fit, so this is safe to run on a
-- live table and safe to re-run.
--
-- Pairs with the server-side clamp added to submit.php ($attributionWidths),
-- which truncates to these widths so an over-long value can never fail an
-- insert again. Run this FIRST — the clamp allows up to 255 for these fields.
--
--   mysql -u <user> -p <database> < sql/alter_leads_widen_passthrough_ids.sql
--
-- Fresh databases created from sql/schema.sql already use these widths.

-- Preflight, and the reason this migration is REQUIRED rather than optional:
-- submit.php builds its INSERT from the keys of $row, and that row now carries
-- `sub6` and `lp_subid6` (they were previously missing from it, which is why
-- both columns have always been NULL). If either column does not exist on this
-- database, EVERY submission fails once the code is deployed.
--
-- Both have their own earlier migrations (alter_leads_add_sub1_sub6.sql,
-- alter_leads_add_lp_subid6.sql), but since nothing ever wrote to them their
-- absence would have gone unnoticed. Re-asserted here so running this one file
-- is enough to make the schema match the new code.
ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `sub6`      VARCHAR(255) DEFAULT NULL AFTER `sub5`,
    ADD COLUMN IF NOT EXISTS `lp_subid6` VARCHAR(255) DEFAULT NULL AFTER `lp_subid5`;

ALTER TABLE `leads`
    MODIFY COLUMN `subid`     VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `lp_subid1` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `lp_subid2` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `lp_subid3` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `lp_subid4` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `lp_subid5` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `lp_subid6` VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `adv1`      VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `adv2`      VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `adv3`      VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `adv4`      VARCHAR(255) DEFAULT NULL,
    MODIFY COLUMN `adv5`      VARCHAR(255) DEFAULT NULL;
