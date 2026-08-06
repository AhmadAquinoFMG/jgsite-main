<?php

/**
 * Post-submit redirect URL builder.
 *
 * The consumer's answers never go straight from the browser into the redirect.
 * They are POSTed to submit.php, validated and normalised there, and only then
 * does this build the redirect URL from the *stored* row — so what rides in the
 * query string is the server's version of each answer (phone as E.164, DOB as
 * ISO, debt as an int), not whatever the client happened to send.
 *
 * Which params get appended is config, not code: config.php ['redirect']['params']
 * maps outgoing query-param name => key in submit.php's $row. Because $row holds
 * the attribution fields too (affid, oid, ef_transaction_id, utm_*), anything in
 * there can be forwarded by naming it in that map.
 */

/**
 * Build the post-submit redirect URL.
 *
 * @param  array $row  submit.php's $row (validated + normalised lead data).
 * @param  array $cfg  config.php ['redirect'] block.
 * @return string      Absolute or relative URL, safe to hand to a browser.
 */
function redirect_build_url(array $row, array $cfg): string
{
    $base   = (string) ($cfg['base'] ?? 'thank-you.php');
    $params = $cfg['params'] ?? [];

    $query = [];
    foreach ($params as $param => $rowKey) {
        // Numeric key means the config listed a bare field name rather than a
        // "param => field" pair; then the param is named after the field.
        if (is_int($param)) {
            $param = $rowKey;
        }

        $value = $row[$rowKey] ?? null;

        // Skip anything the visitor didn't answer. An empty param is noise at
        // best, and at worst reads downstream as a deliberate blank answer.
        if ($value === null || $value === '') {
            continue;
        }

        $query[$param] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    if ($query === []) {
        return $base;
    }

    // Respect a base that already carries a query string (or a bare fragment).
    [$path, $fragment] = array_pad(explode('#', $base, 2), 2, null);
    $separator = str_contains((string) $path, '?') ? '&' : '?';
    $url = $path . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    return $fragment !== null ? $url . '#' . $fragment : $url;
}
