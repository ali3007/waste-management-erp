<?php
/**
 * Attendance sweep — the scheduled processor.
 *
 * Every run (cron every 5 minutes, plus a throttled run when a page opens, so
 * XAMPP works without cron) does, in order:
 *   1. recovery   — for every attendance date that has ended (before today,
 *                   Asia/Karachi) and has no SUCCESS record yet: create any
 *                   missing rows, apply the window closures, then finalise.
 *   2. today      — create today's rows for every tracked, active employee and
 *                   move cases whose window has closed into Pending Review /
 *                   Incomplete.
 *
 * Finalisation (at 12:00 AM Asia/Karachi, i.e. as soon as the date has ended):
 * every case still unresolved becomes Absent. Leave, Off Day, Holiday and valid
 * Present rows are never touched. Because each UPDATE is guarded by the row's
 * current status, repeating a run changes nothing the second time (idempotent);
 * a failed date is recorded as failed and retried on the next run. Nothing here
 * ever reopens a Check-in or Check-out window.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const ATT_SWEEP_LOCK = 'swms_attendance_sweep';
const ATT_RECOVERY_DAYS = 90;

/**
 * Timing groups for a date: which rows follow which window set. Built from the
 * (category, UC) combinations that actually have rows, each resolved with the SAME
 * att_config_for() the marking screens use — so the scheduler can never apply a
 * different window from the one an employee was shown.
 *
 * @return array<int,array{where:string,cfg:array}>
 */
function att_window_groups(string $date): array
{
    if (att_company_config($date) === null) {
        return [];
    }
    $combos = Database::fetchAll('SELECT DISTINCT staff_pool_id, uc_id FROM attendance_records WHERE attendance_date = :d', ['d' => $date]);
    $byTuple = [];
    foreach ($combos as $c) {
        $pool = (int)$c['staff_pool_id'];
        $uc = $c['uc_id'] !== null ? (int)$c['uc_id'] : null;
        $cfg = att_config_for($pool, $uc, $date);
        if ($cfg === null) {
            continue;
        }
        $key = implode('|', [$cfg['checkin_start'], $cfg['checkin_end'], $cfg['checkout_start'], $cfg['checkout_end']]);
        $byTuple[$key]['cfg'] = $cfg;
        $byTuple[$key]['pools'][$pool][] = $uc;
    }
    $groups = [];
    foreach ($byTuple as $g) {
        $parts = [];
        foreach ($g['pools'] as $pool => $ucs) {
            $ids = array_values(array_filter($ucs, static fn($u) => $u !== null));
            $sub = [];
            if ($ids) {
                $sub[] = 'a.uc_id IN (' . att_in($ids) . ')';
            }
            if (in_array(null, $ucs, true)) {
                $sub[] = 'a.uc_id IS NULL';
            }
            $parts[] = '(a.staff_pool_id = ' . (int)$pool . ' AND (' . implode(' OR ', $sub) . '))';
        }
        $groups[] = ['where' => '(' . implode(' OR ', $parts) . ')', 'cfg' => $g['cfg']];
    }
    return $groups;
}

/**
 * Move rows matching $where from one status to another, auditing each one.
 * Guarded per row by its current status, so a repeat run is a no-op.
 *
 * @param array<string,mixed> $set  columns to set
 */
function att_transition(string $date, string $where, string $fromStatus, array $set, string $action, string $describe, array $extraAuditNew = []): int
{
    $rows = Database::fetchAll(
        "SELECT a.id, a.employee_id, a.uc_id, a.status FROM attendance_records a
          WHERE a.attendance_date = :d AND a.status = :s AND a.finalized_at IS NULL AND {$where}
          FOR UPDATE",
        ['d' => $date, 's' => $fromStatus]
    );
    $n = 0;
    $now = att_now_sql();
    foreach ($rows as $r) {
        $cols = [];
        $params = ['id' => (int)$r['id'], 'from' => $fromStatus, 'now' => $now];
        foreach ($set as $k => $v) {
            $cols[] = "`{$k}` = :s_{$k}";
            $params['s_' . $k] = $v;
        }
        $changed = Database::execute(
            'UPDATE attendance_records SET ' . implode(', ', $cols) . ', updated_at = :now WHERE id = :id AND status = :from AND finalized_at IS NULL',
            $params
        );
        if ($changed === 1) {
            $n++;
            att_audit($action, $describe, [
                'employee_id' => (int)$r['employee_id'], 'date' => $date, 'uc_id' => $r['uc_id'] !== null ? (int)$r['uc_id'] : null,
                'user_id' => null, 'old' => ['status' => $fromStatus], 'new' => ['status' => $set['status']] + $extraAuditNew,
            ]);
        }
    }
    return $n;
}

/** Apply window closures for a date. Returns rows changed. */
function att_apply_window_transitions(string $date): int
{
    $today = att_today();
    if ($date > $today) {
        return 0;
    }
    $time = att_now()->format('H:i:s');
    $total = 0;
    foreach (att_window_groups($date) as $g) {
        $inClosed = $date < $today || $time > $g['cfg']['checkin_end'];
        $outClosed = $date < $today || $time > $g['cfg']['checkout_end'];
        Database::beginTransaction();
        try {
            if ($inClosed) {
                $total += att_transition($date, $g['where'] . ' AND a.checkin_at IS NULL', 'not_marked',
                    ['status' => 'pending_review', 'review_state' => 'pending', 'review_reason' => 'no_checkin'],
                    'pending_review', 'Check-in window closed with no Check-in: flagged Pending Review', ['review_reason' => 'no_checkin']);
            }
            if ($outClosed) {
                $total += att_transition($date, $g['where'] . ' AND a.checkin_at IS NOT NULL AND a.checkout_at IS NULL', 'checked_in',
                    ['status' => 'incomplete', 'review_state' => 'pending', 'review_reason' => 'no_checkout'],
                    'incomplete', 'Check-out window closed with no Check-out: marked Incomplete, awaiting review', ['review_reason' => 'no_checkout']);
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
    return $total;
}

/**
 * Re-evaluate TODAY after a timing change took effect today.
 *
 * Only cases the scheduler had flagged from the OLD windows and nobody has acted on
 * (review still pending, no review decision, not finalised) are touched:
 *   • Pending Review (no Check-in) whose Check-in window is not closed under the new
 *     timing → Not marked yet, so the employee can check in inside the new window.
 *   • Incomplete (no Check-out) whose Check-out window is not closed → Checked in.
 * Then the normal window closures run, so a shortened window flags what it now excludes.
 * Recorded Check-in / Check-out times, Present / Absent / Leave rows and reviewed cases
 * are never altered, and no window is opened that the new configuration does not allow.
 *
 * @return array{reopened:int,flagged:int}
 */
function att_reconcile_timing_today(): array
{
    $today = att_today();
    $time = att_now()->format('H:i:s');
    $reopened = 0;
    Database::beginTransaction();
    try {
        foreach (att_window_groups($today) as $g) {
            if ($time <= $g['cfg']['checkin_end']) {
                $reopened += att_transition($today, $g['where'] . " AND a.checkin_at IS NULL AND a.review_state = 'pending'", 'pending_review',
                    ['status' => 'not_marked', 'review_state' => 'none', 'review_reason' => null],
                    'timing_reopened', 'Timing changed: the Check-in window is open again — back to Not marked yet');
            }
            if ($time <= $g['cfg']['checkout_end']) {
                $reopened += att_transition($today, $g['where'] . " AND a.checkin_at IS NOT NULL AND a.checkout_at IS NULL AND a.review_state = 'pending'", 'incomplete',
                    ['status' => 'checked_in', 'review_state' => 'none', 'review_reason' => null],
                    'timing_reopened', 'Timing changed: the Check-out window is open again — back to Checked in');
            }
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        throw $e;
    }
    return ['reopened' => $reopened, 'flagged' => att_apply_window_transitions($today)];
}

/** Finalise a date that has ended: unresolved → Absent; lock every row of the day. */
function att_finalize_day(string $date): int
{
    if ($date >= att_today()) {
        throw new LogicException('A date can only be finalised after it has ended.');
    }
    $total = 0;
    Database::beginTransaction();
    try {
        $now = att_now_sql();
        foreach (['not_marked', 'checked_in', 'pending_review', 'incomplete'] as $from) {
            $total += att_transition($date, '1 = 1', $from,
                ['status' => 'absent', 'resolution_source' => 'auto', 'finalized_at' => $now],
                'auto_absent', 'Unresolved at 12:00 AM (Asia/Karachi): automatically marked Absent');
        }
        /* Everything else (Present, Leave, Off Day, Holiday, Absent from review) is simply locked. */
        Database::execute('UPDATE attendance_records SET finalized_at = :n WHERE attendance_date = :d AND finalized_at IS NULL', ['n' => $now, 'd' => $date]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        throw $e;
    }
    return $total;
}

/** Process one ended date with job-run bookkeeping. @return array{date:string,ok:bool,rows:int,error:?string} */
function att_process_past_day(string $date): array
{
    $now = att_now_sql();
    Database::execute(
        "INSERT INTO attendance_job_runs (attendance_date, status, attempts, started_at) VALUES (:d, 'running', 1, :n)
         ON DUPLICATE KEY UPDATE status = 'running', attempts = attempts + 1, started_at = VALUES(started_at), finished_at = NULL, error_message = NULL",
        ['d' => $date, 'n' => $now]
    );
    try {
        $rows = att_generate_rows($date);
        $rows += att_apply_window_transitions($date);
        $rows += att_finalize_day($date);
        Database::execute("UPDATE attendance_job_runs SET status = 'success', rows_affected = :r, finished_at = :n WHERE attendance_date = :d",
            ['r' => $rows, 'n' => att_now_sql(), 'd' => $date]);
        return ['date' => $date, 'ok' => true, 'rows' => $rows, 'error' => null];
    } catch (Throwable $e) {
        log_event('attendance', 'Finalisation failed', ['date' => $date, 'error' => $e->getMessage()]);
        Database::execute("UPDATE attendance_job_runs SET status = 'failed', error_message = :m, finished_at = :n WHERE attendance_date = :d",
            ['m' => mb_substr($e->getMessage(), 0, 480), 'n' => att_now_sql(), 'd' => $date]);
        return ['date' => $date, 'ok' => false, 'rows' => 0, 'error' => $e->getMessage()];
    }
}

/** Dates that have ended and still need processing (oldest first). @return string[] */
function att_pending_dates(): array
{
    $today = att_today();
    $goLive = att_setting('attendance_go_live_date');
    if ($goLive === null || att_valid_date($goLive) === null) {
        att_save_setting('attendance_go_live_date', $today);
        $goLive = $today;
    }
    $floor = (new DateTimeImmutable($today, att_tz()))->modify('-' . ATT_RECOVERY_DAYS . ' days')->format('Y-m-d');
    $from = max($goLive, $floor);
    $done = array_column(Database::fetchAll("SELECT attendance_date FROM attendance_job_runs WHERE status = 'success' AND attendance_date >= :f", ['f' => $from]), 'attendance_date');
    $done = array_flip($done);
    $dates = [];
    for ($d = new DateTimeImmutable($from, att_tz()); $d->format('Y-m-d') < $today; $d = $d->modify('+1 day')) {
        if (!isset($done[$d->format('Y-m-d')])) {
            $dates[] = $d->format('Y-m-d');
        }
    }
    return $dates;
}

/**
 * The full sweep. Safe to call from cron, the web, or by hand, at any time and
 * as often as wanted: a named database lock keeps two runs from overlapping.
 *
 * @return array{ran:bool,reason:?string,days:array,today_rows:int,today_changes:int}
 */
function att_sweep(): array
{
    $out = ['ran' => false, 'reason' => null, 'days' => [], 'today_rows' => 0, 'today_changes' => 0];
    $got = (string)Database::fetchValue('SELECT GET_LOCK(:n, 0)', ['n' => ATT_SWEEP_LOCK]);
    if ($got !== '1') {
        $out['reason'] = 'Another sweep is already running.';
        return $out;
    }
    try {
        att_config_cache(true);
        foreach (att_pending_dates() as $date) {
            $out['days'][] = att_process_past_day($date);
        }
        $today = att_today();
        $out['today_rows'] = att_generate_rows($today);
        $out['today_changes'] = att_apply_window_transitions($today);
        att_save_setting('attendance_last_sweep_at', (string)time());
        $out['ran'] = true;
    } catch (Throwable $e) {
        log_event('attendance', 'Sweep failed', ['error' => $e->getMessage()]);
        $out['reason'] = $e->getMessage();
    } finally {
        Database::query('SELECT RELEASE_LOCK(:n)', ['n' => ATT_SWEEP_LOCK]);
    }
    return $out;
}

/**
 * Opportunistic sweep for pages that show live attendance: at most once every
 * 30 seconds. It is a supplement to the scheduler, never a replacement.
 */
function att_lazy_sweep(): void
{
    try {
        $last = (int)att_setting('attendance_last_sweep_at', '0');
        if (time() - $last >= 30) {
            att_save_setting('attendance_last_sweep_at', (string)time());
            att_sweep();
        }
    } catch (Throwable $e) {
        log_event('attendance', 'Lazy sweep failed', ['error' => $e->getMessage()]);
    }
}

/** Last N job runs for the Configuration screen. */
function att_recent_job_runs(int $limit = 15): array
{
    return Database::fetchAll('SELECT * FROM attendance_job_runs ORDER BY attendance_date DESC LIMIT ' . (int)$limit);
}
