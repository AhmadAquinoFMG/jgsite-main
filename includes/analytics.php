<?php
/**
 * Umami analytics tag. Renders the tracking script only when a website ID is
 * configured (config.php → ['umami']), so local/dev runs stay clean.
 *
 * Funnel drop-off events:
 *   funnel-landing / field-* / funnel-*-click   index.php
 *   funnel-N-<name>[-done], funnel-exit         assets/js/funnel.js
 *   thank_you_view, call_click                  thank-you.php
 * bin/funnel-slack-report.php reads exactly these names — rename on one side and
 * the Slack digest silently reports zeroes.
 *
 * NOTE the `defer` below: window.umami does not exist while a page's classic
 * scripts run, so load-time events must go through window.jgTrack
 * (includes/track.php), which every page includes right after this one.
 *
 * $cfg is provided by the including page.
 */
$umami = $cfg['umami'] ?? [];
if (empty($umami['website_id']) || empty($umami['src'])) {
    return; // analytics disabled
}
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<script defer src="<?= $e($umami['src']) ?>"
        data-website-id="<?= $e($umami['website_id']) ?>"></script>
