-- Migration: add `fbc` to the `leads` table.
--
-- Meta's click identifier, in the `fb.<subdomainIndex>.<creationTime>.<fbclid>`
-- format their Conversions API expects. Sourced from the _fbc cookie the pixel
-- writes, or built from the fbclid when that cookie isn't there yet
-- (assets/js/funnel.js).
--
-- Run this on an EXISTING database:
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_fbc.sql
--
-- Fresh databases created from sql/schema.sql already include this column.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `fbc` VARCHAR(255) DEFAULT NULL AFTER `fbp`;
