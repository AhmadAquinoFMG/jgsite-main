<?php
/**
 * TikTok Pixel base code. Ported from the DebtWave funnel
 * (debt-wave-official/includes/header.php), wired the way includes/analytics.php
 * already is rather than pasted into a page.
 *
 * THE ONLY PLACE the pixel ID belongs. Every page renders the tag through this
 * file — never hardcode a second snippet on a page. Two tags means two
 * ttq.page() calls per visit, which doubles the view count the ad spend is
 * judged against. That is the same rule the 'umami' comment in config.php
 * states at length, and it applies here for the same reason.
 *
 * ⚠ THIS PIXEL IS SHARED WITH THE DEBTWAVE FUNNEL. It is deliberately the same
 * ID, so TikTok sees one pixel spanning both sites and reports one combined
 * number. Traffic and conversions CANNOT be split per funnel afterwards — the
 * pixel does not carry which site a pageview came from. Give this funnel its
 * own pixel in Events Manager and set TIKTOK_PIXEL_ID in .env if per-funnel
 * reporting is ever wanted; nothing here has to change but the ID.
 *
 * Suppressed on local hosts, so a dev run never fires a pageview at a live ad
 * account. config.php has no is-local helper of its own — the Umami tag above
 * fires from localhost today — so the check is inline here rather than shared.
 *
 * NOT deferred, unlike the Umami tag. The snippet defines window.ttq
 * synchronously and queues every call made before the remote script lands, so
 * anything later in the page can call ttq.track() straight away. Deferring it
 * would leave ttq undefined for exactly the inline code most likely to want it.
 *
 * PAGEVIEWS ONLY. Nothing calls ttq.track() anywhere, so TikTok can optimise on
 * traffic but not on leads and the campaign reports zero conversions against
 * real spend. thank-you.php is where that event belongs, but the event name has
 * to match what is configured in Events Manager first.
 *
 * $cfg is provided by the including page.
 */
$tiktokPixelId = $cfg['tiktok']['pixel_id'] ?? '';
if ($tiktokPixelId === '') {
    return; // pixel disabled
}

$tiktokHost = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
if ($tiktokHost === 'localhost'
    || $tiktokHost === '127.0.0.1'
    || $tiktokHost === '::1'
    || str_ends_with($tiktokHost, '.local')
    || str_ends_with($tiktokHost, '.test')
    || str_ends_with($tiktokHost, '.localhost')) {
    return; // local/dev run
}

// Only the characters a pixel ID is made of. It is interpolated into a string
// literal in every page's HTML.
if (preg_match('/^[A-Za-z0-9]{1,40}$/', $tiktokPixelId) !== 1) {
    return;
}
?>
<!-- TikTok Pixel Code Start -->
<script>
!function (w, d, t) {
  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(
var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script")
;n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};

  ttq.load('<?= htmlspecialchars($tiktokPixelId, ENT_QUOTES, 'UTF-8') ?>');
  ttq.page();
}(window, document, 'ttq');
</script>
<!-- TikTok Pixel Code End -->
