/* =========================================================================
   JG Wentworth funnel — 8-step UI mechanics.

   Single-page, JS-driven flow.
     • Step 5 is a SINGLE free-form "Home Address" field that still submits a
       segregated street/city/state/zip. It uses the lazy-loaded Google Places
       (New) SDK (keyed from window.FUNNEL.googlePlacesKey) for autocomplete, with
       a submit-time Geocoder fallback; mock suggestions when no key is set.
       Rollback to the legacy multi-field UI with ?address_classic=1.

   Steps: 1 debt · 2 behind on payments · 3 employment · 4 income (auto-advance radios) ·
          5 name · 6 address · 7 dob · 8 email (Continue) ·
          9 phone + consent + Submit
   ========================================================================= */
(function () {
    'use strict';

    var form = document.getElementById('funnelForm');
    if (!form) return;

    var steps   = Array.prototype.slice.call(form.querySelectorAll('.step'));
    var total   = steps.length;
    var current = 1; // 1-based

    var fill      = document.getElementById('progressFill');
    var btnBack   = document.getElementById('btnBack');
    var btnNext   = document.getElementById('btnNext');
    var btnSubmit = document.getElementById('btnSubmit');

    function stepEl(n)   { return steps[n - 1]; }

    /* ----------------------------------------------------- analytics (Umami)
       Funnel drop-off tracking. Every event is named after the DATA THE STEP
       COLLECTS, not its position, so an event name still means the same thing
       after a step is inserted, moved or dropped:

         event_view_<field>       first time the step is shown
         event_engage_<field>     first focus of one of its inputs (index.php marks
                                  these with data-jg-event)
         event_<field>_complete   the step validated and the visitor advanced
         event_abandon_<field>    the visitor left the page while on this step
         event_resume_<field>     the visitor came BACK to this step

       Naming each one per field (rather than one event carrying a step number as a
       property) is what makes them countable in Umami — its funnel and event
       reports group by event NAME, so a shared name with a `step` prop cannot be
       broken out per step. bin/funnel-slack-report.php reads these names; rename on
       one side only and the Slack digest silently reports zeroes.

       Umami may be absent (script blocked / not configured) — track() guards. */
    var STEP_FIELDS = {
        1: 'debt_amount', 2: 'behind_payment', 3: 'employment', 4: 'income', 5: 'name',
        6: 'address', 7: 'dob', 8: 'email', 9: 'phone'
    };
    function field(n)     { return STEP_FIELDS[n] || ('step_' + n); }
    function stepProps(n) { return { step: n, field: field(n) }; }

    // Prefer window.jgTrack (includes/track.php): this file is a classic script at
    // the end of <body>, so it runs BEFORE the deferred Umami tag — window.umami is
    // not there yet and the load-time step-1 view (the drop-off report's entry
    // anchor) would be lost. jgTrack queues until the tracker is live. The direct
    // umami call stays as a fallback for any page that omits the shim.
    function track(event, data) {
        if (typeof window.jgTrack === 'function') {
            window.jgTrack(event, data);
        } else if (window.umami && typeof window.umami.track === 'function') {
            window.umami.track(event, data);
        }
    }

    // A step already seen and shown again is a RESUME (back button, or a 422
    // bouncing the visitor to the offending step). Unlike the others this is not
    // once-per-visit: every return trip counts, because repeated returns to the
    // same field are the signal that the field itself is the problem.
    var trackedSteps = {};
    function trackStep(n) {
        if (trackedSteps[n]) {
            track('event_resume_' + field(n), stepProps(n));
            return;
        }
        trackedSteps[n] = true;
        track('event_view_' + field(n), stepProps(n));
    }

    // Fired when a step is validated and the visitor advances. Comparing
    // event_view_<field> against event_<field>_complete shows which field visitors
    // stall on rather than merely pass through.
    var completedSteps = {};
    function trackStepComplete(n) {
        if (completedSteps[n]) return;
        completedSteps[n] = true;
        track('event_' + field(n) + '_complete', stepProps(n));
    }

    // First-touch per field. index.php marks each input with data-jg-event; we fire
    // that event once, on first focus. focusin (not click) so tab/keyboard entry
    // counts too — which is also why these can't be plain data-umami-event
    // attributes, whose declarative tracking only listens for clicks.
    var engagedFields = {};
    form.addEventListener('focusin', function (ev) {
        var el = ev.target && ev.target.closest ? ev.target.closest('[data-jg-event]') : null;
        if (!el) return;
        var name = el.getAttribute('data-jg-event');
        if (!name || engagedFields[name]) return;
        engagedFields[name] = true;
        track(name, stepProps(current));
    });

    // Abandonment: fire once when the visitor leaves before submitting (tab
    // close, navigating away, or backgrounding on mobile), naming the FIELD they
    // left from. This is the explicit "where did they drop off" signal.
    // We use 'visibilitychange' -> hidden rather than 'beforeunload' because it
    // fires reliably across desktop and mobile and lets the request flush.
    var submitted   = false;
    var exitTracked = false;
    function trackExit() {
        if (exitTracked || submitted) return;
        exitTracked = true;
        track('event_abandon_' + field(current), stepProps(current));
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') trackExit();
    });

    /* --------------------------------------------------------- rendering */
    function render() {
        steps.forEach(function (s) {
            s.classList.toggle('is-active', Number(s.dataset.step) === current);
        });

        // Expose the active step so CSS can reveal step-specific disclosures
        // (e.g. the FCRA notice below the nav on the DOB step).
        form.setAttribute('data-current', current);

        trackStep(current);

        fill.style.width = ((current / total) * 100) + '%';

        btnBack.hidden = current === 1;
        // The back arrow shares the form-nav row with one primary button, chosen
        // per step via data-nav: 'next' (Continue, default) on the input steps,
        // 'submit' (Submit) on the final phone step, which carries the consent text.
        var nav = stepEl(current).dataset.nav || 'next';
        btnNext.hidden   = nav !== 'next';
        btnSubmit.hidden = nav !== 'submit';

        runLazyLoad(current);

        var active = stepEl(current);
        var firstInput = active.querySelector('input:not([type=hidden]):not([disabled]), select');
        if (firstInput) {
            try { firstInput.focus({ preventScroll: true }); } catch (e) { firstInput.focus(); }
        }
        active.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function goNext() { if (current < total) { current++; render(); } }
    function goBack() { if (current > 1) { current--; render(); } }

    /* -------------------------------------------------------- validation */
    function clearError(scope) {
        scope.querySelectorAll('.invalid').forEach(function (f) { f.classList.remove('invalid'); });
        var note = scope.querySelector('.field-error');
        if (note) note.remove();
    }
    function fail(scope, field, msg) {
        if (field) field.classList.add('invalid');
        var note = scope.querySelector('.field-error');
        if (!note) {
            note = document.createElement('p');
            note.className = 'field-error';
            scope.appendChild(note);
        }
        note.textContent = msg;
        if (field) field.focus();
        return false;
    }

    var RX = {
        name:  /^[A-Za-z][A-Za-z .'\-]{0,48}$/,
        zip:   /^\d{5}$/
    };

    // Email check: well-formed local part (no leading/trailing dot, no
    // consecutive dots, no stray symbols) AND a well-formed domain. Any domain
    // is accepted — no trusted-domain restriction.
    function checkEmail(v) {
        var at = v.lastIndexOf('@');
        if (at < 1 || at !== v.indexOf('@')) return { ok: false, code: 'invalid_email' };

        var local  = v.slice(0, at);
        var domain = v.slice(at + 1).toLowerCase();

        // local: starts/ends alphanumeric; allows . _ % + - between; no ".."
        if (!/^[A-Za-z0-9](?:[A-Za-z0-9._%+\-]*[A-Za-z0-9])?$/.test(local)) {
            return { ok: false, code: 'invalid_email' };
        }
        if (local.indexOf('..') !== -1) return { ok: false, code: 'invalid_email' };

        // domain: one or more dot-separated labels + a TLD of 2+ letters
        if (!/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,}$/.test(domain)) {
            return { ok: false, code: 'invalid_email' };
        }
        return { ok: true };
    }

    // returns {ok:bool, code:string} per field; codes mirror the spec's error keys
    function checkField(f) {
        var v = (f.value || '').trim();
        var kind = f.dataset.validate;
        if (f.required && !v) return { ok: false, code: 'required' };
        if (!v) return { ok: true };

        switch (kind) {
            // single free-form address field: only non-empty required (checked
            // above); street/city/state/zip are resolved + validated at submit time.
            case 'address': return { ok: true };
            case 'name':   return RX.name.test(v)  ? { ok: true } : { ok: false, code: 'invalid_format' };
            case 'street': return v.length >= 4    ? { ok: true } : { ok: false, code: 'too_short' };
            case 'city':   return v.length >= 2    ? { ok: true } : { ok: false, code: 'too_short' };
            case 'zip':    return RX.zip.test(v)   ? { ok: true } : { ok: false, code: 'invalid_format' };
            case 'email':  return checkEmail(v);
            case 'dob':    return checkDob(v);
            case 'phone':  return phoneDigits(v).length === 10 ? { ok: true } : { ok: false, code: 'invalid_length' };
        }
        return { ok: true };
    }

    var MSG = {
        required:       'This field is required.',
        invalid_format: 'Please check the format and try again.',
        too_short:      'That looks too short — please enter more detail.',
        incomplete:     'Please enter a full date as MM/DD/YYYY.',
        out_of_range:   'Please enter a valid calendar date.',
        underage:       'You must be at least 18 years old.',
        invalid_length: 'Please enter a valid 10-digit phone number.',
        invalid_email:  'Please enter a valid email address.'
    };

    function validateStep(n) {
        var scope = stepEl(n);
        clearError(scope);

        // radio steps: a selection must exist (auto-advance usually handles this)
        var radios = scope.querySelectorAll('input[type=radio][required]');
        if (radios.length) {
            var name = radios[0].name;
            if (!scope.querySelector('input[name="' + name + '"]:checked')) {
                return fail(scope, null, 'Please choose an option to continue.');
            }
            return true;
        }

        // field steps: validate each marked field, surface the first failure
        var fields = scope.querySelectorAll('[data-validate], select[required]');
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (f.tagName === 'SELECT') {
                if (f.required && !f.value) return fail(scope, f, 'Please make a selection.');
                continue;
            }
            var r = checkField(f);
            if (!r.ok) return fail(scope, f, MSG[r.code] || MSG.invalid_format);
        }
        return true;
    }

    /* ---- DOB: auto-format MM/DD/YYYY + range/age validation ------------- */
    var dob = document.getElementById('dob');
    if (dob) {
        dob.addEventListener('input', function () {
            var d = dob.value.replace(/\D/g, '').slice(0, 8);

            var mm = d.slice(0, 2);
            if (mm.length === 2 && (+mm === 0 || +mm > 12)) mm = '12';

            var dd = d.slice(2, 4);
            if (dd.length === 2 && (+dd === 0 || +dd > 31)) dd = '31';

            var out = mm;
            if (d.length >= 3) out += '/' + dd;
            if (d.length >= 5) out += '/' + d.slice(4, 8);
            dob.value = out;
        });
    }
    /* ---- DOB: calendar popup (better interactivity) -------------------
       Lightweight, dependency-free month grid with month/year dropdowns.
       Writes the picked day back to #dob as MM/DD/YYYY so the existing
       validation (checkDob) is untouched. Year range = 1900..(this year-18). */
    (function initDobCalendar() {
        var toggle = document.getElementById('dobToggle');
        var cal    = document.getElementById('dobCal');
        if (!dob || !toggle || !cal) return;

        var now      = new Date();
        var MAX_YEAR = now.getFullYear() - 18;   // must be 18+
        var MIN_YEAR = 1900;
        var MONTHS   = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
        var view     = { y: MAX_YEAR - 12, m: 0 };   // month currently shown

        function pad(n) { return (n < 10 ? '0' : '') + n; }

        // seed the view from a valid typed value, if any
        function syncViewFromInput() {
            var m = (dob.value || '').match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
            if (!m) return;
            var mo = +m[1], yr = +m[3];
            if (mo >= 1 && mo <= 12 && yr >= MIN_YEAR && yr <= MAX_YEAR) { view.y = yr; view.m = mo - 1; }
        }

        function build() {
            var selected = (dob.value || '').match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
            var selMo = selected ? +selected[1] : 0;
            var selDa = selected ? +selected[2] : 0;
            var selYr = selected ? +selected[3] : 0;

            var monthOpts = MONTHS.map(function (name, i) {
                return '<option value="' + i + '"' + (i === view.m ? ' selected' : '') + '>' + name + '</option>';
            }).join('');
            var yearOpts = '';
            for (var y = MAX_YEAR; y >= MIN_YEAR; y--) {
                yearOpts += '<option value="' + y + '"' + (y === view.y ? ' selected' : '') + '>' + y + '</option>';
            }

            var first   = new Date(view.y, view.m, 1).getDay();      // 0=Sun
            var dim     = new Date(view.y, view.m + 1, 0).getDate();  // days in month
            var headers = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
                .map(function (d) { return '<span class="dob-cal__dow">' + d + '</span>'; }).join('');

            var cells = '';
            for (var b = 0; b < first; b++) cells += '<span class="dob-cal__pad"></span>';
            for (var d = 1; d <= dim; d++) {
                var isSel = (d === selDa && view.m === selMo - 1 && view.y === selYr);
                cells += '<button type="button" class="dob-cal__day' + (isSel ? ' is-selected' : '') +
                         '" data-day="' + d + '">' + d + '</button>';
            }

            cal.innerHTML =
                '<div class="dob-cal__head">' +
                    '<button type="button" class="dob-cal__nav" data-nav="-1" aria-label="Previous month">&#8249;</button>' +
                    '<div class="dob-cal__selects">' +
                        '<select class="dob-cal__month" aria-label="Month">' + monthOpts + '</select>' +
                        '<select class="dob-cal__year" aria-label="Year">' + yearOpts + '</select>' +
                    '</div>' +
                    '<button type="button" class="dob-cal__nav" data-nav="1" aria-label="Next month">&#8250;</button>' +
                '</div>' +
                '<div class="dob-cal__dows">' + headers + '</div>' +
                '<div class="dob-cal__grid">' + cells + '</div>';
        }

        function open() {
            syncViewFromInput();
            build();
            cal.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
        function close() {
            cal.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () { cal.hidden ? open() : close(); });

        cal.addEventListener('change', function (ev) {
            if (ev.target.classList.contains('dob-cal__month')) { view.m = +ev.target.value; build(); }
            if (ev.target.classList.contains('dob-cal__year'))  { view.y = +ev.target.value; build(); }
        });

        cal.addEventListener('click', function (ev) {
            var nav = ev.target.closest('.dob-cal__nav');
            if (nav) {
                view.m += +nav.dataset.nav;
                if (view.m < 0)  { view.m = 11; view.y--; }
                if (view.m > 11) { view.m = 0;  view.y++; }
                if (view.y < MIN_YEAR) view.y = MIN_YEAR;
                if (view.y > MAX_YEAR) view.y = MAX_YEAR;
                build();
                return;
            }
            var day = ev.target.closest('.dob-cal__day');
            if (day) {
                dob.value = pad(view.m + 1) + '/' + pad(+day.dataset.day) + '/' + view.y;
                clearError(stepEl(6));
                close();
            }
        });

        // close on outside click / Escape
        document.addEventListener('click', function (ev) {
            if (!cal.hidden && !cal.contains(ev.target) && ev.target !== toggle && !toggle.contains(ev.target)) close();
        });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
    })();

    function checkDob(v) {
        var m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!m) return { ok: false, code: 'incomplete' };
        var mo = +m[1], da = +m[2], yr = +m[3];
        var dt = new Date(yr, mo - 1, da);
        var valid = dt.getFullYear() === yr && dt.getMonth() === mo - 1 && dt.getDate() === da;
        if (!valid || mo < 1 || mo > 12 || yr < 1900) return { ok: false, code: 'out_of_range' };
        var now = new Date(), age = now.getFullYear() - yr;
        if (now.getMonth() < mo - 1 || (now.getMonth() === mo - 1 && now.getDate() < da)) age--;
        if (dt > now) return { ok: false, code: 'out_of_range' };
        if (age < 18) return { ok: false, code: 'underage' };
        return { ok: true };
    }

    /* ---- phone formatting --------------------------------------------- */
    function phoneDigits(v) { return (v || '').replace(/\D/g, '').slice(0, 10); }
    function formatPhone(v) {
        var d = phoneDigits(v);
        if (d.length > 6) return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
        if (d.length > 3) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
        if (d.length > 0) return '(' + d;
        return '';
    }
    var phone = document.getElementById('phone');
    if (phone) {
        phone.addEventListener('input', function () { phone.value = formatPhone(phone.value); });
    }

    /* ===================================================================
       LAZY-LOADED INTEGRATIONS
       =================================================================== */
    var lazyLoaded = {};
    function runLazyLoad(n) {
        var key = stepEl(n).dataset.lazy;
        if (!key || lazyLoaded[key]) return;
        lazyLoaded[key] = true;
        if (key === 'places' && address.present) address.init();
    }

    // Toggles the Submit button; re-enabled after a failed submit attempt.
    function setSubmitEnabled(on) {
        btnSubmit.disabled = !on;
        if (on) btnSubmit.textContent = 'Submit';
    }

    /* ---- Address controller (step 5) ----------------------------------
       Default (single mode): the visitor types in ONE field (#address, no name),
       and we always populate the four hidden inputs street/city/state/zip from
       either a picked Google suggestion (trusted only while the field is unchanged)
       or a submit-time geocode, with a raw-text fallback so the funnel never traps
       the visitor. Classic mode (?address_classic=1 → no #address element): the
       legacy multi-field UI, where each visible field validates on its own.
       Autocomplete uses the Places API (New) JS SDK, rendering into our styled
       #placesSuggestions list; the key comes from window.FUNNEL.googlePlacesKey
       (config.php -> .env). No key → a small mock list keeps local/dev working. */
    var address = buildAddress();
    function buildAddress() {
        var single  = !!document.getElementById('address');
        var visible = document.getElementById(single ? 'address' : 'street'); // the field the user types in
        if (!visible) return { present: false, single: false };

        var streetEl = document.getElementById('street');
        var cityEl   = document.getElementById('city');
        var stateEl  = document.getElementById('state');
        var zipEl    = document.getElementById('zip');
        var list     = document.getElementById('placesSuggestions');
        var key      = (window.FUNNEL && window.FUNNEL.googlePlacesKey) || '';

        var picked    = null;   // trusted {street,city,state,zip} from a chosen suggestion
        var pickedFor = '';     // visible.value at the moment of that pick

        function close() { if (list) { list.hidden = true; list.innerHTML = ''; } }
        function debounce(fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }

        function setParts(p) {
            if (streetEl) streetEl.value = p.street || '';
            if (cityEl)   cityEl.value   = p.city   || '';
            if (stateEl)  stateEl.value  = p.state  || '';
            if (zipEl)    zipEl.value    = p.zip    || '';
        }

        // render [{label, onPick}] rows into the styled suggestion list
        function render(rows) {
            if (!list) return;
            list.innerHTML = '';
            if (!rows.length) return close();
            rows.forEach(function (r) {
                var li = document.createElement('li');
                li.className = 'places-item';
                li.setAttribute('role', 'option');
                li.textContent = r.label;
                li.addEventListener('mousedown', function (ev) { ev.preventDefault(); r.onPick(); });
                list.appendChild(li);
            });
            list.hidden = false;
        }

        // Google addressComponents (New: longText/shortText; legacy geocoder:
        // long_name/short_name) -> our {street, city, state(2-letter), zip(5-digit)}.
        function parseComponents(comps, formatted) {
            var g = { num: '', route: '', locality: '', sublocality: '', admin1: '', zip: '' };
            (comps || []).forEach(function (c) {
                var t = c.types || [];
                var long  = c.longText  != null ? c.longText  : c.long_name;
                var short = c.shortText != null ? c.shortText : c.short_name;
                if (t.indexOf('street_number') > -1) g.num = long;
                else if (t.indexOf('route') > -1) g.route = long;
                else if (t.indexOf('locality') > -1) g.locality = long;
                else if (t.indexOf('sublocality') > -1 || t.indexOf('sublocality_level_1') > -1) g.sublocality = long;
                else if (t.indexOf('administrative_area_level_1') > -1) g.admin1 = short;
                else if (t.indexOf('postal_code') > -1) g.zip = long;
            });
            var streetLine = (g.num + ' ' + g.route).trim();
            return {
                street: streetLine || (formatted ? String(formatted).split(',')[0] : ''),
                city:   g.locality || g.sublocality || '',
                state:  g.admin1 || '',
                zip:    (g.zip || '').slice(0, 5)
            };
        }

        // A suggestion was chosen.
        //   single : show the formatted address in the one field, stash the parsed
        //            parts, and trust them ONLY while the field equals that string.
        //   classic: put the street line in #street, parts in the visible fields.
        function onPick(p, formatted) {
            if (single) {
                var shown = formatted ||
                    [p.street, p.city, ((p.state || '') + ' ' + (p.zip || '')).trim()]
                        .filter(Boolean).join(', ');
                visible.value = shown;
                picked = p; pickedFor = shown;
                setParts(p);
            } else {
                visible.value = p.street || '';
                setParts(p);
            }
            close();
        }

        // Hand-editing after a pick discards the trusted parts (single mode) so we
        // re-resolve from the edited text at submit time.
        visible.addEventListener('input', function () {
            if (single && picked && visible.value !== pickedFor) { picked = null; pickedFor = ''; }
        });
        visible.addEventListener('blur', function () { setTimeout(close, 120); });

        /* ----- SDK loader (defines google.maps.importLibrary) ----- */
        function loadSdk() {
            if (window.google && window.google.maps && window.google.maps.importLibrary) {
                return Promise.resolve();
            }
            (g => { var h, a, k, p = "The Google Maps JavaScript API", c = "google", l = "importLibrary", q = "__ib__", m = document, b = window; b = b[c] || (b[c] = {}); var d = b.maps || (b.maps = {}), r = new Set, e = new URLSearchParams, u = () => h || (h = new Promise(async (f, n) => { await (a = m.createElement("script")); e.set("libraries", [...r] + ""); for (k in g) e.set(k.replace(/[A-Z]/g, t => "_" + t[0].toLowerCase()), g[k]); e.set("callback", c + ".maps." + q); a.src = `https://maps.${c}apis.com/maps/api/js?` + e; d[q] = f; a.onerror = () => h = n(Error(p + " could not load.")); a.nonce = m.querySelector("script[nonce]")?.nonce || ""; m.head.append(a); })); d[l] ? console.warn(p + " only loads once. Ignoring:", g) : d[l] = (f, ...n) => r.add(f) && u().then(() => d[l](f, ...n)); })({ key: key, v: "weekly" });
            return Promise.resolve();
        }

        /* ----- autocomplete wiring (Places New) ----- */
        function initAutocomplete() {
            if (!key) { initMock(); return; }
            loadSdk()
                .then(function () { return google.maps.importLibrary('places'); })
                .then(function (places) {
                    var Suggestion = places.AutocompleteSuggestion;
                    var Token      = places.AutocompleteSessionToken;
                    var token      = new Token();
                    var seq        = 0;

                    visible.addEventListener('input', debounce(function () {
                        var input = visible.value.trim();
                        if (input.length < 3) return close();
                        var mine = ++seq;
                        Suggestion.fetchAutocompleteSuggestions({
                            input: input, sessionToken: token, includedRegionCodes: ['us']
                        }).then(function (res) {
                            if (mine !== seq) return; // a newer keystroke already fired
                            var rows = (res.suggestions || []).map(function (sg) {
                                var pred = sg.placePrediction;
                                return {
                                    label: (pred.text && pred.text.text) || String(pred.text || ''),
                                    onPick: function () { selectPlace(pred); }
                                };
                            });
                            render(rows);
                        }).catch(function (err) { console.error('[funnel] places fetch failed', err); close(); });
                    }, 200));

                    function selectPlace(pred) {
                        var place = pred.toPlace();
                        place.fetchFields({ fields: ['addressComponents', 'formattedAddress'] })
                            .then(function () {
                                onPick(parseComponents(place.addressComponents, place.formattedAddress), place.formattedAddress);
                                token = new Token(); // close the billing session, start a fresh one
                            })
                            .catch(function (err) { console.error('[funnel] place details failed', err); });
                    }
                })
                .catch(function (err) {
                    console.error('[funnel] Google Places failed to load — using mock', err);
                    initMock();
                });
        }

        /* ----- mock fallback (no key configured) ----- */
        function initMock() {
            var MOCK = [
                { street: '1600 Amphitheatre Pkwy', city: 'Mountain View', state: 'CA', zip: '94043' },
                { street: '350 Fifth Ave',           city: 'New York',       state: 'NY', zip: '10118' },
                { street: '233 S Wacker Dr',         city: 'Chicago',        state: 'IL', zip: '60606' },
                { street: '1 Apple Park Way',        city: 'Cupertino',      state: 'CA', zip: '95014' }
            ];
            visible.addEventListener('input', function () {
                if (visible.value.trim().length < 3) return close();
                render(MOCK.map(function (s) {
                    var line = s.street + ', ' + s.city + ', ' + s.state + ' ' + s.zip;
                    return { label: line, onPick: function () { onPick(s, line); } };
                }));
            });
        }

        /* ----- submit-time geocode (single mode), hard ~4s timeout ----- */
        function geocode(text, cb) {
            var done = false;
            var timer = setTimeout(function () { if (!done) { done = true; cb(null); } }, 4000);
            function finish(parts) { if (done) return; done = true; clearTimeout(timer); cb(parts); }
            if (!key || !text) return finish(null);
            loadSdk()
                .then(function () { return google.maps.importLibrary('geocoding'); })
                .then(function (geo) {
                    var gc = new geo.Geocoder();
                    gc.geocode({ address: text, componentRestrictions: { country: 'us' } }, function (results, status) {
                        if (status === 'OK' && results && results[0]) {
                            finish(parseComponents(results[0].address_components, results[0].formatted_address));
                        } else { finish(null); }
                    });
                })
                .catch(function () { finish(null); });
        }

        function finalize(parts, cb) {
            setParts(parts);
            var hasCity = !!parts.city, hasState = !!parts.state, hasZip = !!parts.zip;
            // Two distinct event names so a drop-off report (which counts by event
            // name) can measure how often the single field yields a partial address.
            track(hasCity && hasState && hasZip ? 'event_address_resolved' : 'event_address_partial', {
                step: 6, field: 'address',
                has_city: hasCity, has_state: hasState, has_zip: hasZip
            });
            cb();
        }

        // Resolve the four components, populate the hidden inputs, emit analytics,
        // then cb(). Synchronous when we already hold trusted picked parts; async
        // (≤4s) when we must geocode the typed text.
        function resolveForSubmit(cb) {
            // classic mode: visible fields are separate and already validated
            if (!single) { cb(); return; }

            var text = (visible.value || '').trim();

            // (a) trusted picked parts, field unchanged since the pick
            if (picked && visible.value === pickedFor) { finalize(picked, cb); return; }

            // (b) submit-time geocode of the typed text
            geocode(text, function (parts) {
                if (parts && (parts.street || parts.city || parts.state || parts.zip)) {
                    if (!parts.street) parts.street = text; // keep the typed line if the geocoder had no street
                    finalize(parts, cb);
                } else {
                    // (c) fallback: raw typed string as street, blanks elsewhere — never trap the visitor
                    finalize({ street: text, city: '', state: '', zip: '' }, cb);
                }
            });
        }

        return { present: true, single: single, init: initAutocomplete, resolveForSubmit: resolveForSubmit };
    }

    /* ------------------------------------------------------------ events */
    btnNext.addEventListener('click', function () {
        if (!validateStep(current)) return;

        // Step 6 single-field: resolve the segregated address (may geocode) before
        // advancing. Disable Continue ONLY while that async resolution is in flight.
        if (current === 6 && address.present && address.single) {
            btnNext.disabled = true;
            address.resolveForSubmit(function () {
                btnNext.disabled = false;
                trackStepComplete(6);
                goNext();
            });
            return;
        }

        trackStepComplete(current);
        goNext();
    });
    btnBack.addEventListener('click', goBack);

    // radio steps (1–4): clear any error on selection; the Continue button
    // (not auto-advance) drives the step forward, consistent with all pages.
    form.querySelectorAll('.step[data-advance="auto"] input[type=radio]').forEach(function (r) {
        r.addEventListener('change', function () {
            clearError(r.closest('.step'));
        });
    });

    // Enter advances manual steps (never submits early). Behaviour follows the
    // step's nav: 'next' clicks Continue, 'submit' (final phone step) allows the
    // native submit.
    form.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') return;
        var nav = stepEl(current).dataset.nav || 'next';
        if (nav === 'submit') return;
        ev.preventDefault();
        if (nav === 'next') btnNext.click();
    });

    // Map each server-validated field to the step that collects it, so a 422
    // can bounce the visitor back to fix it.
    var FIELD_STEP = {
        debt_amount: 1, behind_payment: 2, employment: 3, income: 4,
        first_name: 5, last_name: 5,
        street: 6, city: 6, state: 6, zip: 6,
        dob: 7, email: 8, phone: 9
    };

    // Surface server-side {field: code} errors: jump to the earliest offending
    // step and mark the field, reusing the client's error styling/messages.
    function showServerErrors(errors) {
        var fields = Object.keys(errors || {});
        if (!fields.length) return;
        fields.sort(function (a, b) { return (FIELD_STEP[a] || 99) - (FIELD_STEP[b] || 99); });
        var first = fields[0];
        var step  = FIELD_STEP[first] || current;
        current = step; render();
        var scope = stepEl(step);
        var field = scope.querySelector('[name="' + first + '"]') ||
                    scope.querySelector('[data-validate]');
        fail(scope, field, MSG[errors[first]] || MSG.invalid_format);
    }

    var submitting = false;
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (submitting) return;

        submitting = true;
        submitted  = true; // a completion, not an abandonment — suppress event_abandon_*
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Submitting…';

        // ATTEMPT, not success: this fires before the POST resolves, so 422s and
        // network failures are in here too. The conversion signal is
        // event_view_thank_you, fired by thank-you.php after submit.php accepts.
        track('event_submit_attempt', stepProps(current));

        fetch('submit.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; })
                    .then(function (j) { return { status: r.status, body: j }; });
            })
            .then(function (res) {
                if (res.body && res.body.ok) {
                    window.location.assign('thank-you.php');
                    return;
                }
                submitting = false;
                setSubmitEnabled(true);
                if (res.status === 422 && res.body && res.body.errors) {
                    showServerErrors(res.body.errors);
                } else {
                    fail(stepEl(current), null, 'Something went wrong. Please try again.');
                }
            })
            .catch(function () {
                submitting = false;
                submitted  = false;
                setSubmitEnabled(true);
                fail(stepEl(current), null, 'Network error — please check your connection and try again.');
            });
    });

    // Attribution: copy every param below straight from the URL into its
    // same-named hidden field on load, so submit.php can store it and
    // includes/leadprosper.php can forward it. Every one of these has a hidden
    // field whose id matches the query param name (see index.php). Everflow's
    // ef_transaction_id is the one exception — that's written later by the
    // cookie watcher in assets/js/tracking/everflow.js, once EF.click() resolves.
    (function captureAttribution() {
        var qs = new URLSearchParams(location.search);
        [
            'affid', 'oid', 'source_id',
            'lp_subid1', 'lp_subid2', 'lp_subid3', 'lp_subid4', 'lp_subid5',
            'adv1', 'adv2', 'adv3', 'adv4', 'adv5', 'subid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_creative', 'utm_placement', 'utm_adgroup', 'utm_matchtype',
            'gclid', 'gbraid', 'fbclid', 'fb_adid', 'ms_placement', 'ms_publisher', 'ttclid'
        ].forEach(function (k) {
            var v = qs.get(k), el = document.getElementById(k);
            if (v && el) el.value = v;
        });

        // fbp is not a URL param — Meta's pixel sets it as the _fbp cookie.
        var fbpMatch = document.cookie.match(/(?:^|;\s*)_fbp=([^;]+)/);
        var fbpEl = document.getElementById('fbp');
        if (fbpMatch && fbpEl) fbpEl.value = fbpMatch[1];

        var landingEl = document.getElementById('landingPageUrl');
        if (landingEl) landingEl.value = location.href;
    })();

    render();
})();
