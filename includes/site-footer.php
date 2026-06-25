<?php
/**
 * Main site footer (NON-funnel pages): logo + social row, then the
 * "Company" and "Legal Information" link columns and the copyright line.
 *
 * Mirrors jgwentworth.com's light footer. Link columns come from
 * $cfg['footer_company'] / $cfg['footer_legal']; social links from
 * $cfg['social_links'] (keys must match the icon set below).
 * $cfg is provided by the including page.
 */
$brand = $cfg['brand'];
$e     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// Inline brand-icon paths (FontAwesome 5 brands). Each entry: [viewBox, path].
$social_icons = [
    'facebook'  => ['0 0 320 512', 'M279.14 288l14.22-92.66h-88.91v-60.13c0-25.35 12.42-50.06 52.24-50.06h40.42V6.26S260.43 0 225.36 0c-73.22 0-121.08 44.38-121.08 124.72v70.62H22.89V288h81.39v224h100.17V288z'],
    'tiktok'    => ['0 0 448 512', 'M448 209.91a210.06 210.06 0 0 1-122.77-39.25v178.72A162.55 162.55 0 1 1 185 188.31v89.89a74.62 74.62 0 1 0 52.23 71.18V0h88a121.18 121.18 0 0 0 1.86 22.17A122.18 122.18 0 0 0 381 102.39a121.43 121.43 0 0 0 67 20.14z'],
    'youtube'   => ['0 0 576 512', 'M549.66 124.08c-6.28-23.65-24.79-42.28-48.28-48.6C458.78 64 288 64 288 64S117.22 64 74.63 75.49c-23.5 6.32-42 24.95-48.29 48.6-11.41 42.87-11.41 132.3-11.41 132.3s0 89.44 11.41 132.31c6.29 23.65 24.79 41.5 48.29 47.82C117.22 448 288 448 288 448s170.78 0 213.38-11.49c23.49-6.32 42-24.17 48.28-47.82 11.41-42.87 11.41-132.31 11.41-132.31s0-89.43-11.41-132.3zm-317.51 213.5V175.18l142.74 81.2z'],
    'instagram' => ['0 0 448 512', 'M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z'],
    'x'         => ['0 0 512 512', 'M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z'],
    'pinterest' => ['0 0 384 512', 'M204 6.5C101.4 6.5 0 74.9 0 185.6 0 256 39.6 296 63.6 296c9.9 0 15.6-27.6 15.6-35.4 0-9.3-23.7-29.1-23.7-67.8 0-80.4 61.2-137.4 140.4-137.4 68.1 0 118.5 38.7 118.5 109.8 0 53.1-21.3 152.7-90.3 152.7-24.9 0-46.2-18-46.2-43.8 0-37.8 26.4-74.4 26.4-113.4 0-66.2-93.9-54.2-93.9 25.8 0 16.8 2.1 35.4 9.6 50.7-13.8 59.4-42 147.9-42 209.1 0 18.9 2.7 37.5 4.5 56.4 3.4 3.8 1.7 3.4 6.9 1.5 50.1-68.7 48.3-82.2 70.8-171.6 12.3 23.4 44.1 36 69.3 36 106.2 0 153.9-103.5 153.9-196.8C384 71.3 298.2 6.5 204 6.5z'],
];
?>
<footer class="main-footer">
    <div class="main-footer__inner">

        <!-- Top: logo left, social right -->
        <div class="main-footer__top">
            <img class="main-footer__logo" src="<?= $e($brand['logo_header']) ?>"
                 alt="<?= $e($brand['name']) ?>" loading="lazy">

            <div class="main-footer__social">
                <?php foreach ($cfg['social_links'] as $key => $href): ?>
                    <?php if (!isset($social_icons[$key])) continue; ?>
                    <a class="main-footer__social-link" href="<?= $e($href) ?>"
                       aria-label="<?= $e(ucfirst($key)) ?>">
                        <svg viewBox="<?= $e($social_icons[$key][0]) ?>" aria-hidden="true">
                            <path d="<?= $e($social_icons[$key][1]) ?>"/>
                        </svg>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Link columns -->
        <div class="main-footer__cols">
            <div class="main-footer__col">
                <h3 class="main-footer__heading">Company</h3>
                <ul class="main-footer__links">
                    <?php foreach ($cfg['footer_company'] as $label => $href): ?>
                        <li><a href="<?= $e($href) ?>"><?= $e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="main-footer__col main-footer__col--legal">
                <h3 class="main-footer__heading">Legal Information</h3>
                <ul class="main-footer__links main-footer__links--legal">
                    <?php foreach ($cfg['footer_legal'] as $label => $href): ?>
                        <?php $blank = $href !== '#' ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
                        <li><a href="<?= $e($href) ?>"<?= $blank ?>><?= $e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <p class="main-footer__copyright"><?= $e($brand['copyright']) ?></p>
    </div>
</footer>
