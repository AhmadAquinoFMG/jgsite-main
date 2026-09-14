<?php

/**
 * The post log query — which call logs exist, and how they are filtered,
 * counted, paged and summarised.
 *
 * Shared by posts.php (the portal-wide log) and lead.php (one lead's log), so
 * the two screens name their destinations the same way and neither has to
 * hard-code the list of log tables. Adding a destination later is one entry in
 * portal_post_sources() and nothing else.
 *
 * Every filter value is bound. The only strings interpolated into the SQL text
 * are the table and source names below — literals in this file, never request
 * input — and the LIMIT/OFFSET integers, which MySQL will not accept as bound
 * parameters while emulated prepares are off (includes/db.php).
 */

declare(strict_types=1);

if (!function_exists('portal_post_sources')) {

    /**
     * The call logs the portal reads, in the order they are offered.
     *
     * `accepted` says whether the table has an `accepted` column: LeadProsper
     * and JG Scoring record a verdict, the (legacy, no longer written) Equifax
     * pull only ever had an HTTP result. That flag drives both the SELECT and
     * which sources an accepted/rejected filter can possibly match.
     *
     * @return array<string, array{label:string, table:string, accepted:bool}>
     */
    function portal_post_sources(): array
    {
        return [
            'leadprosper' => ['label' => 'LeadProsper', 'table' => 'leadprosper_logs', 'accepted' => true],
            'jgscoring'   => ['label' => 'JG Scoring',  'table' => 'jgscoring_logs',   'accepted' => true],
            'equifax'     => ['label' => 'Equifax',     'table' => 'equifax_logs',     'accepted' => false],
        ];
    }

    /**
     * The log tables this database actually has.
     *
     * Same reasoning as portal_lead_columns(): environments run different
     * migrations, and a UNION naming a table that is not there fails the whole
     * query rather than skipping that one destination. An environment that never
     * ran the Equifax migration should still get a post log.
     *
     * Read once per request from the database's own catalogue.
     */
    function portal_post_tables(PDO $pdo): array
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }
        $stmt = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()"
        );
        $tables = array_column($stmt->fetchAll(), 'TABLE_NAME');
        return $tables;
    }

    /** Read + normalise the post log filters from the query string. */
    function portal_post_filters(array $query): array
    {
        $source = (string) ($query['source'] ?? '');
        if (!array_key_exists($source, portal_post_sources())) {
            $source = '';
        }

        $outcome = (string) ($query['outcome'] ?? '');
        if (!in_array($outcome, ['accepted', 'rejected', 'failed'], true)) {
            $outcome = '';
        }

        /* Mode is not validated against a fixed list: the values in the log
           tables are whatever the integrations wrote — 'live', 'test', 'mock',
           'production' — and a whitelist here would quietly drop the filter for
           any value someone adds later. It is bound, never interpolated, and the
           dropdown is built from the values actually present
           (portal_post_modes()), so an unknown one simply matches nothing. */
        $mode = substr(trim((string) ($query['mode'] ?? '')), 0, 20);

        // Validated, not passed through — an invalid date would otherwise become
        // a comparison that silently widens the result set instead of erroring.
        $date = static function (?string $v): string {
            $v = trim((string) $v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
        };

        return [
            'source'  => $source,
            'outcome' => $outcome,
            'mode'    => $mode,
            'from'    => $date($query['from'] ?? ''),
            'to'      => $date($query['to'] ?? ''),
            'lead'    => max(0, (int) ($query['lead'] ?? 0)),
        ];
    }

    /** An unfiltered filter set — the shape every query builder here expects. */
    function portal_post_no_filter(): array
    {
        return portal_post_filters([]);
    }

    /**
     * The sources a query should actually touch: present in this database,
     * matching the destination filter, and capable of the outcome asked for.
     *
     * An accepted/rejected filter drops Equifax rather than matching nothing in
     * it — the column does not exist there, so `accepted = 1` is an error, not
     * an empty set.
     *
     * @return array<string, array{label:string, table:string, accepted:bool}>
     */
    function portal_post_active_sources(PDO $pdo, array $f): array
    {
        $tables = portal_post_tables($pdo);
        $out    = [];

        foreach (portal_post_sources() as $key => $src) {
            if ($f['source'] !== '' && $f['source'] !== $key) {
                continue;
            }
            if (!in_array($src['table'], $tables, true)) {
                continue;
            }
            if (!$src['accepted'] && in_array($f['outcome'], ['accepted', 'rejected'], true)) {
                continue;
            }
            $out[$key] = $src;
        }

        return $out;
    }

    /**
     * The `mode` values these logs actually contain, for the filter dropdown.
     *
     * Read from the data rather than hard-coded for the reason above: the
     * Equifax rows say 'production' where the newer integrations say 'live'.
     */
    function portal_post_modes(PDO $pdo): array
    {
        $modes = [];
        foreach (portal_post_active_sources($pdo, portal_post_no_filter()) as $src) {
            try {
                $rows = $pdo->query(
                    "SELECT DISTINCT mode v FROM `{$src['table']}`
                      WHERE mode IS NOT NULL AND mode <> '' ORDER BY mode LIMIT 20"
                )->fetchAll();
                foreach (array_column($rows, 'v') as $mode) {
                    $modes[(string) $mode] = true;
                }
            } catch (Throwable $ex) {
                // A log table without a `mode` column just contributes nothing.
            }
        }
        $out = array_keys($modes);
        sort($out);
        return $out;
    }

    /**
     * WHERE clause + bound parameters for one source.
     *
     * $tag suffixes every placeholder because the branches are unioned into a
     * single statement and each carries its own copy of the same filters.
     *
     * @return array{sql:string, params:array}
     */
    function portal_post_where(array $f, array $source, string $tag): array
    {
        $where  = [];
        $params = [];

        if ($f['from'] !== '') {
            $where[] = "created_at >= :from_{$tag}";
            $params["from_{$tag}"] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            // Inclusive of the whole end day, as on the lead list.
            $where[] = "created_at <= :to_{$tag}";
            $params["to_{$tag}"] = $f['to'] . ' 23:59:59';
        }
        if ($f['lead'] > 0) {
            $where[] = "lead_id = :lead_{$tag}";
            $params["lead_{$tag}"] = $f['lead'];
        }
        if ($f['mode'] !== '') {
            $where[] = "mode = :mode_{$tag}";
            $params["mode_{$tag}"] = $f['mode'];
        }

        switch ($f['outcome']) {
            case 'accepted':
                $where[] = 'accepted = 1';
                break;
            case 'rejected':
                /* Turned down by the buyer, which is not the same as failing to
                   reach them: LeadProsper answers 200 with {"status":"ERROR"}
                   for a rejected lead, so this reads the verdict column and not
                   the status code. A NULL verdict on a 2xx means the response
                   could not be parsed — an unknown, not a rejection — so it is
                   deliberately excluded. */
                $where[] = 'accepted = 0';
                break;
            case 'failed':
                // Never got a usable answer at all: no response, or an error status.
                $where[] = '(response_status IS NULL OR response_status = 0 OR response_status >= 300)';
                break;
        }

        return [
            'sql'    => $where ? ' WHERE ' . implode(' AND ', $where) : '',
            'params' => $params,
        ];
    }

    /**
     * Total matching attempts, for the pager.
     *
     * One COUNT per source rather than a COUNT over the union: each count is
     * satisfied from that table's own indexes, and summing them here avoids
     * building the merged result set just to number it.
     */
    function portal_post_count(PDO $pdo, array $f): int
    {
        $total = 0;
        foreach (portal_post_active_sources($pdo, $f) as $key => $src) {
            $w    = portal_post_where($f, $src, 'c_' . $key);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $src['table'] . '`' . $w['sql']);
            $stmt->execute($w['params']);
            $total += (int) $stmt->fetchColumn();
        }
        return $total;
    }

    /**
     * One page of attempts across every active source, newest first.
     *
     * Each branch is capped at limit+offset before the union: the outer ORDER BY
     * can never need more rows from any one table than that, and the cap keeps a
     * table with millions of rows from being sorted in full to render fifty.
     *
     * A lone branch is emitted unparenthesised — MySQL will not take an outer
     * ORDER BY on a single parenthesised SELECT.
     */
    function portal_post_page(PDO $pdo, array $f, int $limit, int $offset): array
    {
        $sources = portal_post_active_sources($pdo, $f);
        if (!$sources) {
            return [];
        }

        $cap    = $limit + $offset;
        $cores  = [];
        $params = [];

        foreach ($sources as $key => $src) {
            $w = portal_post_where($f, $src, 's_' . $key);
            /* `accepted` is absent on a source that never recorded a verdict;
               NULL keeps the branches union-compatible and reads the same way to
               portal_post_status(). */
            $accepted = $src['accepted'] ? 'accepted' : 'NULL';

            $cores[] = "SELECT '{$key}' AS source, id, lead_id, mode, response_status,
                       {$accepted} AS accepted, error, duration_ms, created_at
                  FROM `{$src['table']}`" . $w['sql'];
            $params += $w['params'];
        }

        $order = ' ORDER BY created_at DESC, id DESC';

        if (count($cores) === 1) {
            $sql = $cores[0] . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        } else {
            $branches = array_map(
                static fn(string $core): string => '(' . $core . $order . ' LIMIT ' . (int) $cap . ')',
                $cores
            );
            $sql = implode(' UNION ALL ', $branches)
                . $order . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        /* The payloads come in a second pass, over the rows that survived the
           ordering — see portal_post_hydrate_bodies(). */
        return portal_post_hydrate_bodies($pdo, portal_post_label_rows($stmt->fetchAll()));
    }

    /**
     * Attach `request_body` and `response_body` to rows already chosen.
     *
     * Deliberately a second query rather than two more columns in the one above.
     * The bodies are LONGTEXT — on the legacy equifax_logs rows the response is
     * an entire credit report — and each branch of that union is capped at
     * limit+offset, so selecting them there would drag the payloads of every row
     * on every earlier page through the sort just to throw them away. This pass
     * touches exactly the rows being rendered, by primary key.
     */
    function portal_post_hydrate_bodies(PDO $pdo, array $rows): array
    {
        if (!$rows) {
            return $rows;
        }

        $sources = portal_post_sources();

        // Group the ids by the table they came from — one query per source.
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row['source']][] = (int) $row['id'];
        }

        $bodies = [];
        foreach ($ids as $key => $list) {
            if (!isset($sources[$key])) {
                continue;
            }
            /* Ints by construction (cast just above), and every one of them came
               out of this same table's id column a moment ago — nothing from the
               request reaches this list. */
            $in   = implode(', ', array_map('intval', $list));
            $stmt = $pdo->query(
                "SELECT id, request_body, response_body
                   FROM `{$sources[$key]['table']}` WHERE id IN ({$in})"
            );
            foreach ($stmt->fetchAll() as $body) {
                $bodies[$key . ':' . (int) $body['id']] = $body;
            }
        }

        foreach ($rows as &$row) {
            $body = $bodies[$row['source'] . ':' . (int) $row['id']] ?? null;
            $row['request_body']  = $body['request_body']  ?? null;
            $row['response_body'] = $body['response_body'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Every attempt for one lead, newest first, with the payloads.
     *
     * lead.php exists to show what was sent and what came back, so this one
     * takes the whole body where the list takes a prefix. Sources missing from
     * this database are skipped rather than failing the page.
     */
    function portal_post_lead_logs(PDO $pdo, int $leadId): array
    {
        $sources = portal_post_active_sources($pdo, portal_post_no_filter());
        if (!$sources) {
            return [];
        }

        $cores  = [];
        $params = [];

        foreach ($sources as $key => $src) {
            $accepted = $src['accepted'] ? 'accepted' : 'NULL';
            $cores[]  = "SELECT '{$key}' AS source, id, mode, request_body, response_status,
                        response_body, {$accepted} AS accepted, error, duration_ms, created_at
                   FROM `{$src['table']}` WHERE lead_id = :lead_{$key}";
            $params["lead_{$key}"] = $leadId;
        }

        $stmt = $pdo->prepare(implode(' UNION ALL ', $cores) . ' ORDER BY created_at DESC, id DESC');
        $stmt->execute($params);

        return portal_post_label_rows($stmt->fetchAll());
    }

    /**
     * Attach the display label to each row.
     *
     * Done in PHP rather than as a literal in the SELECT so the label lives in
     * exactly one place — renaming a destination should not mean editing SQL.
     */
    function portal_post_label_rows(array $rows): array
    {
        $sources = portal_post_sources();
        foreach ($rows as &$row) {
            $row['destination'] = $sources[$row['source']]['label'] ?? (string) $row['source'];
        }
        unset($row);
        return $rows;
    }

    /**
     * The DOM id of one attempt on the lead page, so the list can link straight
     * to it instead of to the top of a lead with nine attempts.
     */
    function portal_post_anchor(array $row): string
    {
        return 'post-' . (string) $row['source'] . '-' . (int) $row['id'];
    }

    /**
     * The one line worth showing in a list: why this attempt ended how it did.
     *
     * Preference order is the order an operator would read them — the buyer's
     * own reason first, our transport error only when there is no reason because
     * nothing came back.
     */
    function portal_post_detail(array $row): string
    {
        $body    = (string) ($row['response_body'] ?? '');
        $summary = portal_lp_summary($body);
        $message = $summary['message'];

        if ($message === null && $body !== '') {
            /* A response that is not valid JSON — an upstream error page, a body
               cut short by a timeout — will not decode, but the message is often
               still in there as text, and it is the single most useful string on
               the row. Put back through JSON so
               \u escapes and quotes come out as they were written. */
            if (preg_match('/"message"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $m) === 1) {
                $decoded = json_decode('"' . $m[1] . '"');
                $message = is_string($decoded) ? $decoded : $m[1];
            }
        }

        $parts = array_values(array_filter(
            [$summary['status'], $message],
            static fn(?string $v): bool => $v !== null && $v !== ''
        ));

        if (!$parts && ($row['error'] ?? '') !== '') {
            $parts[] = (string) $row['error'];
        }

        return implode(' — ', $parts);
    }

    /** One-line description of the active filter, for the page subheading. */
    function portal_post_filter_summary(array $f): string
    {
        $parts = [];
        foreach (['source', 'outcome', 'mode', 'from', 'to'] as $key) {
            if (($f[$key] ?? '') !== '') {
                $parts[] = $key . '=' . $f[$key];
            }
        }
        if ($f['lead'] > 0) {
            $parts[] = 'lead=' . $f['lead'];
        }
        return $parts ? implode(' ', $parts) : 'no filter';
    }
}
