/* =========================================================================
   JG Wentworth funnel — 8-step UI mechanics.

   Single-page, JS-driven flow.
     • Step 5 is a SINGLE free-form "Home Address" field that still submits a
       segregated street/city/state/zip. It uses the lazy-loaded Google Places
       (New) SDK (keyed from window.FUNNEL.googlePlacesKey) for autocomplete, with
       a submit-time Geocoder fallback; mock suggestions when no key is set.
       The step will NOT advance on anything less than a whole address — street,
       city, state, ZIP and country. The house number is NOT required: a street
       name is enough. Two tests, because either alone is fooled:
       checkAddressParts() reads Google's own components (so a locality can't
       pass itself off as a street), and
       partsNotInText() requires the city and ZIP to appear in the field itself (so
       a typed fragment Google silently COMPLETED can't pass either — picking a
       suggestion rewrites the field, which is what makes the text test fair).
       submit.php enforces the same fields server-side.
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

    /* ---- input sanitising -----------------------------------------------
       "𝓈𝒶𝓂𝓅𝓁𝑒 𝓉𝑒𝓍𝓉" pasted into a field is not a font we can restyle. It is a
       different set of CODEPOINTS — Mathematical Alphanumeric Symbols, fullwidth
       forms, enclosed letters — and our stylesheet has no glyph for U+1D4C8, so
       the browser silently falls back to whichever font does. No CSS fixes that;
       the characters themselves have to be rewritten.

       NFKC ("compatibility composition") is exactly that mapping — 𝓈→s, ｓ→s,
       ⓢ→s, ﬁ→fi — and it deliberately leaves real letters alone: é stays é, and
       a decomposed e + U+0301 is composed INTO é, which is the form the DB and
       LeadProsper want anyway. So accented input keeps working; only the
       decorative impostors get folded.

       NFKC can't touch same-shape letters borrowed from another script
       (Cyrillic "е", Greek "ο") or emoji — those are legitimately distinct
       characters — so a second pass drops anything outside Latin plus the
       punctuation each field actually uses. submit.php repeats both, since none
       of this binds a client that skips the browser. */

    // \p{Script=Latin} is every Latin letter INCLUDING the accented ones (é, ñ,
    // ø, ł); \p{Mark} keeps the combining accents that ride on letters with no
    // precomposed form. Digits are Script=Common, so a field that wants them has
    // to say so. Feature-detected because a \p{…} literal is a PARSE error on a
    // browser without Unicode property escapes (pre-2018) — that would take the
    // whole funnel down, not just this. The fallback ranges still cover é and ñ.
    var UNI = (function () {
        try { new RegExp('\\p{Script=Latin}', 'u'); return true; } catch (e) { return false; }
    })();
    var L = UNI ? '\\p{Script=Latin}' : 'A-Za-z\\u00C0-\\u024F';
    var M = UNI ? '\\p{Mark}'         : '\\u0300-\\u036F';

    function urx(src, flags) {
        try { return new RegExp(src, (flags || '') + 'u'); } catch (e) { return null; }
    }

    // What each kind of field KEEPS after folding; null means fold only.
    var DROP = {
        name:    urx('[^' + L + M + " .'-]", 'g'),
        city:    urx('[^' + L + M + " .'-]", 'g'),
        street:  urx('[^' + L + M + "0-9 .,'#/-]", 'g'),
        address: urx('[^' + L + M + "0-9 .,'#/-]", 'g'),
        email:   urx('[^A-Za-z0-9@._%+-]', 'g'),
        zip:     urx('[^0-9]', 'g'),
        dob:     urx('[^0-9/]', 'g'),
        phone:   urx('[^0-9 ()+-]', 'g')
    };

    function fold(v) {
        if (v.normalize) v = v.normalize('NFKC');
        return v
            .replace(/[‘’ʼ]/g, "'")        // curly apostrophes (iOS, Word)
            .replace(/[‐-―]/g, '-')             // en/em dashes
            .replace(/[​-‍⁠﻿]/g, ''); // zero-width padding
    }

    /* ---- title case, name fields only -----------------------------------
       Live typing only ever RAISES the first letter of each word; it never
       lowercases what follows. That distinction is the whole trick: a rule that
       also lowered the tail would rewrite "McDonald" into "Mcdonald" the moment
       the visitor typed the D, and fight every DeAngelo and O'Connor keystroke
       by keystroke. Word boundaries include the punctuation a name carries, so
       o'brien -> O'Brien and mary-jane -> Mary-Jane. */
    var WORD_START = urx('(^|[\\s\'.-])([' + L + '])', 'g');
    var WORD       = urx('[' + L + M + ']+', 'g');

    // ß uppercases to "SS" — two characters, which would break the caret maths
    // below. No name starts with one, so leave anything that doesn't map 1:1.
    function up(ch) { var u = ch.toUpperCase(); return u.length === 1 ? u : ch; }

    function titleCase(v) {
        if (!WORD_START) return v.replace(/(^|[\s'.-])([a-z])/g, function (m, sep, ch) { return sep + up(ch); });
        return v.replace(WORD_START, function (m, sep, ch) { return sep + up(ch); });
    }

    // A name left in ALL CAPS ("JOHN SMITH") is the one case where lowering the
    // tail is right. It runs on blur, never per keystroke, so a half-typed "MC"
    // is not rewritten under the visitor's cursor mid-word.
    function unshout(v) {
        if (!WORD) return v;
        return v.replace(WORD, function (w) {
            var isShouted = w.length > 1 && w === w.toUpperCase() && w !== w.toLowerCase();
            return isShouted ? w.charAt(0) + w.slice(1).toLowerCase() : w;
        });
    }

    function sanitize(v, kind) {
        v = fold(v);
        var re = DROP[kind];
        if (re) v = v.replace(re, '');
        // Length-preserving, which is what lets scrub() reuse it for the caret.
        if (kind === 'name') v = titleCase(v);
        return v;
    }

    function settleName(el) {
        if (!el || !el.dataset || el.dataset.validate !== 'name') return;
        var v = unshout(el.value);
        if (v !== el.value) el.value = v;
    }

    function scrub(el) {
        if (!el || el.tagName !== 'INPUT') return;
        var type = (el.type || '').toLowerCase();
        if (type === 'hidden' || type === 'radio' || type === 'checkbox') return;

        var before = el.value;
        if (!before) return;
        var kind   = el.dataset.validate;
        var after  = sanitize(before, kind);
        if (after === before) return;

        // Keep the caret where the visitor left it — sanitising just the text
        // BEFORE it yields that same character's new index, so a paste into the
        // middle of a filled field doesn't throw them to the end.
        var pos = null;
        try { pos = el.selectionStart; } catch (e) {}   // null on type=email
        el.value = after;
        if (pos !== null) {
            var at = sanitize(before.slice(0, pos), kind).length;
            try { el.setSelectionRange(at, at); } catch (e) {}
        }
    }

    // Capture phase, so this runs BEFORE each field's own input listener (DOB
    // and phone formatting, Places autocomplete) and they all see clean text.
    // 'change' as well: autofill and drag-and-drop don't always fire 'input'.
    form.addEventListener('input',  function (ev) { scrub(ev.target); }, true);
    form.addEventListener('change', function (ev) { scrub(ev.target); }, true);
    // focusout, not blur — blur doesn't bubble, and Continue takes the focus.
    form.addEventListener('focusout', function (ev) { settleName(ev.target); });

    var RX = {
        // Accented letters are real names — José, Ñuñez, Łukasz. The sanitiser
        // has already removed anything that merely LOOKS like a letter, so this
        // can afford to be script-wide rather than A–Z. Leading char excludes
        // \p{Mark}: a name can't start with a bare combining accent.
        name:  urx('^[' + L + '][' + L + M + " .'-]{0,48}$") ||
               /^[A-Za-zÀ-ɏ][A-Za-zÀ-ɏ .'\-]{0,48}$/,
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
            // single free-form address field: non-empty is all we can judge from the
            // text itself. The four components are resolved (pick or geocode) and
            // checked for completeness before the step advances — see the Continue
            // handler and checkAddressParts().
            case 'address': return { ok: true };
            case 'name':   return RX.name.test(v)  ? { ok: true } : { ok: false, code: 'invalid_format' };
            case 'street': return v.length >= 4    ? { ok: true } : { ok: false, code: 'too_short' };
            case 'city':   return v.length >= 2    ? { ok: true } : { ok: false, code: 'too_short' };
            case 'zip':    return RX.zip.test(v)   ? { ok: true } : { ok: false, code: 'invalid_format' };
            case 'email':  return checkEmail(v);
            case 'dob':    return checkDob(v);
            case 'phone':  return checkPhone(v);
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
        invalid_area:   'Please enter a valid phone number — it cannot start with 0 or 1.',
        invalid_email:  'Please enter a valid email address.'
    };

    /* ---- address completeness (step 6, both UI modes) -------------------
       A lead is only usable with a full mailing address, so the step refuses to
       advance until all five parts are present. Checking it here — while the
       address field is still in front of the visitor — beats letting them run to
       the end and be bounced back by submit.php's 422 five steps later. */
    var PART_LABELS = {
        street:  'street address',
        city:    'city',
        state:   'state',
        zip:     'ZIP code',
        country: 'country'
    };
    var PART_ORDER = ['street', 'city', 'state', 'zip', 'country'];

    // The street NAME is enough — no house number required, so "NW 10th St,
    // Miami, FL 33136" is a complete answer. What still has to be caught is a
    // result with no street line at all, which is what a locality-level pick
    // ("Miami, FL") resolves to.
    function isStreetLine(v) { return v.length >= 4; }

    // p is {street, city, state, zip, country}, optionally with the has* flags
    // parseComponents attaches. Returns {ok:true} or {ok:false, missing:[part,…]}.
    // Presence only — no country-specific format rules here, so a resolved
    // address from anywhere passes as long as it is whole.
    function checkAddressParts(p) {
        p = p || {};
        function val(k) { return (p[k] || '').trim(); }

        // Google-resolved parts say outright whether a street was found; typed
        // parts (classic mode) only have the text, so they fall back to length.
        var street     = val('street');
        var fromGoogle = p.hasRoute !== undefined;
        var streetOk   = street && (fromGoogle
            ? (p.hasPostBox || p.hasRoute)
            : isStreetLine(street));

        var missing = [];
        if (!streetOk)                 missing.push('street');
        if (val('city').length    < 2) missing.push('city');
        if (val('state').length   < 2) missing.push('state');
        if (val('zip').length     < 3) missing.push('zip');
        if (!val('country'))           missing.push('country');

        if (!missing.length) return { ok: true };
        return { ok: false, missing: missing };
    }

    // "…we still need the city and ZIP code." Naming the gap beats a generic
    // "address is incomplete", which leaves the visitor guessing what to retype.
    function addressErrorMsg(res) {
        var missing = res.missing;
        var names = PART_ORDER
            .filter(function (k) { return missing.indexOf(k) > -1; })
            .map(function (k) { return PART_LABELS[k]; });
        // A single gap is now the common case (a locality-level pick misses only
        // the street), so it gets its own grammar rather than "street address
        // are required".
        if (names.length === 1) {
            return 'Please enter a complete address — the ' + names[0] + ' is missing.';
        }
        var list = names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
        return 'Please enter a complete address — ' + list + ' are required';
    }

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
                clearError(dob.closest('.step')); // the DOB step, whatever number it holds
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
    // NANP area codes never begin with 0 or 1, so a number that does is a typo or
    // junk. Checked here as well as in submit.php, which returns the same code.
    function checkPhone(v) {
        var d = phoneDigits(v);
        if (d.length !== 10) return { ok: false, code: 'invalid_length' };
        if (d.charAt(0) === '0' || d.charAt(0) === '1') return { ok: false, code: 'invalid_area' };
        return { ok: true };
    }
    var phone = document.getElementById('phone');
    if (phone) {
        phone.addEventListener('input', function () {
            phone.value = formatPhone(phone.value);
            var scope = phone.closest('.step');
            if (!scope) return;
            clearError(scope);
            // Flag the bad area code as soon as all ten digits are in — waiting for
            // Submit means a round trip to submit.php just to say the same thing.
            var r = checkPhone(phone.value);
            if (!r.ok && r.code === 'invalid_area') fail(scope, phone, MSG[r.code]);
        });
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
       and we populate the hidden inputs street/city/state/zip/country from either
       a picked Google suggestion (trusted only while the field is unchanged) or a
       Continue-time geocode. An address that resolves only partially does NOT
       advance the step — resolve() reports what is missing and the visitor is asked
       to pick from the suggestions. Classic mode (?address_classic=1 → no #address
       element): the legacy multi-field UI, where each visible field validates on
       its own and the same completeness check runs over the four of them.
       Autocomplete uses the Places API (New) JS SDK, rendering into our styled
       #placesSuggestions list; the key comes from window.FUNNEL.googlePlacesKey
       (config.php -> .env). No key → a small mock list keeps local/dev working. */
    var address = buildAddress();
    function buildAddress() {
        var single  = !!document.getElementById('address');
        var visible = document.getElementById(single ? 'address' : 'street'); // the field the user types in
        if (!visible) return { present: false, single: false };

        var streetEl  = document.getElementById('street');
        var cityEl    = document.getElementById('city');
        var stateEl   = document.getElementById('state');
        var zipEl     = document.getElementById('zip');
        var countryEl = document.getElementById('country');
        var list      = document.getElementById('placesSuggestions');
        var key       = (window.FUNNEL && window.FUNNEL.googlePlacesKey) || '';

        var picked     = null;  // trusted {street,city,state,zip,country} from a chosen suggestion
        var pickedFor  = '';    // visible.value at the moment of that pick
        var resolvedFor = null; // text whose resolution already sits in the payload fields
                                // (so a repeat Continue on a blocked address doesn't re-geocode)
        var resolvedRes = null; // and that resolution's completeness verdict

        function close() { if (list) { list.hidden = true; list.innerHTML = ''; } }
        function debounce(fn, ms) { var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); }; }

        function setParts(p) {
            if (streetEl) streetEl.value = p.street || '';
            if (cityEl)   cityEl.value   = p.city   || '';
            if (stateEl)  stateEl.value  = p.state  || '';
            if (zipEl)    zipEl.value    = p.zip    || '';
            // Country only when the resolution actually carried one — otherwise keep
            // the field's own default (index.php seeds "US"), so a Google result that
            // omits the component can't blank a value we already had.
            if (countryEl && p.country) countryEl.value = p.country;
        }

        // Read whatever is currently in the payload fields. Single mode: what the
        // last pick/geocode wrote. Classic mode: what the visitor typed/selected.
        function currentParts() {
            return {
                street:  streetEl  ? streetEl.value  : '',
                city:    cityEl    ? cityEl.value    : '',
                state:   stateEl   ? stateEl.value   : '',
                zip:     zipEl     ? zipEl.value     : '',
                country: countryEl ? countryEl.value : ''
            };
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
        // long_name/short_name) -> our {street, city, state, zip, country} plus the
        // has* flags checkAddressParts needs. state and country come from shortText
        // (CA, US) to match what submit.php and LeadProsper expect.
        //
        // The street line is composed ONLY from real components. It must never fall
        // back to the formatted address: a locality-level result formats as
        // "Springfield, IL 62701, USA", so taking the first segment invented a
        // street ("Springfield") and let an address with no street at all pass the
        // completeness check.
        function parseComponents(comps) {
            var g = { num: '', route: '', postBox: '', locality: '', sublocality: '', admin1: '', zip: '', country: '' };
            (comps || []).forEach(function (c) {
                var t = c.types || [];
                var long  = c.longText  != null ? c.longText  : c.long_name;
                var short = c.shortText != null ? c.shortText : c.short_name;
                if (t.indexOf('street_number') > -1) g.num = long;
                else if (t.indexOf('route') > -1) g.route = long;
                else if (t.indexOf('post_box') > -1) g.postBox = long;
                else if (t.indexOf('locality') > -1) g.locality = long;
                else if (t.indexOf('sublocality') > -1 || t.indexOf('sublocality_level_1') > -1) g.sublocality = long;
                else if (t.indexOf('administrative_area_level_1') > -1) g.admin1 = short;
                else if (t.indexOf('postal_code') > -1) g.zip = long;
                else if (t.indexOf('country') > -1) g.country = short || long;
            });
            return {
                street:    g.postBox || (g.num + ' ' + g.route).trim(),
                city:      g.locality || g.sublocality || '',
                state:     g.admin1 || '',
                zip:       (g.zip || '').slice(0, 5),
                country:   g.country || '',
                // Flags, not guesses: only Google can tell a street line apart
                // from a locality that merely reads like one. The house number
                // is kept when offered but never required — see isStreetLine.
                hasRoute:   !!g.route,
                hasPostBox: !!g.postBox
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
                resolvedFor = null; resolvedRes = null;
                setParts(p);
            } else {
                visible.value = p.street || '';
                setParts(p);
            }
            // A pick answers an "incomplete address" error — drop it now rather than
            // leaving stale red text under a field the visitor just fixed.
            clearError(visible.closest('.step'));
            close();
        }

        // Hand-editing after a pick discards the trusted parts AND the components
        // they wrote (single mode), so the next Continue re-resolves from the edited
        // text and can never advance on the previous address's city/state/ZIP.
        visible.addEventListener('input', function () {
            clearError(visible.closest('.step'));
            if (!single) return;
            resolvedFor = null; resolvedRes = null;
            if (picked && visible.value !== pickedFor) {
                picked = null; pickedFor = '';
                setParts({});
            }
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
                                onPick(parseComponents(place.addressComponents), place.formattedAddress);
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
                { street: '1600 Amphitheatre Pkwy', city: 'Mountain View', state: 'CA', zip: '94043', country: 'US' },
                { street: '350 Fifth Ave',           city: 'New York',       state: 'NY', zip: '10118', country: 'US' },
                { street: '233 S Wacker Dr',         city: 'Chicago',        state: 'IL', zip: '60606', country: 'US' },
                { street: '1 Apple Park Way',        city: 'Cupertino',      state: 'CA', zip: '95014', country: 'US' }
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
                        if (status !== 'OK' || !results || !results[0]) return finish(null);
                        var r = results[0];
                        // partial_match means the geocoder could not match what was
                        // typed and picked its best guess — typically snapping a
                        // city-less "123 Main St" to whichever Main St it likes.
                        // Its components come back complete, so trusting it would
                        // put an address the visitor never entered on the lead.
                        // Treat it as unresolved and ask them to pick a suggestion.
                        if (r.partial_match) return finish(null);
                        finish(parseComponents(r.address_components));
                    });
                })
                .catch(function () { finish(null); });
        }

        // Loose containment test: case/punctuation/spacing-insensitive.
        function norm(s) {
            return String(s || '').toLowerCase().replace(/[.,]/g, ' ').replace(/\s+/g, ' ').trim();
        }

        // THE typed-fragment hole. The geocoder does not just validate an address,
        // it COMPLETES one: "1600 Amphitheatre Pkwy" with no city and no ZIP comes
        // back with both filled in, and partial_match is not set, because Google did
        // match the little that was typed. So a components-only check cannot tell a
        // full address from a fragment Google finished — both look whole.
        //
        // Requiring the city and ZIP to also appear in the FIELD TEXT can tell them
        // apart: the visitor either typed the whole address, or picked a suggestion,
        // which rewrites the field to Google's own formatted address. A fragment
        // stays a fragment, and we ask them to pick from the list.
        function partsNotInText(parts, text) {
            var t = norm(text);
            var absent = [];
            if (parts.city && t.indexOf(norm(parts.city)) === -1) absent.push('city');
            if (parts.zip  && t.indexOf(parts.zip) === -1)        absent.push('zip');
            return absent;
        }

        // Write the resolution into the payload fields, report whether it is whole,
        // and emit the analytics pair. Two distinct event names so a drop-off report
        // (which counts by event NAME) can measure how often the single field yields
        // a partial address — i.e. how often this gate is what holds visitors up.
        // fromPick skips the text test: the visitor chose that exact address off the
        // list, so the field already holds Google's rendering of it.
        function finalize(parts, text, fromPick, cb) {
            setParts(parts);

            // Judge the resolution itself, not the hidden inputs: the has* flags
            // that tell a street apart from a locality live on `parts` and
            // cannot be read back out of the DOM.
            var res = checkAddressParts(parts);
            if (res.ok && !fromPick) {
                var absent = partsNotInText(parts, text);
                if (absent.length) res = { ok: false, missing: absent };
            }
            resolvedFor = text;
            resolvedRes = res;

            track(res.ok ? 'event_address_resolved' : 'event_address_partial', {
                step: 6, field: 'address',
                has_city:  !!parts.city,
                has_state: !!parts.state,
                has_zip:   !!parts.zip,
                missing:   res.ok ? '' : res.missing.join(',')
            });
            cb(res);
        }

        // Resolve the address components, populate the payload inputs, emit
        // analytics, then cb({ok, missing}). Synchronous when we already hold
        // trusted picked parts or an unchanged prior resolution; async (≤4s) when
        // we must geocode the typed text. An incomplete result is reported as such
        // — the caller keeps the visitor on the step.
        function resolve(cb) {
            // classic mode: the visitor fills the parts directly, so just check them
            if (!single) { cb(checkAddressParts(currentParts())); return; }

            var text = (visible.value || '').trim();

            // (a) trusted picked parts, field unchanged since the pick
            if (picked && visible.value === pickedFor) { finalize(picked, text, true, cb); return; }

            // (b) already resolved this exact text — don't pay for a second geocode
            if (resolvedFor === text && resolvedRes) { cb(resolvedRes); return; }

            // (c) geocode the typed text. Whatever comes back is used as-is: the
            // typed line is NEVER promoted into a missing street, or "62701" would
            // arrive as a street next to the ZIP's own city and state and pass.
            geocode(text, function (parts) {
                if (parts) { finalize(parts, text, false, cb); return; }
                // Nothing usable came back (no key, timeout, partial match, or an
                // unrecognised address): keep the typed line as the street so it
                // isn't lost, and let the check block on the parts we can't fill.
                finalize({ street: text, city: '', state: '', zip: '' }, text, false, cb);
            });
        }

        // Which input to flag for a missing part: single mode has only the one
        // visible field; classic mode points at the offending input.
        function fieldFor(part) {
            if (single) return visible;
            return { street: streetEl, city: cityEl, state: stateEl, zip: zipEl }[part] || visible;
        }

        return { present: true, single: single, init: initAutocomplete, resolve: resolve, fieldFor: fieldFor };
    }

    /* ------------------------------------------------------------ events */
    btnNext.addEventListener('click', function () {
        if (!validateStep(current)) return;

        // Step 6: resolve the address (may geocode) and advance ONLY if it came back
        // whole — street, city, state, ZIP and country. A partial address keeps the
        // visitor here with the missing parts named, rather than sending an
        // unusable lead on to the rest of the funnel. Continue is disabled only
        // while the async resolution is in flight.
        if (current === 6 && address.present) {
            btnNext.disabled = true;
            address.resolve(function (res) {
                btnNext.disabled = false;
                if (!res.ok) {
                    var scope = stepEl(6);
                    clearError(scope);
                    // Distinct from event_address_partial (fired by finalize, once per
                    // resolution): this counts BLOCKED CONTINUE CLICKS, so repeated
                    // attempts on the same bad address show up as the friction they are.
                    track('event_address_incomplete', {
                        step: 6, field: 'address', missing: res.missing.join(',')
                    });
                    fail(scope, address.fieldFor(res.missing[0]), addressErrorMsg(res));
                    return;
                }
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
        street: 6, city: 6, state: 6, zip: 6, country: 6,
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

        // Last pass over every field: catches anything written programmatically
        // (autofill, a picked Places suggestion, a password manager) that never
        // raised an input event of its own.
        form.querySelectorAll('input').forEach(function (el) { scrub(el); settleName(el); });

        // Late-arriving _fbp/_fbc: still empty at load, present by now.
        captureMetaIds();

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
                    // submit.php builds the destination and appends the answers
                    // it just validated (includes/redirect.php), so we follow
                    // what it hands back rather than assembling a URL here from
                    // client-side values. Bare 'thank-you.php' is the fallback
                    // for an older/unexpected response shape with no redirect.
                    window.location.assign(res.body.redirect || 'thank-you.php');
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

    /* Meta's two match keys are cookies, not query params, so they are read here
       rather than copied off the URL — and read again right before the POST,
       because a cookie can appear after load (assets/js/tracking/attribution.js
       mints _fbp synchronously, but a tag manager or a real pixel would not).
       Reading at load only would ship an empty field on a fast submit.

       Neither value is ever overwritten once set: the first one we resolved for
       this pageview is the one the CAPI event should carry.

       fbc is the click identifier, so unlike fbp it only exists when there is an
       fbclid. The cookie wins when present — it carries the pixel's own creation
       time — otherwise it is built in Meta's documented format,
       fb.<subdomainIndex>.<creationTime>.<fbclid>, which dedupes against a
       cookie-sourced value for the same click. */
    function captureMetaIds() {
        function cookie(name) {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
            return m ? m[1] : '';
        }

        var fbpEl = document.getElementById('fbp');
        if (fbpEl && !fbpEl.value) fbpEl.value = cookie('_fbp');

        var fbcEl = document.getElementById('fbc');
        if (fbcEl && !fbcEl.value) {
            var fbclid = new URLSearchParams(location.search).get('fbclid');
            fbcEl.value = cookie('_fbc') ||
                (fbclid ? 'fb.1.' + Date.now() + '.' + fbclid : '');
        }
    }

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
            'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'sub6',
            'lp_subid1', 'lp_subid2', 'lp_subid3', 'lp_subid4', 'lp_subid5', 'lp_subid6',
            'adv1', 'adv2', 'adv3', 'adv4', 'adv5', 'subid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_creative', 'utm_placement', 'utm_adgroup', 'utm_matchtype',
            'gclid', 'gbraid', 'fbclid', 'fb_adid', 'ms_placement', 'ms_publisher', 'ttclid',
            // Not attribution: the QA test-mode token (?test=fmg_true). Rides in
            // the same way because it needs exactly the same thing — the landing
            // URL's value carried through to the POST. submit.php validates it.
            'test'
        ].forEach(function (k) {
            var v = qs.get(k), el = document.getElementById(k);
            if (v && el) el.value = v;
        });

        captureMetaIds();

        var landingEl = document.getElementById('landingPageUrl');
        if (landingEl) landingEl.value = location.href;
    })();

    render();
})();
