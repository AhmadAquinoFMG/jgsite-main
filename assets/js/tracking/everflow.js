/* ============================================================
   Everflow Affiliate Tracking — LAZY
   ------------------------------------------------------------
   Exposes window.initEverflow(). Ported from the sibling `cmd-main`
   project's proven integration, adapted to this funnel's hidden field
   ids (affid, efTransactionId) and reading offer/affiliate id from
   window.FUNNEL.everflow (see index.php / config.php ['everflow']).

   Lazy by design: the SDK + its network call only fire on first user
   interaction / a 4s safety timeout / form submit — whichever comes
   first — so a bounce visitor with zero interaction never loads it.
   Acceptable on a CPA payment model since bounce visitors don't
   convert anyway, so the missed attribution has no revenue cost.
   ============================================================ */
(function () {
    var cfg = (window.FUNNEL && window.FUNNEL.everflow) || {};
    if (!cfg.offerId) return; // disabled — no offer configured

    function runEverflowClick() {
        if (typeof EF === 'undefined') return;

        var qs = new URLSearchParams(location.search);
        var offer_id = qs.get('oid') || cfg.offerId;
        var affiliate_id = qs.get('affid') || cfg.affiliateId || '';
        var source_id = qs.get('source_id') || '';
        var sub1 = qs.get('sub1') || '';
        var sub2 = qs.get('sub2') || '';
        var sub3 = qs.get('sub3') || '';
        var sub4 = qs.get('sub4') || '';
        var sub5 = qs.get('sub5') || '';
        var uid = qs.get('uid') || '';
        var transaction_id = qs.get('_ef_transaction_id') || '';

        // Fire click only if affiliate_id changed since last session.
        if (affiliate_id !== sessionStorage.getItem('last_affid')) {
            EF.click({
                offer_id: offer_id,
                affiliate_id: affiliate_id,
                source_id: source_id,
                sub1: sub1,
                sub2: sub2,
                sub3: sub3,
                sub4: sub4,
                sub5: sub5,
                uid: uid,
                transaction_id: transaction_id
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
        script.src = 'https://' + (cfg.domain || 'www.f0cg2trk.com') + '/scripts/sdk/everflow.js';
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
