<?php
/**
 * Attendance records — one row per tracked employee per attendance date.
 *
 * Rows are created from the live HR data (never from a hand-kept list) by the
 * scheduler, or on first touch. The row also stores the employee's category,
 * UC and Supervisor AS THEY WERE that day, so transfers never rewrite history.
 *
 * Default status of a new row (precedence is deliberate, so a day is never
 * counted in two categories):
 *   Sunday → Off Day  >  Office Staff holiday → Holiday  >  approved Leave  >  Not marked yet
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

function att_holiday_on(string $date): ?array
{
    return Database::fetch('SELECT * FROM attendance_holidays WHERE holiday_date = :d', ['d' => $date]);
}

/** Id of an APPROVED leave request covering the date (pending/rejected never count). */
function att_approved_leave_id(int $employeeId, string $date): ?int
{
    $v = Database::fetchValue(
        "SELECT MIN(id) FROM attendance_leave_requests WHERE employee_id = :e AND status = 'approved' AND :d BETWEEN start_date AND end_date",
        ['e' => $employeeId, 'd' => $date]
    );
    return $v !== null ? (int)$v : null;
}

/** @return array{0:string,1:?int,2:?int} [status, leave_request_id, holiday_id] */
function att_default_status(int $employeeId, int $poolId, string $date): array
{
    if (att_is_sunday($date)) {
        return ['off_day', null, null];
    }
    if (att_pool_observes_holidays($poolId) && ($h = att_holiday_on($date)) !== null) {
        return ['holiday', null, (int)$h['id']];
    }
    $leave = att_approved_leave_id($employeeId, $date);
    if ($leave !== null) {
        return ['leave', $leave, null];
    }
    return ['not_marked', null, null];
}

function att_find_record(int $employeeId, string $date, bool $forUpdate = false): ?array
{
    return Database::fetch(
        'SELECT * FROM attendance_records WHERE employee_id = :e AND attendance_date = :d' . ($forUpdate ? ' FOR UPDATE' : ''),
        ['e' => $employeeId, 'd' => $date]
    );
}

/**
 * Make sure today's row exists for one employee (idempotent: the unique key
 * on employee+date makes a concurrent duplicate impossible) and return it,
 * locked. Returns null when no company timing is in force for the date.
 * Call inside a transaction.
 */
function att_ensure_row(array $employee, string $date): ?array
{
    if (att_find_record((int)$employee['id'], $date) !== null) {
        return att_find_record((int)$employee['id'], $date, true);
    }
    if (att_company_config($date) === null) {
        return null;
    }
    /* Insert first, lock second: locking a row that does not exist yet would take
       a gap lock and let two simultaneous first-touches deadlock. */
    [$status, $leaveId, $holidayId] = att_default_status((int)$employee['id'], (int)$employee['staff_pool_id'], $date);
    $now = att_now_sql();
    Database::execute(
        'INSERT IGNORE INTO attendance_records
            (employee_id, attendance_date, staff_pool_id, staff_type_id, uc_id, supervisor_employee_id, status, resolution_source,
             leave_request_id, holiday_id, created_at, updated_at)
         VALUES (:e, :d, :p, :t, :uc, :sup, :s, \'system\', :l, :h, :c, :u)',
        ['e' => (int)$employee['id'], 'd' => $date, 'p' => (int)$employee['staff_pool_id'], 't' => (int)$employee['staff_type_id'],
         'uc' => $employee['uc_id'], 'sup' => att_supervisor_of_uc($employee['uc_id']), 's' => $status,
         'l' => $leaveId, 'h' => $holidayId, 'c' => $now, 'u' => $now]
    );
    return att_find_record((int)$employee['id'], $date, true);
}

/**
 * Create the day's rows for every tracked, active employee (bulk, idempotent:
 * INSERT IGNORE against the unique employee+date key). Nothing is generated
 * when no company timing is in force for the date — an unconfigured module
 * must not turn everyone Absent at midnight.
 *
 * Tracked = Office pools / self types, plus Field/Transport-type employees who
 * are currently assigned to a UC (and are not the Supervisor themselves).
 */
function att_generate_rows(string $date): int
{
    if (att_company_config($date) === null) {
        return 0;
    }
    $cfg = att_config_cache();
    $selfPools = att_in($cfg['self_pools']);
    $selfTypes = att_in($cfg['self_types']);
    $supPools = att_in($cfg['supervised_pools']);
    $supTypes = att_in($cfg['supervisor_types']);
    $sunday = att_is_sunday($date) ? 1 : 0;
    $now = att_now_sql();

    return Database::execute(
        "INSERT IGNORE INTO attendance_records
            (employee_id, attendance_date, staff_pool_id, staff_type_id, uc_id, supervisor_employee_id, status, resolution_source,
             leave_request_id, holiday_id, created_at, updated_at)
         SELECT e.id, :d1, e.staff_pool_id, e.staff_type_id, ac.uc_id, sup.sid,
                CASE WHEN {$sunday} = 1 THEN 'off_day'
                     WHEN h.id IS NOT NULL AND e.staff_pool_id IN ({$selfPools}) THEN 'holiday'
                     WHEN lv.id IS NOT NULL THEN 'leave'
                     ELSE 'not_marked' END,
                'system',
                CASE WHEN {$sunday} = 0 AND NOT (h.id IS NOT NULL AND e.staff_pool_id IN ({$selfPools})) THEN lv.id ELSE NULL END,
                CASE WHEN {$sunday} = 0 AND e.staff_pool_id IN ({$selfPools}) THEN h.id ELSE NULL END,
                :n1, :n2
           FROM employees e
           LEFT JOIN (SELECT employee_id, MIN(uc_id) AS uc_id FROM employee_assignments WHERE unassigned_at IS NULL GROUP BY employee_id) ac
                  ON ac.employee_id = e.id
           LEFT JOIN (SELECT a.uc_id, MIN(se.id) AS sid
                        FROM employee_assignments a
                        JOIN employees se ON se.id = a.employee_id AND se.status = 'active' AND se.staff_type_id IN ({$supTypes})
                       WHERE a.unassigned_at IS NULL GROUP BY a.uc_id) sup ON sup.uc_id = ac.uc_id
           LEFT JOIN attendance_holidays h ON h.holiday_date = :d2
           LEFT JOIN (SELECT employee_id, MIN(id) AS id FROM attendance_leave_requests
                       WHERE status = 'approved' AND :d3 BETWEEN start_date AND end_date GROUP BY employee_id) lv ON lv.employee_id = e.id
          WHERE e.status = 'active'
            AND DATE(e.created_at) <= :d4
            AND (e.staff_type_id IN ({$selfTypes}) OR e.staff_pool_id IN ({$selfPools})
                 OR (e.staff_pool_id IN ({$supPools}) AND e.staff_type_id NOT IN ({$supTypes}) AND ac.uc_id IS NOT NULL))",
        ['d1' => $date, 'd2' => $date, 'd3' => $date, 'd4' => $date, 'n1' => $now, 'n2' => $now]
    );
}

/** A record with display names joined — for lists, history and exports. */
function att_record_select(): string
{
    return "SELECT a.*, e.name AS employee_name, e.employee_code, e.status AS employee_status,
                   sp.name AS pool_name, st.name AS type_name,
                   u.name AS uc_name, u.code AS uc_code,
                   se.name AS supervisor_name,
                   ci.name AS checkin_by_name, co.name AS checkout_by_name,
                   lv.reason AS leave_reason, h.title AS holiday_title
              FROM attendance_records a
              JOIN employees e ON e.id = a.employee_id
              JOIN hr_staff_pools sp ON sp.id = a.staff_pool_id
              JOIN hr_staff_types st ON st.id = a.staff_type_id
              LEFT JOIN ucs u ON u.id = a.uc_id
              LEFT JOIN employees se ON se.id = a.supervisor_employee_id
              LEFT JOIN users ci ON ci.id = a.checkin_by_user_id
              LEFT JOIN users co ON co.id = a.checkout_by_user_id
              LEFT JOIN attendance_leave_requests lv ON lv.id = a.leave_request_id
              LEFT JOIN attendance_holidays h ON h.id = a.holiday_id";
}

/** Short human note for a record (resolution, leave/holiday detail). */
function att_record_note(array $r): string
{
    $parts = [];
    if ($r['status'] === 'holiday' && !empty($r['holiday_title'])) {
        $parts[] = (string)$r['holiday_title'];
    }
    if ($r['status'] === 'leave' && !empty($r['leave_reason'])) {
        $parts[] = (string)$r['leave_reason'];
    }
    if ($r['status'] === 'off_day') {
        $parts[] = 'Weekly off day (Sunday)';
    }
    if (in_array($r['status'], ['pending_review', 'incomplete'], true) && $r['review_state'] === 'pending') {
        $parts[] = $r['review_reason'] === 'no_checkout' ? 'Check-out missing — awaiting review' : 'No Check-in — awaiting review';
    }
    if ($r['resolution_source'] === 'review') {
        $parts[] = 'Resolved by review';
    } elseif ($r['resolution_source'] === 'correction') {
        $parts[] = 'Corrected';
    } elseif ($r['resolution_source'] === 'auto' && $r['status'] === 'absent') {
        $parts[] = 'Automatic Absent (unresolved at midnight)';
    }
    return implode(' · ', $parts);
}
