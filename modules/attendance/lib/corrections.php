<?php
/**
 * Attendance correction — the ONLY way to change a locked/historical record.
 *
 * Requires the explicit attendance.edit permission (never implied by marking,
 * review or any role), a reason, and keeps the original in attendance_corrections
 * and the audit trail. It is a different operation from marking: it never makes
 * the ordinary Check-in/Check-out windows reopen, it is refused for a day that is
 * still being recorded (today's open cases belong to marking and review), and
 * there is no "edit own attendance" permission or path.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

function att_correctable_statuses(): array
{
    return ['present', 'absent', 'incomplete', 'leave', 'off_day', 'holiday'];
}

function att_snapshot(?array $r): array
{
    if ($r === null) {
        return ['record' => 'none'];
    }
    return [
        'status' => $r['status'], 'checkin_at' => $r['checkin_at'], 'checkout_at' => $r['checkout_at'],
        'review_state' => $r['review_state'], 'resolution_source' => $r['resolution_source'],
    ];
}

/**
 * @param array{status:string,checkin:string,checkout:string,reason:string} $in
 * @return array{ok:bool,message:string}
 */
function att_correct(int $employeeId, string $date, array $in, array $ctx): array
{
    if (!($ctx['can'])('attendance.edit')) {
        return ['ok' => false, 'message' => 'You do not have the Attendance Edit permission.'];
    }
    $reason = Security::cleanText((string)($in['reason'] ?? ''), 500);
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'A correction reason of at least 5 characters is required.'];
    }
    $date = att_valid_date($date);
    if ($date === null || $date > att_today()) {
        return ['ok' => false, 'message' => 'Choose a valid date that is not in the future.'];
    }
    $status = (string)($in['status'] ?? '');
    if (!in_array($status, att_correctable_statuses(), true)) {
        return ['ok' => false, 'message' => 'Choose a valid status.'];
    }
    $employee = att_load_employee($employeeId);
    if ($employee === null) {
        return ['ok' => false, 'message' => 'That employee could not be found.'];
    }
    if ($employee['mode'] === null) {
        return ['ok' => false, 'message' => 'That employee is not part of an attendance category.'];
    }
    if (att_is_self($ctx, $employeeId)) {
        return ['ok' => false, 'message' => 'You cannot correct your own attendance.'];
    }

    /* Times → datetimes on the attendance date, then the sequence rules. */
    $cin = trim((string)($in['checkin'] ?? ''));
    $cout = trim((string)($in['checkout'] ?? ''));
    $t1 = $cin !== '' ? att_valid_time($cin) : null;
    $t2 = $cout !== '' ? att_valid_time($cout) : null;
    if (($cin !== '' && $t1 === null) || ($cout !== '' && $t2 === null)) {
        return ['ok' => false, 'message' => 'Enter times as HH:MM.'];
    }
    $in1 = $t1 !== null ? $date . ' ' . $t1 : null;
    $out1 = $t2 !== null ? $date . ' ' . $t2 : null;
    if ($out1 !== null && $in1 === null) {
        return ['ok' => false, 'message' => 'A Check-out cannot exist without a Check-in.'];
    }
    if ($in1 !== null && $out1 !== null && $out1 <= $in1) {
        return ['ok' => false, 'message' => 'Check-out must be after Check-in.'];
    }
    if ($status === 'present' && ($in1 === null || $out1 === null)) {
        return ['ok' => false, 'message' => 'Present requires both a Check-in and a Check-out time.'];
    }
    if ($status === 'incomplete' && ($in1 === null || $out1 !== null)) {
        return ['ok' => false, 'message' => 'Incomplete means a Check-in with no Check-out.'];
    }
    if (in_array($status, ['leave', 'off_day', 'holiday'], true) && ($in1 !== null || $out1 !== null)) {
        return ['ok' => false, 'message' => 'A Leave, Off Day or Holiday record cannot have Check-in/Check-out times.'];
    }

    try {
        Database::beginTransaction();
        $rec = att_find_record($employeeId, $date, true);
        $inScope = att_employee_in_scope($ctx, $employeeId)
            || ($rec !== null && $rec['uc_id'] !== null && ($s = att_scope_uc_ids($ctx)) !== null && in_array((int)$rec['uc_id'], $s, true))
            || att_scope_uc_ids($ctx) === null;
        if (!$inScope) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'That employee is outside your authorised scope.'];
        }
        if ($date === att_today() && $rec !== null && in_array($rec['status'], ATT_OPEN_STATUSES, true) && $rec['finalized_at'] === null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'Today\'s attendance is still open. It is completed by marking, or by Pending Review — not by correction.'];
        }
        if ($date === att_today() && $rec === null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'Today\'s attendance row does not exist yet.'];
        }

        $now = att_now_sql();
        $created = false;
        if ($rec === null) {
            $created = true;
            Database::insert('attendance_records', [
                'employee_id' => $employeeId, 'attendance_date' => $date,
                'staff_pool_id' => $employee['staff_pool_id'], 'staff_type_id' => $employee['staff_type_id'],
                'uc_id' => $employee['uc_id'], 'supervisor_employee_id' => att_supervisor_of_uc($employee['uc_id']),
                'status' => 'absent', 'resolution_source' => 'system', 'finalized_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $rec = att_find_record($employeeId, $date, true);
        }
        $before = att_snapshot($created ? null : $rec);
        $after = ['status' => $status, 'checkin_at' => $in1, 'checkout_at' => $out1, 'review_state' => 'resolved', 'resolution_source' => 'correction'];
        $beforeCmp = $before + ['checkin_at' => null, 'checkout_at' => null];
        if (!$created && $beforeCmp['status'] === $status && (string)$rec['checkin_at'] === (string)$in1 && (string)$rec['checkout_at'] === (string)$out1) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'Nothing would change.'];
        }

        $sets = ['status' => $status, 'checkin_at' => $in1, 'checkout_at' => $out1, 'review_state' => 'resolved',
                 'resolution_source' => 'correction', 'last_corrected_at' => $now, 'last_corrected_by' => $ctx['user_id'],
                 'finalized_at' => $rec['finalized_at'] ?? ($date < att_today() ? $now : null), 'updated_at' => $now];
        if ((string)$rec['checkin_at'] !== (string)$in1) {
            $sets += ['checkin_by_user_id' => $in1 !== null ? $ctx['user_id'] : null, 'checkin_channel' => null];
        }
        if ((string)$rec['checkout_at'] !== (string)$out1) {
            $sets += ['checkout_by_user_id' => $out1 !== null ? $ctx['user_id'] : null, 'checkout_channel' => null];
        }
        if ($status !== 'leave') {
            $sets['leave_request_id'] = null;
        }
        if ($status !== 'holiday') {
            $sets['holiday_id'] = null;
        }
        Database::update('attendance_records', $sets, ['id' => (int)$rec['id']]);

        Database::insert('attendance_corrections', [
            'record_id' => (int)$rec['id'], 'employee_id' => $employeeId, 'attendance_date' => $date,
            'original_value' => json_encode($before, JSON_UNESCAPED_UNICODE), 'corrected_value' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'corrected_by' => $ctx['user_id'], 'corrected_at' => $now,
        ]);
        att_audit('correction', 'Attendance corrected for ' . $employee['name'] . ' on ' . $date . ': ' . ($before['status'] ?? 'none') . ' → ' . $status, [
            'employee_id' => $employeeId, 'date' => $date, 'uc_id' => $rec['uc_id'] !== null ? (int)$rec['uc_id'] : null,
            'user_id' => $ctx['user_id'], 'reason' => $reason, 'old' => $before, 'new' => $after,
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Correction failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The correction could not be saved.'];
    }
    return ['ok' => true, 'message' => 'Correction saved. The original record is kept in the correction history.'];
}

function att_correction_history(?int $employeeId = null, int $limit = 100): array
{
    return Database::fetchAll(
        'SELECT c.*, e.name AS employee_name, e.employee_code, u.name AS by_name
           FROM attendance_corrections c JOIN employees e ON e.id = c.employee_id LEFT JOIN users u ON u.id = c.corrected_by'
        . ($employeeId !== null ? ' WHERE c.employee_id = :e' : '') . ' ORDER BY c.id DESC LIMIT ' . (int)$limit,
        $employeeId !== null ? ['e' => $employeeId] : []
    );
}
