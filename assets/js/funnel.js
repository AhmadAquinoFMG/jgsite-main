/* =========================================================================
   JG Wentworth funnel — 8-step UI mechanics.

   Single-page, JS-driven flow. THIRD-PARTY INTEGRATIONS ARE MOCKED (UI only):
     • Step 5 street uses a lazy-loaded Google Places STUB (mock suggestions).
   Swap the marked stub functions for real SDK calls to go live.

   Steps: 1 debt · 2 employment · 3 income (auto-advance radios) ·
          4 name · 5 address · 6 dob · 7 email (Continue) ·
          8 phone + consent + Submit
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

    /* --------------------------------------------------------- rendering */
    function render() {
        steps.forEach(function (s) {
            s.classList.toggle('is-active', Number(s.dataset.step) === current);
        });

        // Expose the active step so CSS can reveal step-specific disclosures
        // (e.g. the FCRA notice below the nav on the DOB step).
        form.setAttribute('data-current', current);

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

    // Only accept mail from these trusted/verified consumer providers. This
    // blocks junk/typo domains and "random symbols on the end of the domain"
    // by construction (anything not on the list is rejected). Add domains here
    // as needed.
    var TRUSTED_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'ymail.com', 'rocketmail.com',
        'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com',
        'proton.me', 'protonmail.com',
        'comcast.net', 'verizon.net', 'att.net', 'sbcglobal.net', 'cox.net'
    ];

    // Strict email check: well-formed local part (no leading/trailing dot, no
    // consecutive dots, no stray symbols) AND a domain on the trusted list.
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

        if (TRUSTED_EMAIL_DOMAINS.indexOf(domain) === -1) {
            return { ok: false, code: 'untrusted_domain' };
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
        invalid_email:  'Please enter a valid email address.',
        untrusted_domain: 'Please use an email from a common provider (e.g. gmail.com, outlook.com, yahoo.com).'
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
            var out = d.slice(0, 2);
            if (d.length >= 3) out += '/' + d.slice(2, 4);
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
       LAZY-LOADED INTEGRATIONS (STUBBED — UI ONLY)
       =================================================================== */
    var lazyLoaded = {};
    function runLazyLoad(n) {
        var key = stepEl(n).dataset.lazy;
        if (!key || lazyLoaded[key]) return;
        lazyLoaded[key] = true;
        if (key === 'places')   loadGooglePlaces();
    }

    /* ---- Google Places STUB (step 5) ----------------------------------
       Real impl: inject https://maps.googleapis.com/maps/api/js?key=...&libraries=places
       then `new google.maps.places.Autocomplete(streetInput)`. Here we mock a
       suggestions dropdown so the UI/UX is fully wired. ------------------ */
    function loadGooglePlaces() {
        console.log('[funnel] lazy-load Google Places (stub)');
        var street = document.getElementById('street');
        var list   = document.getElementById('placesSuggestions');
        if (!street || !list) return;

        var MOCK = [
            { street: '1600 Amphitheatre Pkwy', city: 'Mountain View', state: 'CA', zip: '94043' },
            { street: '350 Fifth Ave',           city: 'New York',       state: 'NY', zip: '10118' },
            { street: '233 S Wacker Dr',         city: 'Chicago',        state: 'IL', zip: '60606' },
            { street: '1 Apple Park Way',        city: 'Cupertino',      state: 'CA', zip: '95014' }
        ];

        function close() { list.hidden = true; list.innerHTML = ''; }
        function pick(s) {
            street.value = s.street;
            document.getElementById('city').value  = s.city;
            document.getElementById('state').value = s.state;
            document.getElementById('zip').value   = s.zip;
            close();
        }

        street.addEventListener('input', function () {
            var q = street.value.trim().toLowerCase();
            if (q.length < 3) return close();
            list.innerHTML = '';
            MOCK.forEach(function (s) {
                var li = document.createElement('li');
                li.className = 'places-item';
                li.setAttribute('role', 'option');
                li.textContent = s.street + ', ' + s.city + ', ' + s.state + ' ' + s.zip;
                li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(s); });
                list.appendChild(li);
            });
            list.hidden = false;
        });
        street.addEventListener('blur', function () { setTimeout(close, 120); });
    }

    /* ------------------------------------------------------------ events */
    btnNext.addEventListener('click', function () {
        if (validateStep(current)) goNext();
    });
    btnBack.addEventListener('click', goBack);

    // radio steps (1–3): clear any error on selection; the Continue button
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

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();

        // ---- Backend submission goes here (not wired yet) ----
        var payload = {};
        new FormData(form).forEach(function (v, k) { payload[k] = v; });
        console.log('[funnel] lead captured (stub, not sent):', payload);

        // Advance to the "You're Pre-Qualified" confirmation page.
        // (A real backend would POST `payload` first, then redirect.)
        window.location.assign('thank-you.php');
    });

    render();
})();
