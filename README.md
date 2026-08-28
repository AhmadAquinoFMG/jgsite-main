# JG Wentworth — Debt Relief Funnel (PHP)

A PHP clone of the look & feel of `https://www.jgwentworth.com/ds-aff-lp-2`
(originally WordPress + Elementor + Gravity Forms). Plain-PHP, no-framework,
no-Composer rebuild of the multi-step debt-relief lead funnel, now with a real
backend: server-side validation, lead storage in MySQL, TCPA proof-of-consent
capture, Firebase phone (OTP) verification, and a JG Wentworth Debt Resolution
scoring call that returns the consumer's verified total debt.

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
| `config.php` | Single source of content **and** integration config (DB, Firebase, compliance, JG scoring, LeadProsper, Everflow), all fed by a tiny built-in `.env` reader. |
| `submit.php` | Lead endpoint: server-side validation → Firebase token verify → compliance/attribution capture → insert → JG scoring call → LeadProsper post. Returns JSON. |
| `includes/db.php` | Lazy PDO singleton (MySQL/MariaDB). |
| `includes/firebase.php` | Verifies the Firebase phone-auth ID token (native openssl, no Admin SDK). |
| `includes/compliance.php` | TrustedForm + Jornaya (LeadiD) tags, rendered into `<head>` when configured. |
| `includes/jgscoring.php` | JG Wentworth DR intake client + logger (`off`/`mock`/`live` modes). Source of verified total debt. |
| `includes/equifax.php` | Equifax Consumer Credit Report client + logger. **Dormant** — kept on disk, never called. |
| `includes/leadprosper.php` | LeadProsper direct-post client + logger (`off`/`test`/`live` modes). |
| `includes/buyers.php` | Buyer registry lookup — the matched buyer's logo and CALL NOW number (`buyers.did`) for the thank-you page. |
| `assets/js/tracking/everflow.js` | Everflow click attribution (lazy-loaded SDK + cookie watcher); conversion fires client-side on `thank-you.php` via an Everflow campaign trigger. |
| `sql/schema.sql` | `leads` + `jgscoring_logs` + `leadprosper_logs` + (legacy) `equifax_logs` table definitions. |
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
- **Buyer-verified total debt (via LeadProsper)** — LeadProsper's *Customize
  Supplier API Response* feature echoes a buyer's response field back to us on the
  `direct_post` reply, under a Key set on the campaign (`total_debt_included`).
  That yields the buyer's own settleable-debt figure with no second delivery and no
  credentials — see `leadprosper_buyer_total_debt()` and `LP_BUYER_TOTAL_DEBT_KEY`.
  Requires the per-buyer mapping in the buyer's setup, and LeadProsper documents it
  as working only for **exclusive leads sold to one buyer**.

  This is now the **fallback**, not the primary source — see JG Lead Scoring
  below. It still earns its keep for the leads where our own direct call failed
  (JG down, token expired) and a buyer answered anyway.

- **JG Wentworth Lead Scoring (primary verified total debt)** —
  `includes/jgscoring.php` posts the stored lead to
  `https://leadscoring.jgwentworth.com/api/leads/dr/` and keeps
  `total_debt_included` from the response, along with `prequalified`, `accepted`,
  `disposition`, `credit_rating` and JG's own lead id. That figure is what rides
  on the LeadProsper post, what `leads.total_debt` records, and what the
  thank-you savings math uses. Best-effort; every attempt is logged to
  `jgscoring_logs`. Ships in `off` mode.

  > ⚠ **This endpoint is JG's lead INTAKE, not a lookup — every `live` call
  > creates a real lead at JG.** The integration was removed once for exactly
  > this reason: JG also sits as a buyer on LeadProsper campaign 35954, so
  > running both delivered the same consumer twice and LeadProsper's copy (the
  > one that pays) came back **"duplicated by buyer"**. Before setting
  > `JGSCORING_MODE=live`, remove JG as a buyer on that campaign — or accept the
  > duplicate deliberately. `JGSCORING_MODE=mock` exercises the whole pipeline
  > (payload build, `jgscoring_logs` insert, `leads.jgw_*` update, LeadProsper
  > hand-off) with a synthetic response and no network call.

- **LeadProsper** — direct-post lead distribution (`includes/leadprosper.php`), posted
  after the lead is stored and after the JG scoring call so the verified
  total debt can be included. Best-effort; logs every attempt to `leadprosper_logs`. Ships in `off`
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
4. **Scores the lead with JG Wentworth** (`POST /api/leads/dr/`) and logs the
   request/response to `jgscoring_logs` — best-effort ("log & continue"): a JG
   failure is logged but never blocks the lead. The response's
   `total_debt_included` becomes `leads.total_debt` and the `total_debt` posted to
   LeadProsper; the rest of the envelope lands in `leads.jgw_*`. No SSN is
   involved — `ok_to_pull_credit: true` authorises the pull on JG's side.
5. **Posts to LeadProsper** and logs the request/response to `leadprosper_logs` —
   best-effort, same "log & continue" contract as the JG call. Ships in `off` mode.
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

> ⚠ **Compliance / PII.** `jgscoring_logs.request_body` stores the consumer's
> full identity (name, DOB, address, email, phone) and the `ok_to_pull_credit`
> authorisation, in cleartext. The API token is not in the body — it travels in
> the `Authorization` header. Restrict DB access and set a retention policy. The
> legacy `equifax_logs` rows are worse (full SSN + raw credit report) and are the
> first candidate for purging.

### Testing the JG scoring call

`bin/jgscoring-probe.php` fires **one** scoring call from the CLI and prints the
request, the raw response and the parsed figures — no funnel submission, no
Turnstile, no database writes. Use it to validate credentials and payload shape
in isolation.

```bash
php bin/jgscoring-probe.php                    # mock: no network, no lead
php bin/jgscoring-probe.php --live             # ⚠ REAL call, REAL lead at JG
php bin/jgscoring-probe.php --live --show-request
```

`--live` is mandatory to reach the network, because there is no dry run: the
endpoint is JG's intake, so every live call creates a lead on their side. The
probe sends an obviously-fake identity (`Qatest Fmgprobe`) so it is
unmistakable in JG's CRM; override any field with `JGPROBE_*` env vars.

A successful call answers **`201 Created`** (not 200) with JG's own lead id —
the client accepts any 2xx, which matters here.

Two responses that look like failures but aren't:

- **`{"detail":"Authentication credentials were not provided."}`** — JG's API is
  Django REST Framework, and its `TokenAuthentication` backend requires the
  keyword prefix. Set `JGSCORING_AUTH_SCHEME=Token`; a bare 40-char token with
  no prefix produces exactly this 401.
- **`"disposition":"Rejected - Lead Quality"` with `total_debt_included: null`**
  — the call worked; JG screened the identity out. The probe's default identity
  (`Qatest Fmgprobe`, `1 Test Street`, a reserved `555` phone) is rejected by
  design, which is what makes it safe for testing credentials: it proves auth
  without putting a usable lead into JG's pipeline. To exercise the happy path
  and see a real `total_debt_included`, override the identity with `JGPROBE_*`
  env vars using details that pass a quality screen, or ask JG for a whitelisted
  QA identity. Do NOT reuse a real consumer's details from a past payload — that
  posts a duplicate of an actual person.

A rejection is handled, not an error: `total_debt_included: null` parses to
`NULL` rather than `0`, so submit.php posts `total_debt=0` with
`softpull_returned=0` and a rejected lead never looks like a genuine zero
balance.

Full-pipeline testing — `jgscoring_logs` insert, `leads.jgw_*` update, the
LeadProsper hand-off — needs a real submission. `JGSCORING_MODE=mock` runs that
whole path with a synthetic response and no network call.

> ⚠ The QA test mode (`?test=fmg_true`, `TEST_MODE_AFFIDS`) only downgrades the
> **LeadProsper** post to `lp_action=test`. It does **not** gate the JG call, so
> a QA submission with `JGSCORING_MODE=live` still creates a real lead at JG.

### Verified total debt

`total_debt_included` from JG's response is the verified figure. It is **JG's own
underwriting** of the consumer's settleable debt — their scope rule, applied on
their side, not ours. (The Equifax pull it replaced computed an unsecured-only
total locally, excluding student loans; historical rows carry that figure and are
distinguishable by `leads.total_debt_source = 'equifax'`.)

Where each figure lands:

| Column | Meaning |
| --- | --- |
| `leads.total_debt` | What we **sent** to LeadProsper. InCharge Debt Solutions qualifies on it, so it is never overwritten after the post. |
| `leads.jgw_total_debt` | JG's `total_debt_included` from our own direct call. Only filled from a buyer's echoed figure when that direct call returned nothing. |
| `leads.total_debt_source` | `jgw` (our direct call), `buyer` (LP echo fallback), `equifax` (historical), or NULL. |

The visitor's self-reported debt range is also sent separately as
`self_assessed_debt`. It is never substituted into `total_debt`: when no verified
figure is available `total_debt` posts as `0` while `self_assessed_debt` remains
present, so an estimate is never presented as a verified number. The
`softpull_returned` tracking flag reflects our own JG call specifically — a
buyer's echoed figure can't make a failed call look successful.

The confirmation page's hold-timer length is configurable in `config.php` →
`['prequal']` (`hold_minutes`). The CTA phone number is not in config at all.

The CALL NOW number itself is per-buyer: `buyers.did` holds each buyer's own
inbound line, and `thank-you.php` renders the matched buyer's number — so a
consumer sold to InCharge reads and dials InCharge, and one sold to JG reads and
dials JG. An unmatched buyer, or a buyer with no DID on file, falls back to
the `did` of the row named by `['prequal']['cta_buyer']` (JG). The database is
therefore the single place any CTA number is edited — including the funnel's
default — and swapping it is an `UPDATE`, not a deploy. `['brand']['phone']` is
the last resort, used only if the DB can't answer or that row has no usable DID.
Both buyers' DIDs are seeded in `sql/alter_add_buyers.sql`.

Because unmatched visits render the house row's `did`, that row should hold a
CallGrid tracking line rather than a raw DID — a raw number there ends call
attribution for those visits, the same trap the old `cta_phone` comment warned
about. **The database wins:** the seed only fills a row with no number,
so a DID changed with the `UPDATE` at the bottom of that file survives every
later run and is never reverted by a deploy. Store a number in any shape — bare digits, dashes, a leading
country code — and it renders in one house format, `(855) 600-0593`, with the
`tel:` href in E.164 (`+18556000593`) so a dialer never has to guess the
country.

`buyers.use_callgrid` decides whether call tracking runs for that buyer. JG keeps
it (`1`): the button reads JG's DID and CallGrid's number pool still rewrites the
`tel:` target, so attribution is unchanged. InCharge has it off (`0`): the SDK is
not loaded at all on their thank-you page, nothing is assigned, and their own DID
is both what the visitor reads and what they dial — our pool has no business
sitting in front of a line the buyer already owns. An unmatched buyer is tracked,
since the fallback number is ours. Unlike `did`, this column takes the seed's
value on every migration run.

## Configuration (`.env`)

See `.env.example` for the full list. Groups: `GOOGLE_PLACES_KEY`; `APP_ENV`;
database (`DB_*`); compliance (`TRUSTEDFORM_ENABLED`, `JORNAYA_*`); JG scoring
(`JGSCORING_*`); LeadProsper (`LEADPROSPER_MODE`, `LP_*`); Everflow
(`EVERFLOW_*`); QA test mode (`TEST_MODE_TOKEN`, `TEST_MODE_AFFIDS`,
`TEST_MODE_AFFID`). `.env` is gitignored.
