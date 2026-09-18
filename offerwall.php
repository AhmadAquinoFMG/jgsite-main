<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
$offerwall = require __DIR__ . '/includes/offerwall-campaigns.php';
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$campaigns = $offerwall['campaigns'];

/* ---------------------------------------- student-loan card (conditional)
   Shown only to a lead Equifax found a student-loan balance for that was NOT
   already sold to the student buyer (lead_routing_decision()'s student_offer).

   Two sources, and the order matters. The SESSION is authoritative: submit.php
   set it, a visitor cannot edit it, and its presence is the actual permission to
   render the card. The URL param is a fallback for the balance only — this page
   is opened in a separate tab by design, and a session that has expired or was
   never started (a link reopened the next morning, a browser that dropped the
   cookie) would otherwise drop the one relevant offer on the page.

   That fallback is why the card promises nothing conditional. Someone can append
   ?student_debt=999999 and see it; what they get is a card and a number in a
   sentence, no eligibility, no rate, no pricing. Never let this value reach
   anything that decides an outcome — and note it is deliberately excluded from
   offerwall_attribution_keys(), so no partner CTA can interpolate it. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionStudentDebt = isset($_SESSION['student_debt']) ? (int) $_SESSION['student_debt'] : 0;
$urlStudentDebt     = max(0, (int) ($_GET['student_debt'] ?? 0));
$studentDebt        = $sessionStudentDebt > 0 ? $sessionStudentDebt : $urlStudentDebt;
$studentCampaigns   = $studentDebt > 0 ? ($offerwall['student_campaigns'] ?? []) : [];

/* Partner CTA links may carry {placeholder} tokens named after any attribution
   key decline_offerwall_url() carries onto this page's URL (see
   offerwall_attribution_keys()). {transaction_id} is an alias for Everflow's
   ef_transaction_id. Values are URL-encoded; a token with no value becomes
   empty rather than reaching the partner as literal "{sub2}". */
require_once __DIR__ . '/includes/routing.php';
$ctaTokens = [];
foreach (offerwall_attribution_keys() as $key) {
    $ctaTokens['{' . $key . '}'] = $_GET[$key] ?? '';
}
$ctaTokens['{transaction_id}'] = $_GET['ef_transaction_id'] ?? $_GET['transaction_id'] ?? '';
$fillCta = static function (string $url) use ($ctaTokens): string {
    return strtr($url, array_map(static fn($v): string => rawurlencode(trim((string) $v)), $ctaTokens));
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
    <link rel="stylesheet" href="assets/css/offerwall.css?v=6">
    <?php include __DIR__ . '/includes/analytics.php'; ?>
    <?php include __DIR__ . '/includes/tiktok.php'; ?>
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
                <?php if ($studentCampaigns !== []): ?>
                    <h1 id="offerwall-title">You may have student loan options</h1>
                    <p>Based on your credit file you carry about
                        <strong>$<?= $e(number_format($studentDebt)) ?></strong> in student loans.
                        Federal repayment programs may be able to help.</p>
                <?php else: ?>
                    <h1 id="offerwall-title">Check out other options that may fit your needs</h1>
                    <p>You may still be eligible for alternative financial products below.</p>
                <?php endif; ?>
                <div class="offerwall-note">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z"/><path d="M12 16v-4M12 8h.01"/></svg>
                    <span>No impact to your credit score. Checking options is free and does not obligate you to enroll.</span>
                </div>
            </div>
        </section>

        <section class="offerwall-options" aria-label="Alternative financial offers">
            <div class="offerwall-shell">
                <div class="offer-list">
                    <?php /* Student card first and outside the sponsored sort below: it is
                             the only offer matched to something we actually read off this
                             consumer's file, so it leads. The general wall follows under
                             its own heading so the two do not read as one ranked list. */ ?>
                    <?php foreach ($studentCampaigns as $studentPosition => $campaign): ?>
                        <article class="offer-card offer-card--student"
                                 data-offer-id="<?= $e($campaign['id']) ?>"
                                 data-offer-name="<?= $e($campaign['name']) ?>"
                                 data-offer-position="<?= $studentPosition + 1 ?>"
                                 data-offer-sponsored="false">
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
                                   href="<?= $e($fillCta((string) ($campaign['cta_link'] ?? ''))) ?>"
                                   data-offer-cta
                                   data-cta-text="<?= $e($campaign['cta_text']) ?>"
                                   rel="nofollow sponsored">
                                    <span><?= $e($campaign['cta_text']) ?></span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($studentCampaigns !== []): ?>
                        <p class="sponsored-label">Other options that may fit your needs</p>
                    <?php endif; ?>

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
                                   href="<?= $e($fillCta((string) ($campaign['cta_link'] ?? ''))) ?>"
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
