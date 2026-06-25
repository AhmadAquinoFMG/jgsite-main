# JG Wentworth — Debt Relief Funnel (PHP)

A PHP clone of the look & feel of `https://www.jgwentworth.com/ds-aff-lp-2`
(originally WordPress + Elementor + Gravity Forms). This is a plain-PHP,
no-framework, no-database rebuild of the 6-step debt-relief lead funnel.

## Run locally

```bash
php -S 127.0.0.1:8000
# open http://127.0.0.1:8000/index.php
```

Requires PHP 8.0+. No Composer/dependencies.

## Structure

| File | Purpose |
|------|---------|
| `index.php` | Page markup + the 6-step form. Pulls all copy/options from `config.php`. |
| `config.php` | Single source of content: brand, debt options, US states, consent/legal text, footer links. Edit here, not the markup. |
| `includes/header.php` | **Funnel** header — centered logo only. |
| `includes/footer.php` | **Funnel** footer — brand, address, legal links, disclosure. |
| `includes/site-header.php` | **Main-site** header (non-funnel pages) — logo, primary nav, Log In. |
| `includes/site-footer.php` | **Main-site** footer (non-funnel pages) — logo + social, Company/Legal columns, copyright. |
| `assets/css/style.css` | All styling (Poppins, brand greens `#006846`/`#1B976A`, teal accent). |
| `assets/js/funnel.js` | Multi-step navigation, client-side validation, progress bar, auto-advance. |
| `assets/img/` | Logos, trust badges and icons pulled from the live site. |

## The funnel flow (8 steps)

Single page, JS-driven (no reloads between steps). A progress bar advances 1/8 → 8/8.

| # | Step | Input | Advance |
|---|------|-------|---------|
| 1 | Debt amount | 5 radio cards | auto |
| 2 | Employment status | 4 radio cards | auto |
| 3 | Annual income | 3 radio cards | auto |
| 4 | First & last name | 2 text inputs | Continue |
| 5 | Street address | street/city/zip + state `<select>`, **Google Places autocomplete** on street | Continue |
| 6 | Date of birth | single input, **auto-formats MM/DD/YYYY** | Continue |
| 7 | Email | email input | Continue |
| 8 | Phone + verification | phone → **Send Code** → 6 OTP boxes → **Verify** → TCPA + **Submit** | Submit |

Client-side validation surfaces per-field error states: `invalid_format`,
`too_short`, `incomplete` / `out_of_range` / `underage` (DOB),
`invalid_length` (phone), and `not_verified` (gates Submit).

## UI only — mocked integrations

Two third-party integrations are **lazy-loaded stubs** (UI/UX fully wired, no live SDK):

- **Google Places (step 5)** — `loadGooglePlaces()` in `funnel.js` mocks a
  suggestions dropdown that fills street/city/state/zip. Replace with the real
  Maps JS API (`libraries=places` + `google.maps.places.Autocomplete`).
- **Firebase phone auth (step 8)** — `loadFirebase()` is a no-op; send/verify are
  mocked (**demo code: `123456`**). Replace with `signInWithPhoneNumber()` +
  `confirmationResult.confirm()`. Submit is gated on the hidden `is_verified="1"`.

Each stub is marked with a comment block showing where the real SDK call goes.

## Backend (not wired yet)

On Submit, `funnel.js` collects the lead into a `FormData` object, logs it to the
console, and then redirects to **`thank-you.php`** — the "You're Pre-Qualified"
confirmation page (assigned-specialist messaging, click-to-call CTA, and a
"your file is held for N:00" countdown). **Nothing is sent or stored.** To wire a
real destination, replace the marked block in `funnel.js`
(`// ---- Backend submission goes here ----`) with a `fetch()` to a new
`submit.php` that validates server-side and forwards to your CRM / email / database,
then redirect on success.

The confirmation page's phone number and hold-timer length are configurable in
`config.php` → `['prequal']` (`cta_phone`, `hold_minutes`).
