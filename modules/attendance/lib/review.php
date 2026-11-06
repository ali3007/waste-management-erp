<?php
/**
 * Pending-review workflow.
 *
 * A case is a record that is Pending Review (no Check-in) or Incomplete
 * (Check-in without Check-out) and still awaiting a decision. A reviewer — someone
 * holding attendance.review whose scope covers the employee — records a decision
 * (Present or Absent), with a reason. A decision records WHO, WHEN, WHAT and WHY,
 * and it never records a Check-in or Check-out time: the review workflow cannot be
 * used to enter late attendance. Cases not decided by 12:00 AM Asia/Karachi become
 * Absent automatically (see sweep.php); afterwards only Attendance Edit applies.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Open review cases the actor may see, newest date first. */
function att_review_cases(array $ctx, int $limit = 200): array
{
    $scope = att_scope_sql($ctx, 'a.employee_id');
    return Database::fetchAll(
        att_record_select() . " WHERE a.review_state = 'pending' AND a.status IN ('pending_review','incomplete') AND a.finalized_at IS NULL"
        . ($scope !== '' ? ' AND ' . $scope : '') . ' ORDER BY a.attendance_date DESC, e.name ASC LIMIT ' . (int)$limit
    );
}

/** @return array{ok:bool,message:string} */
function att_review_decide(int $recordId, string $decision, string $reason, array $ctx): array
{
    $reason = Security::cleanText($reason, 500);
    if (!in_array($decision, ['present', 'absent'], true)) {
        return ['ok' => false, 'message' => 'Choose a decision.'];
    }
    if (mb_strlen($reason) < 5) {
        return ['ok' => false, 'message' => 'A reason of at least 5 characters is required.'];
    }
    if (!($ctx['can'])('attendance.review')) {
        return ['ok' => false, 'message' => 'You do not have permission to review attendance.'];
    }
    try {
        Database::beginTransaction();
        $rec = Database::fetch('SELECT * FROM attendance_records WHERE id = :id FOR UPDATE', ['id' => $recordId]);
        if ($rec === null || !att_employee_in_scope($ctx, (int)$rec['employee_id'])) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'That case could not be found.'];
        }
        if ($rec['finalized_at'] !== null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'The review deadline (12:00 AM) has passed; this day is final. Use Attendance Correction instead.'];
        }
        if ($rec['review_state'] !== 'pending' || !in_array($rec['status'], ['pending_review', 'incomplete'], true)) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'This case is no longer awaiting review.'];
        }
        if (att_is_self($ctx, (int)$rec['employee_id'])) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'You cannot review your own attendance.'];
        }
        $now = att_now_sql();
        Database::execute(
            "UPDATE attendance_records SET status = :s, review_state = 'resolved', resolution_source = 'review', updated_at = :n
              WHERE id = :id AND review_state = 'pending'",
            ['s' => $decision, 'n' => $now, 'id' => $recordId]
        );
        Database::insert('attendance_reviews', [
            'record_id' => $recordId, 'employee_id' => (int)$rec['employee_id'], 'attendance_date' => $rec['attendance_date'],
            'decision' => $decision, 'reason' => $reason, 'previous_status' => $rec['status'],
            'decided_by' => $ctx['user_id'], 'decided_at' => $now,
        ]);
        att_audit('review_decision', 'Pending review resolved as ' . ucfirst($decision), [
            'employee_id' => (int)$rec['employee_id'], 'date' => $rec['attendance_date'], 'uc_id' => $rec['uc_id'] !== null ? (int)$rec['uc_id'] : null,
            'user_id' => $ctx['user_id'], 'reason' => $reason,
            'old' => ['status' => $rec['status'], 'review_state' => 'pending'], 'new' => ['status' => $decision, 'review_state' => 'resolved'],
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Review decision failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The decision could not be saved.'];
    }
    return ['ok' => true, 'message' => 'Decision recorded: ' . ucfirst($decision) . '.'];
}
