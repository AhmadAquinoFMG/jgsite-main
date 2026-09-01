<?php

/**
 * PII masking for the portal.
 *
 * The portal shows consumer records, so direct contact identifiers are masked
 * by default and revealed one field at a time through reveal.php, which writes
 * an audit row for each reveal.
 *
 * WHY THE MASKING IS SERVER-SIDE: if the page carried the real value and the
 * browser merely hid it, the value would sit in the page source and the audit
 * trail would be a fiction — anyone could read every consumer's phone number by
 * pressing Ctrl+U, and portal_audit would show nothing. So a masked field never
 * leaves the server intact. The cost is a round trip per reveal, which is the
 * whole point.
 *
 * WHAT IS MASKED: the identifiers that let someone contact or impersonate the
 * consumer — email, phone, street, date of birth — plus the same values
 * wherever they appear inside a logged request/response body. Name, city, state
 * and zip stay visible: an operator has to be able to recognise the lead they
 * are looking at, and a list of "M••• M•••" rows is unusable as a work queue.
 *
 * A mask is never reversible from what it shows. These helpers keep only enough
 * to tell two records apart (a domain, the last four digits), not enough to
 * reconstruct the value.
 */

declare(strict_types=1);

if (!function_exists('portal_maskable_fields')) {

    /**
     * The lead columns reveal.php will unmask. Anything not listed here is
     * refused, so a crafted ?field= cannot walk the whole row.
     */
    function portal_maskable_fields(): array
    {
        return ['email', 'phone', 'street', 'dob'];
    }

    /** m•••••@gmail.com — keeps the domain, which is often the useful part. */
    function portal_mask_email(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }
        $at = strpos($value, '@');
        if ($at === false) {
            return portal_mask_generic($value, 1);
        }
        $local  = substr($value, 0, $at);
        $domain = substr($value, $at);
        return substr($local, 0, 1) . str_repeat('•', max(strlen($local) - 1, 3)) . $domain;
    }

    /** (•••) •••-6278 — last four only, the standard for confirming identity. */
    function portal_mask_phone(?string $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if ($digits === '') {
            return '—';
        }
        if (strlen($digits) > 10 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        $last = substr($digits, -4);
        return strlen($digits) >= 10 ? "(•••) •••-{$last}" : '•••' . $last;
    }

    /** ••••-••-•• — a birth date is an identity key; nothing is kept. */
    function portal_mask_dob(?string $value): string
    {
        return trim((string) $value) === '' ? '—' : '••••-••-••';
    }

    /** Keeps $keep leading characters. Used for street and anything unclassed. */
    function portal_mask_generic(?string $value, int $keep = 0): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }
        $head = $keep > 0 ? substr($value, 0, $keep) : '';
        return $head . str_repeat('•', max(strlen($value) - $keep, 3));
    }

    /**
     * Mask one lead column by name. Unknown columns pass through untouched —
     * only the fields in portal_maskable_fields() are hidden.
     */
    function portal_mask_field(string $field, ?string $value): string
    {
        switch ($field) {
            case 'email':
                return portal_mask_email($value);
            case 'phone':
                return portal_mask_phone($value);
            case 'dob':
                return portal_mask_dob($value);
            case 'street':
                return portal_mask_generic($value, 0);
            default:
                return (string) $value;
        }
    }

    /**
     * Mask identifiers inside an arbitrary text blob — the JG/LeadProsper
     * request and response bodies.
     *
     * Without this the masking above is decorative: those payloads carry the
     * same email, phone and date of birth in plain text, one click away on the
     * same page. The bodies are matched by shape rather than by key name
     * because each integration names its fields differently (`email` vs
     * `email_address`, `phone` vs `phone_number`) and a new one would silently
     * slip through a key-based list.
     *
     * Deliberately conservative — it over-masks rather than under-masks. A
     * 10-digit run that happens to be an id gets dotted out too; the raw body
     * is one audited click away when that matters.
     */
    function portal_mask_body(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        // Email addresses, anywhere in the blob.
        $text = preg_replace_callback(
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            static fn(array $m): string => portal_mask_email($m[0]),
            $text
        ) ?? $text;

        // Y-m-d dates: date_of_birth / dob, in whatever key the partner uses.
        $text = preg_replace('/\b(19|20)\d{2}-\d{2}-\d{2}\b/', '••••-••-••', $text) ?? $text;

        /* Runs of 10-11 digits — phone numbers, with or without a country code.
           Bounded by non-digits so it cannot eat part of a longer id. */
        $text = preg_replace_callback(
            '/(?<!\d)\+?1?\d{10}(?!\d)/',
            static fn(array $m): string => portal_mask_phone($m[0]),
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * Pretty-print a JSON body for display, masked.
     *
     * Falls back to the raw string when the body is not JSON — an upstream
     * error page or a truncated response still needs to be readable, and that
     * is exactly when someone is looking at it.
     */
    function portal_format_body(?string $body, bool $masked = true): string
    {
        $body = (string) $body;
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        $out = $decoded !== null
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;

        return $masked ? portal_mask_body($out) : $out;
    }
}
