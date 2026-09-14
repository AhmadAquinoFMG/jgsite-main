-- Migration: add `submit_nonce` to the `leads` table.
--
-- The idempotency key for one submission attempt. index.php mints 32 random hex
-- characters per pageview into a hidden field; assets/js/funnel.js re-POSTs the
-- SAME FormData when a submit fails, so a retry carries the same nonce as the
-- attempt it is retrying and lands on the UNIQUE below instead of creating a
-- second lead (and a second billed LeadProsper post).
--
-- Why a UNIQUE index rather than a SELECT-then-INSERT check in submit.php: two
-- concurrent POSTs both pass a check and both insert. Only the index is
-- race-free. submit.php catches the 23000 and answers with the ORIGINAL lead's
-- redirect, so the visitor still reaches the thank-you page.
--
-- NULLs are exempt from a UNIQUE index in MariaDB/MySQL, which is deliberate:
-- historical rows, and any POST that arrives without a nonce (no-JS, a stale
-- cached page), still insert normally — they just lose this protection.
--
-- MUST be run BEFORE deploying the code change: submit.php builds its INSERT
-- from the keys of $row, so the new field makes every insert fail until this
-- column exists.
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_submit_nonce.sql
--
-- Fresh databases created from sql/schema.sql already include this column.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `submit_nonce` CHAR(32) DEFAULT NULL AFTER `form_name`,
    ADD UNIQUE KEY IF NOT EXISTS `uniq_submit_nonce` (`submit_nonce`);
