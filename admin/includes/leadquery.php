<?php

/**
 * The lead list query — filters, pagination, counting.
 *
 * Shared by leads.php (the table) and export.php (the CSV), so an export always
 * covers exactly the rows the operator is looking at. Duplicating the WHERE
 * building in both would guarantee they drift.
 *
 * Every filter value is bound, never interpolated. The only strings that reach
 * the SQL text are chosen from fixed lists in this file.
 */

declare(strict_types=1);

if (!function_exists('portal_lead_filters')) {

    /**
     * Read + normalise the filters from the query string.
     *
     * Returns a canonical array so leads.php, export.php and the audit trail all
     * describe the same filter the same way.
     */
    function portal_lead_filters(array $query): array
    {
        $status = (string) ($query['status'] ?? '');
        if (!in_array($status, ['accepted', 'rejected', 'not_posted'], true)) {
            $status = '';
        }

        /* Dates are validated to Y-m-d rather than passed through: an invalid
           date would otherwise become a silently-empty comparison that quietly
           widens the result set instead of erroring. */
        $date = static function (?string $v): string {
            $v = trim((string) $v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
        };

        return [
            'q'          => trim((string) ($query['q'] ?? '')),
            'from'       => $date($query['from'] ?? ''),
            'to'         => $date($query['to'] ?? ''),
            'status'     => $status,
            'affid'      => trim((string) ($query['affid'] ?? '')),
            'utm_source' => trim((string) ($query['utm_source'] ?? '')),
            // Bots are hidden by default: they are noise in a work queue, and
            // they are never posted anywhere.
            'show_bots'  => !empty($query['show_bots']),
        ];
    }

    /**
     * Build the WHERE clause and its bound parameters.
     *
     * @return array{sql:string, params:array}
     */
    function portal_lead_where(array $f): array
    {
        $where  = [];
        $params = [];

        if ($f['q'] !== '') {
            /* One box, several meanings — the way an operator actually searches:
               paste an email, a phone, a name, or a lead id. A numeric term also
               tries the id, so "18" finds lead 18 as well as any phone containing
               it. Phone is matched on digits only so "(929) 421-6278" and
               "9294216278" both hit. */
            $digits = preg_replace('/\D/', '', $f['q']) ?? '';
            $like   = '%' . $f['q'] . '%';

            $clause = '(email LIKE :q_email OR first_name LIKE :q_first OR last_name LIKE :q_last';
            $params['q_email'] = $like;
            $params['q_first'] = $like;
            $params['q_last']  = $like;

            if ($digits !== '') {
                $clause .= ' OR REPLACE(REPLACE(REPLACE(REPLACE(phone, "+", ""), "-", ""), "(", ""), ")", "") LIKE :q_phone';
                $params['q_phone'] = '%' . $digits . '%';
            }
            if (ctype_digit($f['q'])) {
                $clause .= ' OR id = :q_id';
                $params['q_id'] = (int) $f['q'];
            }
            $where[] = $clause . ')';
        }

        if ($f['from'] !== '') {
            $where[] = 'created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            // Inclusive of the whole end day — an operator picking the same date
            // for both ends means "that day", not "nothing".
            $where[] = 'created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }

        switch ($f['status']) {
            case 'accepted':
                $where[] = 'lp_accepted = 1';
                break;
            case 'rejected':
                // Posted and turned down — distinct from never having been sent.
                $where[] = 'lp_status IS NOT NULL AND (lp_accepted = 0 OR lp_accepted IS NULL)';
                break;
            case 'not_posted':
                $where[] = 'lp_status IS NULL AND lp_posted_at IS NULL';
                break;
        }

        if ($f['affid'] !== '') {
            $where[] = 'affid = :affid';
            $params['affid'] = $f['affid'];
        }
        if ($f['utm_source'] !== '') {
            $where[] = 'utm_source = :utm_source';
            $params['utm_source'] = $f['utm_source'];
        }
        if (!$f['show_bots']) {
            $where[] = 'bot_suspected = 0';
        }

        return [
            'sql'    => $where ? ' WHERE ' . implode(' AND ', $where) : '',
            'params' => $params,
        ];
    }

    /** Total matching rows, for the pager. */
    function portal_lead_count(PDO $pdo, array $filters): int
    {
        $w    = portal_lead_where($filters);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM leads' . $w['sql']);
        $stmt->execute($w['params']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of leads.
     *
     * An explicit column list, never SELECT * — `consent_text` is a TEXT column
     * on every row and `landing_page_url` runs to 512 chars; pulling them for a
     * 50-row list is wasted for data the table never shows.
     *
     * LIMIT/OFFSET are cast to int and interpolated because MySQL will not take
     * them as bound parameters when emulated prepares are off (includes/db.php).
     * Both are integers by construction here — never strings from the request.
     */
    function portal_lead_page(PDO $pdo, array $filters, int $limit, int $offset): array
    {
        $w = portal_lead_where($filters);

        $sql = 'SELECT id, created_at, first_name, last_name, email, phone,
                       city, state, zip, debt_amount, total_debt, total_debt_source,
                       affid, source_id, sub1, utm_source, utm_campaign,
                       lp_status, lp_accepted, lp_accepted_buyer, lp_error, lp_posted_at, lp_mode,
                       jgw_status, jgw_disposition, jgw_total_debt,
                       bot_suspected, bot_reason, phone_verified
                  FROM leads' . $w['sql'] . '
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($w['params']);
        return $stmt->fetchAll();
    }

    /** Distinct affids / utm_sources, for the filter dropdowns. */
    function portal_lead_facets(PDO $pdo): array
    {
        $facet = static function (PDO $pdo, string $column): array {
            try {
                $rows = $pdo->query(
                    "SELECT DISTINCT {$column} v FROM leads
                      WHERE {$column} IS NOT NULL AND {$column} <> ''
                      ORDER BY {$column} LIMIT 100"
                )->fetchAll();
                return array_column($rows, 'v');
            } catch (Throwable $ex) {
                return [];
            }
        };
        // Column names are literals here, not request input.
        return [
            'affid'      => $facet($pdo, 'affid'),
            'utm_source' => $facet($pdo, 'utm_source'),
        ];
    }

    /** One-line description of the active filter, for the audit trail. */
    function portal_filter_summary(array $f): string
    {
        $parts = [];
        foreach (['q', 'from', 'to', 'status', 'affid', 'utm_source'] as $key) {
            if (($f[$key] ?? '') !== '') {
                $parts[] = $key . '=' . $f[$key];
            }
        }
        if ($f['show_bots']) {
            $parts[] = 'show_bots=1';
        }
        return $parts ? implode(' ', $parts) : 'no filter';
    }
}
