<?php

/**
 * Shared portal chrome — head, top bar, footer, and the small formatting
 * helpers every screen needs.
 *
 * Kept as functions rather than an include-per-fragment so a page can put
 * things (filter bars, breadcrumbs) between the top bar and the content without
 * fighting the ordering.
 */

declare(strict_types=1);

if (!function_exists('portal_head')) {

    /** HTML-escape. Every page aliases this to $e. */
    function pe(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Security + cache headers, then the document head.
     *
     * The headers are set here as well as in admin/.htaccess: the htaccess
     * covers responses PHP never generates, this covers hosts that ignore
     * htaccess overrides. no-store matters more than usual — a cached admin
     * page is consumer PII sitting in a shared browser's disk cache.
     */
    function portal_head(array $cfg, string $title): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');

        $v = pe((string) ($cfg['asset_version'] ?? '1'));
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title><?= pe($title) ?> — Lead Portal</title>
            <link rel="stylesheet" href="assets/admin.css?v=<?= $v ?>">
        </head>

        <body>
        <?php
    }

    /** Top bar with the active nav item and the sign-out form. */
    function portal_topbar(array $user, string $csrf, string $active = ''): void
    {
        ?>
        <header class="topbar">
            <div class="topbar__brand">
                <span class="topbar__mark">JG</span>
                <span class="topbar__title">Lead Portal</span>
                <nav class="topbar__nav">
                    <a class="topbar__link<?= $active === 'leads' ? ' is-active' : '' ?>" href="leads.php">Leads</a>
                </nav>
            </div>
            <div class="topbar__user">
                <span class="topbar__name"><?= pe($user['name']) ?></span>
                <form method="post" action="logout.php" class="topbar__logout">
                    <input type="hidden" name="csrf" value="<?= pe($csrf) ?>">
                    <button class="btn btn--ghost" type="submit">Sign out</button>
                </form>
            </div>
        </header>
        <?php
    }

    function portal_foot(array $cfg): void
    {
        $v = pe((string) ($cfg['asset_version'] ?? '1'));
        ?>
        <script src="assets/admin.js?v=<?= $v ?>"></script>
        </body>

        </html>
        <?php
    }

    /* ------------------------------------------------------- value helpers */

    /** Em dash for anything empty, so blank cells read as "no value", not "bug". */
    function portal_or_dash(?string $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '—' : $value;
    }

    /** 2026-09-02 03:21 — seconds dropped; they never matter in a list. */
    function portal_datetime(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date('Y-m-d H:i', $ts);
    }

    /** $17,500 */
    function portal_money(?int $value): string
    {
        return $value === null ? '—' : '$' . number_format($value);
    }

    /**
     * The delivery outcome of a lead, as one label + colour.
     *
     * Mirrors how LeadProsper reads its own posts: the HTTP status is not the
     * outcome. LP answers 200 with {"status":"ERROR"} for a rejected lead, so a
     * status-code badge would show every rejection as a success. `lp_accepted`
     * is the authoritative flag; the status code only distinguishes "rejected"
     * from "never got there".
     *
     * @return array{label:string, tone:string}
     */
    function portal_lead_status(array $lead): array
    {
        if ((int) ($lead['bot_suspected'] ?? 0) === 1) {
            return ['label' => 'Bot', 'tone' => 'muted'];
        }
        if ($lead['lp_status'] === null && $lead['lp_posted_at'] === null) {
            return ['label' => 'Not posted', 'tone' => 'muted'];
        }
        if ((int) ($lead['lp_accepted'] ?? 0) === 1) {
            return ['label' => 'Accepted', 'tone' => 'good'];
        }
        if ((int) ($lead['lp_status'] ?? 0) === 0) {
            return ['label' => 'No response', 'tone' => 'bad'];
        }
        return ['label' => 'Rejected', 'tone' => 'warn'];
    }

    /**
     * One post attempt's outcome, from a log row.
     *
     * Same rule as above, one layer down: prefer the parsed `accepted` flag,
     * fall back to the transport result. `error` being set does not by itself
     * mean failure — leadprosper.php records 'http_200' there for a 200 that
     * carried a rejection.
     *
     * @return array{label:string, tone:string}
     */
    function portal_post_status(array $log): array
    {
        $status   = (int) ($log['response_status'] ?? 0);
        $accepted = $log['accepted'] ?? null;

        if ($status === 0) {
            return ['label' => 'No response', 'tone' => 'bad'];
        }
        if ($accepted !== null) {
            return (int) $accepted === 1
                ? ['label' => 'Accepted', 'tone' => 'good']
                : ['label' => 'Rejected', 'tone' => 'warn'];
        }
        if ($status >= 200 && $status < 300) {
            return ['label' => 'HTTP ' . $status, 'tone' => 'good'];
        }
        return ['label' => 'HTTP ' . $status, 'tone' => 'bad'];
    }

    /** <span class="badge badge--good">Accepted</span> */
    function portal_badge(array $status): string
    {
        return sprintf(
            '<span class="badge badge--%s">%s</span>',
            pe($status['tone']),
            pe($status['label'])
        );
    }

    /**
     * Human summary of a LeadProsper response body.
     *
     * LP puts the reason a lead was rejected in `message` — "field `employed`
     * has wrong value" — which is the single most useful string in the whole
     * response and is otherwise buried in the raw JSON.
     *
     * @return array{status:?string, message:?string, price:?float, buyers:int}
     */
    function portal_lp_summary(?string $responseBody): array
    {
        $out = ['status' => null, 'message' => null, 'price' => null, 'buyers' => 0];
        if (!$responseBody) {
            return $out;
        }
        $d = json_decode($responseBody, true);
        if (!is_array($d)) {
            return $out;
        }
        $out['status']  = isset($d['status']) ? (string) $d['status'] : null;
        $out['message'] = isset($d['message']) ? (string) $d['message'] : null;
        $out['price']   = isset($d['sell_price']) ? (float) $d['sell_price'] : null;
        $out['buyers']  = isset($d['buyers']) && is_array($d['buyers']) ? count($d['buyers']) : 0;
        return $out;
    }
}
