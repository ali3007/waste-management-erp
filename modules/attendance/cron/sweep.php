<?php
/**
 * Attendance scheduler entry point.
 *
 *   CLI (preferred):   php modules/attendance/cron/sweep.php [--status]
 *   URL (only for hosts without CLI cron):  .../cron/sweep.php?token=TOKEN
 *
 * Run every 5 minutes. Idempotent and self-healing: overlapping runs are
 * prevented by a database lock, and any day missed during downtime is finalised
 * on the next run. Testing only (command line): --now="2026-10-23 23:59:00".
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    exit(PHP_SAPI === 'cli' ? "Not installed.\n" : 'Not installed.');
}

if (PHP_SAPI === 'cli') {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--now=')) {
            att_set_test_clock(substr($a, 6));
        }
    }
    if (in_array('--status', $argv, true)) {
        echo 'Clock (Asia/Karachi): ' . att_now_sql() . "\nDays waiting to be finalised: " . implode(', ', att_pending_dates() ?: ['none']) . "\n";
        foreach (att_recent_job_runs(5) as $r) {
            echo "  {$r['attendance_date']}  {$r['status']}  rows={$r['rows_affected']}  attempts={$r['attempts']}  {$r['error_message']}\n";
        }
        exit(0);
    }
    $r = att_sweep();
    echo ($r['ran'] ? 'OK' : 'SKIPPED') . ' ' . att_now_sql() . ' finalised=' . count($r['days']) . ' new_rows=' . $r['today_rows'] . ' changes=' . $r['today_changes'] . ($r['reason'] ? ' reason=' . $r['reason'] : '') . "\n";
    foreach ($r['days'] as $d) {
        if (!$d['ok']) {
            fwrite(STDERR, "FAILED {$d['date']}: {$d['error']}\n");
        }
    }
    exit($r['ran'] ? 0 : 1);
}

/* URL mode: constant-time token check; no session, no login. */
$hash = att_setting('attendance_cron_token_hash');
$given = (string)($_GET['token'] ?? '');
if ($hash === null || $given === '' || !hash_equals($hash, Security::hashToken($given))) {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
$r = att_sweep();
echo $r['ran'] ? 'OK' : 'SKIPPED';
