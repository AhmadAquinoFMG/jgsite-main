<?php

/**
 * Funnel configuration — JG Wentworth Debt Relief landing page (PHP clone).
 *
 * Everything content-related lives here so the markup in index.php / includes
 * stays clean. Swap branding, steps, options or copy without touching layout.
 */

/**
 * Minimal .env reader — no Composer/dotenv dependency.
 * Parses KEY=VALUE lines from the project-root .env once, caches them, and
 * lets a real environment variable of the same name win. Keeps secrets (e.g.
 * the Google Places key) out of version control — see .env.example.
 */
if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        static $vars = null;
        if ($vars === null) {
            $vars = [];
            $path = __DIR__ . '/.env';
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') continue;
                    $pos = strpos($line, '=');
                    if ($pos === false) continue;
                    $k = trim(substr($line, 0, $pos));
                    $v = trim(substr($line, $pos + 1));
                    // strip one layer of surrounding quotes, if present
                    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                        $v = substr($v, 1, -1);
                    }
                    $vars[$k] = $v;
                }
            }
        }
        $live = getenv($key);
        if ($live !== false && $live !== '') return $live;
        return array_key_exists($key, $vars) ? $vars[$key] : $default;
    }
}

return [
    // ---- Asset cache-busting -------------------------------------------
    // Bump this whenever CSS/JS changes so browsers/CDNs fetch fresh files.
    // Appended to asset URLs as ?v=… in index.php / thank-you.php.
    'asset_version' => '39',

    // ---- Analytics: Umami -----------------------------------------------
    // Privacy-friendly analytics. Used to measure funnel drop-off (which field
    // visitors leave from) via the per-field events fired in assets/js/funnel.js
    // and read back by bin/funnel-slack-report.php.
    // Leave 'website_id' empty to disable the script entirely.
    //   • Umami Cloud:  src => 'https://cloud.umami.is/script.js'
    //   • Self-hosted:  src => 'https://<your-host>/script.js'
    //
    // THE ONLY PLACE this id belongs. Every page renders the tag through
    // includes/analytics.php — never hardcode a second <script> tag on a page.
    // Two tags means two pageviews per visit, doubled click events, and (because
    // both define window.umami, last one winning) custom events landing on a
    // different property than the pages that don't carry the extra tag — which
    // splits the funnel across two websites and makes it unreportable.
    // bin/funnel-slack-report.php must query this same id: its DEFAULT_WEBSITE,
    // or the UMAMI_WEBSITE_ID env var that overrides it.
    'umami' => [
        'src'        => 'https://cloud.umami.is/script.js',
        'website_id' => '40f1f6d9-80c1-49cf-b6ef-0280ac052f83',
    ],

    // ---- Google Places (address autocomplete, step 5) ------------------
    // Loaded lazily in assets/js/funnel.js. Set GOOGLE_PLACES_KEY in .env
    // (see .env.example). Leave empty to fall back to the built-in mock
    // suggestion list so the funnel still works locally without billing.
    'google_places_key' => env('GOOGLE_PLACES_KEY', ''),

    // ---- Bot-protection: minimum plausible time-to-submit (seconds) -----
    // A submission completed faster than this after the page rendered is flagged
    // as a bot (see submit.php's $botReason). 4s is already generous for a script
    // filling every field in one shot; real visitors take much longer on a 9-step form.
    'timing_min_seconds' => (int) env('TIMING_MIN_SECONDS', '4'),

    // ---- Runtime environment -------------------------------------------
    // 'local' relaxes production-only checks so the funnel can be exercised
    // without live third-party services. Set APP_ENV=production on staging/live.
    'app_env' => env('APP_ENV', 'production'),

    // ---- Operational logging -------------------------------------------
    // File-based structured log (includes/logger.php) for the lead pipeline.
    // Separate from the leads/equifax_logs DATA tables — this is the ops trail.
    //   level: debug | info | warning | error (lines below it are dropped).
    //   dir:   defaults to <project>/logs (gitignored); override with LOG_DIR.
    'logging' => [
        'dir'   => env('LOG_DIR') ?: __DIR__ . '/logs',
        'level' => env('LOG_LEVEL', 'info'),
    ],

    // ---- Database (lead storage) ---------------------------------------
    // MySQL/MariaDB on Cloudways, reached through PDO in includes/db.php.
    // Creds come from .env (see .env.example); nothing is committed.
    'db' => [
        'host'    => env('DB_HOST', 'localhost'),
        'port'    => env('DB_PORT', '3306'),
        'name'    => env('DB_NAME', 'jgw_leads_test'),
        'user'    => env('DB_USER', 'root'),
        'pass'    => env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    // ---- Compliance capture (TCPA proof-of-consent) --------------------
    // TrustedForm needs no key — the script (includes/compliance.php) injects a
    // hidden xxTrustedFormCertUrl the backend stores. Jornaya (LeadiD) needs a
    // campaign+account id. Leave a value empty to omit that tag entirely.
    'compliance' => [
        'trustedform'       => (env('TRUSTEDFORM_ENABLED', '1') === '1'),
        'jornaya_campaign'  => env('JORNAYA_CAMPAIGN_ID', ''),
        'jornaya_account'   => env('JORNAYA_ACCOUNT_ID', ''),
    ],

    // ---- Equifax Consumer Credit Report (OneView, OAuth2) --------------
    // submit.php pulls a credit report after storing the lead and logs the
    // request/response to equifax_logs (includes/equifax.php). Best-effort — a
    // failure is logged but never blocks the lead. Aligned with the proven
    // integration in the sibling `tdo` project.
    //
    //   mode:  'off'        → skip entirely, no log row (default).
    //          'mock'       → synthetic response, DOES log (test the pipeline).
    //          'sandbox'    → live call against api.sandbox.equifax.com.
    //          'production' → live call against api.equifax.com.
    //
    // Credentials are chosen by mode: production uses EQUIFAX_PRODUCTION_*,
    // sandbox uses EQUIFAX_SANDBOX_* (the API key/secret are the OAuth
    // client-credentials). NOTE: 'scope' defaults EMPTY — this account's token
    // endpoint rejects an explicit scope (400 invalid_scope) and only issues a
    // token when scope is omitted. Only set EQUIFAX_SCOPE if your account needs one.
    'equifax' => (function () {
        $mode   = strtolower(env('EQUIFAX_MODE', 'off'));
        $isProd = $mode === 'production';
        return [
            'mode'          => $mode,
            'is_prod'       => $isProd,
            'api_key'       => env($isProd ? 'EQUIFAX_PRODUCTION_API_KEY'    : 'EQUIFAX_SANDBOX_API_KEY', ''),
            'api_secret'    => env($isProd ? 'EQUIFAX_PRODUCTION_API_SECRET' : 'EQUIFAX_SANDBOX_API_SECRET', ''),
            'base_url'      => rtrim(env('EQUIFAX_API_BASE', $isProd ? 'https://api.equifax.com' : 'https://api.sandbox.equifax.com'), '/'),
            'scope'         => env('EQUIFAX_SCOPE', ''), // empty by design — see note above
            'token_path'    => env('EQUIFAX_TOKEN_PATH', '/v2/oauth/token'),
            'product_path'  => env('EQUIFAX_PRODUCT_PATH', '/business/oneview/consumer-credit/v1/reports/credit-report'),
            'model_id'      => env('EQUIFAX_MODEL_ID', '05734'),
            'member_number' => env('EQUIFAX_MEMBER_NUMBER', ''),
            'security_code' => env('EQUIFAX_SECURITY_CODE', ''),
            'customer_code' => env('EQUIFAX_CUSTOMER_CODE', ''),
            'ecoa_inquiry_type'         => env('EQUIFAX_ECOA_INQUIRY_TYPE', 'Individual'),
            'multiple_report_indicator' => env('EQUIFAX_MULTIPLE_REPORT_INDICATOR', '1'),
            'timeout'       => (int) env('EQUIFAX_TIMEOUT', '20'),
            // Redact SSN + account secrets in the stored request_body.
            'redact'        => (env('EQUIFAX_REDACT', '0') === '1'),
            // Optional CA-chain PEM to pin cURL's trust to (includes/equifax.php).
            // Defaults to certs/equifax-ca-chain.pem NEXT TO THIS config.php (i.e.
            // wherever this app is actually deployed) rather than a hardcoded
            // absolute path tied to one specific server/app id — that's what broke
            // before ("error setting certificate file") when the app id in the path
            // didn't match this deployment. Falls back to curl's system CA bundle
            // if the file isn't present. TLS verification is ALWAYS on either way;
            // this only picks which chain is trusted. Override via EQUIFAX_CA_BUNDLE
            // if you need a different path.
            'ca_bundle'     => env('EQUIFAX_CA_BUNDLE', is_readable(__DIR__ . '/certs/equifax-ca-chain.pem') ? __DIR__ . '/certs/equifax-ca-chain.pem' : ''),
        ];
    })(),

    // ---- LeadProsper direct-post (lead distribution) --------------------
    // submit.php posts the lead to LeadProsper AFTER it's stored (and after the
    // Equifax pull, so the verified total debt can be included). Best-effort —
    // aligned with the proven integration in the sibling `tdo` project, adapted
    // to this funnel's field names (includes/leadprosper.php).
    //
    //   mode: 'off'  → skip entirely, no log row (default).
    //         'test' → live call, but flagged lp_action=test so LeadProsper
    //                  never bills/delivers it (use to validate field mapping).
    //         'live' → real, billable/deliverable lead post.
    'leadprosper' => [
        'mode'        => strtolower(env('LEADPROSPER_MODE', 'off')),
        'campaign_id' => env('LP_CAMPAIGN_ID', ''),
        'supplier_id' => env('LP_SUPPLIER_ID', ''),
        'key'         => env('LP_KEY', ''),
        'endpoint'    => env('LP_ENDPOINT', 'https://api.leadprosper.io/direct_post'),
        'timeout'     => (int) env('LP_TIMEOUT', '20'),
        /* Key under which LeadProsper echoes a BUYER's returned value back to us
           ("Customize Supplier API Response" → "Pass data from your buyer's API
           response back to the supplier"). Set to the Key configured on campaign
           35954; it carries JG Wentworth's own `total_debt_included`, which is
           how we get their figure without ever calling JG ourselves.

           Requires the per-buyer mapping too — the campaign toggle alone returns
           nothing — and LeadProsper documents the feature as working only for
           EXCLUSIVE leads sold to one buyer. Map it for JG ONLY: InCharge Debt
           Solutions qualifies on the figure WE send, so it has nothing to return
           and an unmapped buyer keeps the key unambiguous (present ⇒ JG's).

           This value never becomes the total_debt we POST — see submit.php. It
           arrives with the response, i.e. after the post, and it is JG's rule
           applied to JG's product, not a figure to hand InCharge. */
        // 'buyer_total_debt_key' => env('LP_BUYER_TOTAL_DEBT_KEY', 'total_debt_included'),
        /* WHOSE figure we accept under that key — case-insensitive substring of
           the buyer's name in LeadProsper. The campaign has two buyers whose debt
           numbers mean different things: JG returns their own underwritten total,
           InCharge Debt Solutions qualifies on the number WE send. Accepting the
           wrong one would be invisible in the data, so the value is ignored
           unless it is attributable to this buyer.

           Belt and braces: the real protection is mapping the key for JG ALONE in
           LeadProsper's per-buyer setup, which is what stops anyone else writing
           to it. Set empty to accept the key from any buyer. */
        // 'buyer_total_debt_from' => env('LP_BUYER_TOTAL_DEBT_FROM', 'wentworth'),
    ],

    // ---- QA test mode (?test=fmg_true) -----------------------------------
    // A landing URL that runs the real funnel end to end but lands in
    // LeadProsper as a TEST lead:
    //
    //   https://jgdebtrelief.com/?test=fmg_true
    //
    // funnel.js copies the param into the hidden `test` field like any other
    // attribution param, submit.php compares it to the token below, and
    // includes/leadprosper.php posts with lp_action=test — LeadProsper logs the
    // lead against the campaign, shows it flagged TEST, and never bills or
    // delivers it. That post happens even when LEADPROSPER_MODE=off, because a
    // test URL that silently sends nothing is the worse failure mode.
    //
    // JG's own test affiliate id does the same thing without the param, so
    // their QA link is a test lead as it stands:
    //
    //   https://jgdebtrelief.com/?oid=914&affid=300
    //
    // An empty TEST_MODE_TOKEN turns the ?test= half off (no value of ?test=
    // can then flag anything); an empty TEST_MODE_AFFIDS turns the affid half
    // off. They are independent — either one alone flags the lead.
    'test_mode' => [
        'token' => env('TEST_MODE_TOKEN', 'fmg_true'),

        // Test affiliate ids. Traffic arriving on one of these is QA traffic by
        // definition, so the lead is posted as a test whether or not ?test= is
        // on the URL.
        //
        // Deliberately affid ONLY — the ?oid= on that link is NOT part of the
        // check. 914 is the live first-party OFFER id (EVERFLOW_OFFER_FIRST_PARTY
        // above); real first-party links carry it too (?oid=914&affid=989), so
        // treating it as a test marker would flag genuine leads as tests and
        // stop them being billed or delivered.
        'affids' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('TEST_MODE_AFFIDS', '300'))
        ), fn($v) => $v !== '')),

        // The campaign has affid as a REQUIRED field, so a test post that
        // arrived without one is rejected ("field `affid` is required") before
        // any of the mapping being tested is even looked at. Used ONLY on test
        // posts, and only when the visit carried no ?affid= of its own (so a
        // lead that came in on the QA link above keeps its real 300) — it is
        // never stored on the lead and never reaches Everflow. Empty = don't
        // substitute.
        'affid' => env('TEST_MODE_AFFID', '300'),
    ],

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

    // ---- Everflow affiliate tracking (client-side click + conversion) ----
    // The ?affid= on the landing URL is what decides everything:
    //
    //   affid in first_party_affids  -> offer_first_party  (914, 1st party traffic)
    //   affid present, not in list   -> offer_third_party  (915, 3rd party traffic)
    //   affid absent/empty           -> Everflow is not touched at all. The SDK
    //                                   is never even loaded, no click, no
    //                                   conversion. Traffic we can't attribute
    //                                   must not reach Everflow.
    //
    // The offer is derived from affid, NOT from the ?oid= in the URL — affid is
    // the authoritative side of the mapping, and a link arriving with a missing
    // or mismatched oid used to silently kill click attribution. ?oid= is still
    // captured into a hidden field and forwarded to LeadProsper, it just has no
    // say in which Everflow offer the click/conversion lands on.
    //
    // Click fires on the landing page (assets/js/tracking/everflow.js), which
    // then writes the resolved transaction id into the ef_transaction_id hidden
    // field so it rides along with the lead (see index.php and
    // LEADPROSPER_TRACKING_PARAMS). Conversion fires on thank-you.php against
    // the same offer, using the affid submit.php stashed in the session.
    'everflow' => [
        'offer_first_party' => env('EVERFLOW_OFFER_FIRST_PARTY', '914'),
        'offer_third_party' => env('EVERFLOW_OFFER_THIRD_PARTY', '915'),
        'first_party_affids' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('EVERFLOW_FIRST_PARTY_AFFIDS', '989,995,1024'))
        ), fn($v) => $v !== '')),
        'domain' => env('EVERFLOW_DOMAIN', 'www.f0cg2trk.com'),
    ],

    // ---- Attribution persistence (assets/js/tracking/attribution.js) -----
    // The traffic link is an Everflow tracking link:
    //
    //   https://sxcxm.ttrk.io/<id>?sub1={{ad.id}}&sub2={{adset.id}}&sub3={{campaign.id}}
    //     &sub4={{ad.name}}&sub5={{adset.name}}&sub6={{campaign.name}}&sub7={{placement}}
    //     &sub8={{site_source_name}}&utm_source=facebook&utm_medium=paid&…
    //
    // Everflow forwards only the params it owns (affid, oid, source_id,
    // sub1-sub10, uid) plus whatever the offer's destination URL templates in.
    // utm_* is neither, so the click redirect STRIPS every utm before the
    // visitor lands here — which is why the utm hidden fields in index.php used
    // to post empty.
    //
    // FIX THIS UPSTREAM FIRST: add the utm placeholders to the Everflow offer's
    // destination URL (or enable its pass-through of unknown params). The map
    // below is the fallback, and it rebuilds each missing utm from the sub that
    // carries the same value — 'utm_campaign' => 'sub6' reads "if utm_campaign
    // is missing and sub6 is present, utm_campaign = sub6". It mirrors the link
    // above; a new source with a different sub layout is edited here.
    //
    // A utm that IS on the URL is never overwritten. 'defaults' are constants
    // that only hold for traffic matching this mapping, so they're applied only
    // when something was actually derived — direct traffic is never given an
    // invented source.
    'attribution' => [
        'derive' => [
            'utm_creative'  => 'sub1', // {{ad.id}}
            'utm_term'      => 'sub2', // {{adset.id}}
            'utm_content'   => 'sub4', // {{ad.name}}
            'utm_adgroup'   => 'sub5', // {{adset.name}}
            'utm_campaign'  => 'sub6', // {{campaign.name}}
            'utm_placement' => 'sub7', // {{placement}}
        ],
        'defaults' => [
            'utm_source'    => 'facebook',
            'utm_medium'    => 'paid',
            'utm_matchtype' => 'none',
        ],
    ],

    // ---- CallGrid (call tracking on the post-submit page) ----------------
    // Loaded on thank-you.php only — that's the one page with a click-to-call
    // CTA, so it's the only place a tracking number has anything to swap. The
    // SDK reads both ids off the script tag's data-* attributes.
    // Set CALLGRID_ENABLED=0 to skip loading it (local dev / staging).
    'callgrid' => [
        'enabled'            => env('CALLGRID_ENABLED', '1') === '1',
        'src'                => env('CALLGRID_SRC', 'https://cdn.callgrid.com/callgrid.js'),
        'organization_id'    => env('CALLGRID_ORGANIZATION_ID', 'cmnopd1vu002k07iqj9hif1he'),
        'campaign_source_id' => env('CALLGRID_CAMPAIGN_SOURCE_ID', 'cmsgf75n403x307lbj6dbmass'),
    ],

    // ---- Post-submit redirect -------------------------------------------
    // The redirect URL is built server-side (includes/redirect.php) from the row
    // submit.php validated and stored — never from the raw client POST. So the
    // appended values are the normalised ones: phone as E.164, DOB as ISO,
    // debt as an int, radio answers as their canonical option values.
    //
    // 'params' maps outgoing query-param name => field in submit.php's $row.
    // Empty/unanswered fields are dropped rather than sent blank. $row also
    // holds the attribution fields, so anything there can be forwarded by
    // naming it here (e.g. 'affid' => 'affid', 'ef_tid' => 'ef_transaction_id').
    //
    // PRIVACY: everything listed here lands in a URL, which means browser
    // history, the Referer header sent to any third party on the destination
    // page, and web-server access logs. The defaults below are the full set of
    // form answers as specified, PII included — trim this list to the minimum
    // the destination genuinely needs, and prefer an off-site destination that
    // reads them over POST if one is available.
    'redirect' => [
        'base' => env('REDIRECT_BASE', 'thank-you.php'),
        // Param names on the left match the tag names CallGrid captures off the
        // thank-you page URL, so its webhook template ([[tag:employed]],
        // [[tag:client_ip_address]], …) resolves without a mapping layer.
        'params' => [
            // Step answers
            'debt_amount'    => 'debt_amount',
            'behind_payment' => 'behind_payment',
            'employed'       => 'employment',
            'income'         => 'income',
            // Contact / identity
            'first_name'     => 'first_name',
            'last_name'      => 'last_name',
            'street'         => 'street',
            'city'           => 'city',
            'state'          => 'state',
            'zip'            => 'zip',
            // Param name is date_of_birth, not dob, to match the tag the
            // CallGrid webhook template reads ([[tag:date_of_birth]]). The
            // stored field is still `dob` — only the outgoing name differs.
            'date_of_birth'  => 'dob',
            'email'          => 'email',
            'phone'          => 'phone',
            // Lead record. total_debt is the Equifax-verified figure and is
            // absent when the pull returned nothing usable (see submit.php).
            'lead_id'        => 'lead_id',
            'total_debt'     => 'total_debt',
            // Buyer that accepted the lead at LeadProsper. thank-you.php looks
            // this up in the `buyers` table to decide whose logo to show under
            // the savings callout (includes/buyers.php). Absent when no buyer
            // accepted, or when LeadProsper is off. Not PII — a company name.
            'buyer'          => 'accepted_buyer',
            // Meta match keys, for the Conversions API event CallGrid fires off
            // the call. fbc/fbp are the pixel's cookies; the request-level pair
            // must be the *visitor's* ip/ua as we saw them at submit — CallGrid's
            // own server would otherwise attach its own, which Meta rejects as a
            // mismatch against the browser-side pixel event.
            'fbclid'            => 'fbclid',
            'fbp'               => 'fbp',
            'fbc'               => 'fbc',
            'client_ip_address' => 'ip',
            'client_user_agent' => 'user_agent',
        ],
    ],

    // ---- Branding -------------------------------------------------------
    'brand' => [
        'name'         => 'JG Wentworth',
        'logo_header'  => 'assets/img/JG-Wentworth-logo-header.svg',
        'logo_footer'  => 'assets/img/footer-logo.png',
        'phone'        => '1-888-510-3795',
        'dept'         => 'Debt Solutions',
        'address'      => ['1200 Morris Drive', 'Chesterbrook, PA 19087'],
        'copyright'    => 'Copyright © 2026 The JG Wentworth Company. All rights reserved',
    ],

    // ====================================================================
    //  Main site chrome — for NON-funnel pages (includes/site-header.php
    //  and includes/site-footer.php). The funnel keeps its own stripped
    //  header/footer in includes/header.php + includes/footer.php.
    // ====================================================================

    // ---- Primary navigation (site header) ------------------------------
    // 'dropdown' => true renders a chevron for menus with sub-items.
    'nav_links' => [
        ['label' => 'Structured Settlements', 'href' => '#'],
        ['label' => 'Debt Relief',            'href' => '#'],
        ['label' => 'Home Equity Cashout',    'href' => '#', 'dropdown' => true],
        ['label' => 'Other Products',         'href' => '#', 'dropdown' => true],
        ['label' => 'About Us',               'href' => '#', 'dropdown' => true],
        ['label' => 'Resources',              'href' => '#', 'dropdown' => true],
    ],
    'login_url' => '#',

    // ---- Site footer: "Company" column ---------------------------------
    'footer_company' => [
        'Contact Us'        => '#',
        'Affiliate Program' => '#',
        'Careers'           => '#',
        'Newsroom'          => '#',
        'Shop'              => '#',
    ],

    // ---- Site footer: "Legal Information" column ------------------------
    'footer_legal' => [
        'Terms of Use'                                    => 'https://www.jgwentworth.com/terms-use',
        'Legal Disclosures'                               => '#',
        'Your Privacy Rights'                             => '#',
        'Notice at Collection'                            => 'https://www.jgwentworth.com/notice-at-collection',
        'Privacy Policy'                                  => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Licenses'                                        => 'https://www.jgwentworth.com/licenses',
        'Asset-Backed Securitization'                     => '#',
        'Association for Consumer Debt Relief Disclosure' => '#',
        'Do Not Sell My Personal Information'             => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Debt Resolution Loan Disclosures'                => 'https://www.jgwentworth.com/debt-resolution-loan-disclosures',
    ],

    // ---- Site footer: social links (icons rendered in the include) -----
    // Keys must match the icon set in includes/site-footer.php.
    'social_links' => [
        'facebook'  => '#',
        'tiktok'    => '#',
        'youtube'   => '#',
        'instagram' => '#',
        'x'         => '#',
        'pinterest' => '#',
    ],

    // ---- Post-submit "pre-qualified" page (thank-you.php) ---------------
    'prequal' => [
        // The CALL NOW number is NOT configured here — it lives in the `buyers`
        // registry, in `did` (see sql/alter_add_buyers.sql). thank-you.php uses
        // the matched buyer's own number, so the consumer dials the company that
        // actually bought their file, and falls back to the row named below when
        // there is no ?buyer= to match.
        //
        // Which row backs an unmatched visit: a `buyers.name` match token, or a
        // string containing one — 'JG Wentworth' matches the 'Wentworth' row.
        // Their `did` is the funnel's default CTA number, and whether it is a
        // CallGrid tracking line or a raw DID is now a data decision, made in the
        // table rather than in this file. Keeping tracking means keeping a
        // CallGrid number in that row: a raw DID there silently ends call
        // attribution for unmatched visits, exactly as it used to here.
        'cta_buyer'    => 'JG Wentworth',
        'hold_minutes' => 5,                 // countdown the file is "held" for
    ],

    // ---- Trust badges (footer) -----------------------------------------
    'badges' => [
        ['src' => 'assets/img/trustpilot-1-300x240.png',         'alt' => 'Trustpilot 4.8/5 Stars'],
        ['src' => 'assets/img/bbb-1-e1741985838229-300x240.png', 'alt' => 'BBB Accredited Business — A+ Rating'],
        ['src' => 'assets/img/google-300x240.png',               'alt' => 'Google 4.5/5 Stars'],
    ],

    // ====================================================================
    //  Social proof block (landing page, below the trust badges).
    //  Rendered by includes/social-proof.php. Icon keys map to the inline
    //  SVG set defined in that include.
    // ====================================================================

    // ---- "Our commitment to you" cards ---------------------------------
    'commitments' => [
        ['icon' => 'search',  'title' => 'Transparency', 'text' => 'Clear terms, no jargon, and straightforward explanations at every step.'],
        ['icon' => 'shield',  'title' => 'Expertise',    'text' => 'Over 30 years helping Americans move forward with confidence.'],
        ['icon' => 'headset', 'title' => 'Support',      'text' => 'Dedicated specialists focused on your goals, not sales pressure.'],
    ],

    // ---- Headline stats ------------------------------------------------
    'stats' => [
        ['icon' => 'trophy', 'value' => '30+',   'label' => 'Years of experience'],
        ['icon' => 'users',  'value' => '375K+', 'label' => 'JGW Customers'],
        ['icon' => 'card',   'value' => '$2.2B', 'label' => 'Debt Consolidated'],
        ['icon' => 'wallet', 'value' => '$6.5B', 'label' => 'Structured Payouts'],
    ],

    // ---- Customer reviews (all 5-star) ---------------------------------
    'reviews' => [
        [
            'icon' => 'piggy',
            'name' => 'Yeavette',
            'product' => 'Structured Settlement',
            'text' => "The experience with their representatives was fantastic. They were not only engaging but also very helpful in addressing all my questions swiftly. It's evident they prioritize customer satisfaction. Overall, my interaction with JG Wentworth was extremely positive, which reflects why I would give such a high rating."
        ],
        [
            'icon' => 'bag',
            'name' => 'David',
            'product' => 'Debt Consolidation',
            'text' => "I had bills piling up from every direction and couldn't keep up with minimum payments. One monthly payment, and my specialist handled all the negotiating. I'm on track to be debt-free in less than 3 years."
        ],
        [
            'icon' => 'home',
            'name' => 'April',
            'product' => 'Home Equity Cashout',
            'text' => "There is a saying about first impressions… JGW totally exceeded mine! Couldn't ask for a better experience. I started the call almost in tears because of my situation and by the end of the call I was smiling. So excited about my financial future now and it's all thanks to JGW."
        ],
        [
            'icon' => 'home',
            'name' => 'Purvis',
            'product' => 'Home Equity Cashout',
            'text' => "Working with J.G. Wentworth was a fantastic and stress-free experience from start to finish. The specialist I worked with was professional, supportive, and clearly explained every detail. The entire process was fast and seamless."
        ],
        [
            'icon' => 'piggy',
            'name' => 'Tamiko',
            'product' => 'Structured Settlement',
            'text' => "We had worked with JG Wentworth before and they got things done. We worked with them again and they made everything easy for us. We communicate well together. We had called several people, but JG Wentworth is the one that we like the most. Getting our money was quick. We appreciate that and we'll be using them again if we need them."
        ],
        [
            'icon' => 'bag',
            'name' => 'Sarah',
            'product' => 'Debt Consolidation',
            'text' => "I was drowning in credit card debt and didn't know where to turn. My JG Wentworth specialist laid out a clear plan, and I saved over \$14,000. I wish I'd called sooner."
        ],
    ],

    // ---- Debt amount options (step 1) ----------------------------------
    'debt_options' => [
        'Less than $10,000',
        '$10,000 - $24,999',
        '$25,000 - $49,999',
        '$50,000 - $100,000',
        'More than $100,000',
    ],

    // ---- Behind on payments? (step 2) -----------------------------------
    // Keys are the values stored/submitted (must match LeadProsper's
    // `behind_payment` field enum: not_behind | over_30 | over_60); values
    // are the labels shown to the user.
    'behind_payment_options' => [
        'not_behind' => "I'm current on my payments",
        'over_30'    => '30+ days behind',
        'over_60'    => '60+ days behind',
    ],

    // ---- Employment status (step 3) -------------------------------------
    // Keys are the values stored/submitted (must match LeadProsper's
    // `employed` field enum: employed | unemployed | disability | retired);
    // values are the labels shown to the user.
    'employment_options' => [
        'employed'   => 'Employed',
        'unemployed' => 'Unemployed',
        'disability' => 'On Disability',
        'retired'    => 'Retired',
    ],

    // ---- Annual income before taxes (step 4) ---------------------------
    'income_options' => [
        'Under $30,000',
        'Between $30,000 and $100,000',
        'Over $100,000',
    ],

    // ---- US states (address step) --------------------------------------
    'states' => [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DC' => 'District of Columbia',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'PR' => 'Puerto Rico',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ],

    // ---- Consent / legal copy ------------------------------------------
    'consent' => [
        'contact' => 'By clicking “Submit” you consent to allowing JG Wentworth to contact you as described below.',
        'credit'  => 'By clicking "Continue" you consent to allow JG Wentworth to access your credit report as described below. This will not impact your credit score.',
        'tcpa'    => 'By submitting this form, I am providing The J.G. Wentworth Company, together with its subsidiaries and affiliates (collectively, “JGW”), with express written consent to contact me through calls and text messages at the number entered or listed above, including for marketing purposes Such calls and texts may be made using automated means, including autodialers, selection systems, robocalls, and prerecorded or artificial voice recordings, even if my number is listed on any company-specific, state, or federal Do-Not-Call list. Consent is not required as a condition of any purchase or service. Message and data rates may apply. Messaging frequency varies. Text “STOP” to cancel. I further consent to initial contact outside of permissible state and federal call times if made within approximately one hour of submission. I also consent and agree to JG Wentworth’s <a href="https://staging.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice" target="_blank" rel="noopener noreferrer">Privacy Policy</a> and <a href="https://staging.jgwentworth.com/terms-use" target="_blank" rel="noopener noreferrer">Terms of Use</a>. For details on how to opt out, which you may do at any time, please see JGW’s Revocation of Consent Instructions.',
        'fcra'    => 'Pursuant to the Fair Credit Reporting Act (FCRA), I authorize JGW to obtain and use consumer credit reports and other information from consumer reporting agencies for the purpose of evaluating my application, verifying information, providing me with personalized offers for financial products and services, and conducting ongoing account review, servicing, or collection activities as permitted under the Fair Credit Reporting Act (“FCRA”). I direct JGW to share my information with its their subsidiaries and affiliates, as necessary to process my application, evaluate credit available options, provide related services, or to provide me with personalized offers. I acknowledge that this authorization shall remain in effect until I revoke it in writing.',
    ],

    // ---- Footer legal links (left column, vertical) --------------------
    'legal_links' => [
        'Terms of Use'                        => 'https://www.jgwentworth.com/terms-use',
        'Privacy policy'                      => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Licenses'                            => 'https://www.jgwentworth.com/licenses',
        'Notice at Collection'                => 'https://www.jgwentworth.com/notice-at-collection',
        'Do Not Sell My Personal Information' => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Loan Disclosures'                    => 'https://www.jgwentworth.com/wp-content/uploads/2024/04/Loan-Disclosures-New.pdf',
        'Debt Resolution Loan Disclosures'    => 'https://www.jgwentworth.com/debt-resolution-loan-disclosures',
    ],

    // ---- Long disclosure block (right column, paragraphs) --------------
    // Static, self-authored legal copy; the two link-bearing paragraphs are
    // trusted HTML rendered verbatim in the footer.
    'disclosures' => [
        'Program length varies depending on individual situation. Programs are between approximately 24 and 60 months in length. Clients who are able to stay with the program and get all their debt settled have realized approximate average savings of 44% of their originally enrolled balance, before our 26% program fee. These savings are based on JGW client data from June 2025 through April 2026, reflect historical results, and are not guaranteed. JGW’s fees are calculated based on a percentage of the debt enrolled in the program. Read and understand the program agreement prior to enrollment.',

        'This is a Debt resolution program provided by JGW Debt Settlement, LLC (“JGW” or “Us”). JGW offers this program in the following states: AL, AK, AZ, AR, CA, CO, FL, ID, IN, KY, LA, MD, MA, MI, MS, MO, MT, NE, NM, NV, NY, NC, OK, PA, PR, SD, TN, TX, UT, VA, DC. If a consumer residing in any other state contacts Us we may connect them with a law firm that provides debt resolution services in their state. JGW is licensed/registered to provide debt resolution services in states where licensing/registration is required.',

        'Debt resolution program results will vary by individual situation. As such, debt resolution services are not appropriate for everyone. Not all debts are eligible for enrollment. Not all individuals who enroll complete our program for various reasons, including their ability to save sufficient funds. Savings resulting from successful negotiations may result in tax consequences, please consult with a tax professional regarding these consequences. The use of the debt settlement services and the failure to make payments to creditors: (1) Will likely adversely affect your creditworthiness (credit rating/credit score) and make it harder to obtain credit; (2) May result in your being subject to collections or being sued by creditors or debt collectors; and (3) May increase the amount of money you owe due to the accrual of fees and interest by creditors or debt collectors. Failure to pay your monthly bills in a timely manner will result in increased balances and will harm your credit rating. Not all creditors will agree to reduce principal balance, and they may pursue collection, including lawsuits.',

        'JG Wentworth does not pay or assume any debts or provide legal advice, financial advice, tax advice, or credit repair services. You should consult with independent professionals for such advice or services. Please consult with a bankruptcy attorney for information on bankruptcy.',

        'List of Licenses can be accessed here: <a href="https://www.jgwentworth.com/licenses" target="_blank" rel="noopener noreferrer">Licenses &ndash; JG Wentworth</a>',
    ],
];
