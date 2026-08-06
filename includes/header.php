<?php
/** Site header: centered logo with a thin underline. $cfg from index.php. */
$brand = $cfg['brand'];
?>
<header class="site-header">
    <div class="header-inner">
        <a class="logo" href="index.php" aria-label="<?= htmlspecialchars($brand['name']) ?>">
            <img src="<?= htmlspecialchars($brand['logo_header']) ?>"
                 alt="<?= htmlspecialchars($brand['name']) ?> logo" width="200" height="44">
        </a>
    </div>
</header>
