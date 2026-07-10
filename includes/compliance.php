<?php
/**
 * TCPA proof-of-consent tags — rendered into <head> from index.php.
 *
 * Each tag drops a hidden field into the lead form that the backend stores as
 * proof the visitor consented (see submit.php + sql/schema.sql):
 *
 *   • ActiveProspect TrustedForm → hidden input `xxTrustedFormCertUrl`
 *   • Jornaya (LeadiD)           → hidden input `universal_leadid`
 *
 * Both are gated on config.php → ['compliance'] so local/dev runs stay clean
 * (mirrors includes/analytics.php). $cfg is provided by the including page.
 */
$compliance = $cfg['compliance'] ?? [];
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<?php if (!empty($compliance['trustedform'])): ?>
<!-- ActiveProspect TrustedForm -->
<script type="text/javascript">
    (function () {
        var tf = document.createElement('script');
        tf.type = 'text/javascript';
        tf.async = true;
        tf.src = ('https:' === document.location.protocol ? 'https' : 'http')
            + '://api.trustedform.com/trustedform.js?field=xxTrustedFormCertUrl&use_tagged_consent=true&l='
            + new Date().getTime() + Math.random();
        var s = document.getElementsByTagName('script')[0];
        s.parentNode.insertBefore(tf, s);
    })();
</script>
<noscript>
    <img src="https://api.trustedform.com/ns.gif" alt="">
</noscript>
<?php endif; ?>
<?php if (!empty($compliance['jornaya_campaign']) && !empty($compliance['jornaya_account'])): ?>
<!-- Jornaya (LeadiD) -->
<script id="LeadiDscript" type="text/javascript">
    (function () {
        var s = document.createElement('script');
        s.id = 'LeadiDscript_campaign';
        s.type = 'text/javascript';
        s.async = true;
        s.src = '//create.lidstatic.com/campaign/'
            + '<?= $e($compliance['jornaya_campaign']) ?>'
            + '.js?snippet_version=2';
        var f = document.getElementById('LeadiDscript');
        f.parentNode.insertBefore(s, f);
    })();
</script>
<noscript>
    <img src="//create.leadid.com/noscript.gif?lac=<?= $e($compliance['jornaya_account']) ?>&lck=<?= $e($compliance['jornaya_campaign']) ?>" alt="">
</noscript>
<?php endif; ?>
