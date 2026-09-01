/* ============================================================
   Everflow Affiliate Tracking — LAZY, affid-gated
   ------------------------------------------------------------
   Fires the Everflow click for the landing page, then writes the resolved
   transaction id into the funnel's hidden ef_transaction_id field so it rides
   along with the lead to LeadProsper.

   Which offer? Decided by ?affid= alone (window.FUNNEL.everflow, from
   config.php ['everflow']):

     affid in firstPartyAffids -> offerFirstParty (914)
     affid present, not listed -> offerThirdParty (915)
     affid absent/empty        -> hard stop. We return before loading anything,
                                  so the SDK never even hits the network and no
                                  click is fired. Unattributed traffic must not
                                  reach Everflow at all.

   The EF.click() field list below (source_id, sub1-10, uid, transaction_id) is
   the shape Everflow's dashboard snippet uses for this offer — don't "clean it
   up" against a different integration. What differs from that raw snippet is
   deliberate: offer_id comes from the affid mapping instead of ?oid= (a link
   with a missing/mismatched oid used to fire a click with an undefined offer),
   and the whole thing is lazy-loaded on first interaction / 4s / form submit so
   a zero-interaction bounce never loads it — fine on a CPA model, since bounces
   don't convert anyway.

   Conversion is NOT here, and is not fired client-side at all right now:
   thank-you.php's EF.conversion() block is commented out because LeadProsper
   posts the buyer-specific conversion. That makes this file the only place the
   SDK is ever loaded — one page, one load.
   ============================================================ */
(function () {
    var cfg = (window.FUNNEL && window.FUNNEL.everflow) || {};

    // Read affid ourselves rather than via EF.urlParameter(): the gate has to be
    // decidable before we decide whether to load the SDK at all.
    var affiliateId = '';
    try {
        affiliateId = (new URLSearchParams(location.search).get('affid') || '').trim();
    } catch (e) {
        affiliateId = '';
    }
    if (!affiliateId) return; // unattributed — nothing goes to Everflow

    var firstParty = cfg.firstPartyAffids || [];
    var offerId = firstParty.indexOf(affiliateId) !== -1
        ? cfg.offerFirstParty
        : cfg.offerThirdParty;
    if (!offerId) return; // offer ids not configured — fail closed, don't guess

    // Hand the resolved transaction id to the form so it posts with the lead.
    function writeTransactionId(transactionId) {
        if (!transactionId) return;
        var attempts = 0;
        (function write() {
            var affField = document.getElementById('affid');
            var tidField = document.getElementById('efTransactionId');
            if (tidField) {
                if (affField && !affField.value) affField.value = affiliateId;
                tidField.value = transactionId;
            } else if (++attempts < 20) {
                setTimeout(write, 300);
            }
        })();
    }

    // Fallback for SDK builds where EF.click() doesn't return a promise: poll for
    // the tracking cookie until its value stops changing, then take the last
    // pipe-delimited segment. Prefers this offer's own cookie and falls back to
    // any ef_tid_ cookie rather than guessing at prefix naming.
    function watchCookie() {
        var attempts = 0, lastSeen = '', stable = 0;
        var interval = setInterval(function () {
            var cookies = document.cookie.split(';').map(function (c) { return c.trim(); });
            var match = cookies.filter(function (c) {
                return c.indexOf('ef_tid_c_o_' + offerId + '=') === 0;
            })[0] || cookies.filter(function (c) {
                return c.indexOf('ef_tid_') === 0;
            })[0];

            if (match) {
                var rawValue = match.split('=')[1] || '';
                if (rawValue === lastSeen) {
                    stable++;
                } else {
                    stable = 0;
                    lastSeen = rawValue;
                }
                if (stable >= 2) {
                    var parts = rawValue.split('|');
                    writeTransactionId(parts[parts.length - 1]);
                    clearInterval(interval);
                    return;
                }
            }
            if (++attempts >= 50) clearInterval(interval); // 10s timeout
        }, 200);
    }

    function runEverflowClick() {
        if (typeof EF === 'undefined') return;

        // One click per affid per session — a reload shouldn't re-click.
        if (sessionStorage.getItem('last_affid') === affiliateId) {
            watchCookie(); // cookie already exists; still need the id on the form
            return;
        }
        sessionStorage.setItem('last_affid', affiliateId);

        var result = EF.click({
            offer_id: offerId,
            affiliate_id: affiliateId,
            source_id: EF.urlParameter('source_id'),
            sub1: EF.urlParameter('sub1'),
            sub2: EF.urlParameter('sub2'),
            sub3: EF.urlParameter('sub3'),
            sub4: EF.urlParameter('sub4'),
            sub5: EF.urlParameter('sub5'),
            sub6: EF.urlParameter('sub6'),
            sub7: EF.urlParameter('sub7'),
            sub8: EF.urlParameter('sub8'),
            sub9: EF.urlParameter('sub9'),
            sub10: EF.urlParameter('sub10'),
            uid: EF.urlParameter('uid'),
            transaction_id: EF.urlParameter('_ef_transaction_id'),
        });

        if (result && typeof result.then === 'function') {
            result.then(writeTransactionId, watchCookie);
        } else {
            watchCookie();
        }
    }

    /* Loads the SDK at most once per pageview. Everflow's SDK misbehaves when
       it's evaluated twice, and this page has three racing triggers (interaction,
       4s timeout, form submit) plus a bfcache restore, so the guard is on the
       load itself rather than on the trigger. If the SDK is already present —
       another include, a re-entry after bfcache — we skip the tag and go
       straight to the click. This is the only page that loads it: thank-you.php
       fires no conversion (LeadProsper owns that), so nothing else pulls it in. */
    function loadEverflowSDK() {
        if (window._efSdkInitFired) return;
        window._efSdkInitFired = true;

        if (typeof EF !== 'undefined') { runEverflowClick(); return; }

        var src = 'https://' + (cfg.domain || 'www.f0cg2trk.com') + '/scripts/sdk/everflow.js';
        var existing = document.querySelector('script[src="' + src + '"]');
        if (existing) {
            existing.addEventListener('load', runEverflowClick, { once: true });
            return;
        }

        var script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = runEverflowClick;
        document.head.appendChild(script);
    }

    // Lazy gate: first interaction, 4s safety timeout, or form submit — whichever
    // comes first.
    var events = ['pointerdown', 'keydown', 'touchstart', 'scroll'];
    var fired = false;
    function fireOnce() {
        if (fired) return;
        fired = true;
        loadEverflowSDK();
        events.forEach(function (evt) { document.removeEventListener(evt, fireOnce); });
    }
    events.forEach(function (evt) {
        document.addEventListener(evt, fireOnce, { passive: true, once: true });
    });
    var form = document.getElementById('funnelForm');
    if (form) form.addEventListener('submit', fireOnce, { once: true });
    setTimeout(fireOnce, 4000);

    window.initEverflow = loadEverflowSDK;
})();
