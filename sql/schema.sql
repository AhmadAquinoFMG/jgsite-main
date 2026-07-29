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

    -- ---- Qualifying answers (steps 1-3) ----
    `debt_amount`        VARCHAR(64) NOT NULL,   -- self-reported bucket (step 1)
    `self_assessed_debt` VARCHAR(64) DEFAULT NULL, -- same self-reported figure, clearly named
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

    -- ---- Phone verification (Firebase Phone Auth) ----
    `phone_verified`  TINYINT(1)   NOT NULL DEFAULT 0,
    `firebase_uid`    VARCHAR(128) DEFAULT NULL,

    -- ---- Equifax pull outcome (denormalized from equifax_logs for quick
    --      per-lead visibility; NULL when mode=off / no pull happened) ----
    `equifax_mode`    VARCHAR(10)  DEFAULT NULL,   -- 'mock' | 'live'
    `equifax_status`  SMALLINT     DEFAULT NULL,   -- HTTP status (0 = no response)
    `equifax_score`   SMALLINT     DEFAULT NULL,   -- parsed credit score, if any
    `equifax_decision` VARCHAR(64) DEFAULT NULL,
    `equifax_error`   VARCHAR(255) DEFAULT NULL,   -- NULL = success
    `equifax_pulled_at` DATETIME   DEFAULT NULL,
    `total_debt`      INT UNSIGNED DEFAULT NULL,    -- Equifax-verified total debt

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
    `gclid`           VARCHAR(255) DEFAULT NULL,
    `fbclid`          VARCHAR(255) DEFAULT NULL,

    -- ---- Everflow click attribution (captured client-side, see
    --      assets/js/tracking/everflow.js + the hidden fields in index.php) ----
    `affid`             VARCHAR(64)  DEFAULT NULL,
    `oid`               VARCHAR(64)  DEFAULT NULL,
    `ef_transaction_id` VARCHAR(128) DEFAULT NULL,

    -- ---- LeadProsper direct-post outcome (denormalized from leadprosper_logs
    --      for quick per-lead visibility; NULL when mode=off / no post happened) ----
    `lp_mode`         VARCHAR(10)  DEFAULT NULL,   -- 'test' | 'live'
    `lp_status`       SMALLINT     DEFAULT NULL,   -- HTTP status (0 = no response)
    `lp_accepted`     TINYINT(1)   DEFAULT NULL,
    `lp_error`        VARCHAR(255) DEFAULT NULL,   -- NULL = success
    `lp_posted_at`    DATETIME     DEFAULT NULL,

    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_email`      (`email`),
    KEY `idx_phone`      (`phone`)
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
-- Equifax credit-report call log.
--
-- One row per Consumer Credit Report request made from submit.php
-- (includes/equifax.php), for audit + debugging. The call is best-effort:
-- a failure is logged here but never blocks the lead (see leads above).
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
