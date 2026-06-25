<?php
/**
 * Main site header (NON-funnel pages): logo, primary navigation, Log In.
 *
 * Mirrors jgwentworth.com's top bar. Content comes from $cfg['nav_links']
 * and $cfg['login_url']; collapses to a CSS-only burger menu on mobile.
 * $cfg is provided by the including page.
 */
$brand = $cfg['brand'];
$e     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<header class="main-header">
    <div class="main-header__inner">

        <a class="main-header__logo" href="index.php" aria-label="<?= $e($brand['name']) ?>">
            <img src="<?= $e($brand['logo_header']) ?>"
                 alt="<?= $e($brand['name']) ?>" width="200" height="44">
        </a>

        <!-- CSS-only mobile toggle (no JS dependency on non-funnel pages) -->
        <input type="checkbox" id="mainNavToggle" class="main-nav__toggle" hidden>
        <label for="mainNavToggle" class="main-nav__burger" aria-label="Toggle navigation menu">
            <span></span><span></span><span></span>
        </label>

        <nav class="main-nav" aria-label="Primary">
            <ul class="main-nav__list">
                <?php foreach ($cfg['nav_links'] as $item): ?>
                    <li class="main-nav__item">
                        <a class="main-nav__link" href="<?= $e($item['href']) ?>">
                            <?= $e($item['label']) ?>
                            <?php if (!empty($item['dropdown'])): ?>
                                <svg class="main-nav__chevron" viewBox="0 0 24 24" width="14" height="14"
                                     fill="none" stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <a class="main-header__login" href="<?= $e($cfg['login_url'] ?? '#') ?>">Log In</a>

    </div>
</header>
