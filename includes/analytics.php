<?php
/**
 * Umami analytics tag. Renders the tracking script only when a website ID is
 * configured (config.php → ['umami']), so local/dev runs stay clean.
 *
 * Funnel drop-off: the per-step events are fired client-side in
 * assets/js/funnel.js (event name "funnel-step", with the step number/name).
 * Build a Funnel report in Umami from those events to see where visitors exit.
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
