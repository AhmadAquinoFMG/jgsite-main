<?php

/**
 * Operational logger — structured, file-based, no Composer.
 *
 * Complements the DATA tables (`leads`, `equifax_logs`): this is an ops/audit
 * trail on disk for debugging the lead → verify → store → Equifax pipeline.
 * One JSON object per line, daily-rotated, UTC timestamps.
 *
 *   logger($cfg);                              // init once (submit.php)
 *   app_log('info',  'lead',     'received',  ['rid' => $rid]);
 *   app_log('error', 'equifax',  'http_error',['status' => 500]);
 *
 * Levels: debug < info < warning < error. Lines below config logging.level are
 * dropped. Logging must NEVER break a request — every write is best-effort and
 * swallows its own errors.
 *
 * PII: callers pass only non-sensitive context (ids, status codes, buckets,
 * phone last-4). SSNs, ID tokens, emails and full DOBs are never logged here —
 * raw request/response bodies live in equifax_logs, not in this file.
 */

if (!function_exists('app_log')) {

    if (!defined('LOG_LEVELS')) {
        define('LOG_LEVELS', ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40]);
    }

    /** Initialise (pass $cfg once) or read back the logger state. */
    function logger(?array $cfg = null): array
    {
        static $state = null;
        if ($cfg !== null) {
            $lg = $cfg['logging'] ?? [];
            $state = [
                'dir'   => $lg['dir']   ?? (__DIR__ . '/../logs'),
                'level' => LOG_LEVELS[strtolower($lg['level'] ?? 'info')] ?? 20,
            ];
        }
        return $state ?? ['dir' => __DIR__ . '/../logs', 'level' => 20];
    }

    /**
     * Append one structured line to logs/app-YYYY-MM-DD.log.
     *
     * @param string $level   debug|info|warning|error
     * @param string $channel logical stream, e.g. 'lead', 'equifax', 'firebase'
     * @param string $message short event slug, e.g. 'received', 'stored'
     * @param array  $context extra non-PII fields (rid, ids, status, …)
     */
    function app_log(string $level, string $channel, string $message, array $context = []): void
    {
        try {
            $state = logger();
            $weight = LOG_LEVELS[$level] ?? 20;
            if ($weight < $state['level']) {
                return; // below the configured threshold
            }

            $dir = $state['dir'];
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $entry = [
                'ts'      => gmdate('c'),      // UTC ISO-8601
                'level'   => $level,
                'channel' => $channel,
                'msg'     => $message,
            ] + ($context ? ['ctx' => $context] : []);

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }
            @file_put_contents(
                $dir . '/app-' . gmdate('Y-m-d') . '.log',
                $line . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
            // Never let logging break the request.
        }
    }
}
