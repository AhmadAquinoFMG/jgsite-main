-- Adds the buyer registry (`buyers`) and records which buyer took each lead
-- (`leads.lp_accepted_buyer`).
--
-- Idempotent: safe to run against a database that already has some of these.
-- This file is the canonical definition of `buyers` (the table is not in
-- schema.sql), so it is also where the table GROWS — re-run it after a pull to
-- pick up new columns such as `did`.
--
--   mysql -u <user> -p <database> < sql/alter_add_buyers.sql
--
-- Context: LeadProsper's direct_post response names the buyer that accepted the
-- lead (includes/leadprosper.php → leadprosper_accepted_buyer()). submit.php
-- forwards that name to the thank-you page as ?buyer=, and thank-you.php looks
-- it up here to decide whose logo — if anyone's — to show under the savings
-- callout. Keeping it in a table rather than in code means adding a buyer is a
-- row, not a deploy.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `lp_accepted_buyer` VARCHAR(128) DEFAULT NULL AFTER `lp_accepted`;

CREATE TABLE IF NOT EXISTS `buyers` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- MATCH TOKEN, not a display name. Compared as a case-insensitive SUBSTRING
    -- of the buyer name LeadProsper returns, so 'InCharge' matches the campaign's
    -- "InCharge Debt Solutions" without this column having to track their exact
    -- spelling. Same substring convention as leadprosper.buyer_total_debt_from.
    -- Keep it as short as stays unambiguous across the campaign's buyers.
    `name`       VARCHAR(128) NOT NULL,

    -- Consumer-facing name, used as the logo's alt text. NULL falls back to `name`.
    `label`      VARCHAR(128) DEFAULT NULL,

    -- Web-root-relative path to the logo, e.g. 'assets/img/buyers/foo.webp'.
    -- NULL/empty means "matched, but nothing to render" — the same visible
    -- outcome as show_logo = 0, reached without editing the flag.
    `logo_path`  VARCHAR(255) DEFAULT NULL,

    -- The buyer's own inbound number, rendered on the thank-you page's CALL NOW
    -- button when this buyer took the lead — so the consumer reads the number of
    -- the company that actually bought them, not one shared line for everyone.
    -- Format is not significant: store it in whatever shape is convenient and
    -- includes/buyers.php renders it as '(855) 600-0593' with a '+1…' tel: href.
    -- NULL/empty falls back to the row named by ['prequal']['cta_buyer'].
    --
    -- This does NOT replace CallGrid: the number pool still assigns a tracking
    -- DID client-side and still rewrites the tel: target, exactly as before. This
    -- column only changes which number is printed on (and dialled from) the
    -- button before that assignment lands.
    `did`        VARCHAR(32)  DEFAULT NULL,

    -- 0 switches CallGrid OFF for this buyer's thank-you page: the SDK is not
    -- loaded, no pooled number is assigned, and the tel: target stays on the
    -- `did` above. Use it for a buyer whose calls we do not route through our own
    -- tracking — InCharge takes their calls on their own line, so putting our
    -- pool in front of it would re-route a call the buyer already owns and
    -- attribute it to a campaign source that isn't paying for it.
    --
    -- 1 (the default, and JG's setting) keeps the number pool: the button still
    -- READS the buyer's did, and CallGrid swaps the tel: target once it assigns.
    -- An unmatched buyer is treated as 1 — the shared config number is ours, so
    -- there is nothing to protect and every reason to track it.
    `use_callgrid` TINYINT(1) NOT NULL DEFAULT 1,

    -- 0 suppresses the logo for a buyer we still want on file. This is how JG
    -- Wentworth is handled: the funnel is already JG-branded end to end, so
    -- repeating their logo under the savings figure says nothing. A row with the
    -- flag off is deliberate and self-documenting; a missing row is ambiguous.
    `show_logo`  TINYINT(1)   NOT NULL DEFAULT 1,

    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- One row per match token. Also makes the seed below re-runnable.
    UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The CREATE above is a no-op on a database that already has `buyers`, so every
-- column added to the table after its first release needs an explicit ALTER too.
-- Keep the two in step: a column in the CREATE but not here reaches fresh
-- installs only, and existing databases silently miss it.
ALTER TABLE `buyers`
    ADD COLUMN IF NOT EXISTS `did`          VARCHAR(32) DEFAULT NULL     AFTER `logo_path`,
    ADD COLUMN IF NOT EXISTS `use_callgrid` TINYINT(1)  NOT NULL DEFAULT 1 AFTER `did`;

-- The campaign's two buyers. INSERT ... ON DUPLICATE KEY so re-running the file
-- refreshes the logo path/flag without duplicating or resetting anything else.
--
-- JG's DID is their published Debt Solutions line — the same number as
-- config.php ['brand']['phone'] and the site footer, so a consumer sold to JG
-- reads the number JG publishes. InCharge's is their own inbound line.
--
-- THE DATABASE WINS, on every column. `ON DUPLICATE KEY UPDATE id = id` is a
-- deliberate no-op: an existing row is left exactly as it is, so this file only
-- ever BOOTSTRAPS a buyer that isn't there yet. Anything tuned in production —
-- a number, the CallGrid flag, a label — survives every later run untouched, and
-- a deploy can never quietly re-route live calls back to a value in the repo.
--
-- The cost is that changing a seeded value here does NOT reach a database that
-- already has the row: edit the file for future installs, then run the matching
-- UPDATE (below) against each existing environment.
INSERT INTO `buyers` (`name`, `label`, `logo_path`, `did`, `use_callgrid`, `show_logo`) VALUES
    ('InCharge',  'InCharge Debt Solutions', 'assets/img/buyers/Incharge_Debt_Solutions-r.webp', '1-855-600-0593', 0, 1),
    ('Wentworth', 'JG Wentworth',            NULL,                                               '1-888-510-3795', 1, 0)
ON DUPLICATE KEY UPDATE
    `id` = `id`;   -- no-op: never overwrite a live row

-- Changing an EXISTING row takes an explicit UPDATE — by design, per above. Any
-- format works; the page canonicalises it for display and dialing.
--
--   UPDATE `buyers` SET `did` = '(000) 000-0000' WHERE `name` = 'InCharge';
--
-- NULL puts that buyer back on the house number (see thank-you.php), and stays
-- NULL — the seed no longer refills it:
--
--   UPDATE `buyers` SET `did` = NULL WHERE `name` = 'InCharge';
--
-- Turning our call tracking off for a buyer who takes their own calls:
--
--   UPDATE `buyers` SET `use_callgrid` = 0 WHERE `name` = 'InCharge';
--
-- Verify with:
--
--   SELECT `name`, `label`, `did`, `use_callgrid`, `show_logo` FROM `buyers`;
