# JG Wentworth — Debt Relief Funnel (PHP)

A PHP clone of the look & feel of `https://www.jgwentworth.com/ds-aff-lp-2`
(originally WordPress + Elementor + Gravity Forms). Plain-PHP, no-framework,
no-Composer rebuild of the multi-step debt-relief lead funnel, now with a real
backend: server-side validation, lead storage in MySQL, TCPA proof-of-consent
capture, Firebase phone (OTP) verification, and an Equifax credit-report pull.

## Run locally

```bash
# 1. Configure secrets
cp .env.example .env        # then fill in values (see below)

# 2. Create the database and import the schema
mysql -u <user> -p -e "CREATE DATABASE jgw_leads CHARACTER SET utf8mb4"
mysql -u <user> -p jgw_leads < sql/schema.sql

# 3. Serve
php -S 127.0.0.1:8000
# open http://127.0.0.1:8000/index.php
```

Requires PHP 8.0+ with PDO/MySQL, curl, and openssl (all standard). No Composer.

For local dev set `APP_ENV=local` in `.env` — this bypasses server-side Firebase
ID-token verification (there is no real OTP session in dev) and lets the funnel
complete. Set `APP_ENV=production` on staging/live so the phone gate is enforced.

## Structure

| File | Purpose |
|------|---------|
| `index.php` | Page markup + the multi-step form. Pulls all copy/options from `config.php`. |
| `config.php` | Single source of content **and** integration config (DB, Firebase, compliance, Equifax, LeadProsper, Everflow), all fed by a tiny built-in `.env` reader. |
| `submit.php` | Lead endpoint: server-side validation → Firebase token verify → compliance/attribution capture → insert → Equifax pull → LeadProsper post. Returns JSON. |
| `includes/db.php` | Lazy PDO singleton (MySQL/MariaDB). |
| `includes/firebase.php` | Verifies the Firebase phone-auth ID token (native openssl, no Admin SDK). |
| `includes/compliance.php` | TrustedForm + Jornaya (LeadiD) tags, rendered into `<head>` when configured. |
| `includes/equifax.php` | Equifax Consumer Credit Report client + logger (`off`/`mock`/`live` modes). |
| `includes/leadprosper.php` | LeadProsper direct-post client + logger (`off`/`test`/`live` modes). |
| `assets/js/tracking/everflow.js` | Everflow click attribution (lazy-loaded SDK + cookie watcher); conversion fires client-side on `thank-you.php` via an Everflow campaign trigger. |
| `sql/schema.sql` | `leads` + `equifax_logs` + `leadprosper_logs` table definitions. |
| `includes/header.php` / `footer.php` | **Funnel** header/footer. |
| `includes/site-header.php` / `site-footer.php` | **Main-site** header/footer (non-funnel pages). |
| `assets/css/style.css` | All styling (Poppins, brand greens `#006846`/`#1B976A`, teal accent). |
| `assets/js/funnel.js` | Multi-step navigation, validation, Google Places, Firebase OTP, attribution capture, and the `fetch()` submit. |
| `assets/img/` | Logos, trust badges and icons. |

## The funnel flow (9 steps)

Single page, JS-driven (no reloads between steps). A progress bar advances 1/9 → 9/9.

| # | Step | Input | Advance |
|---|------|-------|---------|
| 1 | Debt amount | 5 radio cards | auto |
| 2 | Behind on payments | 3 radio cards | auto |
| 3 | Employment status | 4 radio cards | auto |
| 4 | Annual income | 3 radio cards | auto |
| 5 | First & last name | 2 text inputs | Continue |
| 6 | Address | single free-form field, **Google Places autocomplete**, submits segregated street/city/state/zip (`?address_classic=1` for the legacy multi-field UI) | Continue |
| 7 | Date of birth | single input, **auto-formats MM/DD/YYYY** | Continue |
| 8 | Email | email input | Continue |
| 9 | Phone + verification | phone → **Send code** → 6 OTP boxes → **Verify** → TCPA + **Submit** | Submit |

Client-side validation surfaces per-field error states: `invalid_format`,
`too_short`, `incomplete` / `out_of_range` / `underage` (DOB),
`invalid_length` (phone), `invalid_email` / `untrusted_domain`. On the phone step,
Submit is gated on successful OTP verification (production only).

## Integrations

- **Google Places (step 6)** — real Places API (New) autocomplete, keyed from
  `GOOGLE_PLACES_KEY`. Falls back to a mock suggestion list when unset so the
  funnel works locally without billing.
- **TrustedForm + Jornaya** — TCPA proof-of-consent scripts (`includes/compliance.php`),
  rendered only when configured; their hidden cert/token fields are stored with the lead.
- **LeadProsper** — direct-post lead distribution (`includes/leadprosper.php`), posted
  after the lead is stored and after the Equifax pull so the verified total debt can
  be included. Best-effort; logs every attempt to `leadprosper_logs`. Ships in `off`
  mode — set `LEADPROSPER_MODE=test` to validate field mapping without billing/delivering,
  `live` once you're ready.
- **Everflow** — client-side click attribution (`assets/js/tracking/everflow.js`),
  lazy-loaded on first interaction/4s timeout/form submit. Writes `affid` +
  `ef_transaction_id` into hidden fields that ride along with the lead to
  LeadProsper. Conversion fires separately, client-side, via an Everflow campaign
  trigger configured for `thank-you.php` — no server-side postback. Disabled until
  `EVERFLOW_OFFER_ID` is set.

## Backend

On Submit, `funnel.js` POSTs the form to `submit.php` (falling back to a native
POST if JS is unavailable). `submit.php`:

1. **Validates** every field server-side (mirrors the client rules; radios checked
   against `config.php` options). Invalid → `422 {ok:false, errors:{field:code}}`,
   which the client maps back to the offending step.
2. **Captures** TCPA artifacts (TrustedForm URL, Jornaya token, consent snapshot +
   timestamp) and attribution (IP, user-agent, UTM params, gclid).
3. **Inserts** the lead into the `leads` table.
4. **Pulls an Equifax credit report** and logs the request/response to
   `equifax_logs` — best-effort ("log & continue"): an Equifax failure is logged
   but never blocks the lead. **Note:** the funnel no longer collects an SSN, so
   the pull currently runs without one (it won't return a real report until an
   SSN — or another identifier the contract accepts — is supplied).
5. **Posts to LeadProsper** and logs the request/response to `leadprosper_logs` —
   best-effort, same "log & continue" contract as Equifax. Ships in `off` mode.
6. Returns `{ok:true}`; the client then redirects to **`thank-you.php`**.

> ⚠ **Compliance / PII.** With `EQUIFAX_REDACT=0`, `equifax_logs.request_body`
> stores the full SSN and `response_body` stores the raw credit report in
> cleartext. Restrict DB access, set a retention policy, and consider
> `EQUIFAX_REDACT=1` / column encryption before production.

The Equifax integration ships in `off` mode. Set `EQUIFAX_MODE=mock` to exercise
the logging pipeline without credentials, or `live` for the real OAuth2 +
credit-report call — **confirm the endpoint paths and request schema in
`includes/equifax.php` against your Equifax contract first.**

The confirmation page's phone number and hold-timer length are configurable in
`config.php` → `['prequal']` (`cta_phone`, `hold_minutes`).

## Configuration (`.env`)

See `.env.example` for the full list. Groups: `GOOGLE_PLACES_KEY`; `APP_ENV`;
database (`DB_*`); compliance (`TRUSTEDFORM_ENABLED`, `JORNAYA_*`); Equifax
(`EQUIFAX_*`); LeadProsper (`LEADPROSPER_MODE`, `LP_*`); Everflow
(`EVERFLOW_*`). `.env` is gitignored.
