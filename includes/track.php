<?php
/**
 * Umami event queue (window.jgTrack).
 *
 * includes/analytics.php loads the Umami tag with `defer`, so window.umami does
 * NOT exist yet while a page's own classic scripts run — assets/js/funnel.js sits
 * at the end of <body> and executes BEFORE any deferred script. Every event fired
 * at that moment was dropped on the floor, including the step-1 view that is the
 * entry anchor of bin/funnel-slack-report.php.
 *
 * jgTrack(event, props) hands off to umami when it is live and buffers in order
 * until then, so load-time events survive. Callers never need to know which.
 *
 * Include AFTER includes/analytics.php and BEFORE anything that tracks.
 */
?>
<script>
(function () {
    var queue = [];
    var timer = null;

    function ready() {
        return !!(window.umami && typeof window.umami.track === 'function');
    }

    function flush() {
        if (!ready()) return false;
        while (queue.length) {
            var ev = queue.shift();
            try { window.umami.track(ev[0], ev[1]); } catch (e) {}
        }
        if (timer) { clearInterval(timer); timer = null; }
        return true;
    }

    // The tracker normally appears at the deferred-script stage, i.e. within a few
    // ms. Poll briefly and give up after ~10s so a blocked or unconfigured script
    // never leaves a timer running for the life of the page.
    function drain() {
        if (flush() || timer) return;
        var tries = 0;
        timer = setInterval(function () {
            if (flush() || ++tries > 100) { clearInterval(timer); timer = null; }
        }, 100);
    }

    window.jgTrack = function (event, props) {
        if (ready()) {
            try { window.umami.track(event, props || {}); } catch (e) {}
            return;
        }
        queue.push([event, props || {}]);
        drain();
    };

    document.addEventListener('DOMContentLoaded', flush);
    window.addEventListener('load', flush);
}());
</script>
