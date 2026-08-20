-- Adds the buyer registry (`buyers`) and records which buyer took each lead
-- (`leads.lp_accepted_buyer`).
--
-- Idempotent: safe to run against a database that already has some of these.
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

-- The campaign's two buyers. INSERT ... ON DUPLICATE KEY so re-running the file
-- refreshes the logo path/flag without duplicating or resetting anything else.
INSERT INTO `buyers` (`name`, `label`, `logo_path`, `show_logo`) VALUES
    ('InCharge',  'InCharge Debt Solutions', 'assets/img/buyers/Incharge_Debt_Solutions-r.webp', 1),
    ('Wentworth', 'JG Wentworth',            NULL,                                               0)
ON DUPLICATE KEY UPDATE
    `label`     = VALUES(`label`),
    `logo_path` = VALUES(`logo_path`),
    `show_logo` = VALUES(`show_logo`);
