/* ==========================================================================
   Lead Portal — the small amount of behaviour the pages need.

   No framework and no build step, matching the rest of the project. There is
   exactly one interaction here: revealing a masked value.

   Reveal is a server round trip, not a CSS toggle. The page never contains the
   real value, so there is nothing to un-hide — the fetch IS the reveal, and it
   is what writes the audit row. Anyone changing this to render the value up
   front and hide it with JavaScript should read admin/includes/mask.php first:
   it would put every consumer's phone number in the page source and turn
   portal_audit into a fiction.
   ========================================================================== */

(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-reveal');
        if (!button) return;

        var lead = button.getAttribute('data-lead');
        var field = button.getAttribute('data-field');
        var csrf = button.getAttribute('data-csrf');
        if (!lead || !field) return;

        // Guard against a double-click firing two requests — and therefore
        // logging two reveals for one intent.
        if (button.disabled) return;
        button.disabled = true;
        var original = button.textContent;
        button.textContent = '…';

        var body = new URLSearchParams();
        body.set('lead', lead);
        body.set('field', field);
        body.set('csrf', csrf);

        fetch('reveal.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().catch(function () { return { ok: false, error: 'Unreadable response.' }; }); })
            .then(function (data) {
                if (!data.ok) {
                    button.disabled = false;
                    button.textContent = original;
                    showError(button, data.error || 'Could not reveal.');
                    return;
                }
                applyValue(button, data.value);
                // The button is spent: the value is on screen and the reveal is
                // recorded. Leaving it clickable would only log duplicates.
                button.remove();
            })
            .catch(function () {
                button.disabled = false;
                button.textContent = original;
                showError(button, 'Network error.');
            });
    });

    /**
     * Put the revealed value where its masked version was — either the <pre>
     * holding a request/response body, or the field's own value span.
     */
    function applyValue(button, value) {
        var pre = button.closest('.body') ? button.closest('.body').querySelector('.js-body') : null;
        if (pre) {
            pre.textContent = value;
            pre.classList.add('is-revealed');
            var hint = button.parentNode.querySelector('.hint');
            if (hint) hint.textContent = 'Raw — this view was recorded';
            return;
        }

        var span = button.closest('.datagrid__value');
        span = span ? span.querySelector('.js-value') : null;
        if (span) {
            span.textContent = value;
            span.classList.add('is-revealed');
        }
    }

    function showError(button, message) {
        var existing = button.parentNode.querySelector('.reveal-error');
        if (existing) existing.remove();

        var note = document.createElement('span');
        note.className = 'reveal-error';
        note.textContent = message;
        button.parentNode.appendChild(note);
        setTimeout(function () { note.remove(); }, 6000);
    }
})();
