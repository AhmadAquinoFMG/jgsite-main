<?php

/**
 * Buyer registry lookup — maps the buyer name LeadProsper returns to the logo
 * (if any) and the inbound phone number the thank-you page should show.
 *
 * The name arrives on the thank-you URL as ?buyer=, put there by submit.php from
 * the LeadProsper direct_post response (leadprosper_accepted_buyer()). It is
 * therefore VISITOR-EDITABLE by the time we read it: someone can hand-type
 * ?buyer=InCharge and see that logo without ever having been sold to InCharge.
 * That is accepted here — neither the logo nor the number is an entitlement, and
 * nothing downstream reads this param. Do not extend this to gate anything that
 * matters; use the session (as prequal_savings does) for that.
 *
 * What the param CANNOT do is choose an arbitrary image or an arbitrary number:
 * both come from the `buyers` row, never from the URL, so no value of ?buyer=
 * can point the <img> at a file — or the CALL NOW button at a line — that an
 * operator didn't put in the table. The worst it buys a curious visitor is the
 * other buyer's published inbound number, which is on that buyer's own website.
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
     * Best-effort by design: this decides which logo and which phone number the
     * thank-you page shows, so a DB hiccup returns null — no logo, and the shared
     * config number — rather than taking down a page the visitor has already
     * converted on.
     *
     * @param  array  $cfg  Full config array (for db()).
     * @param  string $name Buyer name as LeadProsper reported it.
     * @return array{id:int,name:string,label:string,logo_path:string,did:string,use_callgrid:bool,show_logo:bool}|null
     */
    function buyer_find(array $cfg, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        try {
            $stmt = db($cfg)->prepare(
                'SELECT id, name, label, logo_path, did, use_callgrid, show_logo
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
            'did'       => (string) ($row['did'] ?? ''),
            'use_callgrid' => (bool) $row['use_callgrid'],
            'show_logo' => (bool) $row['show_logo'],
        ];
    }

    /**
     * The logo to render for $name, or null when there is nothing to show.
     * Convenience wrapper for callers that need nothing else off the row; a page
     * that also wants the phone number should call buyer_find() once and pass the
     * row to buyer_logo_of() and buyer_phone_of() rather than looking it up twice.
     *
     * @return array{path:string,label:string}|null
     */
    function buyer_logo(array $cfg, string $name): ?array
    {
        return buyer_logo_of(buyer_find($cfg, $name));
    }

    /**
     * The logo to render for an already-fetched registry row, or null when there
     * is nothing to show — no row, show_logo off (JG Wentworth), no path on the
     * row, or the file missing from disk. The disk check keeps a stale row from
     * rendering a broken image on the page the visitor converted on.
     *
     * @param  array|null $buyer Row from buyer_find(), or null.
     * @return array{path:string,label:string}|null
     */
    function buyer_logo_of(?array $buyer): ?array
    {
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

    /**
     * This buyer's own inbound number as it should READ on the CALL NOW button,
     * or null to leave the shared config number in place — no row, no `did` set,
     * or a `did` that doesn't look like a dialable US number.
     *
     * Why it's worth showing: the consumer has just been told which company
     * bought their file, so the number under that should reach THAT company. A
     * single shared line for every buyer makes the match a claim rather than a
     * fact, and it lands the call on whoever answers the pool.
     *
     * This does NOT retire CallGrid. The number pool still assigns a tracking
     * DID client-side and still rewrites the tel: target (see thank-you.php), so
     * attribution is unchanged; this only decides which number is printed on the
     * button, and dialled if a visitor taps before assignment lands.
     *
     * The digit check is a typo guard, not sanitisation: the value is
     * operator-entered, and a number one digit short would be an unreachable
     * button on the page the visitor converted on. 10 digits, or 11 starting
     * with a US country code; anything else falls back and is logged.
     *
     * A value entered as bare digits ('18556000593') is punctuated for display,
     * because the button is read aloud off a phone screen and eleven undivided
     * digits are hard to scan. A value that already carries any punctuation is
     * returned untouched — the operator's formatting is assumed deliberate, so
     * '1-888-510-3795' and '(877) 627-1504' both survive as typed.
     *
     * @param  array|null $buyer Row from buyer_find(), or null.
     * @return string|null       Number ready to render, or null.
     */
    function buyer_phone_of(?array $buyer): ?string
    {
        if ($buyer === null || trim($buyer['did']) === '') {
            return null;
        }

        $did    = trim($buyer['did']);
        $digits = preg_replace('/\D/', '', $did);

        if (strlen($digits) !== 10 && !(strlen($digits) === 11 && $digits[0] === '1')) {
            if (function_exists('app_log')) {
                app_log('warning', 'buyers', 'did_invalid', ['buyer' => $buyer['name'], 'did' => $did]);
            }
            return null;
        }

        // Already punctuated → the operator's formatting wins.
        if ($did !== $digits) {
            return $did;
        }

        return strlen($digits) === 11
            ? sprintf('%s-%s-%s-%s', $digits[0], substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7))
            : sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }

    /**
     * Whether CallGrid's number pool may run for this buyer's thank-you page.
     *
     * False for a buyer that takes its own calls (`buyers.use_callgrid = 0`,
     * InCharge): our pool would sit in front of a line the buyer already owns,
     * re-routing a call they paid for through a campaign source that isn't ours
     * to claim. thank-you.php treats a false here the same as CallGrid being
     * switched off in config — the SDK is never loaded, so no number is assigned
     * and the buyer's own `did` is what the visitor reads AND dials.
     *
     * True when there is no row at all: whatever number the page ended up with
     * is ours, so there is nothing to protect and every reason to keep
     * attribution working. Same for a row with the flag on (JG) — the button
     * reads their DID and the pool still swaps the tel: target. Pass the row the
     * button's number actually came from, which on an unmatched visit is the
     * house row rather than null (see thank-you.php).
     *
     * @param  array|null $buyer Row from buyer_find(), or null.
     */
    function buyer_uses_callgrid(?array $buyer): bool
    {
        return $buyer === null || $buyer['use_callgrid'];
    }
}
