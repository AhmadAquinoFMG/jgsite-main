-- JG Wentworth Debt-Relief funnel — lead storage schema.
--
-- Import into the MySQL/MariaDB database (Cloudways in production, a local
-- MySQL/MariaDB for dev):
--
--   mysql -u <user> -p <database> < sql/schema.sql
--
-- submit.php inserts one row per completed funnel submission.

CREATE TABLE IF NOT EXISTS `leads` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- ---- Qualifying answers (steps 1-4) ----
    `debt_amount`        VARCHAR(64) NOT NULL,   -- self-reported bucket (step 1)
    `self_assessed_debt` VARCHAR(64) DEFAULT NULL, -- same self-reported figure, clearly named
    `behind_payment`  VARCHAR(64)  DEFAULT NULL, -- 'not_behind' | 'over_30' | 'over_60' (step 2); required by submit.php, nullable here so ALTER on existing rows doesn't fail
    `employment`      VARCHAR(64)  NOT NULL,
    `income`          VARCHAR(64)  NOT NULL,

    -- ---- Contact (steps 4-8) ----
    `first_name`      VARCHAR(64)  NOT NULL,
    `last_name`       VARCHAR(64)  NOT NULL,
    `street`          VARCHAR(255) NOT NULL,
    `city`            VARCHAR(128) NOT NULL,
    `state`           CHAR(2)      NOT NULL,
    `zip`             CHAR(5)      NOT NULL,
    `dob`             DATE         NOT NULL,
    `email`           VARCHAR(255) NOT NULL,
    `phone`           VARCHAR(20)  NOT NULL,   -- E.164, e.g. +13105551234

    -- ---- Product context (form attributes) ----
    `product`         VARCHAR(64)  DEFAULT NULL,
    `form_name`       VARCHAR(64)  DEFAULT NULL,

    -- ---- Duplicate-submit guard ----
    -- Idempotency key for one submission attempt: 32 random hex chars minted
    -- per pageview by index.php and re-sent verbatim when funnel.js retries a
    -- failed submit. The UNIQUE below is what makes the retry safe; submit.php
    -- catches the 23000 and replays the original lead's redirect. NULL is
    -- exempt from the index, so a no-JS or legacy POST still inserts.
    `submit_nonce`    CHAR(32)     DEFAULT NULL,

    -- ---- Phone verification (Firebase Phone Auth) ----
    `phone_verified`  TINYINT(1)   NOT NULL DEFAULT 0,
    `firebase_uid`    VARCHAR(128) DEFAULT NULL,

    -- ---- Equifax pull outcome — LEGACY, NO LONGER WRITTEN. The pull was
    --      replaced by the JG scoring call (jgw_* below); includes/equifax.php
    --      is dormant and submit.php never invokes it. Kept so historical rows
    --      still parse. ----
    `equifax_mode`    VARCHAR(10)  DEFAULT NULL,   -- 'mock' | 'live'
    `equifax_status`  SMALLINT     DEFAULT NULL,   -- HTTP status (0 = no response)
    `equifax_score`   SMALLINT     DEFAULT NULL,   -- parsed credit score, if any
    `equifax_decision` VARCHAR(64) DEFAULT NULL,
    `equifax_error`   VARCHAR(255) DEFAULT NULL,   -- NULL = success
    `equifax_pulled_at` DATETIME   DEFAULT NULL,
    -- What we SENT to LeadProsper: JG's verified total_debt_included. Kept as
    -- the record of what the buyers were actually told — InCharge Debt Solutions
    -- qualifies on this figure, so a buyer's own number must never overwrite it.
    -- (Historical rows hold the Equifax-verified unsecured total instead.)
    `total_debt`      INT UNSIGNED DEFAULT NULL,
    -- Which figure fed our records / the consumer-facing math (thank-you savings,
    -- redirect params, Zapier): 'jgw' when our own JG scoring call produced it,
    -- 'buyer' when that call failed and a buyer's echoed figure filled the gap.
    -- NULL when neither produced one. 'equifax' on historical rows.
    `total_debt_source` VARCHAR(10) DEFAULT NULL,

    -- ---- JG Wentworth DR scoring outcome (denormalized from jgscoring_logs
    --      for quick per-lead visibility; NULL when mode=off / no call) ----
    `jgw_mode`          VARCHAR(10)  DEFAULT NULL,  -- 'mock' | 'live'
    `jgw_status`        SMALLINT     DEFAULT NULL,  -- HTTP status (0 = no response)
    -- JG's own underwritten settleable total (`total_debt_included`). Written by
    -- the direct scoring call; a buyer's figure echoed back through the
    -- LeadProsper response only ever fills this when the direct call returned
    -- nothing (COALESCE(jgw_total_debt, :buyer_debt) in submit.php).
    `jgw_total_debt`    INT UNSIGNED DEFAULT NULL,
    `jgw_prequalified`  TINYINT(1)   DEFAULT NULL,
    `jgw_accepted`      TINYINT(1)   DEFAULT NULL,
    `jgw_disposition`   VARCHAR(64)  DEFAULT NULL,
    `jgw_credit_rating` VARCHAR(32)  DEFAULT NULL,
    `jgw_external_id`   VARCHAR(64)  DEFAULT NULL,
    `jgw_error`         VARCHAR(255) DEFAULT NULL,  -- NULL = usable result
    `jgw_scored_at`     DATETIME     DEFAULT NULL,

    -- ---- Compliance / proof-of-consent (TCPA) ----
    `trustedform_url` VARCHAR(255) DEFAULT NULL,
    `jornaya_token`   VARCHAR(128) DEFAULT NULL,
    `consent_text`    TEXT         DEFAULT NULL,   -- snapshot of the TCPA copy shown
    `consent_at`      DATETIME     DEFAULT NULL,   -- server time consent was recorded

    -- ---- Attribution / request meta ----
    `ip`              VARCHAR(45)  DEFAULT NULL,   -- IPv4 or IPv6
    `user_agent`      VARCHAR(255) DEFAULT NULL,
    `utm_source`      VARCHAR(128) DEFAULT NULL,
    `utm_medium`      VARCHAR(128) DEFAULT NULL,
    `utm_campaign`    VARCHAR(128) DEFAULT NULL,
    `utm_term`        VARCHAR(128) DEFAULT NULL,
    `utm_content`     VARCHAR(128) DEFAULT NULL,
    `utm_creative`    VARCHAR(128) DEFAULT NULL,
    `utm_placement`   VARCHAR(128) DEFAULT NULL,
    `utm_adgroup`     VARCHAR(128) DEFAULT NULL,
    `utm_matchtype`   VARCHAR(64)  DEFAULT NULL,
    `gclid`           VARCHAR(255) DEFAULT NULL,
    `gbraid`          VARCHAR(255) DEFAULT NULL,
    `fbclid`          VARCHAR(255) DEFAULT NULL,
    `fbp`             VARCHAR(255) DEFAULT NULL,   -- Meta pixel's _fbp cookie value
    `fbc`             VARCHAR(255) DEFAULT NULL,   -- Meta's _fbc cookie, or fb.1.<ts>.<fbclid>
    `fb_adid`         VARCHAR(128) DEFAULT NULL,
    `ms_placement`    VARCHAR(128) DEFAULT NULL,   -- Microsoft/Bing Ads
    `ms_publisher`    VARCHAR(128) DEFAULT NULL,
    `ttclid`          VARCHAR(255) DEFAULT NULL,   -- TikTok click id
    `subid`           VARCHAR(255) DEFAULT NULL,

    -- ---- Everflow click attribution (captured client-side, see
    --      assets/js/tracking/everflow.js + the hidden fields in index.php) ----
    `affid`             VARCHAR(64)  DEFAULT NULL,
    `oid`               VARCHAR(64)  DEFAULT NULL,
    `source_id`         VARCHAR(64)  DEFAULT NULL,
    -- Everflow click sub-parameters. Affiliate-supplied free text, so sized
    -- generously rather than to any known format.
    `sub1`              VARCHAR(255) DEFAULT NULL,
    `sub2`              VARCHAR(255) DEFAULT NULL,
    `sub3`              VARCHAR(255) DEFAULT NULL,
    `sub4`              VARCHAR(255) DEFAULT NULL,
    `sub5`              VARCHAR(255) DEFAULT NULL,
    `sub6`              VARCHAR(255) DEFAULT NULL,
    `ef_transaction_id` VARCHAR(128) DEFAULT NULL,
    `landing_page_url`  VARCHAR(512) DEFAULT NULL,

    -- ---- LeadProsper sub-affiliate / advertiser passthrough ids ----
    -- 255, matching `sub1`-`sub6`: lp_subid1-6 receive those values when the
    -- landing URL left a slot empty (includes/leadprosper.php), so a narrower
    -- column here would fail the insert on a sub that fitted its own column.
    `lp_subid1`       VARCHAR(255) DEFAULT NULL,
    `lp_subid2`       VARCHAR(255) DEFAULT NULL,
    `lp_subid3`       VARCHAR(255) DEFAULT NULL,
    `lp_subid4`       VARCHAR(255) DEFAULT NULL,
    `lp_subid5`       VARCHAR(255) DEFAULT NULL,
    `lp_subid6`       VARCHAR(255) DEFAULT NULL,
    `adv1`            VARCHAR(255) DEFAULT NULL,
    `adv2`            VARCHAR(255) DEFAULT NULL,
    `adv3`            VARCHAR(255) DEFAULT NULL,
    `adv4`            VARCHAR(255) DEFAULT NULL,
    `adv5`            VARCHAR(255) DEFAULT NULL,

    -- ---- LeadProsper direct-post outcome (denormalized from leadprosper_logs
    --      for quick per-lead visibility; NULL when mode=off / no post happened) ----
    `lp_mode`         VARCHAR(10)  DEFAULT NULL,   -- 'test' | 'live'
    `lp_status`       SMALLINT     DEFAULT NULL,   -- HTTP status (0 = no response)
    `lp_accepted`     TINYINT(1)   DEFAULT NULL,
    `lp_error`        VARCHAR(255) DEFAULT NULL,   -- NULL = success
    `lp_posted_at`    DATETIME     DEFAULT NULL,

    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- ---- Bot protection (see docs/bot-protection.md) --------------------
    `bot_suspected`   TINYINT(1)   NOT NULL DEFAULT 0,
    `bot_reason`      VARCHAR(32)  DEFAULT NULL,   -- 'honeypot' | 'timing' | 'turnstile'

    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_email`      (`email`),
    KEY `idx_phone`      (`phone`),
    UNIQUE KEY `uniq_submit_nonce` (`submit_nonce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- JG Wentworth Lead Scoring call log (includes/jgscoring.php).
--
-- One row per scoring call, for audit + debugging. This is where verified total
-- debt now comes from: `total_debt` is JG's `total_debt_included`, which
-- submit.php posts to LeadProsper and denormalizes onto leads.jgw_*. Mirrors
-- equifax_logs below, which it replaced.
--
-- ⚠ DUPLICATE DELIVERY: that endpoint is JG's lead INTAKE, not a lookup — every
-- live call creates a lead at JG. JG also sits as a buyer on the LeadProsper
-- campaign, and running both once delivered the same consumer twice, with the
-- paying LeadProsper copy rejected as "duplicated by buyer". Keep JG off the LP
-- campaign while jgscoring.mode=live.
--
-- ⚠ COMPLIANCE: `request_body` carries the consumer's full identity (name, DOB,
-- address, email, phone) and authorizes a credit pull (ok_to_pull_credit). No
-- API token is stored — it travels in the Authorization header, not the body.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jgscoring_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`         BIGINT UNSIGNED DEFAULT NULL,
    `mode`            VARCHAR(10)  NOT NULL DEFAULT 'live',   -- 'mock' | 'live'
    `request_body`    LONGTEXT     DEFAULT NULL,              -- ⚠ full PII
    `response_status` SMALLINT     DEFAULT NULL,              -- HTTP status (0 = no response)
    `response_body`   LONGTEXT     DEFAULT NULL,
    `total_debt`      INT UNSIGNED DEFAULT NULL,              -- parsed total_debt_included
    `prequalified`    TINYINT(1)   DEFAULT NULL,
    `accepted`        TINYINT(1)   DEFAULT NULL,
    `credit_rating`   VARCHAR(32)  DEFAULT NULL,
    `jgw_id`          VARCHAR(64)  DEFAULT NULL,              -- JG's own lead id
    `external_id`     VARCHAR(64)  DEFAULT NULL,
    `error`           VARCHAR(255) DEFAULT NULL,              -- NULL = usable result
    `duration_ms`     INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_lead_id`    (`lead_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_jgscoring_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- LeadProsper direct-post call log.
--
-- One row per lead post attempt made from submit.php (includes/leadprosper.php),
-- for audit + debugging. The call is best-effort: a failure is logged here but
-- never blocks the lead (see leads above). Mirrors equifax_logs below.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leadprosper_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`         BIGINT UNSIGNED DEFAULT NULL,
    `mode`            VARCHAR(10)  NOT NULL DEFAULT 'live',   -- 'test' | 'live'
    `request_body`    LONGTEXT     DEFAULT NULL,              -- lp_key redacted
    `response_status` SMALLINT     DEFAULT NULL,              -- HTTP status (0 = no response)
    `response_body`   LONGTEXT     DEFAULT NULL,
    `accepted`        TINYINT(1)   DEFAULT NULL,
    `error`           VARCHAR(255) DEFAULT NULL,              -- transport/parse error, if any
    `duration_ms`     INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_lead_id`    (`lead_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_leadprosper_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Equifax credit-report call log — LEGACY, NO LONGER WRITTEN.
--
-- The pull was replaced by the JG scoring call above; includes/equifax.php is
-- dormant and submit.php no longer invokes it. Retained so historical rows
-- survive. Safe to drop once they are no longer needed.
--
-- What it did: one row per Consumer Credit Report request made from submit.php.
-- Best-effort — a failure was logged here but never blocked the lead.
--
-- ⚠ COMPLIANCE: `request_body` contains the SSN and identity sent to Equifax,
-- and `response_body` contains the raw credit report. These are highly
-- sensitive. Storing them in cleartext is a deliberate choice — restrict DB
-- access, consider column encryption / a short retention window, and enable
-- redaction (config equifax.redact) if raw retention is not required.
-- SSN is intentionally NOT stored on the `leads` table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `equifax_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`         BIGINT UNSIGNED DEFAULT NULL,
    `mode`            VARCHAR(10)  NOT NULL DEFAULT 'live',   -- 'mock' | 'live'
    `request_url`     VARCHAR(255) DEFAULT NULL,
    `request_body`    LONGTEXT     DEFAULT NULL,              -- ⚠ contains SSN/PII
    `response_status` SMALLINT     DEFAULT NULL,              -- HTTP status (0 = no response)
    `response_body`   LONGTEXT     DEFAULT NULL,              -- ⚠ raw credit report
    `score`           SMALLINT     DEFAULT NULL,              -- parsed credit score, if available
    `decision`        VARCHAR(64)  DEFAULT NULL,              -- parsed decision/outcome, if available
    `error`           VARCHAR(255) DEFAULT NULL,              -- transport/parse error, if any
    `duration_ms`     INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_lead_id`    (`lead_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_equifax_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
