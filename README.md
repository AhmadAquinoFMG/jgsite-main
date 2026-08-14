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
| 6 | Address | single free-form field, **Google Places autocomplete**, submits segregated street/city/state/zip/country (`?address_classic=1` for the legacy multi-field UI) | Continue (blocked until the address is complete) |
| 7 | Date of birth | single input, **auto-formats MM/DD/YYYY** | Continue |
| 8 | Email | email input | Continue |
| 9 | Phone + verification | phone → **Send code** → 6 OTP boxes → **Verify** → TCPA + **Submit** | Submit |

Client-side validation surfaces per-field error states: `invalid_format`,
`too_short`, `incomplete` / `out_of_range` / `underage` (DOB),
`invalid_length` (phone), `invalid_email` / `untrusted_domain`. On the phone step,
Submit is gated on successful OTP verification (production only).

Step 6 will not advance on a partial address, and takes two tests to enforce it
because either alone is fooled:

1. **Components** (`checkAddressParts`) — Google's own `addressComponents` must
   carry a house number *and* a street name (or a PO box), plus city, state, ZIP
   and country. Never derived from the formatted address string: a locality-level
   result formats as `"Springfield, IL 62701, USA"`, so reading the first segment
   invents a street.
2. **Field text** (`partsNotInText`) — the city and ZIP must also appear in the
   field. The geocoder *completes* addresses: `"1600 Amphitheatre Pkwy"` comes back
   with a city and ZIP the visitor never typed, and `partial_match` is not set,
   so the components look whole. Picking a suggestion rewrites the field to
   Google's formatted address, so a real pick always passes this; a typed fragment
   does not. Picked results skip the test — the visitor chose that exact address.

Geocoder results flagged `partial_match` are treated as unresolved (Google's
"couldn't match what was typed, here's my best guess"). Either failure keeps the
visitor on the step and names the missing parts. It's the same set `submit.php`
requires, so an incomplete address is caught while the field is still on screen
instead of coming back as a 422 from the final Submit.

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
- **QA test mode** — a test visit runs the funnel for real (real validation, a
  real row in `leads`) but posts the lead to LeadProsper with `lp_action=test`:
  it appears in the campaign's lead log flagged **TEST** and is never billed or
  delivered. Two independent triggers, both in `config.php ['test_mode']`:
  - `?test=fmg_true` — `funnel.js` copies the param into a hidden `test` field
    and `submit.php` compares it to `TEST_MODE_TOKEN` (empty disables it).
  - `?affid=300` — JG's test affiliate id (`TEST_MODE_AFFIDS`), so their QA link
    `?oid=914&affid=300` needs no extra param. Matched on **affid only**: `914`
    is the live first-party *offer* id that real links carry too
    (`?oid=914&affid=989`), so treating it as a test marker would flag genuine
    leads as tests.

  The post happens even when `LEADPROSPER_MODE=off`. The campaign requires
  `affid`, so a test visit that carried none has `TEST_MODE_AFFID` (default
  `300`) substituted **into the post only** — an `?affid=` actually on the URL
  always wins, and the stored lead is untouched. QA rows are identifiable
  afterwards by `leads.lp_mode = 'test'`.
- **Everflow** — fully client-side; no server-side postback. The `?affid=` on the
  landing URL decides the offer (`includes/everflow.php`, table in
  `config.php ['everflow']`): a first-party affid
  (`EVERFLOW_FIRST_PARTY_AFFIDS`, default `989,995,1024`) routes to offer **914**,
  any other affid to **915**, and **no affid means Everflow is never contacted** —
  the SDK isn't even loaded. Example link:
  `https://jgdebtrelief.com/?oid=914&affid=989`. Note the offer is derived from
  `affid`, not from `?oid=` — `oid` is still captured and forwarded to LeadProsper
  but has no say in routing.
  - *Click* — `assets/js/tracking/everflow.js`, lazy-loaded on first
    interaction/4s timeout/form submit. Writes `affid` + `ef_transaction_id` into
    hidden fields that ride along with the lead to LeadProsper.
  - *Conversion* — `thank-you.php`, against the same offer. `submit.php` stashes
    the affid in the session only after it accepts the lead, and `thank-you.php`
    consumes it on read, so a reload can't double-fire a billable conversion.

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
6. **Builds the redirect URL server-side** (`includes/redirect.php`) and returns
   `{ok:true, redirect:"…"}`; the client follows that URL rather than hardcoding a
   destination. The consumer's answers only reach the query string after passing
   through validation here, so the appended values are the server's normalised
   copies (phone as E.164, DOB as ISO, debt as an int) and can't be values the
   client made up. Destination and param list are config, not code —
   `config.php ['redirect']` (`REDIRECT_BASE`, default `thank-you.php`) maps
   outgoing param name => field in `$row`; unanswered fields are dropped rather
   than sent blank. Since `$row` also holds attribution, `affid`/`oid`/
   `ef_transaction_id`/`utm_*` can be forwarded by naming them in that map.
   A no-JS native POST gets a `303` to the same URL, so it lands on the page
   instead of raw JSON.

> ⚠ **PII in the URL.** Every param in `config.php ['redirect']['params']` ends up
> in browser history, in the `Referer` sent to any third-party script on the
> destination page, and in web-server access logs. The defaults are the full set
> of form answers *including* name, street, DOB, email and phone. Trim that list
> to what the destination genuinely needs, and prefer a destination that accepts
> them over POST if one exists.

> ⚠ **Compliance / PII.** With `EQUIFAX_REDACT=0`, `equifax_logs.request_body`
> stores the full SSN and `response_body` stores the raw credit report in
> cleartext. Restrict DB access, set a retention policy, and consider
> `EQUIFAX_REDACT=1` / column encryption before production.

The Equifax integration ships in `off` mode. Set `EQUIFAX_MODE=mock` to exercise
the logging pipeline without credentials, or `live` for the real OAuth2 +
credit-report call — **confirm the endpoint paths and request schema in
`includes/equifax.php` against your Equifax contract first.**

**Verified total debt is unsecured debt only, with student loans excluded.**
Equifax has no request-level account-type filter, so the report comes back
complete and `includes/equifax.php` classifies each trade line when it computes
the total: credit cards, charge accounts, unsecured notes/lines of credit,
personal loans and medical debt count; mortgages, HELOCs, autos, leases,
timeshares and every student/education loan are dropped. Classification is
fail-closed — a trade line that can't be positively identified as unsecured
doesn't count. Set `EQUIFAX_DEBT_SCOPE=all` to sum every trade line instead
(debugging only; that figure is not settleable debt).

The confirmation page's phone number and hold-timer length are configurable in
`config.php` → `['prequal']` (`cta_phone`, `hold_minutes`).

## Configuration (`.env`)

See `.env.example` for the full list. Groups: `GOOGLE_PLACES_KEY`; `APP_ENV`;
database (`DB_*`); compliance (`TRUSTEDFORM_ENABLED`, `JORNAYA_*`); Equifax
(`EQUIFAX_*`); LeadProsper (`LEADPROSPER_MODE`, `LP_*`); Everflow
(`EVERFLOW_*`); QA test mode (`TEST_MODE_TOKEN`, `TEST_MODE_AFFIDS`,
`TEST_MODE_AFFID`). `.env` is gitignored.
