<?php

/**
 * Student-debt extraction probe — replay a STORED Equifax report through the
 * exact gates that decide `student_debt`, and print what each trade line hit.
 *
 * Exists because a student_debt of 0 on the LeadProsper post has several very
 * different causes that look identical from outside, and the response body is
 * already sitting in equifax_logs. Rather than guess, this replays it:
 *
 *   - 0 because the report parsed and NO line matched equifax_trade_is_student()
 *     — the narrow rule missed a loan. The per-trade table below shows which
 *     line and which gate rejected it.
 *   - 0 because the loans matched but every balance really is 0 (deferred and
 *     in-school loans routinely report that way). Correct, nothing to fix.
 *   - NOT ACTUALLY 0: null never reaches the post at all. leadprosper_payload()
 *     ends in array_filter(fn($v) => $v !== '' && $v !== null), so a null
 *     student_debt is DROPPED from the JSON, and a field LeadProsper never
 *     received can render as 0 in their UI. This probe distinguishes the two,
 *     which the LP screen cannot.
 *
 * Read-only: it runs no credit pull, spends no Equifax quota, and writes
 * nothing. Safe against production.
 *
 *   php bin/equifax-student-probe.php              # latest stored report
 *   php bin/equifax-student-probe.php --lead=1234  # one lead
 *   php bin/equifax-student-probe.php --id=99      # one equifax_logs row
 *   php bin/equifax-student-probe.php --limit=5    # last 5 reports
 *   php bin/equifax-student-probe.php --file=r.json  # a raw report on disk
 *
 * --file is the one to reach for when the body was never stored, or when
 * Equifax support sends a sample: it takes the report JSON exactly as Equifax
 * returned it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/equifax.php';

// ---------------------------------------------------------------- arguments
$opts = getopt('', ['lead::', 'id::', 'limit::', 'file::', 'raw']);
$limit = max(1, (int) ($opts['limit'] ?? 1));

/** @return array<int,array{label:string,body:string}> */
$loadReports = static function () use ($opts, $cfg, $limit): array {
    if (isset($opts['file'])) {
        $path = (string) $opts['file'];
        if (!is_file($path)) {
            fwrite(STDERR, "No such file: {$path}\n");
            exit(1);
        }
        return [['label' => $path, 'body' => (string) file_get_contents($path)]];
    }

    $where = 'response_body IS NOT NULL AND response_body <> ""';
    $args  = [];
    if (isset($opts['lead'])) {
        $where .= ' AND lead_id = :lead';
        $args['lead'] = (int) $opts['lead'];
    }
    if (isset($opts['id'])) {
        $where .= ' AND id = :id';
        $args['id'] = (int) $opts['id'];
    }

    $stmt = db($cfg)->prepare(
        "SELECT id, lead_id, mode, response_status, response_body, created_at
           FROM equifax_logs
          WHERE {$where}
          ORDER BY id DESC
          LIMIT {$limit}"
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'label' => sprintf(
                'equifax_logs id=%s lead=%s mode=%s http=%s at=%s',
                $r['id'], $r['lead_id'], $r['mode'], $r['response_status'], $r['created_at']
            ),
            'body' => (string) $r['response_body'],
        ];
    }
    return $out;
};

/**
 * Every trade line anywhere in the report, flattened — the same walk
 * equifax_sum_trade_balances() does, but collecting instead of summing so each
 * line can be shown with the verdict it got.
 *
 * @return array<int,array<string,mixed>>
 */
function probe_collect_trades($node): array
{
    $found = [];
    if (!is_array($node)) {
        return $found;
    }
    foreach ($node as $key => $value) {
        if (is_string($key)
            && in_array(strtolower($key), ['trades', 'tradelines', 'accounts'], true)
            && is_array($value)) {
            foreach ($value as $trade) {
                if (is_array($trade)) {
                    $found[] = $trade;
                }
            }
        } elseif (is_array($value)) {
            $found = array_merge($found, probe_collect_trades($value));
        }
    }
    return $found;
}

/** Flatten a bare-code-or-{code,description} field for display. */
function probe_show($field): string
{
    if (is_array($field)) {
        return trim(((string) ($field['code'] ?? $field['identifier'] ?? '')) . '/' .
                    ((string) ($field['description'] ?? $field['value'] ?? '')), '/');
    }
    return (string) $field;
}

// -------------------------------------------------------------------- report
$reports = $loadReports();
if ($reports === []) {
    fwrite(STDERR, "No stored Equifax reports matched. Try --limit, --lead or --file.\n");
    exit(1);
}

foreach ($reports as $report) {
    echo str_repeat('=', 78), "\n", $report['label'], "\n", str_repeat('=', 78), "\n";

    $parsed = json_decode($report['body'], true);
    if (!is_array($parsed)) {
        /* The one path that yields NULL rather than 0, and therefore the one
           where LeadProsper is sent no student_debt key at all. */
        echo "Report did NOT decode as JSON.\n";
        echo "  => equifax_extract_total_student_debt() returns NULL\n";
        echo "  => student_debt is DROPPED from the LeadProsper payload entirely.\n";
        echo "     A 0 on the LP screen here means 'never received', not 'zero'.\n\n";
        continue;
    }

    $trades = probe_collect_trades($parsed);
    printf("Trade lines found: %d\n\n", count($trades));

    if ($trades === []) {
        echo "  No trades/tradelines/accounts array anywhere in this report.\n";
        echo "  Either the consumer is thin-file, or Equifax nested the lines under\n";
        echo "  a key this walk does not recognise — check the raw body with --raw.\n\n";
    }

    printf("%-4s %-22s %-22s %-12s %-9s %s\n",
        '#', 'accountType', 'portfolioType', 'balance', 'student?', 'why');
    echo str_repeat('-', 100), "\n";

    $studentTotal = 0.0;
    $studentLines = 0;
    $zeroBalance  = 0;

    foreach ($trades as $i => $trade) {
        $balanceRaw = $trade['balanceAmount'] ?? ($trade['balance'] ?? ($trade['currentBalance'] ?? null));
        $isStudent  = equifax_trade_is_student($trade);

        // Reproduce the gate that decided it, for the 'why' column.
        [$code] = equifax_code_pair($trade['accountType'] ?? ($trade['accountTypeCode'] ?? ''));
        if ($code === '12') {
            $why = 'accountType 12';
        } elseif ($code === '01') {
            $why = 'accountType 01 -> never student';
        } else {
            $hasBu = false;
            foreach ((array) ($trade['narrativeCodes'] ?? []) as $n) {
                [$nCode] = equifax_code_pair($n);
                if ($nCode === 'BU') {
                    $hasBu = true;
                }
            }
            $why = $hasBu
                ? 'narrative BU'
                : ($isStudent ? 'phrase match' : 'no 12 / no BU / no exact phrase');
        }

        if ($isStudent) {
            $studentLines++;
            if (is_numeric($balanceRaw) && (float) $balanceRaw > 0) {
                $studentTotal += (float) $balanceRaw;
            } else {
                $zeroBalance++;
            }
        }

        printf("%-4d %-22s %-22s %-12s %-9s %s\n",
            $i + 1,
            substr(probe_show($trade['accountType'] ?? ($trade['accountTypeCode'] ?? '')), 0, 22),
            substr(probe_show($trade['portfolioType'] ?? ($trade['portfolioTypeCode'] ?? '')), 0, 22),
            $balanceRaw === null ? '(none)' : (string) $balanceRaw,
            $isStudent ? 'YES' : 'no',
            $why
        );
    }

    $extracted = equifax_extract_total_student_debt($parsed);
    $unsecured = equifax_extract_total_debt($parsed);

    echo "\n";
    printf("student lines matched : %d (of %d trades)\n", $studentLines, count($trades));
    printf("matched but 0 balance : %d\n", $zeroBalance);
    printf("student_debt extracted: %s\n", $extracted === null ? 'NULL' : (string) $extracted);
    printf("total_debt (unsecured): %s\n", $unsecured === null ? 'NULL' : (string) $unsecured);

    echo "\nWhat LeadProsper receives:\n";
    if ($extracted === null) {
        echo "  student_debt DROPPED from the payload (array_filter removes null).\n";
        echo "  A 0 on the LP screen is 'never sent', not a verified zero.\n";
    } else {
        printf("  student_debt = %d  (sent explicitly; 0 survives array_filter)\n", $extracted);
        if ($extracted === 0 && $studentLines > 0) {
            echo "  Lines matched but carried no positive balance — deferred or paid-off\n";
            echo "  loans report this way. The 0 is correct.\n";
        } elseif ($extracted === 0) {
            echo "  NO line matched equifax_trade_is_student(). The rule is deliberately\n";
            echo "  narrow (see its docblock): accountType 12, narrative BU, or the exact\n";
            echo "  phrases 'student loan'/'education loan'. A description reading only\n";
            echo "  'Education' does NOT match. Check the table above for the real text.\n";
        }
    }
    echo "\n";

    if (isset($opts['raw'])) {
        echo "---- raw body ----\n", $report['body'], "\n\n";
    }
}
