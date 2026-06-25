<?php
/** Site footer: dark-green two-column layout (brand/legal left, disclosures right). */
$brand   = $cfg['brand'];
?>
<footer class="site-footer">
    <div class="footer-inner">

        <div class="footer-head">
            <img class="footer-logo" src="<?= htmlspecialchars($brand['logo_footer']) ?>"
                 alt="<?= htmlspecialchars($brand['name']) ?>" loading="lazy">
        </div>

        <div class="footer-cols">

            <!-- Left: brand, address, legal links -->
            <div class="footer-left">
                <h3 class="footer-heading"><?= htmlspecialchars($brand['dept']) ?></h3>
                <address class="footer-address">
                    <?= implode('<br>', array_map('htmlspecialchars', $brand['address'])) ?>
                </address>

                <a class="footer-link" href="#">Unsubscribe</a>

                <h3 class="footer-heading footer-heading--legal">Legal</h3>
                <nav class="footer-legal" aria-label="Legal">
                    <?php foreach ($cfg['legal_links'] as $label => $href): ?>
                        <?php $blank = $href !== '#' ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
                        <a class="footer-link" href="<?= htmlspecialchars($href) ?>"<?= $blank ?>><?= htmlspecialchars($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- Right: disclosures -->
            <div class="footer-right">
                <?php foreach ($cfg['disclosures'] as $para): ?>
                    <p><?= $para /* trusted static HTML (may contain links) */ ?></p>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="footer-copyright"><?= htmlspecialchars($brand['copyright']) ?></p>
    </div>
</footer>
