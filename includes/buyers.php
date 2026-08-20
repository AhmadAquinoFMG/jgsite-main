<?php

/**
 * Buyer registry lookup — maps the buyer name LeadProsper returns to the logo
 * (if any) the thank-you page should show.
 *
 * The name arrives on the thank-you URL as ?buyer=, put there by submit.php from
 * the LeadProsper direct_post response (leadprosper_accepted_buyer()). It is
 * therefore VISITOR-EDITABLE by the time we read it: someone can hand-type
 * ?buyer=InCharge and see that logo without ever having been sold to InCharge.
 * That is accepted here — the logo is decoration, not an entitlement, and
 * nothing downstream reads this param. Do not extend this to gate anything that
 * matters; use the session (as prequal_savings does) for that.
 *
 * What the param CANNOT do is choose an arbitrary image: the path is read from
 * the `buyers` row, never from the URL, so no value of ?buyer= can point the
 * <img> at a file an operator didn't put in the table.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('buyer_find')) {

    /**
     * The registry row whose match token appears in $name, or null.
     *
     * Matching is the inverse of the usual LIKE: the stored `name` is the needle
     * and the passed-in buyer name is the haystack (LOCATE('InCharge', 'InCharge
     * Debt Solutions')), because the table holds short tokens and LeadProsper
     * returns the full legal name. LOCATE rather than LIKE deliberately — under
     * LIKE the column supplies the pattern, so a '%' or '_' in a stored name
     * would silently act as a wildcard. Case-insensitive via the utf8mb4_unicode_ci
     * collation. Longest token first, so a specific buyer wins over a broader one
     * if two ever both match.
     *
     * Best-effort by design: this decides whether a decorative logo renders, so a
     * DB hiccup returns null (no logo) rather than taking down a page the visitor
     * has already converted on.
     *
     * @param  array  $cfg  Full config array (for db()).
     * @param  string $name Buyer name as LeadProsper reported it.
     * @return array{id:int,name:string,label:string,logo_path:string,show_logo:bool}|null
     */
    function buyer_find(array $cfg, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        try {
            $stmt = db($cfg)->prepare(
                'SELECT id, name, label, logo_path, show_logo
                   FROM buyers
                  WHERE LOCATE(name, :name) > 0
                  ORDER BY CHAR_LENGTH(name) DESC
                  LIMIT 1'
            );
            $stmt->execute(['name' => $name]);
            $row = $stmt->fetch();
        } catch (Throwable $ex) {
            if (function_exists('app_log')) {
                app_log('error', 'buyers', 'lookup_failed', ['buyer' => $name, 'error' => $ex->getMessage()]);
            }
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'label'     => (string) ($row['label'] ?: $row['name']),
            'logo_path' => (string) ($row['logo_path'] ?? ''),
            'show_logo' => (bool) $row['show_logo'],
        ];
    }

    /**
     * The logo to render for $name, or null when there is nothing to show —
     * unknown buyer, show_logo off (JG Wentworth), no path on the row, or the
     * file missing from disk. The disk check keeps a stale row from rendering a
     * broken image on the page the visitor converted on.
     *
     * @return array{path:string,label:string}|null
     */
    function buyer_logo(array $cfg, string $name): ?array
    {
        $buyer = buyer_find($cfg, $name);
        if ($buyer === null || !$buyer['show_logo'] || $buyer['logo_path'] === '') {
            return null;
        }

        // Paths are operator-entered, so a leading slash or a stray '..' is a typo
        // rather than an attack — normalise the first, refuse the second.
        $path = ltrim($buyer['logo_path'], '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        if (!is_file(__DIR__ . '/../' . $path)) {
            if (function_exists('app_log')) {
                app_log('warning', 'buyers', 'logo_missing', ['buyer' => $buyer['name'], 'path' => $path]);
            }
            return null;
        }

        return ['path' => $path, 'label' => $buyer['label']];
    }
}
