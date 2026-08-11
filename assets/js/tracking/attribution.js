/* ============================================================
   Attribution persistence — keep the tracking params on the URL
   ------------------------------------------------------------
   The problem this solves: the traffic link is an Everflow tracking link, e.g.

     https://sxcxm.ttrk.io/<id>?sub1={{ad.id}}&…&utm_source=facebook&utm_campaign={{campaign.name}}&…

   Everflow only forwards the params it knows about (affid, oid, source_id,
   sub1-sub10, uid, …) plus whatever is templated into the offer's destination
   URL. `utm_*` is none of those, so the click redirect DROPS every utm param on
   the way here — the visitor lands on a URL that has the subs but no utms, and
   every utm hidden field in index.php posts empty.

   The real fix is upstream: put the utm placeholders in the Everflow offer's
   destination URL (or turn on its pass-through-unknown-params option). This
   file is the safety net for when that isn't in place, plus the thing upstream
   config can't do — surviving an in-session reload or navigation that lands on
   a bare URL.

   Three jobs, in order, on every page that loads it:

     1. RESTORE   Merge the params on the current URL with the ones saved
                  earlier this session. The URL always wins; the store only
                  fills gaps. A reload of a bare '/' therefore keeps the
                  attribution of the click that started the session.
     2. DERIVE    Fill still-missing utm_* from the sub params that DID survive
                  the redirect, using the mapping the traffic link itself
                  defines (window.FUNNEL.attribution, from config.php).
     3. PERSIST   Save the merged set to sessionStorage and rewrite the visible
                  URL with history.replaceState so it carries the full set.

   Plus one job that has nothing to do with the URL: Meta's _fbp match key. No
   pixel is installed on this site, so nothing would otherwise create that
   cookie and every lead would report an empty fbp to the Conversions API. This
   file mints it in the pixel's own format and format-compatible cookie, and
   defers to a real pixel's value if one is ever installed. See section 2b.

   Ordering matters: this must run BEFORE assets/js/funnel.js, whose
   captureAttribution() copies location.search into the hidden fields — it reads
   the URL this file has already repaired, so nothing there needs to change.
   Same for the landing_page_url it records.

   replaceState only ADDS params. Anything already on the URL is left exactly as
   it is, so this can never clobber a value the ad platform or the server put
   there (thank-you.php's server-built query string included).
   ============================================================ */
(function () {
    'use strict';

    var STORE_KEY = 'jgw_attribution';
    var MAX_LEN = 200; // per value — long enough for a campaign name, short of a URL bomb

    /* Every param worth carrying. Superset of the list funnel.js copies into
       hidden fields: sub7/sub8 have no hidden field but are the source of
       utm_placement / the Meta traffic signal, so they still have to survive. */
    var PARAMS = [
        'affid', 'oid', 'source_id', 'uid',
        'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'sub6', 'sub7', 'sub8', 'sub9', 'sub10',
        'lp_subid1', 'lp_subid2', 'lp_subid3', 'lp_subid4', 'lp_subid5', 'lp_subid6',
        'adv1', 'adv2', 'adv3', 'adv4', 'adv5', 'subid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_creative', 'utm_placement', 'utm_adgroup', 'utm_matchtype',
        'gclid', 'gbraid', 'fbclid', 'fb_adid', 'ms_placement', 'ms_publisher', 'ttclid'
    ];

    /* fbp/fbc are deliberately NOT in PARAMS. They never arrive as query params —
       they are cookies — so there is nothing for the URL-sourced merge above to
       find, and pushing them onto the visible URL via replaceState would leak an
       identifier into every shared/copied link. They get their own pass below,
       and are excluded from the URL rewrite. */
    var COOKIE_ONLY = ['fbp', 'fbc'];

    /* A visitor can start a second, different click in the same tab. These are
       the params that identify WHICH click — if any of them arrives with a
       different value than what's stored, the stored set belongs to the
       previous click and is discarded wholesale rather than blended into the
       new one (which would attribute the lead to a mix of two campaigns). */
    var IDENTITY = ['affid', 'sub1', 'sub2', 'sub3', 'gclid', 'fbclid', 'ttclid'];

    var cfg = (window.FUNNEL && window.FUNNEL.attribution) || {};
    var derive = cfg.derive || {};     // utm name -> sub name it can be rebuilt from
    var defaults = cfg.defaults || {}; // utm name -> constant, only for derived traffic

    function clean(value) {
        if (value === null || value === undefined) return '';
        var trimmed = String(value).trim();
        return trimmed.length > MAX_LEN ? trimmed.slice(0, MAX_LEN) : trimmed;
    }

    // sessionStorage throws in Safari private mode and in some embedded
    // webviews. Attribution is worth zero broken pageviews, so every access is
    // wrapped — a failure just means "no store", and the URL still gets fixed.
    function readStore() {
        try {
            var raw = sessionStorage.getItem(STORE_KEY);
            var parsed = raw ? JSON.parse(raw) : null;
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function writeStore(values) {
        try {
            sessionStorage.setItem(STORE_KEY, JSON.stringify(values));
        } catch (e) { /* non-fatal */ }
    }

    function readCookie(name) {
        var match = document.cookie.match(
            new RegExp('(?:^|;\\s*)' + name + '=([^;]*)')
        );
        return match ? clean(decodeURIComponent(match[1])) : '';
    }

    function writeCookie(name, value, days) {
        try {
            var expires = new Date(Date.now() + days * 86400000).toUTCString();
            document.cookie = name + '=' + encodeURIComponent(value) +
                '; expires=' + expires +
                '; path=/; SameSite=Lax' +
                (location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) { /* non-fatal */ }
    }

    var qs;
    try {
        qs = new URLSearchParams(location.search);
    } catch (e) {
        return; // no URLSearchParams, no work we can do safely
    }

    /* ---- 1. Restore ------------------------------------------------------- */
    var fromUrl = {};
    PARAMS.forEach(function (name) {
        var value = clean(qs.get(name));
        if (value) fromUrl[name] = value;
    });

    var stored = readStore();

    // Same-click check. Only params present on BOTH sides can disagree; a param
    // the URL doesn't carry says nothing about which click this is.
    var isNewClick = IDENTITY.some(function (name) {
        return fromUrl[name] && stored[name] && fromUrl[name] !== stored[name];
    });
    if (isNewClick) stored = {};

    var values = {};
    PARAMS.forEach(function (name) {
        var value = fromUrl[name] || stored[name] || '';
        if (value) values[name] = value;
    });

    /* ---- 2. Derive -------------------------------------------------------- */
    /* Rebuild the utms Everflow dropped from the subs it kept. The mapping is
       the traffic link's own (sub1={{ad.id}} rides in utm_creative, sub6=
       {{campaign.name}} in utm_campaign, and so on) — it lives in config.php so
       a new source with a different sub layout is a config change, not a code
       change. Only ever fills a BLANK utm: a real one on the URL always wins. */
    var derivedAny = false;
    Object.keys(derive).forEach(function (utm) {
        var source = values[derive[utm]];
        if (!values[utm] && source) {
            values[utm] = source;
            derivedAny = true;
        }
    });

    /* The constants (utm_source=facebook, utm_medium=paid, …) are only true for
       traffic that came through a link matching the mapping above, so they are
       gated on having actually derived something. Unattributed or direct
       traffic gets no invented source. */
    if (derivedAny) {
        Object.keys(defaults).forEach(function (utm) {
            var value = clean(defaults[utm]);
            if (!values[utm] && value) values[utm] = value;
        });
    }

    /* ---- 2b. Meta browser identifiers ------------------------------------- */
    /* There is no Meta pixel on this site, so nothing writes the _fbp cookie its
       Conversions API expects as a match key. We mint it ourselves in Meta's
       documented format —

           fb.<subdomainIndex>.<creationTime>.<randomNumber>

       — subdomainIndex 1 and a millisecond timestamp, exactly what the pixel
       would have written. 90 days is the pixel's own expiry. If a real pixel is
       ever installed it writes the same cookie name and we defer to its value
       from that point on, since an existing cookie is never overwritten.

       The cookie is the source of truth here, not sessionStorage: it outlives
       the session and is shared with thank-you.php, so the same visitor reports
       one stable fbp across the whole funnel. */
    var fbp = readCookie('_fbp');
    if (!fbp) {
        fbp = 'fb.1.' + Date.now() + '.' + Math.floor(Math.random() * 1e10);
        writeCookie('_fbp', fbp, 90);
    }
    values.fbp = fbp;

    /* fbc identifies the CLICK, so unlike fbp it only exists when there is an
       fbclid. Cookie first (a real pixel's creation time is authoritative),
       then a build from the fbclid on this URL, then whatever a previous
       pageview in this session already resolved. Minted here as well as in
       funnel.js so thank-you.php — which has no funnel.js — reports it too. */
    var fbc = readCookie('_fbc');
    if (!fbc && values.fbclid) {
        fbc = 'fb.1.' + Date.now() + '.' + values.fbclid;
        writeCookie('_fbc', fbc, 90);
    }
    if (!fbc && stored.fbc) fbc = stored.fbc;
    if (fbc) values.fbc = fbc;

    /* ---- 3. Persist ------------------------------------------------------- */
    writeStore(values);

    var added = false;
    Object.keys(values).forEach(function (name) {
        if (COOKIE_ONLY.indexOf(name) !== -1) return; // cookie-borne, never on the URL
        if (!clean(qs.get(name))) {
            qs.set(name, values[name]);
            added = true;
        }
    });

    if (added && window.history && typeof history.replaceState === 'function') {
        try {
            var query = qs.toString();
            history.replaceState(
                history.state,
                '',
                location.pathname + (query ? '?' + query : '') + location.hash
            );
        } catch (e) { /* non-fatal — hidden fields below don't depend on it */ }
    }

    /* Hidden fields are funnel.js's job (it reads the repaired URL above), but
       it has already run on pages that load this file late, and thank-you.php
       has no funnel.js at all. Filling any empty same-named field here is
       idempotent and covers both. */
    Object.keys(values).forEach(function (name) {
        var el = document.getElementById(name);
        if (el && !el.value) el.value = values[name];
    });

    window.FUNNEL_ATTRIBUTION = values;
})();
