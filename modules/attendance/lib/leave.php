<?php
/**
 * Leave workflow — Pending → Approved / Rejected, with history.
 *
 * Who may SUBMIT:
 *   • Office Staff (self mode): the employee, for themselves (attendance.leave_submit_self).
 *   • Field / Transport Staff (supervised mode): their assigned Supervisor, on their
 *     behalf (attendance.leave_submit_proxy), for an employee in the Supervisor's UC.
 * Who may DECIDE: only users holding attendance.leave_approve (within their scope).
 * Submitting never grants approval, and nobody can approve their own request.
 *
 * Only APPROVED leave is Leave. Approval is applied to the day rows that exist
 * and still have no recorded events; finished days are never rewritten (that is
 * what Attendance Correction is for). Leave never bypasses attendance windows.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Employees the actor may submit leave for (id, name, code, mode). */
function att_leave_submittable(array $ctx): array
{
    $out = [];
    $can = $ctx['can'];
    if ($can('attendance.leave_submit_self') && $ctx['employee'] !== null) {
        $e = att_load_employee((int)$ctx['employee']['id']);
        if ($e !== null && $e['status'] === 'active' && $e['mode'] === 'self') {
            $out[] = $e;
        }
    }
    if ($can('attendance.leave_submit_proxy') && att_has_team_scope($ctx)) {
        $scope = att_scope_uc_ids($ctx);
        $cfg = att_config_cache();
        $rows = Database::fetchAll(
            "SELECT DISTINCT e.id FROM employees e
               JOIN employee_assignments a ON a.employee_id = e.id AND a.unassigned_at IS NULL
              WHERE e.status = 'active' AND e.staff_pool_id IN (" . att_in($cfg['supervised_pools']) . ')
                AND e.staff_type_id NOT IN (' . att_in(array_merge($cfg['supervisor_types'], $cfg['self_types'])) . ')'
            . ($scope !== null ? ' AND a.uc_id IN (' . att_in($scope) . ')' : '') . ' ORDER BY e.name LIMIT 500'
        );
        foreach ($rows as $r) {
            $e = att_load_employee((int)$r['id'], $scope);
            if ($e !== null && !att_is_self($ctx, $e['id'])) {
                $out[] = $e;
            }
        }
    }
    return $out;
}

function att_leave_submit_authority(array $ctx, array $employee): ?string
{
    $can = $ctx['can'];
    if ($employee['status'] !== 'active' || $employee['mode'] === null) {
        return 'Leave can only be requested for an active employee in an attendance category.';
    }
    if ($employee['mode'] === 'self') {
        if (!att_is_self($ctx, (int)$employee['id'])) {
            return 'Office Staff submit their own leave requests.';
        }
        return $can('attendance.leave_submit_self') ? null : 'You do not have permission to submit leave requests.';
    }
    if (!$can('attendance.leave_submit_proxy')) {
        return 'You do not have permission to submit leave on behalf of staff.';
    }
    if (att_is_self($ctx, (int)$employee['id'])) {
        return 'You cannot submit leave for yourself from this screen.';
    }
    if (!att_has_team_scope($ctx)) {
        return 'Your login is not linked to an employee record, so your UC cannot be determined.';
    }
    $scope = att_scope_uc_ids($ctx);
    if ($scope !== null && !array_intersect($employee['uc_ids'] ?? [], $scope)) {
        return 'This employee is not assigned to your UC.';
    }
    return null;
}

/** @return array{ok:bool,message:string,id?:int} */
function att_leave_submit(int $employeeId, string $start, string $end, string $reason, array $ctx): array
{
    $start = att_valid_date($start);
    $end = att_valid_date($end);
    $reason = Security::cleanText($reason, 500);
    if ($start === null || $end === null) {
        return ['ok' => false, 'message' => 'Enter valid start and end dates.'];
    }
    if ($end < $start) {
        return ['ok' => false, 'message' => 'The end date cannot be before the start date.'];
    }
    if ($start < att_today()) {
        return ['ok' => false, 'message' => 'Leave cannot start in the past. Past days are changed only through Attendance Correction.'];
    }
    if ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 365) {
        return ['ok' => false, 'message' => 'A leave request cannot be longer than one year.'];
    }
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'Give a reason (at least 5 characters).'];
    }
    $employee = att_load_employee($employeeId);
    if ($employee === null) {
        return ['ok' => false, 'message' => 'That employee could not be found.'];
    }
    if (($deny = att_leave_submit_authority($ctx, $employee)) !== null) {
        att_audit_safe('leave_blocked', 'Leave submission refused: ' . $deny, ['employee_id' => $employeeId, 'outcome' => 'blocked', 'reason' => $deny, 'subject_type' => 'leave']);
        return ['ok' => false, 'message' => $deny];
    }
    try {
        Database::beginTransaction();
        Database::fetchValue('SELECT id FROM employees WHERE id = :id FOR UPDATE', ['id' => $employeeId]);   // serialise per employee
        $clash = Database::fetch(
            "SELECT id, status, start_date, end_date FROM attendance_leave_requests
              WHERE employee_id = :e AND status IN ('pending','approved') AND start_date <= :en AND end_date >= :st LIMIT 1",
            ['e' => $employeeId, 'st' => $start, 'en' => $end]
        );
        if ($clash !== null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'This overlaps an existing ' . $clash['status'] . ' leave request (' . att_fmt_date($clash['start_date']) . ' – ' . att_fmt_date($clash['end_date']) . ').'];
        }
        $now = att_now_sql();
        $id = Database::insert('attendance_leave_requests', [
            'employee_id' => $employeeId, 'staff_pool_id' => $employee['staff_pool_id'], 'uc_id' => $employee['uc_id'],
            'start_date' => $start, 'end_date' => $end, 'reason' => $reason, 'status' => 'pending',
            'submitted_by' => $ctx['user_id'], 'submitted_at' => $now,
        ]);
        Database::insert('attendance_leave_history', ['leave_request_id' => $id, 'action' => 'submitted', 'actor_user_id' => $ctx['user_id'], 'note' => $reason, 'created_at' => $now]);
        att_audit('leave_submitted', 'Leave requested for ' . $employee['name'] . ' (' . $start . ' to ' . $end . ')', [
            'employee_id' => $employeeId, 'uc_id' => $employee['uc_id'], 'user_id' => $ctx['user_id'], 'subject_type' => 'leave', 'subject_id' => $id,
            'reason' => $reason, 'new' => ['status' => 'pending', 'start' => $start, 'end' => $end, 'on_behalf' => !att_is_self($ctx, $employeeId)],
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Leave submit failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The leave request could not be saved.'];
    }
    return ['ok' => true, 'message' => 'Leave request submitted and waiting for approval.', 'id' => $id];
}

/** Requests visible to the actor (scoped by current assignment; own requests always). */
function att_leave_list(array $ctx, string $status = '', int $limit = 100, int $offset = 0): array
{
    $where = ['1 = 1'];
    $params = [];
    $scope = att_scope_sql($ctx, 'r.employee_id');
    $mine = $ctx['employee'] !== null ? (int)$ctx['employee']['id'] : 0;
    if ($scope !== '') {
        $where[] = "({$scope} OR r.employee_id = {$mine})";
    }
    if (!($ctx['can'])('attendance.leave_view') && !($ctx['can'])('attendance.leave_approve')) {
        $where[] = "r.employee_id = {$mine}";             // own requests only
    }
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'r.status = :st';
        $params['st'] = $status;
    }
    return Database::fetchAll(
        "SELECT r.*, e.name AS employee_name, e.employee_code, sp.name AS pool_name, u.name AS uc_name,
                sb.name AS submitted_by_name, db.name AS decided_by_name
           FROM attendance_leave_requests r
           JOIN employees e ON e.id = r.employee_id
           JOIN hr_staff_pools sp ON sp.id = r.staff_pool_id
           LEFT JOIN ucs u ON u.id = r.uc_id
           LEFT JOIN users sb ON sb.id = r.submitted_by
           LEFT JOIN users db ON db.id = r.decided_by
          WHERE " . implode(' AND ', $where) . ' ORDER BY (r.status = \'pending\') DESC, r.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
        $params
    );
}

/** @return array{ok:bool,message:string} */
function att_leave_decide(int $requestId, string $decision, string $note, array $ctx): array
{
    if (!($ctx['can'])('attendance.leave_approve')) {
        return ['ok' => false, 'message' => 'You do not have permission to approve or reject leave.'];
    }
    if (!in_array($decision, ['approve', 'reject'], true)) {
        return ['ok' => false, 'message' => 'Unknown decision.'];
    }
    $note = Security::cleanText($note, 500);
    if ($decision === 'reject' && mb_strlen($note) < 5) {
        return ['ok' => false, 'message' => 'A rejection reason is required.'];
    }
    try {
        Database::beginTransaction();
        $req = Database::fetch('SELECT * FROM attendance_leave_requests WHERE id = :id FOR UPDATE', ['id' => $requestId]);
        if ($req === null || (!att_employee_in_scope($ctx, (int)$req['employee_id']))) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'That request could not be found.'];
        }
        if ($req['status'] !== 'pending') {
            Database::rollBack();
            return ['ok' => false, 'message' => 'This request has already been ' . $req['status'] . '.'];
        }
        if (att_is_self($ctx, (int)$req['employee_id'])) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'You cannot approve or reject your own leave request.'];
        }
        $now = att_now_sql();
        $applied = 0;
        $skipped = 0;
        if ($decision === 'approve') {
            $clash = Database::exists(
                "SELECT id FROM attendance_leave_requests WHERE employee_id = :e AND id <> :id AND status = 'approved' AND start_date <= :en AND end_date >= :st",
                ['e' => (int)$req['employee_id'], 'id' => $requestId, 'st' => $req['start_date'], 'en' => $req['end_date']]
            );
            if ($clash) {
                Database::rollBack();
                return ['ok' => false, 'message' => 'This overlaps leave that is already approved for the employee.'];
            }
            /* Days that already have a row: apply to untouched open days only. */
            $rows = Database::fetchAll(
                'SELECT id, employee_id, uc_id, status, finalized_at, checkin_at FROM attendance_records
                  WHERE employee_id = :e AND attendance_date BETWEEN :s AND :en FOR UPDATE',
                ['e' => (int)$req['employee_id'], 's' => $req['start_date'], 'en' => $req['end_date']]
            );
            foreach ($rows as $r) {
                if (in_array($r['status'], ['not_marked', 'pending_review'], true) && $r['checkin_at'] === null && $r['finalized_at'] === null) {
                    Database::execute(
                        "UPDATE attendance_records SET status = 'leave', leave_request_id = :l, review_state = 'none', review_reason = NULL,
                                resolution_source = 'system', updated_at = :n WHERE id = :id AND finalized_at IS NULL",
                        ['l' => $requestId, 'n' => $now, 'id' => (int)$r['id']]
                    );
                    $applied++;
                } elseif (!in_array($r['status'], ['off_day', 'holiday', 'leave'], true)) {
                    $skipped++;
                }
            }
        }
        $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
        Database::execute(
            'UPDATE attendance_leave_requests SET status = :s, decided_by = :u, decided_at = :n, decision_note = :note WHERE id = :id AND status = \'pending\'',
            ['s' => $newStatus, 'u' => $ctx['user_id'], 'n' => $now, 'note' => $note !== '' ? $note : null, 'id' => $requestId]
        );
        Database::insert('attendance_leave_history', ['leave_request_id' => $requestId, 'action' => $newStatus, 'actor_user_id' => $ctx['user_id'], 'note' => $note !== '' ? $note : null, 'created_at' => $now]);
        att_audit('leave_' . $newStatus, 'Leave request #' . $requestId . ' ' . $newStatus, [
            'employee_id' => (int)$req['employee_id'], 'uc_id' => $req['uc_id'] !== null ? (int)$req['uc_id'] : null, 'user_id' => $ctx['user_id'],
            'subject_type' => 'leave', 'subject_id' => $requestId, 'reason' => $note,
            'old' => ['status' => 'pending'], 'new' => ['status' => $newStatus, 'days_applied_now' => $applied, 'days_with_existing_attendance' => $skipped],
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Leave decision failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The decision could not be saved.'];
    }
    $msg = $decision === 'approve' ? 'Leave approved.' : 'Leave rejected.';
    if ($decision === 'approve' && $skipped > 0) {
        $msg .= " {$skipped} day(s) already had recorded attendance and were left unchanged.";
    }
    return ['ok' => true, 'message' => $msg];
}

function att_leave_history(int $requestId): array
{
    return Database::fetchAll(
        'SELECT h.*, u.name AS actor_name FROM attendance_leave_history h LEFT JOIN users u ON u.id = h.actor_user_id WHERE h.leave_request_id = :id ORDER BY h.id',
        ['id' => $requestId]
    );
}
