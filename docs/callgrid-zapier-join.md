# CallGrid → Lead Join Guide — Zapier + Google Sheets

How to attach a lead's details to an inbound call when there is **no CallGrid number
pool**, by joining the two in Zapier on the caller's phone number.

## Why this exists

The obvious approach — put the lead's details on the thank-you page URL and let CallGrid
pick them up as tags — does not work here. Reading CallGrid's SDK (`cdn.callgrid.com/callgrid.js`)
establishes three facts:

1. **The SDK ignores the query string.** It reads `location.search` exactly twice: once
   for `utm_source`, and once in `getAttributionTags()` where it builds a `URLSearchParams`
   and discards the result. Arbitrary URL params are never harvested.
2. **Custom tags reach it only two ways** — the `data-tags` JSON attribute on the script
   tag, or `addTags()` on an instance that auto-init keeps in a closure and never exposes.
   `thank-you.php` uses the former.
3. **Tags are session-scoped, and every event that carries them requires an assigned
   number.** The SDK emits three event types (`impression`, `click`, `ping`), all bound to
   a number from the pool. With no pool, `getNumber()` throws `"No available numbers"`,
   DNI never swaps, no impression is sent, and the tags never leave the browser.

The root problem is structural: CallGrid links a *call* to a *visitor session* by *which
number was dialed*. That is the entire purpose of a number pool. With one static tracking
number (`config.php` → `['prequal']['cta_phone']`) shared by every visitor, there is
nothing to join on, so every session-scoped tag on the call webhook arrives empty —
including CallGrid's own `VisitorState` / `VisitorCity` / `VisitorZipCode`.

Call-level tags (`CallId`, `CallerNumber`, `duration`) still populate, because those come
from the call itself at CallGrid's switch and never touch the browser.

So the join moves to Zapier, keyed on the one identifier that travels through the phone
network: **the caller's number**.

## How it works

```
1. Funnel submit
   submit.php validates → stores lead #4821 (phone +15125550123)
                        │
                        └─POST──► Zapier Catch Hook A  ("Zap 1")
                                  { lead_id, phone_digits, email, fbc, ... }
                                  → upserts a row in Google Sheets, keyed phone_digits

2. Visitor taps CALL NOW on thank-you.php
   dials (877) 627-1504 ──► CallGrid's switch ──► forwarded to the specialist

3. Call ends
   CallGrid ──POST──► Zapier Catch Hook B  ("Zap 2", the existing webhook)
                      { CallId, CallerNumber: +15125550123, duration: 214,
                        lead_id: "", email: "", fbc: "" }   ← session tags empty

4. Zap 2 looks up the sheet where phone_digits = digits(CallerNumber)
   → finds the row from step 1 → merges → sends the complete record onward
```

**This depends entirely on `CallerNumber` populating on a real call.** Confirm that before
building anything downstream — see [Verifying](#verifying).

---

## 1. Server side (already built)

Nothing to write. `submit.php` posts the lead after the Equifax pull (so the verified
total debt rides along) and skips suspected bots, matching the log-and-continue contract
of the Equifax and LeadProsper steps — a Zapier outage can never cost a stored lead.

| File | Role |
| --- | --- |
| `includes/zapier.php` | `zapier_lead_payload()` + `zapier_send_lead()` |
| `config.php` → `['zapier']` | enable flag, webhook URL, timeout |
| `submit.php` | the push, gated on `$botReason === null` |

### Enable it

In `.env`:

```
ZAPIER_ENABLED=1
ZAPIER_LEAD_WEBHOOK_URL=https://hooks.zapier.com/hooks/catch/27354261/46ghysk/
ZAPIER_TIMEOUT=8
```

This must be a **second** catch hook — not the one CallGrid itself posts to. An empty
`ZAPIER_LEAD_WEBHOOK_URL` keeps the push dormant, so an unconfigured environment sends
nothing and logs nothing as a failure.

`ZAPIER_TIMEOUT` is deliberately short: the push runs inline on the submit request, and a
slow Zapier must not hold up the visitor's redirect.

### What it sends

Nineteen keys, **the same set every time** — blanks included. The shape has to be
identical on every submit, because Zapier builds its field list from a sample payload and
a spreadsheet joins by column position. A key that vanished because the visitor left it
empty would shift every column after it.

```json
{
  "lead_id": 4821,
  "phone": "+15125550123",
  "phone_digits": "5125550123",
  "email": "jane@example.com",
  "first_name": "Jane",
  "last_name": "Doe",
  "dob": "1985-04-02",
  "state": "TX",
  "zip": "78701",
  "city": "Austin",
  "behind_payment": "yes",
  "employed": "employed",
  "total_debt": "27450",
  "fbclid": "IwAR123",
  "fbp": "fb.1.1700000000.987",
  "fbc": "fb.1.1700000000.IwAR123",
  "client_ip_address": "203.0.113.9",
  "client_user_agent": "Mozilla/5.0 (iPhone)",
  "submitted_at": "2026-08-10T14:22:07+00:00"
}
```

Field names deliberately mirror the CallGrid webhook body, so the two sides of the Zap
line up key-for-key.

`phone_digits` is the join column: the number stripped to 10 digits with the US country
code removed. `+15125550123`, `5125550123`, and `(512) 555-0123` all reduce to
`5125550123`, so the match holds regardless of how either side formats it.

**Trade-off:** because blanks are always sent, a re-submit from the same phone number will
overwrite a previously-populated field with an empty value if that answer is missing the
second time. Rare in practice, and the cost of stable columns.

---

## 2. Google Sheet setup

Create a sheet with these headers in row 1, in this order:

```
lead_id | phone | phone_digits | email | first_name | last_name | dob | state | zip |
city | behind_payment | employed | total_debt | fbclid | fbp | fbc |
client_ip_address | client_user_agent | submitted_at
```

**Before any data lands**, select the `phone`, `phone_digits`, `zip`, and `dob` columns →
**Format → Number → Plain text**.

This is the single most common cause of these lookups silently failing. Left as
Automatic, Sheets stores `5125550123` as a number and `1985-04-02` as a date; Zapier's
lookup does a string comparison, which then never matches. Fixing the format *after* data
exists does not re-coerce the existing rows — do it first.

---

## 3. Zap 1 — store the lead

Fires once per funnel submission.

| Step | App | Configuration |
| --- | --- | --- |
| Trigger | Webhooks by Zapier → **Catch Hook** | The URL from `ZAPIER_LEAD_WEBHOOK_URL` |
| Action | Google Sheets → **Lookup Spreadsheet Row** | Column `phone_digits`, value = `phone_digits` from the trigger. Enable **"create if it doesn't exist yet"** |
| Action | Google Sheets → **Update Spreadsheet Row** | Row ID from the lookup; map all 19 fields |

That lookup-then-update pair is an upsert: exactly one row per phone number, always
current.

The extra step is worth it. Zapier's lookup returns the **first** match, so with
append-only rows a repeat caller would join against their *oldest* submission rather than
their newest. If you want full history instead, use *Create Spreadsheet Row* alone and
accept that caveat, or write an archive copy to a second sheet.

To generate a sample payload for field mapping, set `ZAPIER_LEAD_WEBHOOK_URL`, deploy, and
submit the funnel once.

---

## 4. Zap 2 — join on the call

This is the **existing** CallGrid webhook Zap. Insert the lookup between the trigger and
whatever it currently does.

| Step | App | Configuration |
| --- | --- | --- |
| Trigger | Webhooks by Zapier → Catch Hook | Existing — CallGrid posts here |
| Action | Formatter → **Numbers → digits only** | Input: `CallerNumber` |
| Action | Google Sheets → **Lookup Spreadsheet Row** | Column `phone_digits`, value = the formatter output |
| Action | *(your existing downstream action)* | Map fields from the lookup, not from the empty `[[tag:...]]` values |

Two settings that matter on the lookup step:

- **"Create if not found" must be OFF.** Otherwise every unrecognized caller writes a junk
  row into your lead sheet.
- **Set it to continue when nothing matches.** Otherwise a call from an unknown number
  errors the whole Zap out, and you lose the call record too — worse than a partial one.

The CallGrid webhook body itself needs no changes. The session-scoped tags will keep
arriving blank; Zap 2 fills them from the lookup. The only tag it genuinely depends on is
`CallerNumber`.

---

## Verifying

Work through these in order — each one isolates a different failure.

1. **Is the lead push firing?** Submit the funnel, then check `logs/` for a `zapier` /
   `lead_push` entry. `status: 200` means Zapier accepted it. A `skipped` entry means
   `ZAPIER_LEAD_WEBHOOK_URL` is empty; `curl_error_*` means the request never completed.
2. **Did the row land?** Check the sheet. If the row exists but `phone_digits` renders
   right-aligned, the column is still formatted as a number — fix the format and re-test.
3. **Does `CallerNumber` populate?** Place a **real** call to the tracking number and
   inspect what Zapier receives. A test fire from the webhook editor's ▶ button has no
   call attached and will show *every* tag empty, including `CallId` and `duration` —
   that is expected and proves nothing.
4. **Does the lookup match?** With a real call from a number you submitted, confirm Zap 2's
   lookup step returns the row rather than falling through.

If step 3 shows all tags empty on a genuine call, the problem is not attribution — it is
that CallGrid isn't resolving the `[[tag:...]]` syntax at all. Check the platform's actual
substitution syntax and tag names before going further; nothing in this guide will help.

---

## Known limits

**Coverage is partial by design.** A caller using a different phone than they submitted, or
withholding caller ID, will not match. There is no fix short of a number pool. This is why
Zap 2 must pass unmatched calls through rather than erroring.

**The sheet accumulates PII** — email, DOB, full name, IP, user agent — indefinitely. A
Google Sheet is far easier to share accidentally than a Zapier Table. Restrict it to named
accounts, never "anyone with the link", and set a retention/deletion schedule.

**Lookups slow as it grows.** Noticeable past ~10k rows, unreliable past ~50k. Archive
periodically.

**Meta's Conversions API requires hashed PII.** If the downstream action feeds the CAPI,
confirm who is doing the SHA-256 hashing of `email` / `phone` / `first_name` / `last_name`
— unhashed values are rejected.

## If you ever add a number pool

The `data-tags` wiring in `thank-you.php` is already in place and starts working the day a
pool exists — DNI will assign a number, fire an impression carrying the tags, and the
session-scoped fields on the call webhook will populate on their own. At that point this
Zapier join becomes a redundant fallback and can be retired.

One thing to fix first: `loadCallGrid()` does not set `targetPhoneNumber`, and the SDK's
`shouldReplaceNumber()` returns `true` for every number when no target is configured. DNI
would therefore swap *every* phone number on the page — including the brand number in the
header and footer. Set `script.dataset.targetPhoneNumber` to the CTA number before
enabling a pool.
