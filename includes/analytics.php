<?php
/**
 * Umami analytics tag. Renders the tracking script only when a website ID is
 * configured (config.php → ['umami']), so local/dev runs stay clean.
 *
 * Funnel events are named after the DATA THEY COLLECT, never the step's position,
 * so a name survives steps being inserted, moved or removed:
 *
 *   event_view_landing, event_choice_<field>,
 *   event_engage_<field>, event_*_click             index.php
 *   event_view_<field>, event_<field>_complete,
 *   event_abandon_<field>, event_resume_<field>,
 *   event_submit_attempt, event_address_resolved    assets/js/funnel.js
 *   event_view_thank_you, event_call_click          thank-you.php
 *
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
