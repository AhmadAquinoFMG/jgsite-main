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
| `config.php` | Single source of content **and** integration config (DB, Firebase, compliance, Equifax), all fed by a tiny built-in `.env` reader. |
| `submit.php` | Lead endpoint: server-side validation → Firebase token verify → compliance/attribution capture → insert → Equifax pull. Returns JSON. |
| `includes/db.php` | Lazy PDO singleton (MySQL/MariaDB). |
| `includes/firebase.php` | Verifies the Firebase phone-auth ID token (native openssl, no Admin SDK). |
| `includes/compliance.php` | TrustedForm + Jornaya (LeadiD) tags, rendered into `<head>` when configured. |
| `includes/equifax.php` | Equifax Consumer Credit Report client + logger (`off`/`mock`/`live` modes). |
| `sql/schema.sql` | `leads` + `equifax_logs` table definitions. |
| `includes/header.php` / `footer.php` | **Funnel** header/footer. |
| `includes/site-header.php` / `site-footer.php` | **Main-site** header/footer (non-funnel pages). |
| `assets/css/style.css` | All styling (Poppins, brand greens `#006846`/`#1B976A`, teal accent). |
| `assets/js/funnel.js` | Multi-step navigation, validation, Google Places, Firebase OTP, attribution capture, and the `fetch()` submit. |
| `assets/img/` | Logos, trust badges and icons. |

## The funnel flow (8 steps)

Single page, JS-driven (no reloads between steps). A progress bar advances 1/8 → 8/8.

| # | Step | Input | Advance |
|---|------|-------|---------|
| 1 | Debt amount | 5 radio cards | auto |
| 2 | Employment status | 4 radio cards | auto |
| 3 | Annual income | 3 radio cards | auto |
| 4 | First & last name | 2 text inputs | Continue |
| 5 | Address | single free-form field, **Google Places autocomplete**, submits segregated street/city/state/zip (`?address_classic=1` for the legacy multi-field UI) | Continue |
| 6 | Date of birth | single input, **auto-formats MM/DD/YYYY** | Continue |
| 7 | Email | email input | Continue |
| 8 | Phone + verification | phone → **Send code** → 6 OTP boxes → **Verify** → TCPA + **Submit** | Submit |

Client-side validation surfaces per-field error states: `invalid_format`,
`too_short`, `incomplete` / `out_of_range` / `underage` (DOB),
`invalid_length` (phone), `invalid_email` / `untrusted_domain`. On the phone step,
Submit is gated on successful OTP verification (production only).

## Integrations

- **Google Places (step 5)** — real Places API (New) autocomplete, keyed from
  `GOOGLE_PLACES_KEY`. Falls back to a mock suggestion list when unset so the
  funnel works locally without billing.
- **TrustedForm + Jornaya** — TCPA proof-of-consent scripts (`includes/compliance.php`),
  rendered only when configured; their hidden cert/token fields are stored with the lead.

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
5. Returns `{ok:true}`; the client then redirects to **`thank-you.php`**.

> ⚠ **Compliance / PII.** With `EQUIFAX_REDACT=0`, `equifax_logs.request_body`
> stores the full SSN and `response_body` stores the raw credit report in
> cleartext. Restrict DB access, set a retention policy, and consider
> `EQUIFAX_REDACT=1` / column encryption before production.

The Equifax integration ships in `off` mode. Set `EQUIFAX_MODE=mock` to exercise
the logging pipeline without credentials, or `live` for the real OAuth2 +
credit-report call — **confirm the endpoint paths and request schema in
`includes/equifax.php` against your Equifax contract first.**

Lead forwarding to a CRM / LeadProsper is **not wired yet** — there's a marked
`TODO` seam in `submit.php` after the insert.

The confirmation page's phone number and hold-timer length are configurable in
`config.php` → `['prequal']` (`cta_phone`, `hold_minutes`).

## Configuration (`.env`)

See `.env.example` for the full list. Groups: `GOOGLE_PLACES_KEY`; `APP_ENV`;
database (`DB_*`); compliance (`TRUSTEDFORM_ENABLED`, `JORNAYA_*`); Equifax
(`EQUIFAX_*`). `.env` is gitignored.
