<?php
/**
 * Social-proof block for the landing page, shown below the trust badges:
 *   1. "Our commitment to you" — three value cards (light green band)
 *   2. Headline stats row
 *   3. "Real customers, real reviews" — masonry wall of review cards
 *
 * Content is config-driven (config.php → commitments / stats / reviews).
 * Each item's 'icon' key maps to the inline SVG set below. $cfg from the
 * including page.
 */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// Inline icon set — stroke style, viewBox 0 0 24 24 (matches the site's
// existing inline SVGs). Add new glyphs here as needed.
$icons = [
    'search'  => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'shield'  => '<path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
    'headset' => '<path d="M4 14a8 8 0 0 1 16 0"/><rect x="3" y="13" width="4" height="7" rx="1.5"/><rect x="17" y="13" width="4" height="7" rx="1.5"/>',
    'trophy'  => '<path d="M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M7 4H4v2a3 3 0 0 0 3 3"/><path d="M17 4h3v2a3 3 0 0 1-3 3"/><line x1="12" y1="14" x2="12" y2="18"/><path d="M8.5 21h7"/><path d="M9 21a3 3 0 0 1 6 0"/>',
    'users'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    // Debt Consolidated — outline card crossed out with a diagonal slash.
    'card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="3.2" y1="18.8" x2="20.8" y2="5.2"/>',
    // Structured Payouts — solid (filled) card with a light magstripe. The
    // child fill overrides the wrapper <svg fill="none">.
    'wallet'  => '<rect x="2.5" y="5.5" width="19" height="13" rx="2.5" fill="currentColor" stroke="none"/><rect x="2.5" y="9" width="19" height="2.6" fill="#EFF7F3" stroke="none"/>',
    'piggy'   => '<path d="M20 10.5c0-3-3.1-5.5-7-5.5s-7 2.5-7 5.5c0 1.6.8 3 2 4.1V17h2.2l.6-1.3c.7.2 1.4.3 2.2.3s1.5-.1 2.2-.3l.6 1.3H18v-2.4c1.2-1.1 2-2.5 2-4.1z"/><circle cx="10" cy="10" r="1"/><line x1="3" y1="10.5" x2="6" y2="10.5"/>',
    'home'    => '<path d="M4 11l8-6 8 6"/><path d="M6 10v9h12v-9"/>',
    'bag'     => '<path d="M9 4h6l1.5 3H7.5z"/><path d="M7.5 7C6 9.2 5 12 5 14.6A4.4 4.4 0 0 0 9.4 19h5.2A4.4 4.4 0 0 0 19 14.6C19 12 18 9.2 16.5 7"/><path d="M12 10v5"/><path d="M10.5 11.5h3"/>',
];
$icon = function (string $name) use ($icons) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($icons[$name] ?? '') . '</svg>';
};
$starRow = '<span class="review-stars" role="img" aria-label="Rated 5 out of 5">'
    . str_repeat('<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.5l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 18.8 6.2 20.9l1.1-6.5L2.6 9.3l6.5-.9z"/></svg>', 5)
    . '</span>';
?>
<section class="social-proof" aria-label="Why customers choose JG Wentworth">

    <!-- 1. Commitment trio -->
    <div class="commit-band">
        <div class="sp-inner">
            <div class="sp-head sp-reveal">
                <h2 class="sp-heading">Our commitment to you</h2>
                <p class="sp-subheading">What you can count on at every step of your journey.</p>
            </div>
            <div class="commit-grid">
                <?php foreach ($cfg['commitments'] as $c): ?>
                    <div class="commit-card sp-reveal">
                        <span class="commit-icon"><?= $icon($c['icon']) ?></span>
                        <h3 class="commit-title"><?= $e($c['title']) ?></h3>
                        <p class="commit-text"><?= $e($c['text']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 2. Stats -->
    <div class="sp-inner stats-row">
        <?php foreach ($cfg['stats'] as $s): ?>
            <div class="stat sp-reveal">
                <span class="stat-icon"><?= $icon($s['icon']) ?></span>
                <span class="stat-body">
                    <span class="stat-value"><?= $e($s['value']) ?></span>
                    <span class="stat-label"><?= $e($s['label']) ?></span>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 3. Reviews -->
    <div class="sp-inner reviews">
        <div class="sp-head sp-reveal">
            <h2 class="sp-heading">Real customers, real reviews</h2>
            <p class="sp-subheading">Real stories from people we've helped move forward.</p>
        </div>
        <div class="reviews-grid">
            <?php foreach ($cfg['reviews'] as $r): ?>
                <article class="review-card sp-reveal">
                    <div class="review-head">
                        <span class="review-avatar"><?= $icon($r['icon']) ?></span>
                        <span class="review-id">
                            <span class="review-name"><?= $e($r['name']) ?></span>
                            <span class="review-product"><?= $e($r['product']) ?></span>
                        </span>
                        <?= $starRow ?>
                    </div>
                    <p class="review-text"><?= $e($r['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

</section>

<script>
/* Scroll-reveal for the social-proof block. Elements tagged .sp-reveal fade and
   slide up as they enter the viewport, staggered within each row. Honors
   prefers-reduced-motion and degrades gracefully without IntersectionObserver. */
(function () {
    var els = document.querySelectorAll('.social-proof .sp-reveal');
    if (!els.length) return;

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || !('IntersectionObserver' in window)) {
        els.forEach(function (el) { el.classList.add('is-visible'); });
        return;
    }

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var el = entry.target;
            // Stagger by the element's position among its .sp-reveal siblings.
            var sibs = Array.prototype.filter.call(el.parentNode.children, function (c) {
                return c.classList.contains('sp-reveal');
            });
            var i = sibs.indexOf(el);
            if (i > 0) el.style.transitionDelay = (i * 90) + 'ms';
            el.classList.add('is-visible');
            io.unobserve(el);
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    els.forEach(function (el) { io.observe(el); });
})();
</script>
