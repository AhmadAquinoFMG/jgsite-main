/* ============================================================
   Everflow Affiliate Tracking — LAZY
   ------------------------------------------------------------
   Exposes window.initEverflow(). The EF.click() call block below is
   the exact snippet from the Everflow dashboard for this offer —
   script src (scripts/main.js) and field list (sub1-10, uid,
   transaction_id) must match it verbatim; don't "clean it up" against
   a different Everflow integration's shape.

   Wrapping is this funnel's own: lazy-load (first interaction / 4s
   safety timeout / form submit, so a zero-interaction bounce never
   loads it — fine on a CPA model since bounces don't convert anyway),
   plus a cookie watcher that copies the resolved affiliate/transaction
   id into the funnel's hidden fields (affid, efTransactionId) so they
   ride along with the lead to LeadProsper.
   ============================================================ */
(function () {
    var cfg = (window.FUNNEL && window.FUNNEL.everflow) || {};
    if (!cfg.offerId) return; // disabled — no offer configured

    function runEverflowClick() {
        if (typeof EF === 'undefined') return;

        var offer_id = EF.urlParameter('oid') || cfg.offerId;
        var affiliate_id = EF.urlParameter('affid') || cfg.affiliateId || '';

        // Fire click only if affiliate_id changed since last session.
        if (affiliate_id !== sessionStorage.getItem('last_affid')) {
            EF.click({
                offer_id: offer_id,
                affiliate_id: affiliate_id,
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
            sessionStorage.setItem('last_affid', affiliate_id);
        }

        // Cookie watcher: poll for the Everflow tracking cookie, then write the
        // resolved affiliate/transaction id into the funnel's hidden fields.
        var expectedPrefix = affiliate_id && affiliate_id !== cfg.affiliateId
            ? 'ef_tid_c_a_' : 'ef_tid_c_o_';
        var attempts = 0;
        var lastSeenValue = '';
        var stableCount = 0;

        var interval = setInterval(function () {
            var cookies = document.cookie.split(';').map(function (c) { return c.trim(); });
            var match = cookies.find(function (c) { return c.startsWith(expectedPrefix); });

            if (match) {
                var rawValue = match.split('=')[1] || '';
                if (rawValue === lastSeenValue) {
                    stableCount++;
                } else {
                    stableCount = 0;
                    lastSeenValue = rawValue;
                }

                if (stableCount >= 2) {
                    var parts = rawValue.split('|');
                    var latestTransactionId = parts[parts.length - 1];

                    var writeFields = function () {
                        var affField = document.getElementById('affid');
                        var tidField = document.getElementById('efTransactionId');
                        if (affField && tidField) {
                            if (!affField.value) affField.value = affiliate_id;
                            tidField.value = latestTransactionId;
                        } else {
                            setTimeout(writeFields, 300);
                        }
                    };
                    writeFields();
                    clearInterval(interval);
                }
            }

            if (++attempts >= 50) clearInterval(interval); // 10-second timeout
        }, 200);
    }

    function loadEverflowSDK() {
        if (window._efSdkInitFired) return;
        window._efSdkInitFired = true;

        var script = document.createElement('script');
        script.src = 'https://' + (cfg.domain || 'www.f0cg2trk.com') + '/scripts/main.js';
        script.async = true;
        script.onload = runEverflowClick;
        document.head.appendChild(script);
    }

    // Lazy gate: first interaction, 4s safety timeout, or form submit — whichever
    // comes first. No separate lazy-tracking.js dependency needed for this funnel.
    var fired = false;
    function fireOnce() {
        if (fired) return;
        fired = true;
        loadEverflowSDK();
        ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (evt) {
            document.removeEventListener(evt, fireOnce);
        });
    }
    ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (evt) {
        document.addEventListener(evt, fireOnce, { passive: true, once: true });
    });
    var form = document.getElementById('funnelForm');
    if (form) form.addEventListener('submit', fireOnce, { once: true });
    setTimeout(fireOnce, 4000);

    window.initEverflow = loadEverflowSDK;
})();
