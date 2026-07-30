# Bot Protection Guide — Honeypot, Submit-Timing, Cloudflare Turnstile

How to add three layered bot defenses to the funnel (`index.php` → `submit.php`):

1. **Honeypot field** — an invisible input real visitors never fill in; naive bots do.
2. **Submit-timing check** — rejects submissions completed faster than a human could
   plausibly fill a 9-step form.
3. **Cloudflare Turnstile** — Cloudflare's CAPTCHA replacement, verified server-side.

## Design summary

All three signals feed one variable in `submit.php`: `$botReason` (`null`, or one of
`'honeypot'` / `'timing'` / `'turnstile'`). When set:

- The lead **is still stored** in `leads` (flagged `bot_suspected = 1`, `bot_reason =
  '<reason>'`) — so you get an audit trail and can see attack volume/patterns.
- The lead **never reaches Equifax or LeadProsper** — those two best-effort blocks in
  `submit.php` get gated on `if (!$botReason)`, so a caught bot lead is never pulled
  against Equifax or delivered/billed to LeadProsper.
- The HTTP response is **always `{ok:true}`**, exactly like a real success, and the
  browser still redirects to `thank-you.php`. This is the honeypot trap: a bot (or a
  human attacker probing the form) sees a normal "success" and gets no signal that
  it was caught, so it has no reason to adapt.

This is a deliberate trade-off: Turnstile's typical UX is "show an error, let the
visitor retry." Here it's silently swallowed instead, in favor of the trap behavior.
If that's ever undesirable for a *real* visitor who fails Turnstile for a benign
reason (e.g. an ad-blocker breaking the widget), the fallback is that they still get a
`{ok:true}` + thank-you page — they are never blocked from completing the funnel, just
their lead won't be forwarded live. Decide if that trade-off is acceptable before
enabling `TURNSTILE_ENABLED=1` in production.

---

## 1. Honeypot field

### `index.php` — add the field

Add this inside the hidden-fields block near the top of the `<form>` (around line 70,
right after `landing_page_url`):

```php
<!-- Honeypot: invisible to real visitors (see .hp-field in style.css). Real
     bot-form-fillers often populate any input they can find, including ones
     with no visible label — this one silently marks the submission as a bot
     in submit.php instead of erroring, so the trap stays invisible. -->
<div class="hp-field" aria-hidden="true">
    <label for="website">Website</label>
    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
</div>
```

`name="website"` is deliberate — it's one of the field names generic bot form-fillers
target most often (alongside `url`/`homepage`). Do **not** use `type="hidden"` or
`display:none`/`hidden` — some bots specifically skip those; a real (but off-screen)
text input is a stronger trap.

### `assets/css/style.css` — hide it visually, not structurally

```css
/* Honeypot field (index.php) — off-screen, not display:none, so it still
   "exists" to less careful bots but is unreachable/invisible to real users. */
.hp-field {
    position: absolute;
    left: -9999px;
    top: -9999px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
```

### `submit.php` — check it

Add this right after the `$post` helper is defined (~line 46), before any other
validation:

```php
/* --------------------------------------------------------- bot detection */
// Aggregates all three signals below (honeypot / timing / Turnstile). When
// set, the lead is still stored (flagged) but never reaches Equifax/LeadProsper,
// and the response still looks like a normal success — see the bottom of this
// file and docs/bot-protection.md for the full rationale.
$botReason = null;

if ($post('website') !== '') {
    $botReason = 'honeypot';
}
```

---

## 2. Submit-timing check

### `index.php` — stamp the render time

Add next to the honeypot field:

```php
<!-- Server-rendered timestamp (NOT a JS Date.now() — that's trivially spoofable
     by a bot that just sets the field itself). submit.php compares this against
     request time to reject implausibly fast completions. -->
<input type="hidden" name="form_rendered_at" value="<?= time() ?>">
```

### `config.php` — make the threshold configurable

Add near the other simple flat config values (e.g. right under `google_places_key`):

```php
// ---- Bot-protection: minimum plausible time-to-submit (seconds) -----
// A submission completed faster than this after the page rendered is flagged
// as a bot (see submit.php's $botReason). 4s is already generous for a script
// filling every field in one shot; real visitors take much longer on a 9-step form.
'timing_min_seconds' => (int) env('TIMING_MIN_SECONDS', '4'),
```

### `.env.example` / `.env.production.example`

```
# ---- Bot protection ------------------------------------------------------
# Minimum seconds between page render and submit before a lead is flagged as
# a bot (see docs/bot-protection.md). Default 4.
TIMING_MIN_SECONDS=4
```

### `submit.php` — compute the delta

Right after the honeypot check:

```php
$renderedAt = (int) $post('form_rendered_at');
if ($botReason === null && $renderedAt > 0
    && (time() - $renderedAt) < (int) ($cfg['timing_min_seconds'] ?? 4)) {
    $botReason = 'timing';
}
```

---

## 3. Cloudflare Turnstile

### 3a. Cloudflare dashboard setup (one-time, per domain)

1. Log into the [Cloudflare dashboard](https://dash.cloudflare.com/) → **Turnstile**
   (left sidebar).
2. **Add Site** → enter the funnel's production domain (and any staging domains you
   want covered — Turnstile supports multiple hostnames per widget).
3. Widget mode: choose **Managed** (recommended — Cloudflare adaptively decides
   whether to show an interactive checkbox based on risk signals; good default
   balance of friction vs. protection). **Invisible** is a valid alternative if you
   want zero visual footprint — just note it in the widget settings, no code
   difference on this end.
4. Copy the **Site Key** (public, goes in the HTML) and **Secret Key** (private, goes
   in `.env`, never in client-side code or version control).

### 3b. `config.php`

Add a new block, mirroring the existing `leadprosper` block's shape:

```php
// ---- Cloudflare Turnstile (bot protection on the final funnel step) -------
// Verified server-side in submit.php via includes/turnstile.php. Leave
// TURNSTILE_ENABLED=0 (default) to skip rendering the widget and skip
// server-side verification entirely — useful for local dev.
'turnstile' => [
    'enabled'    => env('TURNSTILE_ENABLED', '0') === '1',
    'site_key'   => env('TURNSTILE_SITE_KEY', ''),
    'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    'endpoint'   => env('TURNSTILE_VERIFY_ENDPOINT', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    'timeout'    => (int) env('TURNSTILE_TIMEOUT', '10'),
],
```

### 3c. `.env.example` / `.env.production.example`

```
# ---- Cloudflare Turnstile (bot protection) --------------------------------
# Create a widget at https://dash.cloudflare.com/ → Turnstile → Add Site.
# Leave TURNSTILE_ENABLED=0 to disable (no widget rendered, no server check).
TURNSTILE_ENABLED=0
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
#TURNSTILE_VERIFY_ENDPOINT=https://challenges.cloudflare.com/turnstile/v0/siteverify
#TURNSTILE_TIMEOUT=10
```

### 3d. `index.php` — load the script

In `<head>`, alongside the other conditionally-loaded scripts (same pattern as the
Everflow script near the bottom of the file):

```php
<?php if (!empty($cfg['turnstile']['enabled'])): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
```

### 3e. `index.php` — render the widget on the final step

Inside step 9 (phone + consent + submit), after the consent note and before
`</section>` (~line 278):

```php
<?php if (!empty($cfg['turnstile']['enabled'])): ?>
    <div class="cf-turnstile" data-sitekey="<?= $e($cfg['turnstile']['site_key']) ?>"></div>
<?php endif; ?>
```

No JavaScript wiring is needed for this to reach the server: `assets/js/funnel.js`'s
submit handler builds the POST body via `new FormData(form)`, and because the widget
div lives inside `<form id="funnelForm">`, Turnstile's script auto-injects a hidden
`<input type="hidden" name="cf-turnstile-response">` into that div once solved —
`FormData` picks it up automatically.

**Optional hardening**: Turnstile solves asynchronously. If you want to guarantee the
token exists before the visitor can click Submit (rather than relying on
server-side rejection), gate `btnSubmit` on Turnstile's `callback` option:

```js
// Only needed if TURNSTILE_ENABLED — safe no-op otherwise since the widget div
// won't exist and this selector will find nothing.
if (window.turnstile && document.querySelector('.cf-turnstile')) {
    btnSubmit.disabled = true;
    window.turnstile.render('.cf-turnstile', {
        callback: function () { btnSubmit.disabled = false; }
    });
}
```
This is optional — server-side verification in `submit.php` is the actual
enforcement; this JS only improves UX by not letting a visitor click Submit before
the token exists.

### 3f. New file: `includes/turnstile.php`

Mirrors the curl-wrapper pattern already used in `includes/equifax.php`
(`equifax_http()`) and `includes/leadprosper.php` (`leadprosper_http()`):

```php
<?php

/**
 * Cloudflare Turnstile server-side verification.
 *
 * Called from submit.php as part of bot detection (see docs/bot-protection.md).
 * Best-effort in shape (never throws) but its result IS blocking — unlike
 * Equifax/LeadProsper's "log & continue", a failed verify sets $botReason in
 * submit.php, which routes the lead away from Equifax/LeadProsper (but still
 * returns {ok:true} to the caller — see submit.php).
 */

if (!function_exists('turnstile_verify')) {

    /**
     * @return array{ok:bool, error:?string, raw:?string}
     */
    function turnstile_verify(array $cfg, string $token, string $remoteIp): array
    {
        $ts = $cfg['turnstile'] ?? [];

        if ($token === '') {
            return ['ok' => false, 'error' => 'missing_token', 'raw' => null];
        }
        if (($ts['secret_key'] ?? '') === '') {
            return ['ok' => false, 'error' => 'not_configured', 'raw' => null];
        }

        $body = http_build_query([
            'secret'   => $ts['secret_key'],
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $http = turnstile_http(
            (string) ($ts['endpoint'] ?? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            $body,
            (int) ($ts['timeout'] ?? 10)
        );

        if ($http['error'] !== null) {
            return ['ok' => false, 'error' => $http['error'], 'raw' => null];
        }

        $decoded = json_decode((string) $http['body'], true);
        $ok = is_array($decoded) && !empty($decoded['success']);

        return [
            'ok'    => $ok,
            'error' => $ok ? null : ('turnstile_' . implode(',', $decoded['error-codes'] ?? ['unknown'])),
            'raw'   => $http['body'],
        ];
    }

    /** Minimal curl wrapper. Returns ['status'=>int, 'body'=>?string, 'error'=>?string]. */
    function turnstile_http(string $url, string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'curl_unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        $cerr   = $errno !== 0 ? ('curl_error_' . $errno) : null;
        curl_close($ch);
        return [
            'status' => $status,
            'body'   => $resp === false ? null : (string) $resp,
            'error'  => $cerr,
        ];
    }
}
```

### 3g. `submit.php` — wire it in and require the new file

Add the `require` alongside the existing includes near the top:

```php
require __DIR__ . '/includes/turnstile.php';
```

Then, right after the timing check:

```php
if ($botReason === null && !empty($cfg['turnstile']['enabled'])) {
    $ts = turnstile_verify($cfg, $post('cf-turnstile-response'), $_SERVER['REMOTE_ADDR'] ?? '');
    if (empty($ts['ok'])) {
        $botReason = 'turnstile';
    }
}
```

This all happens before the existing `$errors` field-validation block and well before
the `leads` INSERT — consistent with how the rest of `submit.php` blocks on bad input
before doing any DB work.

---

## 4. Wiring `$botReason` through the rest of `submit.php`

### Store the flag on the lead row

In the `$row = [...]` array (where `debt_amount`, `employment`, etc. are assembled),
add:

```php
'bot_suspected' => $botReason !== null ? 1 : 0,
'bot_reason'    => $botReason,
```

(Requires the two new columns from the migration below — `$cols =
array_keys($row)` already drives the INSERT dynamically, so no other change is
needed there.)

### Gate Equifax and LeadProsper

Wrap each existing best-effort block:

```php
if ($botReason === null) {
    // existing Equifax try/catch block, unchanged
}
```

```php
if ($botReason === null) {
    // existing LeadProsper try/catch block, unchanged
}
```

### Keep logging, keep the response the same

Right before `echo json_encode(['ok' => true, ...])`, add an audit log line (bot or
not — cheap, and gives you a queryable signal in the app log):

```php
if ($botReason !== null) {
    app_log('warning', 'lead', 'bot_suspected', ['rid' => $rid, 'lead_id' => $leadId, 'reason' => $botReason]);
}
```

The response itself — `{ok:true, total_debt:…, estimated_savings:…}` — does **not**
change based on `$botReason`. That's the point: the caller (bot or human) always sees
success and the browser redirects to `thank-you.php` exactly as normal.

---

## 5. Database migration

### `sql/schema.sql` — add to the `leads` table definition

```sql
`bot_suspected`   TINYINT(1)   NOT NULL DEFAULT 0,
`bot_reason`      VARCHAR(32)  DEFAULT NULL,   -- 'honeypot' | 'timing' | 'turnstile'
```

### New file: `sql/alter_leads_add_bot_flags.sql`

```sql
-- Migration: add `bot_suspected` / `bot_reason` to the `leads` table.
--
-- Run this on an EXISTING database:
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_bot_flags.sql
--
-- Fresh databases created from sql/schema.sql already include these columns.

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `bot_suspected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_at`,
    ADD COLUMN IF NOT EXISTS `bot_reason` VARCHAR(32) DEFAULT NULL AFTER `bot_suspected`;
```

---

## 6. Touch-point summary

| File | Change |
|---|---|
| `config.php` | new `turnstile` block + `timing_min_seconds` |
| `.env.example`, `.env.production.example` | `TURNSTILE_*`, `TIMING_MIN_SECONDS` |
| `index.php` | honeypot field, render-timestamp field, Turnstile script + widget div |
| `assets/css/style.css` | `.hp-field` off-screen rule |
| `includes/turnstile.php` | **new** — `turnstile_verify()` |
| `submit.php` | `$botReason` aggregation, gated Equifax/LeadProsper blocks, extra INSERT columns, audit log line |
| `sql/schema.sql` | + `bot_suspected`, `bot_reason` columns |
| `sql/alter_leads_add_bot_flags.sql` | **new** — migration for existing DBs |

---

## 7. How to verify it works

**Honeypot** — POST directly to `submit.php` with the honeypot field filled and
otherwise-valid data:
```bash
curl -s -X POST https://<your-domain>/submit.php \
  -d "website=http://spam.example&debt_amount=Less+than+%2410%2C000&behind_payment=not_behind&employment=employed&income=Under+%2430%2C000&first_name=Test&last_name=Bot&street=123+Main+St&city=Austin&state=TX&zip=78701&dob=01%2F01%2F1990&email=test@example.com&phone=5125550100"
```
Expect `{"ok":true,...}` in the response, but check the `leads` table row for that
submission has `bot_suspected = 1, bot_reason = 'honeypot'`, and confirm no row was
written to `leadprosper_logs`/`equifax_logs` for that `lead_id`.

**Timing** — same request, honeypot empty, sent immediately after a fresh page load
so `form_rendered_at` is within `TIMING_MIN_SECONDS` of "now" (a normal `curl` request
happens fast enough already, since there's no real page render delay to begin with —
just POST as usual and check `bot_reason = 'timing'` unless you also fill valid data
including a `form_rendered_at` several seconds in the past).

**Turnstile** — temporarily set `TURNSTILE_SECRET_KEY` to Cloudflare's [documented
always-fail testing secret](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
and submit a real form through the browser; confirm the lead is still stored with
`bot_reason = 'turnstile'` and the visitor still lands on `thank-you.php` normally.
Revert to the real secret key afterward.
