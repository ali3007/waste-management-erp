<?php
/**
 * Attendance marking — the ONE place a Check-in or Check-out is recorded.
 * Both interfaces (Office self-attendance and Supervisor) call it, so the
 * rules cannot differ between them.
 *
 * Enforced here, on the server, for every caller (no role is exempt):
 *   • authorization  (att_mark_authority)
 *   • attendance date = today in Asia/Karachi, server clock only
 *   • non-working days (Sunday, Office holiday, approved Leave) accept no marking
 *   • the event's time window must be OPEN right now — a closed window is final
 *   • Check-out needs a Check-in; no duplicate Check-in or Check-out
 *   • one employee per call (there is deliberately no bulk entry point)
 * Concurrency: the day's row is locked FOR UPDATE and each event is written
 * with a "WHERE checkin_at IS NULL" guard, so two simultaneous requests can
 * never both succeed.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * @param array $ctx  from att_ctx()
 * @return array{ok:bool,message:string,code:string,record:?array}
 */
function att_mark_event(int $employeeId, string $event, array $ctx): array
{
    $fail = static fn(string $code, string $msg, ?array $emp = null, ?array $extra = null): array => att_mark_blocked($event, $employeeId, $emp, $code, $msg, $extra);

    if (!in_array($event, ['checkin', 'checkout'], true)) {
        return $fail('bad_event', 'Unknown attendance action.');
    }
    $employee = att_load_employee($employeeId, att_scope_uc_ids($ctx));
    if ($employee === null) {
        return $fail('not_found', 'That employee could not be found.');
    }
    if (($deny = att_mark_authority($ctx, $employee)) !== null) {
        return $fail('not_authorized', $deny, $employee);
    }

    $today = att_today();
    $label = att_event_label($event);
    $channel = $employee['mode'] === 'self' ? 'self' : 'supervisor';

    try {
        Database::beginTransaction();
        $now = att_now();                       // taken inside the lock: the recorded instant
        $row = att_ensure_row($employee, $today);
        if ($row === null) {
            Database::rollBack();
            return $fail('no_timing', 'Attendance timing has not been configured yet. Ask an administrator.', $employee);
        }

        $nonWorking = ['off_day' => 'Today is the weekly off day (Sunday); attendance is not recorded.',
                       'holiday' => 'Today is a holiday for Office Staff; attendance is not recorded.',
                       'leave'   => 'This employee is on approved leave today; attendance is not recorded.'];
        if (isset($nonWorking[$row['status']])) {
            Database::rollBack();
            return $fail('non_working', $nonWorking[$row['status']], $employee);
        }

        $cfg = att_config_for((int)$row['staff_pool_id'], $row['uc_id'] !== null ? (int)$row['uc_id'] : null, $today);
        if ($cfg === null) {
            Database::rollBack();
            return $fail('no_timing', 'Attendance timing has not been configured yet. Ask an administrator.', $employee);
        }
        $state = att_window_state($cfg, $event, $now->format('H:i:s'));
        if ($state !== 'open') {
            Database::rollBack();
            return $fail($state === 'before' ? 'window_not_open' : 'window_closed', att_window_message($cfg, $event, $state), $employee, ['window' => $cfg]);
        }

        if ($event === 'checkin') {
            if ($row['checkin_at'] !== null) {
                Database::rollBack();
                return $fail('duplicate', 'Check-in is already recorded for today (' . att_fmt_time(substr((string)$row['checkin_at'], 11)) . ').', $employee);
            }
            if ($row['status'] !== 'not_marked') {
                Database::rollBack();
                return $fail('invalid_state', 'This day can no longer accept a Check-in (status: ' . att_status_label((string)$row['status']) . ').', $employee);
            }
            $changed = Database::execute(
                "UPDATE attendance_records SET checkin_at = :t, checkin_by_user_id = :u, checkin_channel = :c,
                        status = 'checked_in', resolution_source = 'events', updated_at = :n
                  WHERE id = :id AND checkin_at IS NULL AND status = 'not_marked'",
                ['t' => $now->format('Y-m-d H:i:s'), 'u' => $ctx['user_id'], 'c' => $channel, 'n' => $now->format('Y-m-d H:i:s'), 'id' => (int)$row['id']]
            );
        } else {
            if ($row['checkin_at'] === null) {
                Database::rollBack();
                return $fail('no_checkin', 'Check-out cannot be recorded without a Check-in.', $employee);
            }
            if ($row['checkout_at'] !== null) {
                Database::rollBack();
                return $fail('duplicate', 'Check-out is already recorded for today (' . att_fmt_time(substr((string)$row['checkout_at'], 11)) . ').', $employee);
            }
            if ($row['status'] !== 'checked_in') {
                Database::rollBack();
                return $fail('invalid_state', 'This day can no longer accept a Check-out (status: ' . att_status_label((string)$row['status']) . ').', $employee);
            }
            $changed = Database::execute(
                "UPDATE attendance_records SET checkout_at = :t, checkout_by_user_id = :u, checkout_channel = :c,
                        status = 'present', resolution_source = 'events', updated_at = :n
                  WHERE id = :id AND checkin_at IS NOT NULL AND checkout_at IS NULL AND status = 'checked_in'",
                ['t' => $now->format('Y-m-d H:i:s'), 'u' => $ctx['user_id'], 'c' => $channel, 'n' => $now->format('Y-m-d H:i:s'), 'id' => (int)$row['id']]
            );
        }
        if ($changed !== 1) {
            Database::rollBack();
            return $fail('conflict', 'This attendance event was just recorded by another request.', $employee);
        }

        att_audit($event, $label . ' recorded for ' . $employee['name'] . ' at ' . $now->format('H:i:s')
            . ($channel === 'supervisor' ? ' by supervisor' : ' (self)'), [
            'employee_id' => $employeeId, 'date' => $today, 'uc_id' => $row['uc_id'] !== null ? (int)$row['uc_id'] : null,
            'user_id' => $ctx['user_id'],
            'old' => ['status' => $row['status']], 'new' => ['status' => $event === 'checkin' ? 'checked_in' : 'present', 'time' => $now->format('Y-m-d H:i:s'), 'channel' => $channel],
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Marking failed', ['employee_id' => $employeeId, 'event' => $event, 'error' => $e->getMessage()]);
        return $fail('error', 'The attendance could not be saved. Please try again.', $employee);
    }

    $record = Database::fetch(att_record_select() . ' WHERE a.employee_id = :e AND a.attendance_date = :d', ['e' => $employeeId, 'd' => $today]);
    return ['ok' => true, 'code' => 'ok', 'record' => $record,
            'message' => $label . ' recorded at ' . $now->format('h:i A') . ' for ' . $employee['name'] . '.'
                . ($event === 'checkin' ? ' Check-out is still required to complete today\'s attendance.' : '')];
}

/** Audit a refused attempt (outside any transaction) and build the failure result. */
function att_mark_blocked(string $event, int $employeeId, ?array $employee, string $code, string $message, ?array $extra = null): array
{
    att_audit_safe('mark_blocked', att_event_label($event) . ' refused' . ($employee ? ' for ' . $employee['name'] : '') . ': ' . $message, [
        'employee_id' => $employee['id'] ?? null, 'date' => att_today(), 'uc_id' => $employee['uc_id'] ?? null,
        'outcome' => 'blocked', 'reason' => $code,
        'new' => ['event' => $event, 'requested_employee_id' => $employeeId] + ($extra ?? []),
    ]);
    return ['ok' => false, 'code' => $code, 'message' => $message, 'record' => null];
}

/**
 * What the interface should offer one employee right now. Computed with the
 * SAME rules as att_mark_event so buttons and server can never disagree —
 * though the server never relies on this: it re-checks everything on POST.
 *
 * @return array{record:?array,can_checkin:bool,can_checkout:bool,hint:string,window:?array,state:string}
 */
function att_day_view(array $employee, ?array $record = null): array
{
    $today = att_today();
    $record ??= att_find_record((int)$employee['id'], $today);
    $cfg = att_config_for((int)$employee['staff_pool_id'], $record !== null && $record['uc_id'] !== null ? (int)$record['uc_id'] : $employee['uc_id'], $today);
    $out = ['record' => $record, 'can_checkin' => false, 'can_checkout' => false, 'hint' => '', 'window' => $cfg, 'state' => $record['status'] ?? 'not_marked'];

    if ($cfg === null) {
        $out['hint'] = 'Attendance timing has not been configured.';
        return $out;
    }
    $status = $record['status'] ?? att_default_status((int)$employee['id'], (int)$employee['staff_pool_id'], $today)[0];
    $out['state'] = $status;
    $time = att_now()->format('H:i:s');
    $inState = att_window_state($cfg, 'checkin', $time);
    $outState = att_window_state($cfg, 'checkout', $time);

    switch ($status) {
        case 'off_day':
            $out['hint'] = 'Sunday is the weekly off day.';
            break;
        case 'holiday':
            $out['hint'] = 'Today is a holiday.';
            break;
        case 'leave':
            $out['hint'] = 'On approved leave today.';
            break;
        case 'not_marked':
            $out['can_checkin'] = $inState === 'open';
            $out['hint'] = $inState === 'open' ? 'Check-in is open until ' . att_fmt_time($cfg['checkin_end']) . '.'
                : ($inState === 'before' ? 'Check-in opens at ' . att_fmt_time($cfg['checkin_start']) . '.' : 'The Check-in window closed at ' . att_fmt_time($cfg['checkin_end']) . '.');
            break;
        case 'checked_in':
            $out['can_checkout'] = $outState === 'open';
            $out['hint'] = $outState === 'open' ? 'Check-out is open until ' . att_fmt_time($cfg['checkout_end']) . '.'
                : ($outState === 'before' ? 'Check-out opens at ' . att_fmt_time($cfg['checkout_start']) . '.' : 'The Check-out window closed at ' . att_fmt_time($cfg['checkout_end']) . '.');
            break;
        case 'present':
            $out['hint'] = 'Attendance complete for today.';
            break;
        default:
            $out['hint'] = 'Awaiting review — late attendance cannot be recorded.';
    }
    return $out;
}
