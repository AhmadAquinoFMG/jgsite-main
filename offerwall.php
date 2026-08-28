<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
$offerwall = require __DIR__ . '/includes/offerwall-campaigns.php';
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$affid = trim((string) ($_GET['affid'] ?? ''));
$partner = $offerwall['affiliate_partners'][$affid] ?? null;
$campaigns = $offerwall['campaigns'];

$resolveLink = static function (array $campaign) use ($partner): string {
    if ($partner !== null && isset($campaign['cta_links'][$partner])) {
        return (string) $campaign['cta_links'][$partner];
    }
    return (string) ($campaign['cta_link'] ?? '');
};

usort($campaigns, static fn(array $a, array $b): int => ($a['sponsored'] ?? false) <=> ($b['sponsored'] ?? false));
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>More Financial Options | <?= $e($cfg['brand']['name']) ?></title>
    <meta name="description" content="Explore additional financial options that may fit your needs.">
    <link rel="icon" type="image/png" href="assets/img/jg-icon.png?v=<?= $e($cfg['asset_version']) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $e($cfg['asset_version']) ?>">
    <link rel="stylesheet" href="assets/css/offerwall.css?v=4">
    <?php include __DIR__ . '/includes/analytics.php'; ?>
    <?php include __DIR__ . '/includes/track.php'; ?>
</head>
<body class="offerwall-page">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main>
        <section class="offerwall-hero" aria-labelledby="offerwall-title">
            <div class="offerwall-shell">
                <div class="offerwall-kicker">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    Your request has been received
                </div>
                <h1 id="offerwall-title">Check out other options that may fit your needs</h1>
                <p>You may still be eligible for alternative financial products below.</p>
                <div class="offerwall-note">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M12 16v-4M12 8h.01"/></svg>
                    <span>No impact to your credit score. Checking options is free and does not obligate you to enroll.</span>
                </div>
            </div>
        </section>

        <section class="offerwall-options" aria-label="Alternative financial offers">
            <div class="offerwall-shell">
                <?php if ($affid !== '' && $partner === null): ?>
                    <div class="offerwall-alert" role="status">
                        The supplied affiliate ID is not mapped, so default offer links are being used.
                    </div>
                <?php endif; ?>

                <div class="offer-list">
                    <?php $sponsoredStarted = false; ?>
                    <?php foreach ($campaigns as $position => $campaign): ?>
                        <?php if (!empty($campaign['sponsored']) && !$sponsoredStarted): $sponsoredStarted = true; ?>
                            <p class="sponsored-label">Sponsored alternatives</p>
                        <?php endif; ?>
                        <article class="offer-card"
                                 data-offer-id="<?= $e($campaign['id']) ?>"
                                 data-offer-name="<?= $e($campaign['name']) ?>"
                                 data-offer-position="<?= $position + 1 ?>"
                                 data-offer-sponsored="<?= !empty($campaign['sponsored']) ? 'true' : 'false' ?>">
                            <div class="offer-logo">
                                <img src="<?= $e($campaign['logo']) ?>"
                                     alt="<?= $e($campaign['name']) ?> logo"
                                     width="160" height="90" loading="lazy">
                            </div>
                            <div class="offer-card__body">
                                <h2><?= $e($campaign['name']) ?></h2>
                                <p><?= $e($campaign['description']) ?></p>
                                <ul>
                                    <?php foreach (array_slice($campaign['benefits'], 0, 3) as $benefit): ?>
                                        <li><?= $e($benefit) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="offer-card__action">
                                <a class="offer-button"
                                   href="<?= $e($resolveLink($campaign)) ?>"
                                   data-offer-cta
                                   data-cta-text="<?= $e($campaign['cta_text']) ?>"
                                   rel="nofollow sponsored">
                                    <span><?= $e($campaign['cta_text']) ?></span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="offerwall-disclaimer">JG Wentworth is not the lender or provider of the offers shown above. Selecting an option takes you to a third-party website. Eligibility, rates, terms, and availability are determined by each provider.</p>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script src="assets/js/offerwall.js?v=1"></script>
</body>
</html>
