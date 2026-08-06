<?php
/**
 * JG Wentworth — Debt Relief funnel landing page (PHP clone of /ds-aff-lp-2).
 *
 * Single-page, JS-driven 9-step form:
 *   1 debt amount · 2 behind on payments · 3 employment · 4 income (auto-advance radios) ·
 *   5 name · 6 address · 7 date of birth · 8 email (Continue) ·
 *   9 phone + consent + Submit.
 *
 * UI ONLY: Google Places (step 5) is a lazy-loaded STUB — see
 * assets/js/funnel.js. Submit is not wired to a backend.
 */
// A fresh funnel run invalidates any estimated savings from a prior
// submission held in the session for thank-you.php (see submit.php).
session_start();
unset($_SESSION['prequal_savings'], $_SESSION['ef_conversion']);

$cfg = require __DIR__ . '/config.php';
$e   = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/* ---- Funnel landing event props -------------------------------------------
   One "event_view_landing" event per pageview, carrying the traffic source. Step 1
   (event_view_debt_amount) is the entry anchor of the drop-off report, so without
   this there is no measurement of the landing → step 1 gap — the largest and
   previously invisible drop on the page. Built server-side from a WHITELIST of
   query params (never the raw query string) and length-capped, then emitted as a
   JSON object with every HTML-significant character hex-escaped, so a crafted
   ?utm_source=… can't break out of the inline <script>. */
$landingProps = [];
foreach ([
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
    'affid', 'oid', 'source_id', 'subid', 'gclid', 'fbclid', 'ttclid',
] as $param) {
    $value = trim((string) ($_GET[$param] ?? ''));
    if ($value !== '') {
        $landingProps[$param] = substr($value, 0, 100);
    }
}
$landingJson = json_encode(
    $landingProps,
    JSON_FORCE_OBJECT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="robots" content="index, follow">
    <title><?= $e($cfg['brand']['name']) ?> — Debt Relief Program</title>
    <link rel="icon" type="image/png" href="assets/img/jg-icon.png?v=<?= $e($cfg['asset_version']) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $e($cfg['asset_version']) ?>">

    <?php include __DIR__ . '/includes/analytics.php'; ?>
    <?php include __DIR__ . '/includes/track.php'; ?>
    <?php include __DIR__ . '/includes/compliance.php'; ?>

    <!-- Funnel entry, queued by includes/track.php until the deferred Umami tag
         is live. Reported as "Landed" in bin/funnel-slack-report.php. -->
    <script>jgTrack('event_view_landing', <?= $landingJson ?>);</script>

    <?php if (!empty($cfg['turnstile']['enabled'])): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <?php /*
      No Everflow snippet in <head> on purpose. The click is fired by
      assets/js/tracking/everflow.js at the bottom of this page, which gates on
      ?affid= and resolves the offer id from it. A hardcoded snippet here used to
      call EF.click() with offer_id: EF.urlParameter('oid'), which fired with an
      undefined offer on any visit that didn't carry ?oid= — no offer to attribute
      to, so no tracking cookie and nothing to convert against later.
    */ ?>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="funnel">
    <div class="funnel-inner">

        <!-- Progress -->
        <div class="progress-track" aria-hidden="true">
            <div class="progress-fill" id="progressFill" style="width:11.11%"></div>
        </div>

        <form id="funnelForm" class="funnel-form" novalidate method="post" action="submit.php"
              data-product="Debt Relief" data-name="DRMultiStep_PHP">

            <!-- Product context + attribution/compliance. All of these are stored by
                 submit.php and (where LeadProsper has a matching campaign field)
                 forwarded on in includes/leadprosper.php. -->
            <input type="hidden" name="product"   value="Debt Relief">
            <input type="hidden" name="form_name" value="DRMultiStep_PHP">
            <input type="hidden" name="xxTrustedFormCertUrl" id="xxTrustedFormCertUrl">
            <input type="hidden" name="universal_leadid"     id="universal_leadid">

            <!-- Everflow click attribution. affid/oid/ef_transaction_id ride along
                 to LeadProsper (includes/leadprosper.php). ef_transaction_id is
                 written by the cookie watcher in assets/js/tracking/everflow.js
                 once the Everflow tracking cookie is stable; the rest of these are
                 copied straight from the query string on load (funnel.js). -->
            <input type="hidden" name="affid"              id="affid">
            <input type="hidden" name="oid"                 id="oid">
            <input type="hidden" name="ef_transaction_id"   id="efTransactionId">
            <input type="hidden" name="source_id"           id="source_id">
            <input type="hidden" name="lp_subid1"           id="lp_subid1">
            <input type="hidden" name="lp_subid2"           id="lp_subid2">
            <input type="hidden" name="lp_subid3"           id="lp_subid3">
            <input type="hidden" name="lp_subid4"           id="lp_subid4">
            <input type="hidden" name="lp_subid5"           id="lp_subid5">
            <input type="hidden" name="adv1"                id="adv1">
            <input type="hidden" name="adv2"                id="adv2">
            <input type="hidden" name="adv3"                id="adv3">
            <input type="hidden" name="adv4"                id="adv4">
            <input type="hidden" name="adv5"                id="adv5">
            <input type="hidden" name="subid"               id="subid">

            <!-- UTM + ad-platform click ids, all copied from the query string. -->
            <input type="hidden" name="utm_source"    id="utm_source">
            <input type="hidden" name="utm_medium"    id="utm_medium">
            <input type="hidden" name="utm_campaign"  id="utm_campaign">
            <input type="hidden" name="utm_term"      id="utm_term">
            <input type="hidden" name="utm_content"   id="utm_content">
            <input type="hidden" name="utm_creative"  id="utm_creative">
            <input type="hidden" name="utm_placement" id="utm_placement">
            <input type="hidden" name="utm_adgroup"   id="utm_adgroup">
            <input type="hidden" name="utm_matchtype" id="utm_matchtype">
            <input type="hidden" name="gclid"         id="gclid">
            <input type="hidden" name="gbraid"        id="gbraid">
            <input type="hidden" name="fbclid"        id="fbclid">
            <input type="hidden" name="fb_adid"       id="fb_adid">
            <input type="hidden" name="ms_placement"  id="ms_placement">
            <input type="hidden" name="ms_publisher"  id="ms_publisher">
            <input type="hidden" name="ttclid"        id="ttclid">

            <!-- fbp is NOT a URL param — funnel.js reads it from the _fbp cookie
                 Meta's pixel sets, if present. -->
            <input type="hidden" name="fbp" id="fbp">

            <input type="hidden" name="landing_page_url" id="landingPageUrl">

            <!-- Honeypot: invisible to real visitors (see .hp-field in style.css). Real
                 bot-form-fillers often populate any input they can find, including ones
                 with no visible label — this one silently marks the submission as a bot
                 in submit.php instead of erroring, so the trap stays invisible. -->
            <div class="hp-field" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <!-- Server-rendered timestamp (NOT a JS Date.now() — that's trivially spoofable
                 by a bot that just sets the field itself). submit.php compares this against
                 request time to reject implausibly fast completions. -->
            <input type="hidden" name="form_rendered_at" value="<?= time() ?>">

            <!-- ===== Step 1: debt amount (radio, auto-advance) ===== -->
            <section class="step is-active" data-step="1" data-advance="auto">
                <h1 class="form-header">Get Debt Relief</h1>
                <p class="form-subtext">How much debt do you owe?</p>
                <div class="choice-group" role="radiogroup" aria-label="Debt amount">
                    <?php foreach ($cfg['debt_options'] as $opt): ?>
                        <label class="choice">
                            <input type="radio" name="debt_amount" value="<?= $e($opt) ?>" required
                                   data-umami-event="event_choice_debt_amount" data-umami-event-choice="<?= $e($opt) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 2: behind on payments (radio, auto-advance) ===== -->
            <section class="step" data-step="2" data-advance="auto">
                <h2 class="step-title">Are you behind on any of your payments?</h2>
                <div class="choice-group" role="radiogroup" aria-label="Behind on payments">
                    <?php foreach ($cfg['behind_payment_options'] as $val => $label): ?>
                        <label class="choice">
                            <input type="radio" name="behind_payment" value="<?= $e($val) ?>" required
                                   data-umami-event="event_choice_behind_payment" data-umami-event-choice="<?= $e($val) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 3: employment status (radio, auto-advance) ===== -->
            <section class="step" data-step="3" data-advance="auto">
                <h2 class="step-title">What is your employment status?</h2>
                <div class="choice-group" role="radiogroup" aria-label="Employment status">
                    <?php foreach ($cfg['employment_options'] as $val => $label): ?>
                        <label class="choice">
                            <input type="radio" name="employment" value="<?= $e($val) ?>" required
                                   data-umami-event="event_choice_employment" data-umami-event-choice="<?= $e($val) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 4: annual income (radio, auto-advance) ===== -->
            <section class="step" data-step="4" data-advance="auto">
                <h2 class="step-title">What is your annual income before taxes?</h2>
                <div class="choice-group" role="radiogroup" aria-label="Annual income">
                    <?php foreach ($cfg['income_options'] as $opt): ?>
                        <label class="choice">
                            <input type="radio" name="income" value="<?= $e($opt) ?>" required
                                   data-umami-event="event_choice_income" data-umami-event-choice="<?= $e($opt) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 5: name =====
                 data-jg-event marks a field for first-touch tracking: funnel.js
                 fires the named event once, on first FOCUS of that field. (Umami's
                 own data-umami-event is click-only, so it would miss every visitor
                 who tabs into the field — hence the separate attribute.) Comparing
                 these against the step's view event separates "saw the step" from
                 "actually started filling it in". -->
            <section class="step" data-step="5">
                <h2 class="step-title">What is your first and last name?</h2>
                <div class="field">
                    <label for="first_name">First name <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name" autocomplete="given-name"
                           data-validate="name" required
                           data-jg-event="event_engage_first_name">
                </div>
                <div class="field">
                    <label for="last_name">Last name <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name" autocomplete="family-name"
                           data-validate="name" required
                           data-jg-event="event_engage_last_name">
                </div>
            </section>

            <!-- ===== Step 6: address =====
                 DEFAULT: one free-form "Home Address" field. The visible input has
                 NO name, so it never enters the payload; funnel.js populates the four
                 hidden inputs (street/city/state/zip) from a picked Google suggestion
                 or a submit-time geocode, so the backend always receives a segregated
                 address. ROLLBACK to the legacy multi-field UI with ?address_classic=1. -->
            <?php $addressClassic = (($_GET['address_classic'] ?? '') === '1'); ?>
            <section class="step" data-step="6" data-lazy="places"
                     data-address-mode="<?= $addressClassic ? 'classic' : 'single' ?>">
            <?php if ($addressClassic): ?>
                <h2 class="step-title">What is your street address?</h2>
                <div class="field places-wrap">
                    <label for="street">Street address <span class="req">*</span></label>
                    <input type="text" id="street" name="street" autocomplete="off"
                           data-validate="street" placeholder="Start typing your address&hellip;" required
                           data-jg-event="event_engage_street">
                    <ul class="places-suggestions" id="placesSuggestions" role="listbox" hidden></ul>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="city">City <span class="req">*</span></label>
                        <input type="text" id="city" name="city" autocomplete="address-level2"
                               data-validate="city" required data-jg-event="event_engage_city">
                    </div>
                    <div class="field">
                        <label for="state">State <span class="req">*</span></label>
                        <select id="state" name="state" autocomplete="address-level1" required
                                data-jg-event="event_engage_state">
                            <option value="">Select State</option>
                            <?php foreach ($cfg['states'] as $abbr => $name): ?>
                                <option value="<?= $e($abbr) ?>"><?= $e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field field--zip">
                    <label for="zip">Zip code <span class="req">*</span></label>
                    <input type="text" id="zip" name="zip" autocomplete="postal-code"
                           inputmode="numeric" data-validate="zip" maxlength="5" required
                           data-jg-event="event_engage_zip">
                </div>
                <!-- Country: not collected from the visitor, but funnel.js requires it
                     to be present before the step advances (see checkAddressParts). -->
                <input type="hidden" id="country" name="country" value="US">
            <?php else: ?>
                <h2 class="step-title">What is your home address?</h2>
                <div class="field places-wrap">
                    <label for="address">Home address <span class="req">*</span></label>
                    <input type="text" id="address" autocomplete="off" autocapitalize="words"
                           data-validate="address" placeholder="Home Address" required
                           data-jg-event="event_engage_address">
                    <ul class="places-suggestions" id="placesSuggestions" role="listbox" hidden></ul>
                    <p class="field-help">Start typing, then pick your address from the list so we
                        capture the street, city, state and ZIP code.</p>
                </div>
                <!-- Segregated payload — populated by funnel.js (pick or Continue-time
                     geocode); the step will not advance until all of them are filled.
                     The visible field above has NO name, so ONLY these reach the backend.
                     Country is seeded here so a resolution that omits the component
                     cannot leave the address incomplete. -->
                <input type="hidden" id="street"  name="street">
                <input type="hidden" id="city"    name="city">
                <input type="hidden" id="state"   name="state">
                <input type="hidden" id="zip"     name="zip">
                <input type="hidden" id="country" name="country" value="US">
            <?php endif; ?>
            </section>

            <!-- ===== Step 7: date of birth (auto-format MM/DD/YYYY) ===== -->
            <section class="step" data-step="7">
                <h2 class="step-title">What's your date of birth?</h2>
                <div class="field">
                    <label for="dob">Date of Birth <span class="req">*</span></label>
                    <div class="dob-wrap">
                        <input type="text" id="dob" name="dob" inputmode="numeric"
                               placeholder="MM/DD/YYYY" maxlength="10" data-validate="dob"
                               autocomplete="bday" required data-jg-event="event_engage_dob">
                        <button type="button" class="dob-toggle" id="dobToggle"
                                aria-label="Open calendar" aria-expanded="false" aria-controls="dobCal">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none"
                                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18"/>
                            </svg>
                        </button>
                        <div class="dob-cal" id="dobCal" role="dialog" aria-label="Choose date of birth" hidden></div>
                    </div>
                </div>
                <p class="consent-note consent-note--left"><?= $e($cfg['consent']['credit']) ?></p>
            </section>

            <!-- ===== Step 8: email ===== -->
            <section class="step" data-step="8">
                <h2 class="step-title">What is your email address?</h2>
                <div class="field">
                    <label for="email">Email address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" autocomplete="email"
                           data-validate="email" required data-jg-event="event_engage_email">
                </div>
            </section>

            <!-- ===== Step 9: phone + consent + submit =====
                 The TCPA consent text lives here, below the fold under the
                 compliance note, instead of on its own page. This is the final
                 step, so it submits. -->
            <section class="step" data-step="9" data-nav="submit">
                <h2 class="step-title">What is your phone number?</h2>
                <div class="field">
                    <label for="phone">Phone <span class="req">*</span></label>
                    <input type="tel" id="phone" name="phone" autocomplete="tel"
                           inputmode="tel" placeholder="(555) 555-5555" maxlength="14"
                           data-validate="phone" required data-jg-event="event_engage_phone">
                </div>

                <p class="consent-note"><?= $e($cfg['consent']['contact']) ?></p>

                <?php if (!empty($cfg['turnstile']['enabled'])): ?>
                    <div class="cf-turnstile" data-sitekey="<?= $e($cfg['turnstile']['site_key']) ?>"></div>
                <?php endif; ?>
            </section>

            <!-- Navigation. The back arrow shares this row with whichever primary
                 button the step uses: Continue (steps 1–7) or Submit (step 8). -->
            <div class="form-nav">
                <button type="button" class="btn-back" id="btnBack" aria-label="Back" hidden
                        data-umami-event="event_back_click">
                    <img src="assets/img/chevron-left-grey.svg" alt="" width="26" height="26">
                </button>
                <!-- Both buttons are shared across every step, which is why these are
                     NOT named after a field: they count CLICKS. event_continue_click
                     includes attempts that bounce off validation (the per-step signal
                     is event_<field>_complete) and event_submit_click includes retries
                     after a 422. Umami's click-only declarative tracking is exactly
                     right for buttons — no focus subtleties to worry about. -->
                <button type="button" class="btn btn-next" id="btnNext"
                        data-umami-event="event_continue_click">Continue</button>
                <button type="submit" class="btn btn-submit" id="btnSubmit" hidden
                        data-umami-event="event_submit_click">Submit</button>
            </div>

            <!-- Step-specific disclosure shown below the nav row. The DOB step's
                 FCRA authorization appears here; visibility is driven by the
                 form's data-current attribute (set in funnel.js). -->
            <p class="step-disclosure" data-for="7"><?= $e($cfg['consent']['fcra']) ?></p>

            <!-- Phone step's TCPA consent. Sits below the Submit button (and above
                 the reviews section) rather than above it; the short contact note
                 stays above Submit inside the step. Trusted static HTML (legal links). -->
            <p class="step-disclosure tcpa" data-for="9"><?= $cfg['consent']['tcpa'] ?></p>
        </form>

        <!-- On submit, funnel.js redirects to thank-you.php (pre-qualified page). -->

        <!-- Trust badges -->
        <div class="trust-badges">
            <?php foreach ($cfg['badges'] as $b): ?>
                <img src="<?= $e($b['src']) ?>" alt="<?= $e($b['alt']) ?>" loading="lazy">
            <?php endforeach; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/social-proof.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
    window.FUNNEL = {
        googlePlacesKey: <?= json_encode($cfg['google_places_key'] ?? '', JSON_UNESCAPED_SLASHES) ?>,
        appEnv: <?= json_encode($cfg['app_env'] ?? 'production', JSON_UNESCAPED_SLASHES) ?>,
        everflow: {
            offerFirstParty: <?= json_encode((string) ($cfg['everflow']['offer_first_party'] ?? ''), JSON_UNESCAPED_SLASHES) ?>,
            offerThirdParty: <?= json_encode((string) ($cfg['everflow']['offer_third_party'] ?? ''), JSON_UNESCAPED_SLASHES) ?>,
            firstPartyAffids: <?= json_encode(array_map('strval', $cfg['everflow']['first_party_affids'] ?? []), JSON_UNESCAPED_SLASHES) ?>,
            domain: <?= json_encode($cfg['everflow']['domain'] ?? 'www.f0cg2trk.com', JSON_UNESCAPED_SLASHES) ?>
        }
    };
</script>
<script src="assets/js/funnel.js?v=<?= $e($cfg['asset_version']) ?>"></script>
<?php /* Loaded unconditionally — the script itself is the affid gate, and it
         bails before touching the network when there's no affid to attribute to.
         Gating here on a configured offer id would only re-introduce the old
         "silently disabled in .env" failure. */ ?>
<script src="assets/js/tracking/everflow.js?v=<?= $e($cfg['asset_version']) ?>"></script>
</body>
</html>
