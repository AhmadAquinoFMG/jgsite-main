<?php

/**
 * JG Wentworth — post-submit "You're Pre-Qualified" page.
 *
 * Reached after the funnel form is submitted (funnel.js redirects here).
 * Static confirmation screen: assigned-specialist messaging + a click-to-call
 * CTA and a "your file is held for N:00" countdown timer (urgency device).
 * Copy/number/hold-time come from config.php → ['prequal'].
 */
session_start();

$cfg = require __DIR__ . '/config.php';
$e   = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$pq        = $cfg['prequal'];
$ctaPhone  = $pq['cta_phone'];
$ctaTel    = preg_replace('/[^\d+]/', '', $ctaPhone);          // tel: href (digits only)
$holdSecs  = max(1, (int) $pq['hold_minutes']) * 60;           // countdown seconds

// Estimated savings (40% of the debt figure submit.php used), stashed in the
// session by submit.php — never exposed in the URL, so it can't be edited or
// replayed. Persists across reloads/bookmarks of this page; index.php clears
// it when a visitor starts the funnel over. Absent/zero hides the callout.
$estimatedSavings = max(0, (int) ($_SESSION['prequal_savings'] ?? 0));

/* Everflow conversion. submit.php stashes the affid here only after it accepted
   the lead, so this can't fire for a visitor who merely opened the page. The
   offer comes from that affid (includes/everflow.php); no affid stashed means
   no offer and nothing is sent to Everflow at all.

   Consumed on read — unset before rendering, so reloading or bookmarking this
   page can't fire a second conversion for the same lead. Unlike prequal_savings
   (which persists deliberately so the savings callout survives a reload), a
   duplicated conversion would be a billing event. */
require_once __DIR__ . '/includes/everflow.php';
require_once __DIR__ . '/includes/redirect.php';   // redirect_param_names(), for the CallGrid tags below

$efConversion = $_SESSION['ef_conversion'] ?? null;
unset($_SESSION['ef_conversion']);

$efOfferId = $efConversion
    ? everflow_offer_for_affid($efConversion['affid'] ?? '', $cfg['everflow'])
    : null;
$efTransactionId = (string) ($efConversion['transaction_id'] ?? '');

// CallGrid call tracking — only page that carries a click-to-call CTA, so the
// only page where a swappable tracking number matters. Disabled (and not
// emitted at all) when either id is missing, so a half-configured environment
// can't load the SDK with a blank organization.
$cg = $cfg['callgrid'];
$cgOn = $cg['enabled'] && $cg['organization_id'] !== '' && $cg['campaign_source_id'] !== '';

/* CallGrid custom tags — the lead's details, forwarded so its webhook template
   ([[tag:lead_id]], [[tag:email]], …) resolves against a real call.

   These have to be handed over explicitly. callgrid.js does NOT read the query
   string for tags: it pulls `utm_source` out of location.search and nothing
   else, and its only custom-tag inputs are this data-tags attribute and an
   addTags() method on an instance auto-init keeps private. So the params
   submit.php put in the redirect are read back here and passed through.

   The names come from the redirect config rather than a second hardcoded list,
   so adding a param there is enough to start sending it. Values are capped —
   a tag is a short attribute, and the whole set rides in one HTML attribute. */
$cgTags = [];
if ($cgOn) {
    foreach (redirect_param_names($cfg['redirect'] ?? []) as $name) {
        $value = trim((string) ($_GET[$name] ?? ''));
        if ($value !== '') {
            $cgTags[$name] = substr($value, 0, 255);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en-US">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($cfg['brand']['name']) ?> — You're Pre-Qualified</title>
    <link rel="icon" type="image/png" href="assets/img/jg-icon.png?v=<?= $e($cfg['asset_version']) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($cgOn): ?><link rel="preconnect" href="https://cdn.callgrid.com" crossorigin><?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $e($cfg['asset_version']) ?>">

    <?php include __DIR__ . '/includes/analytics.php'; ?>
    <?php include __DIR__ . '/includes/track.php'; ?>

    <!-- Funnel completion. This is the report's authoritative "completed" signal:
         it only fires after submit.php accepted the lead and funnel.js redirected
         here, and it fires from a fresh pageview rather than from a beacon racing
         that redirect — so it can't be undercounted the way event_submit_attempt can.
         See bin/funnel-slack-report.php. -->
    <script>
        jgTrack('event_view_thank_you', {
            has_savings: <?= $estimatedSavings > 0 ? 'true' : 'false' ?>,
            estimated_savings: <?= (int) $estimatedSavings ?>
        });
    </script>

    <?php /* Attribution — restores the session's tracking params (utm_* included)
             onto this page's URL. submit.php builds this redirect from the stored
             lead row, which carries no utm params, so without this the page loses
             the campaign the visitor arrived on. Kept in <head> and ahead of the
             CallGrid block below on purpose: callgrid.js reads utm_source off
             location.search when it initialises, and it can only read what's
             already there. */ ?>
    <script>
        window.FUNNEL = window.FUNNEL || {};
        window.FUNNEL.attribution = <?= json_encode($cfg['attribution'] ?? [], JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>;
    </script>
    <script src="assets/js/tracking/attribution.js?v=<?= $e($cfg['asset_version']) ?>"></script>

    <?php /* Everflow conversion — fires against the offer this lead's affid maps
             to (914 first party / 915 third party). Rendered only when an affid
             was stashed at submit; unattributed leads emit nothing at all.

             transaction_id is passed explicitly when the landing-page click
             managed to resolve one, since that's a firmer match than letting the
             SDK re-read the tracking cookie. When it's blank we omit the key and
             fall back to the cookie, which is the SDK's normal path. */ ?>
    <?php if ($efOfferId): ?>
        <?php
        $efPayload = ['offer_id' => $efOfferId];
        if ($efTransactionId !== '') {
            $efPayload['transaction_id'] = $efTransactionId;
        }
        ?>
        <script type="text/javascript" src="https://<?= $e($cfg['everflow']['domain']) ?>/scripts/main.js"></script>
        <script type="text/javascript">
            EF.conversion(<?= json_encode($efPayload, JSON_UNESCAPED_SLASHES) ?>);
        </script>
    <?php endif; ?>

    <?php if ($cgOn): ?>
        <?php /* CallGrid — injected rather than hard-coded as a <script src> so the
                 guard can run: the SDK misbehaves if it's loaded twice, and a
                 bfcache restore or a second include would otherwise do exactly
                 that. loadCallGrid() is idempotent and safe to call again. */ ?>
        <script>
            function loadCallGrid() {
                if (document.querySelector('script[src*="callgrid.com"]')) return;

                const script = document.createElement('script');
                script.src = <?= json_encode($cg['src'], JSON_UNESCAPED_SLASHES) ?>;
                script.dataset.organizationId = <?= json_encode($cg['organization_id']) ?>;
                script.dataset.campaignSourceId = <?= json_encode($cg['campaign_source_id']) ?>;
                <?php if ($cgTags !== []): ?>
                // Read as JSON by the SDK's config parser; it warns and drops
                // the lot on a parse error, so it must survive a round trip
                // through the attribute intact.
                script.dataset.tags = <?= json_encode(json_encode($cgTags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>;
                <?php endif; ?>
                script.async = true;
                document.head.appendChild(script);
            }

            /* Number pool — swap the CTA to the DID CallGrid assigns this
               visitor, so an inbound call carries the session (and therefore the
               data-tags above) instead of landing on the one shared number.

               Registered BEFORE loadCallGrid() and on `document`, because
               callgrid:numberAssigned is a one-shot: the SDK can dispatch it as
               soon as its request comes back, and a listener attached later in
               the body would simply never hear it. The CTA itself doesn't exist
               yet at this point in <head>, so the number is held and applied
               once the DOM is up.

               Only the tel: target is swapped — the number ON the button stays
               the branded static one from config ['prequal']['cta_phone']. The
               pooled DID is a routing detail, so there's no reason to show it
               and no flicker from rewriting the label mid-read. Note this does
               mean the dialer opens on a number the visitor didn't just read.

               The button is NOT hidden until assignment. It ships rendered and
               dialable with the static number, so it survives a blocked/slow
               SDK and JS being off, and the money CTA never shifts or sits dead
               under the visitor's thumb. The cost is that a tap inside the
               assignment window reaches the same line on the shared number —
               that call just isn't attributable. */
            (function () {
                var assigned = '';

                function applyNumber() {
                    if (!assigned) return;
                    var link = document.getElementById('call-now');
                    if (!link) return;

                    link.href = 'tel:' + assigned.replace(/[^\d+]/g, '');
                }

                document.addEventListener('callgrid:numberAssigned', function (event) {
                    var number = event && event.detail && event.detail.phoneNumber;
                    if (!number) return;

                    assigned = String(number).trim();
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', applyNumber, { once: true });
                    } else {
                        applyNumber();
                    }
                });

                // A bfcache restore re-runs no scripts and re-fires no events,
                // but it does rebuild the DOM from the snapshot — reassert the
                // pooled number so a back-navigation doesn't silently revert the
                // CTA to the static one.
                window.addEventListener('pageshow', function (event) {
                    if (event.persisted) applyNumber();
                });
            })();

            loadCallGrid();
        </script>
    <?php endif; ?>
</head>

<body>

    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="funnel">
        <div class="funnel-inner prequal">

            <!-- Hero: confirmation -->
            <div class="prequal-check" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 6L9 17l-5-5" />
                </svg>
            </div>

            <h1 class="prequal-title">You&rsquo;re Pre-Qualified for <br>a Debt Relief Program</h1>
            <p class="prequal-lede">You could reduce your debt and lower your monthly payments.</p>

            <?php if ($estimatedSavings > 0): ?>
                <div class="prequal-savings">
                    <span class="prequal-savings__label">You can save up to:</span>
                    <span class="prequal-savings__amount">$<?= $e(number_format($estimatedSavings)) ?></span>
                </div>
            <?php endif; ?>

            <!-- Assigned specialist -->
            <!-- <div class="prequal-assigned">
                <span class="prequal-assigned__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none"
                        stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M19 8l2 2 3-3" />
                    </svg>
                </span>
                <div class="prequal-assigned__body">
                    <h2 class="prequal-assigned__title">A Certified Debt Specialist Has Been Assigned to You</h2>
                    <p>They&rsquo;re ready to walk you through your best options for becoming debt free.</p>
                </div>
            </div> -->

            <!-- Call CTA card -->
            <section class="prequal-card" aria-label="Speak with your specialist">
                <h2 class="prequal-card__title">Speak With Your Specialist Now</h2>
                <p class="prequal-card__sub">Your estimate is reserved, but availability is limited.</p>

                <!-- The money click. Umami's declarative tracking holds the tel:
                     navigation until the event is away, so this survives the dialer
                     opening. Reported as "Called" (share of completions). -->
                <a class="prequal-call" id="call-now" href="tel:<?= $e($ctaTel) ?>"
                    data-umami-event="event_call_click">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
                    </svg>
                    <?php /* Two spans so the narrow-screen rule can stack the label
                             above the number, instead of letting the button wrap
                             mid-number ("(888) 471-" / "0463"). */ ?>
                    <span class="prequal-call__label">
                        <span class="prequal-call__cta">CALL NOW:</span>
                        <span class="prequal-call__number"><?= $e($ctaPhone) ?></span>
                    </span>
                </a>

                <!-- Hold timer -->
                <div class="prequal-hold" data-hold-secs="<?= $e($holdSecs) ?>">
                    <span class="prequal-hold__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="13" r="8" />
                            <path d="M12 9v4l2.5 2.5" />
                            <path d="M9 2h6" />
                        </svg>
                    </span>
                    <p class="prequal-hold__label">Your specialist is &nbsp;<br>holding your file for:</p>
                    <div class="prequal-hold__clock" role="timer" aria-live="off">
                        <span class="prequal-hold__unit"><strong id="holdMin"><?= $e(sprintf('%02d', intdiv($holdSecs, 60))) ?></strong><small>MIN</small></span>
                        <span class="prequal-hold__colon">:</span>
                        <span class="prequal-hold__unit"><strong id="holdSec"><?= $e(sprintf('%02d', $holdSecs % 60)) ?></strong><small>SEC</small></span>
                    </div>
                    <p class="prequal-hold__note">After this, you may need to re-qualify.</p>
                </div>

                <ul class="prequal-assurances">
                    <li>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                            <path d="M9 12l2 2 4-4" />
                        </svg>
                        Takes less than 10 minutes
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M9 12l2 2 4-4" />
                        </svg>
                        No obligation
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                        Free consultation
                    </li>
                </ul>
            </section>

            <!-- Legal disclosures, shown in the page body (also present in the footer). -->
            <!-- <section class="prequal-disclosures" aria-label="Program disclosures">
                <?php foreach ($cfg['disclosures'] as $para): ?>
                    <p><?= $para /* trusted static HTML (may contain links) */ ?></p>
                <?php endforeach; ?>
            </section> -->

        </div>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
        /* Hold-file countdown — counts the [data-hold-secs] value down to 00:00. */
        (function() {
            var box = document.querySelector('.prequal-hold');
            if (!box) return;
            var minEl = document.getElementById('holdMin');
            var secEl = document.getElementById('holdSec');
            var left = parseInt(box.getAttribute('data-hold-secs'), 10) || 0;

            function pad(n) {
                return (n < 10 ? '0' : '') + n;
            }

            function paint() {
                minEl.textContent = pad(Math.floor(left / 60));
                secEl.textContent = pad(left % 60);
            }
            paint();
            var t = setInterval(function() {
                if (left <= 0) {
                    clearInterval(t);
                    return;
                }
                left--;
                paint();
            }, 1000);
        })();
    </script>
</body>

</html>