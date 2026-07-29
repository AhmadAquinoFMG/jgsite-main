<?php
/**
 * JG Wentworth — Debt Relief funnel landing page (PHP clone of /ds-aff-lp-2).
 *
 * Single-page, JS-driven 8-step form:
 *   1 debt amount · 2 employment · 3 income (auto-advance radios) ·
 *   4 name · 5 address · 6 date of birth · 7 email (Continue) ·
 *   8 phone + consent + Submit.
 *
 * UI ONLY: Google Places (step 5) is a lazy-loaded STUB — see
 * assets/js/funnel.js. Submit is not wired to a backend.
 */
$cfg = require __DIR__ . '/config.php';
$e   = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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
    <?php include __DIR__ . '/includes/compliance.php'; ?>
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

            <!-- Product context + attribution/compliance. funnel.js fills utm_*/gclid
                 from the query string on load; the TrustedForm and Jornaya scripts
                 (includes/compliance.php) populate the two consent fields. All are
                 stored by submit.php. -->
            <input type="hidden" name="product"   value="Debt Relief">
            <input type="hidden" name="form_name" value="DRMultiStep_PHP">
            <input type="hidden" name="xxTrustedFormCertUrl" id="xxTrustedFormCertUrl">
            <input type="hidden" name="universal_leadid"     id="universal_leadid">
            <input type="hidden" name="utm_source"   id="utm_source">
            <input type="hidden" name="utm_medium"   id="utm_medium">
            <input type="hidden" name="utm_campaign" id="utm_campaign">
            <input type="hidden" name="utm_term"     id="utm_term">
            <input type="hidden" name="utm_content"  id="utm_content">
            <input type="hidden" name="gclid"        id="gclid">
            <input type="hidden" name="fbclid"       id="fbclid">

            <!-- Everflow click attribution. affid/oid are copied from the query
                 string like the UTM fields above; ef_transaction_id is written by
                 the cookie watcher in assets/js/tracking/everflow.js once the
                 Everflow tracking cookie is stable. All three ride along to
                 LeadProsper (includes/leadprosper.php). -->
            <input type="hidden" name="affid"             id="affid">
            <input type="hidden" name="oid"                id="oid">
            <input type="hidden" name="ef_transaction_id"  id="efTransactionId">

            <!-- ===== Step 1: debt amount (radio, auto-advance) ===== -->
            <section class="step is-active" data-step="1" data-advance="auto">
                <h1 class="form-header">Get Debt Relief</h1>
                <p class="form-subtext">How much debt do you owe?</p>
                <div class="choice-group" role="radiogroup" aria-label="Debt amount">
                    <?php foreach ($cfg['debt_options'] as $opt): ?>
                        <label class="choice">
                            <input type="radio" name="debt_amount" value="<?= $e($opt) ?>" required
                                   data-umami-event="choice-debt-amount" data-umami-event-choice="<?= $e($opt) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 2: employment status (radio, auto-advance) ===== -->
            <section class="step" data-step="2" data-advance="auto">
                <h2 class="step-title">What is your employment status?</h2>
                <div class="choice-group" role="radiogroup" aria-label="Employment status">
                    <?php foreach ($cfg['employment_options'] as $opt): ?>
                        <label class="choice">
                            <input type="radio" name="employment" value="<?= $e($opt) ?>" required
                                   data-umami-event="choice-employment" data-umami-event-choice="<?= $e($opt) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 3: annual income (radio, auto-advance) ===== -->
            <section class="step" data-step="3" data-advance="auto">
                <h2 class="step-title">What is your annual income before taxes?</h2>
                <div class="choice-group" role="radiogroup" aria-label="Annual income">
                    <?php foreach ($cfg['income_options'] as $opt): ?>
                        <label class="choice">
                            <input type="radio" name="income" value="<?= $e($opt) ?>" required
                                   data-umami-event="choice-income" data-umami-event-choice="<?= $e($opt) ?>">
                            <span class="choice-radio" aria-hidden="true"></span>
                            <span class="choice-label"><?= $e($opt) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ===== Step 4: name ===== -->
            <section class="step" data-step="4">
                <h2 class="step-title">What is your first and last name?</h2>
                <div class="field">
                    <label for="first_name">First name <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name" autocomplete="given-name"
                           data-validate="name" required>
                </div>
                <div class="field">
                    <label for="last_name">Last name <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name" autocomplete="family-name"
                           data-validate="name" required>
                </div>
            </section>

            <!-- ===== Step 5: address =====
                 DEFAULT: one free-form "Home Address" field. The visible input has
                 NO name, so it never enters the payload; funnel.js populates the four
                 hidden inputs (street/city/state/zip) from a picked Google suggestion
                 or a submit-time geocode, so the backend always receives a segregated
                 address. ROLLBACK to the legacy multi-field UI with ?address_classic=1. -->
            <?php $addressClassic = (($_GET['address_classic'] ?? '') === '1'); ?>
            <section class="step" data-step="5" data-lazy="places"
                     data-address-mode="<?= $addressClassic ? 'classic' : 'single' ?>">
            <?php if ($addressClassic): ?>
                <h2 class="step-title">What is your street address?</h2>
                <div class="field places-wrap">
                    <label for="street">Street address <span class="req">*</span></label>
                    <input type="text" id="street" name="street" autocomplete="off"
                           data-validate="street" placeholder="Start typing your address&hellip;" required>
                    <ul class="places-suggestions" id="placesSuggestions" role="listbox" hidden></ul>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="city">City <span class="req">*</span></label>
                        <input type="text" id="city" name="city" autocomplete="address-level2"
                               data-validate="city" required>
                    </div>
                    <div class="field">
                        <label for="state">State <span class="req">*</span></label>
                        <select id="state" name="state" autocomplete="address-level1" required>
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
                           inputmode="numeric" data-validate="zip" maxlength="5" required>
                </div>
            <?php else: ?>
                <h2 class="step-title">What is your home address?</h2>
                <div class="field places-wrap">
                    <label for="address">Home address <span class="req">*</span></label>
                    <input type="text" id="address" autocomplete="off" autocapitalize="words"
                           data-validate="address" placeholder="Home Address" required>
                    <ul class="places-suggestions" id="placesSuggestions" role="listbox" hidden></ul>
                </div>
                <!-- Segregated payload — populated by funnel.js (pick or submit-time geocode).
                     The visible field above has NO name, so ONLY these reach the backend. -->
                <input type="hidden" id="street" name="street">
                <input type="hidden" id="city"   name="city">
                <input type="hidden" id="state"  name="state">
                <input type="hidden" id="zip"    name="zip">
            <?php endif; ?>
            </section>

            <!-- ===== Step 6: date of birth (auto-format MM/DD/YYYY) ===== -->
            <section class="step" data-step="6">
                <h2 class="step-title">What's your date of birth?</h2>
                <div class="field">
                    <label for="dob">Date of Birth <span class="req">*</span></label>
                    <div class="dob-wrap">
                        <input type="text" id="dob" name="dob" inputmode="numeric"
                               placeholder="MM/DD/YYYY" maxlength="10" data-validate="dob"
                               autocomplete="bday" required>
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

            <!-- ===== Step 7: email ===== -->
            <section class="step" data-step="7">
                <h2 class="step-title">What is your email address?</h2>
                <div class="field">
                    <label for="email">Email address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" autocomplete="email"
                           data-validate="email" required>
                </div>
            </section>

            <!-- ===== Step 8: phone + consent + submit =====
                 The TCPA consent text lives here, below the fold under the
                 compliance note, instead of on its own page. This is the final
                 step, so it submits. -->
            <section class="step" data-step="8" data-nav="submit">
                <h2 class="step-title">What is your phone number?</h2>
                <div class="field">
                    <label for="phone">Phone <span class="req">*</span></label>
                    <input type="tel" id="phone" name="phone" autocomplete="tel"
                           inputmode="tel" placeholder="(555) 555-5555" maxlength="14"
                           data-validate="phone" required>
                </div>

                <p class="consent-note"><?= $e($cfg['consent']['contact']) ?></p>
            </section>

            <!-- Navigation. The back arrow shares this row with whichever primary
                 button the step uses: Continue (steps 1–7) or Submit (step 8). -->
            <div class="form-nav">
                <button type="button" class="btn-back" id="btnBack" aria-label="Back" hidden
                        data-umami-event="funnel-back">
                    <img src="assets/img/chevron-left-grey.svg" alt="" width="26" height="26">
                </button>
                <button type="button" class="btn btn-next" id="btnNext">Continue</button>
                <button type="submit" class="btn btn-submit" id="btnSubmit" hidden>Submit</button>
            </div>

            <!-- Step-specific disclosure shown below the nav row. The DOB step's
                 FCRA authorization appears here; visibility is driven by the
                 form's data-current attribute (set in funnel.js). -->
            <p class="step-disclosure" data-for="6"><?= $e($cfg['consent']['fcra']) ?></p>

            <!-- Phone step's TCPA consent. Sits below the Submit button (and above
                 the reviews section) rather than above it; the short contact note
                 stays above Submit inside the step. Trusted static HTML (legal links). -->
            <p class="step-disclosure tcpa" data-for="8"><?= $cfg['consent']['tcpa'] ?></p>
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
            offerId: <?= json_encode($cfg['everflow']['offer_id'] ?? '', JSON_UNESCAPED_SLASHES) ?>,
            affiliateId: <?= json_encode($cfg['everflow']['affiliate_id'] ?? '', JSON_UNESCAPED_SLASHES) ?>,
            domain: <?= json_encode($cfg['everflow']['domain'] ?? 'www.f0cg2trk.com', JSON_UNESCAPED_SLASHES) ?>
        }
    };
</script>
<script src="assets/js/funnel.js?v=<?= $e($cfg['asset_version']) ?>"></script>
<?php if (!empty($cfg['everflow']['offer_id'])): ?>
<script src="assets/js/tracking/everflow.js?v=<?= $e($cfg['asset_version']) ?>"></script>
<?php endif; ?>
</body>
</html>
