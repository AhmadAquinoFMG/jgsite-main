<?php
/**
 * JG Wentworth — post-submit "You're Pre-Qualified" page.
 *
 * Reached after the funnel form is submitted (funnel.js redirects here).
 * Static confirmation screen: assigned-specialist messaging + a click-to-call
 * CTA and a "your file is held for N:00" countdown timer (urgency device).
 * Copy/number/hold-time come from config.php → ['prequal'].
 */
$cfg = require __DIR__ . '/config.php';
$e   = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$pq        = $cfg['prequal'];
$ctaPhone  = $pq['cta_phone'];
$ctaTel    = preg_replace('/[^\d+]/', '', $ctaPhone);          // tel: href (digits only)
$holdSecs  = max(1, (int) $pq['hold_minutes']) * 60;           // countdown seconds
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
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $e($cfg['asset_version']) ?>">

    <?php include __DIR__ . '/includes/analytics.php'; ?>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="funnel">
    <div class="funnel-inner prequal">

        <!-- Hero: confirmation -->
        <div class="prequal-check" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="34" height="34" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6L9 17l-5-5"/>
            </svg>
        </div>

        <h1 class="prequal-title">You&rsquo;re Pre-Qualified for <br>a Debt Relief Program</h1>
        <p class="prequal-lede">You could reduce your debt and lower your monthly payments.</p>

        <!-- Assigned specialist -->
        <div class="prequal-assigned">
            <span class="prequal-assigned__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none"
                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M19 8l2 2 3-3"/>
                </svg>
            </span>
            <div class="prequal-assigned__body">
                <h2 class="prequal-assigned__title">A Certified Debt Specialist Has Been Assigned to You</h2>
                <p>They&rsquo;re ready to walk you through your best options for becoming debt free.</p>
            </div>
        </div>

        <!-- Call CTA card -->
        <section class="prequal-card" aria-label="Speak with your specialist">
            <h2 class="prequal-card__title">Speak With Your Specialist Now</h2>
            <p class="prequal-card__sub">Your estimate is reserved, but availability is limited.</p>

            <a class="prequal-call" href="tel:<?= $e($ctaTel) ?>">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                <span>CALL NOW: <?= $e($ctaPhone) ?></span>
            </a>

            <!-- Hold timer -->
            <div class="prequal-hold" data-hold-secs="<?= $e($holdSecs) ?>">
                <span class="prequal-hold__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5"/><path d="M9 2h6"/>
                    </svg>
                </span>
                <p class="prequal-hold__label">Your specialist is<br>holding your file for:</p>
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
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>
                    </svg>
                    Takes less than 10 minutes
                </li>
                <li>
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/>
                    </svg>
                    No obligation
                </li>
                <li>
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Free consultation
                </li>
            </ul>
        </section>

    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
/* Hold-file countdown — counts the [data-hold-secs] value down to 00:00. */
(function () {
    var box = document.querySelector('.prequal-hold');
    if (!box) return;
    var minEl = document.getElementById('holdMin');
    var secEl = document.getElementById('holdSec');
    var left  = parseInt(box.getAttribute('data-hold-secs'), 10) || 0;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function paint() {
        minEl.textContent = pad(Math.floor(left / 60));
        secEl.textContent = pad(left % 60);
    }
    paint();
    var t = setInterval(function () {
        if (left <= 0) { clearInterval(t); return; }
        left--;
        paint();
    }, 1000);
})();
</script>
</body>
</html>
