<?php
/**
 * Office Staff yearly holidays (database-driven; nothing hard-coded).
 *
 * Holidays apply ONLY to Office pools (att_pool_observes_holidays); Sunday is
 * handled separately as Off Day for everyone, so a holiday on a Sunday is simply
 * an Off Day (never double counted). Changes are prospective: today or later.
 * Today's untouched Office rows follow an added/removed holiday immediately.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

function att_holidays_for_year(int $year): array
{
    return Database::fetchAll(
        'SELECT h.*, u.name AS by_name FROM attendance_holidays h LEFT JOIN users u ON u.id = h.created_by
          WHERE h.holiday_date BETWEEN :a AND :b ORDER BY h.holiday_date',
        ['a' => sprintf('%04d-01-01', $year), 'b' => sprintf('%04d-12-31', $year)]
    );
}

/** @return array{ok:bool,message:string} */
function att_holiday_save(?int $id, string $date, string $title, string $description, array $ctx): array
{
    if (!($ctx['can'])('attendance.holidays_manage')) {
        return ['ok' => false, 'message' => 'You do not have permission to manage holidays.'];
    }
    $date = att_valid_date($date);
    $title = Security::cleanText($title, 150);
    $description = Security::cleanText($description, 500);
    if ($date === null) {
        return ['ok' => false, 'message' => 'Enter a valid holiday date.'];
    }
    if ($title === '') {
        return ['ok' => false, 'message' => 'Enter the holiday name.'];
    }
    if ($date < att_today()) {
        return ['ok' => false, 'message' => 'Holidays in the past cannot be added or changed (days already recorded stay as they are; use Attendance Correction).'];
    }
    try {
        Database::beginTransaction();
        $old = null;
        if ($id !== null && $id > 0) {
            $old = Database::fetch('SELECT * FROM attendance_holidays WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($old === null) {
                Database::rollBack();
                return ['ok' => false, 'message' => 'That holiday could not be found.'];
            }
            if ($old['holiday_date'] < att_today()) {
                Database::rollBack();
                return ['ok' => false, 'message' => 'A holiday that has already passed cannot be changed.'];
            }
        }
        $dup = Database::fetch('SELECT id FROM attendance_holidays WHERE holiday_date = :d AND id <> :id', ['d' => $date, 'id' => (int)$id]);
        if ($dup !== null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'A holiday is already defined for that date.'];
        }
        $now = att_now_sql();
        if ($old === null) {
            $id = Database::insert('attendance_holidays', ['holiday_date' => $date, 'title' => $title, 'description' => $description !== '' ? $description : null,
                'created_by' => $ctx['user_id'], 'updated_by' => $ctx['user_id'], 'created_at' => $now, 'updated_at' => $now]);
        } else {
            Database::update('attendance_holidays', ['holiday_date' => $date, 'title' => $title, 'description' => $description !== '' ? $description : null,
                'updated_by' => $ctx['user_id'], 'updated_at' => $now], ['id' => $id]);
            att_holiday_release($old, $now);                    // the old date stops being a holiday
        }
        att_holiday_apply((int)$id, $date, $now);
        att_audit($old === null ? 'holiday_added' : 'holiday_changed', 'Holiday "' . $title . '" on ' . $date, [
            'subject_type' => 'holiday', 'subject_id' => (int)$id, 'employee_id' => null, 'date' => $date, 'user_id' => $ctx['user_id'],
            'old' => $old === null ? null : ['date' => $old['holiday_date'], 'title' => $old['title']], 'new' => ['date' => $date, 'title' => $title],
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Holiday save failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The holiday could not be saved.'];
    }
    return ['ok' => true, 'message' => 'Holiday saved.'];
}

/** @return array{ok:bool,message:string} */
function att_holiday_delete(int $id, array $ctx): array
{
    if (!($ctx['can'])('attendance.holidays_manage')) {
        return ['ok' => false, 'message' => 'You do not have permission to manage holidays.'];
    }
    try {
        Database::beginTransaction();
        $h = Database::fetch('SELECT * FROM attendance_holidays WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($h === null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'That holiday could not be found.'];
        }
        if ($h['holiday_date'] < att_today()) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'A holiday that has already passed cannot be removed.'];
        }
        att_holiday_release($h, att_now_sql());
        Database::execute('DELETE FROM attendance_holidays WHERE id = :id', ['id' => $id]);
        att_audit('holiday_removed', 'Holiday "' . $h['title'] . '" on ' . $h['holiday_date'] . ' removed', [
            'subject_type' => 'holiday', 'subject_id' => $id, 'employee_id' => null, 'date' => $h['holiday_date'], 'user_id' => $ctx['user_id'],
            'old' => ['date' => $h['holiday_date'], 'title' => $h['title']],
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Holiday delete failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The holiday could not be removed.'];
    }
    return ['ok' => true, 'message' => 'Holiday removed.'];
}

/** Office rows of that date that have recorded nothing become Holiday (Sunday stays Off Day). */
function att_holiday_apply(int $holidayId, string $date, string $now): void
{
    if (att_is_sunday($date)) {
        return;
    }
    Database::execute(
        "UPDATE attendance_records SET status = 'holiday', holiday_id = :h, leave_request_id = NULL, review_state = 'none', review_reason = NULL,
                resolution_source = 'system', updated_at = :n
          WHERE attendance_date = :d AND finalized_at IS NULL AND checkin_at IS NULL AND checkout_at IS NULL
            AND status IN ('not_marked','pending_review','leave') AND staff_pool_id IN (" . att_in(att_self_pool_ids()) . ')',
        ['h' => $holidayId, 'n' => $now, 'd' => $date]
    );
}

/** Undo a holiday on its (not yet final) date: those rows go back to their default status. */
function att_holiday_release(array $holiday, string $now): void
{
    $rows = Database::fetchAll(
        "SELECT id, employee_id, staff_pool_id FROM attendance_records WHERE attendance_date = :d AND holiday_id = :h AND status = 'holiday' AND finalized_at IS NULL FOR UPDATE",
        ['d' => $holiday['holiday_date'], 'h' => (int)$holiday['id']]
    );
    foreach ($rows as $r) {
        $leave = att_approved_leave_id((int)$r['employee_id'], $holiday['holiday_date']);
        Database::execute(
            'UPDATE attendance_records SET status = :s, holiday_id = NULL, leave_request_id = :l, updated_at = :n WHERE id = :id',
            ['s' => $leave !== null ? 'leave' : 'not_marked', 'l' => $leave, 'n' => $now, 'id' => (int)$r['id']]
        );
    }
}
