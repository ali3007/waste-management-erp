<?php
/**
 * Attendance module self-test (command line).
 *
 *   php tools/attendance-selftest.php --confirm-test-database
 *
 * Drives the REAL module functions with a simulated Asia/Karachi clock and
 * checks every business rule (windows, scope, sweep, midnight Absent, leave,
 * holidays, review, corrections, reports). It writes fixture data, so it
 * REFUSES to run unless the configured database name contains "test" — never
 * point it at a live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require_once dirname(__DIR__) . '/config.php';
if (!str_contains(strtolower(DB_NAME), 'test') || !in_array('--confirm-test-database', $argv, true)) {
    fwrite(STDERR, "Refusing to run: the database name must contain \"test\" and --confirm-test-database must be given.\n");
    exit(2);
}
require_once MODULES_PATH . '/attendance/bootstrap.php';
require_once MODULES_PATH . '/attendance/lib/export.php';

/* Child process for the concurrency test: one simultaneous check-in attempt. */
foreach ($argv as $a) {
    if (str_starts_with($a, '--race=')) {
        clock('2026-10-26 09:30:00');
        $sup = Database::fetch("SELECT e.id, e.user_id, e.status, e.staff_pool_id, e.staff_type_id, st.assignment_mode FROM employees e JOIN hr_staff_types st ON st.id = e.staff_type_id WHERE e.name = 'SupB'");
        $sup['id'] = (int)$sup['id'];
        $target = (int)Database::fetchValue("SELECT id FROM employees WHERE name = 'WorkerB1'");
        $rc = ['user_id' => (int)$sup['user_id'], 'is_super' => false, 'employee' => $sup, 'uc_ids' => [(int)Database::fetchValue("SELECT uc_id FROM employee_assignments WHERE employee_id = {$sup['id']} AND unassigned_at IS NULL")],
               'can' => static fn(string $s): bool => in_array($s, ['attendance.access', 'attendance.mark_team'], true)];
        $res = att_mark_event($target, 'checkin', $rc);
        echo $res['ok'] ? "RACE_OK\n" : 'RACE_NO ' . $res['code'] . "\n";
        exit(0);
    }
}

$pass = 0;
$fail = 0;
function t(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}\n";
    }
}
function section(string $s): void { echo "\n== {$s}\n"; }
function clock(string $dt): void { att_set_test_clock($dt); }

/* ---------------------------------------------------------------- fixtures */
foreach (['attendance_audit_changes', 'attendance_reviews', 'attendance_corrections', 'attendance_leave_history', 'attendance_leave_requests',
          'attendance_records', 'attendance_holidays', 'attendance_timing_configs', 'attendance_job_runs'] as $tbl) {
    Database::execute("DELETE FROM {$tbl}");
}
Database::execute("DELETE FROM activity_log WHERE module = 'attendance'");
Database::execute("DELETE FROM system_settings WHERE setting_key IN ('attendance_go_live_date','attendance_last_sweep_at','attendance_self_pool_ids','attendance_supervised_pool_ids','attendance_supervisor_type_ids','attendance_self_type_ids')");
Database::execute('DELETE FROM employee_assignments');
Database::execute('DELETE FROM employees');
Database::execute('DELETE FROM hr_staff_types');
Database::execute('DELETE FROM ucs');
Database::execute('DELETE FROM tehsils');
Database::execute('DELETE FROM districts');
Database::execute('DELETE FROM regions');
Database::execute("DELETE FROM users WHERE email LIKE 'att-test-%' OR cnic IN ('3520112345671', '3520100000777', '3520199999999')");
att_save_setting('attendance_self_type_ids', '');   // the scenarios below assume Supervisors are NOT self-marking
att_config_cache(true);

$role = (int)Database::fetchValue("SELECT id FROM roles ORDER BY id LIMIT 1");
$mkUser = static fn(string $n): int => Database::insert('users', ['role_id' => $role, 'name' => $n, 'email' => "att-test-{$n}@example.com",
    'password_hash' => 'x', 'login_type' => 'email', 'status' => 'active']);
$uAdmin = $mkUser('admin');
$uOfficeA = $mkUser('officeA');
$uSupA = $mkUser('supA');
$uSupB = $mkUser('supB');
$GLOBALS['__att_cli_actor'] = $uAdmin;

$region = Database::insert('regions', ['name' => 'R1', 'code' => 'R1']);
$district = Database::insert('districts', ['region_id' => $region, 'name' => 'D1', 'code' => 'D1']);
$tehsil = Database::insert('tehsils', ['district_id' => $district, 'name' => 'T1', 'code' => 'T1']);
$ucA = Database::insert('ucs', ['tehsil_id' => $tehsil, 'name' => 'UC-A', 'code' => 'UCA', 'uc_number' => '1']);
$ucB = Database::insert('ucs', ['tehsil_id' => $tehsil, 'name' => 'UC-B', 'code' => 'UCB', 'uc_number' => '2']);

$pool = array_column(Database::fetchAll('SELECT id, slug FROM hr_staff_pools'), 'id', 'slug');
$mkType = static fn(string $name, string $poolSlug, string $mode): int => Database::insert('hr_staff_types', ['staff_pool_id' => $pool[$poolSlug], 'name' => $name, 'slug' => strtolower($name), 'assignment_mode' => $mode, 'status' => 'active']);
$tSup = $mkType('Supervisor', 'field-staff', 'single');
$tWorker = $mkType('Worker', 'field-staff', 'single');
$tDriver = $mkType('Driver', 'transport-staff', 'single');
$tClerk = $mkType('Clerk', 'office-staff', 'none');
att_config_cache(true);

$n = 0;
$mkEmp = static function (string $name, string $poolSlug, int $type, ?int $uc, ?int $userId = null) use (&$n, $pool, $region, $district, $tehsil): int {
    $n++;
    $id = Database::insert('employees', ['employee_code' => 'E' . $n, 'user_id' => $userId, 'name' => $name, 'father_name' => 'F', 'cnic' => str_pad((string)(1000000000000 + $n), 13, '0'),
        'region_id' => $region, 'district_id' => $district, 'tehsil_id' => $tehsil, 'staff_pool_id' => $pool[$poolSlug], 'staff_type_id' => $type, 'status' => 'active',
        'created_at' => '2026-01-01 00:00:00']);
    if ($uc !== null) {
        Database::insert('employee_assignments', ['employee_id' => $id, 'uc_id' => $uc, 'assigned_at' => '2026-01-01 00:00:00']);
    }
    return $id;
};
$officeA = $mkEmp('OfficeA', 'office-staff', $tClerk, null, $uOfficeA);
$officeB = $mkEmp('OfficeB', 'office-staff', $tClerk, null);
$supA = $mkEmp('SupA', 'field-staff', $tSup, $ucA, $uSupA);
$supB = $mkEmp('SupB', 'field-staff', $tSup, $ucB, $uSupB);
$workA1 = $mkEmp('WorkerA1', 'field-staff', $tWorker, $ucA);
$workA2 = $mkEmp('WorkerA2', 'field-staff', $tWorker, $ucA);
$driverA = $mkEmp('DriverA', 'transport-staff', $tDriver, $ucA);
$workB1 = $mkEmp('WorkerB1', 'field-staff', $tWorker, $ucB);

$ctx = static function (?int $employeeId, int $userId, array $perms, bool $super = false): array {
    $emp = null;
    $ucs = [];
    if ($employeeId !== null) {
        $emp = Database::fetch('SELECT e.id, e.status, e.staff_pool_id, e.staff_type_id, st.assignment_mode FROM employees e JOIN hr_staff_types st ON st.id = e.staff_type_id WHERE e.id = :i', ['i' => $employeeId]);
        $emp['id'] = (int)$emp['id'];
        $ucs = array_map('intval', array_column(Database::fetchAll('SELECT uc_id FROM employee_assignments WHERE employee_id = :e AND unassigned_at IS NULL', ['e' => $employeeId]), 'uc_id'));
    }
    return ['user_id' => $userId, 'is_super' => $super, 'employee' => $emp, 'uc_ids' => $ucs, 'can' => static fn(string $s): bool => $super || in_array($s, $perms, true)];
};
$cOfficeA = $ctx($officeA, $uOfficeA, ['attendance.access', 'attendance.mark_self', 'attendance.leave_submit_self']);
$cSupA = $ctx($supA, $uSupA, ['attendance.access', 'attendance.mark_team', 'attendance.leave_submit_proxy', 'attendance.reports', 'attendance.dashboard', 'attendance.review']);
$cSupB = $ctx($supB, $uSupB, ['attendance.access', 'attendance.mark_team', 'attendance.reports']);
$cAdmin = $ctx(null, $uAdmin, [], true);
$cNoPerm = $ctx($supA, $uSupA, ['attendance.access']);
$st = static fn(int $e, string $d): ?string => Database::fetchValue('SELECT status FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $e, 'd' => $d]);

/* Calendar: Wed 2026-10-21, Thu 22, Fri 23, Sat 24, Sun 25, Mon 26 */
$WED = '2026-10-21'; $THU = '2026-10-22'; $FRI = '2026-10-23'; $SUN = '2026-10-25'; $MON = '2026-10-26';

/* ---------------------------------------------------------------- tests */
section('Clock, validation helpers');
clock("{$WED} 07:00:00");
t(att_today() === $WED, 'test clock drives att_today()');
t(att_is_sunday($SUN) && !att_is_sunday($MON), 'Sunday detection');
t(att_valid_time('9:00') === null && att_valid_time('09:00') === '09:00:00', 'time validation');

section('Timing validation & prospective versions');
clock("{$WED} 07:00:00");
[, $errs] = att_validate_timing(['checkin_start' => '08:00', 'checkin_end' => '10:00', 'checkout_start' => '09:30', 'checkout_end' => '19:00']);
t(count($errs) === 1, 'overlapping check-in/check-out windows rejected');
[, $errs] = att_validate_timing(['checkin_start' => '10:00', 'checkin_end' => '08:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00']);
t(count($errs) === 1, 'window that closes before it opens rejected');
t(!att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], '2026-10-20')[0], 'first config cannot start in the past');
t(att_sweep()['ran'] && Database::fetchValue('SELECT COUNT(*) FROM attendance_records') == 0, 'no rows are generated until timing is configured');
[$ok] = att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t($ok, 'first company default saved, effective today');
[$ok, $msg] = att_save_timing(null, ['checkin_start' => '08:30', 'checkin_end' => '10:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t($ok && att_config_for($pool['office-staff'], null, $WED)['checkin_start'] === '08:30:00', 'an ordinary edit is in force immediately (today)');
t(!att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], '2026-10-20')[0], 'an edit cannot be dated in the past');
[$ok] = att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t($ok && att_config_for($pool['office-staff'], null, $WED)['checkin_start'] === '08:00:00', 'restored the 08:00 default for the scenarios below');
[$ok] = att_save_timing(null, ['checkin_start' => '07:00', 'checkin_end' => '10:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], $THU);
t($ok && att_config_for($pool['office-staff'], null, $WED)['checkin_start'] === '08:00:00' && att_config_for($pool['office-staff'], null, $THU)['checkin_start'] === '07:00:00', 'a future-dated change is still supported and does not touch today');
Database::execute("DELETE FROM attendance_timing_configs WHERE effective_from = :d", ['d' => $THU]);
att_company_config('');
[$ok] = att_save_timing($ucB, ['checkin_start' => '09:00', 'checkin_end' => '11:00', 'checkout_start' => '17:00', 'checkout_end' => '20:00'], $THU);
$cfgB = att_config_for($pool['field-staff'], $ucB, $WED);
$cfgA = att_config_for($pool['field-staff'], $ucA, $WED);
t($ok, 'UC-B override scheduled for Thursday');
t($cfgB['source'] === 'company' && $cfgA['source'] === 'company', "today's windows are NOT altered by a UC change scheduled for later");
t(att_config_for($pool['field-staff'], $ucB, $THU)['checkin_end'] === '11:00:00', 'UC-B override applies from its effective date');
t(att_config_for($pool['office-staff'], null, $THU)['checkin_end'] === '10:00:00', 'office staff always use the company default');

section('Row generation (Wed 07:00)');
$r = att_sweep();
t($r['ran'] && $r['today_rows'] === 6, 'rows for the 6 tracked employees (supervisors excluded): ' . $r['today_rows']);
t($st($supA, $WED) === null, 'supervisor is not generated as a supervised employee');
t($st($officeA, $WED) === 'not_marked', 'default status Not marked yet');
t(att_sweep()['today_rows'] === 0, 'sweep is idempotent (no duplicate rows)');

section('Marking — windows');
$res = att_mark_event($officeA, 'checkin', $cOfficeA);
t(!$res['ok'] && $res['code'] === 'window_not_open', 'check-in before the window opens is refused');
clock("{$WED} 08:30:00");
$res = att_mark_event($officeA, 'checkin', $cOfficeA);
t($res['ok'] && $st($officeA, $WED) === 'checked_in', 'check-in inside the window works and is NOT yet Present');
t(!att_mark_event($officeA, 'checkin', $cOfficeA)['ok'], 'duplicate check-in refused');
t(att_mark_event($officeA, 'checkout', $cOfficeA)['code'] === 'window_not_open', 'check-out before its window refused');
t(att_mark_event($officeB, 'checkin', $cOfficeA)['code'] === 'not_authorized', 'office staff cannot mark someone else');
t(att_mark_event($officeA, 'checkin', $cSupA)['code'] === 'not_authorized', 'a supervisor cannot mark Office Staff');
clock("{$WED} 17:00:00");
$res = att_mark_event($officeA, 'checkout', $cOfficeA);
t($res['ok'] && $st($officeA, $WED) === 'present', 'check-out completes the day as Present');
t(att_mark_event($officeA, 'checkout', $cOfficeA)['code'] === 'duplicate', 'duplicate check-out refused');
t(att_mark_event($officeB, 'checkout', $cAdmin)['code'] === 'not_authorized', 'nobody (not even an admin) marks Office Staff on their behalf');

section('Supervisor scope');
clock("{$WED} 08:40:00");
t(att_mark_event($workA1, 'checkin', $cSupA)['ok'], 'supervisor A marks own-UC worker');
t(att_mark_event($workB1, 'checkin', $cSupA)['code'] === 'not_authorized', 'supervisor A cannot mark UC-B worker');
t(att_mark_event($supA, 'checkin', $cSupA)['code'] === 'not_authorized', 'supervisor cannot mark themselves');
t(att_mark_event($workA2, 'checkin', $cNoPerm)['code'] === 'not_authorized', 'no mark_team permission → refused');
t(att_mark_event($driverA, 'checkin', $cSupA)['ok'], 'transport staff in the same UC can be marked');
t(att_mark_event($workA1, 'checkin', $cSupB)['code'] === 'not_authorized', 'supervisor B cannot mark UC-A worker');
$roster = array_column(att_supervised_roster($ucA), 'id');
t(in_array($workA1, array_map('intval', $roster), true) && !in_array($supA, array_map('intval', $roster), true) && !in_array($workB1, array_map('intval', $roster), true), 'roster = active assigned staff of that UC only');

section('Window closure → Pending Review / Incomplete (Wed)');
clock("{$WED} 10:05:00");
att_sweep();
t($st($workA2, $WED) === 'pending_review', 'no check-in after window close → Pending Review');
t($st($officeB, $WED) === 'pending_review', 'office staff with no check-in → Pending Review');
t($st($workA1, $WED) === 'checked_in', 'checked-in staff untouched at 10:05');
$res = att_mark_event($workA2, 'checkin', $cSupA);
t(!$res['ok'] && $res['code'] === 'window_closed', 'late check-in is refused (no late marking)');
t(att_mark_event($workA2, 'checkin', $cAdmin)['code'] === 'window_closed', 'even a Super Admin cannot record a late check-in');
$pending = Database::fetchValue('SELECT review_state FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $workA2, 'd' => $WED]);
t($pending === 'pending', 'case is awaiting review');

section('Review');
t(!att_review_decide((int)Database::fetchValue('SELECT id FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $workA2, 'd' => $WED]), 'present', 'x', $cSupA)['ok'], 'a review needs a real reason');
$rid = (int)Database::fetchValue('SELECT id FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $workA2, 'd' => $WED]);
t(!att_review_decide($rid, 'present', 'Was on official duty', $cSupB)['ok'], 'a reviewer outside the UC scope cannot decide');
$res = att_review_decide($rid, 'present', 'Was on official duty at the depot', $cSupA);
t($res['ok'] && $st($workA2, $WED) === 'present', 'reviewer resolves as Present');
t(Database::fetchValue('SELECT checkin_at FROM attendance_records WHERE id = :i', ['i' => $rid]) === null, 'review records NO check-in time (not late attendance)');
t(!att_review_decide($rid, 'absent', 'changing my mind later', $cSupA)['ok'], 'a resolved case cannot be re-decided');

section('Check-out window closure (UC windows still company-wide today)');
clock("{$WED} 19:05:00");
att_sweep();
t($st($workA1, $WED) === 'incomplete', 'check-in without check-out → Incomplete');
t($st($driverA, $WED) === 'incomplete', 'same for transport staff');
t(att_mark_event($workA1, 'checkout', $cSupA)['code'] === 'window_closed', 'late check-out refused');

section('Midnight finalisation (Thu 00:05)');
$beforeIds = Database::fetchValue("SELECT COUNT(*) FROM attendance_records WHERE attendance_date = :d AND status = 'present'", ['d' => $WED]);
clock("{$THU} 00:05:00");
$r = att_sweep();
t($r['ran'] && count($r['days']) >= 1 && $r['days'][0]['ok'], 'Wednesday finalised: ' . json_encode($r['days']));
t($st($workA1, $WED) === 'absent' && $st($driverA, $WED) === 'absent', 'Incomplete cases not resolved by midnight → Absent');
t($st($officeB, $WED) === 'absent', 'unresolved Pending Review → Absent');
t($st($officeA, $WED) === 'present' && $st($workA2, $WED) === 'present', 'Present (events) and review-approved Present are untouched');
t(Database::fetchValue("SELECT COUNT(*) FROM attendance_records WHERE attendance_date = :d AND finalized_at IS NULL", ['d' => $WED]) == 0, 'every row of the day is locked');
t(Database::fetchValue("SELECT checkin_at FROM attendance_records WHERE employee_id = :e AND attendance_date = :d", ['e' => $workA1, 'd' => $WED]) !== null, 'recorded check-in time is preserved on an auto-Absent row');
$r2 = att_sweep();
t(count($r2['days']) === 0, 'finalisation is idempotent: a second run reprocesses nothing');
t(Database::fetchValue("SELECT COUNT(*) FROM attendance_records WHERE attendance_date = :d AND status = 'absent'", ['d' => $WED]) == 4, 'exactly 4 absentees on Wednesday');
t(Database::fetchValue("SELECT COUNT(*) FROM attendance_audit_changes WHERE outcome = 'blocked'") > 5, 'refused attempts are audited');
t(att_review_decide($rid, 'absent', 'trying after midnight', $cSupA)['ok'] === false, 'no review after finalisation');

section('Thursday: UC-B override in force; row generation after midnight');
clock("{$THU} 10:30:00");
att_sweep();
t($st($workA1, $THU) === 'pending_review' && $st($workB1, $THU) === 'not_marked', 'UC-A closed at 10:00 but UC-B still open until 11:00');
t(att_mark_event($workB1, 'checkin', $cSupB)['ok'], 'UC-B worker can still check in at 10:30 (UC override)');
t(att_mark_event($workA1, 'checkin', $cSupA)['code'] === 'window_closed', 'UC-A worker cannot at 10:30');

section('Leave');
clock("{$THU} 11:30:00");
$res = att_leave_submit($workA2, $MON, $MON, 'Family function out of town', $cSupA);
t($res['ok'], 'supervisor submits leave on behalf of assigned worker');
$leaveId = (int)($res['id'] ?? 0);
t(!att_leave_submit($workA2, $MON, $MON, 'Duplicate request for same day', $cSupA)['ok'], 'overlapping leave refused');
t(!att_leave_submit($workB1, $MON, $MON, 'Not my employee at all', $cSupA)['ok'], 'supervisor cannot submit for another UC');
t(!att_leave_submit($officeA, $MON, $MON, 'Proxy for office staff', $cSupA)['ok'], 'office leave must be submitted by the employee');
t(att_leave_submit($officeA, $MON, $MON, 'Personal work at home town', $cOfficeA)['ok'], 'office staff submits own leave');
t(!att_leave_submit($workA2, $WED, $WED, 'Backdated leave request', $cSupA)['ok'], 'leave cannot start in the past');
$cApprover = $ctx(null, $uAdmin, ['attendance.access', 'attendance.leave_approve']);
t(!att_leave_decide($leaveId, 'approve', '', $cSupA)['ok'], 'submitter without approve permission cannot approve');
t(!att_leave_decide($leaveId, 'reject', '', $cApprover)['ok'], 'rejection needs a reason');
$res = att_leave_decide($leaveId, 'approve', '', $cApprover);
t($res['ok'], 'approver approves');
t(!att_leave_decide($leaveId, 'approve', '', $cApprover)['ok'], 'cannot decide twice');
$officeLeave = (int)Database::fetchValue("SELECT id FROM attendance_leave_requests WHERE employee_id = :e", ['e' => $officeA]);
$cSelfApprover = $ctx($officeA, $uOfficeA, ['attendance.access', 'attendance.leave_approve']);
t(!att_leave_decide($officeLeave, 'approve', '', $cSelfApprover)['ok'], 'nobody approves their own leave');

section('Sunday = Off Day, Monday holiday + leave');
clock("{$SUN} 09:00:00");
att_sweep();
t($st($officeA, $SUN) === 'off_day' && $st($workA1, $SUN) === 'off_day', 'Sunday is Off Day for everyone');
t(att_mark_event($workA1, 'checkin', $cSupA)['code'] === 'non_working', 'no marking on an off day');
$cHol = $ctx(null, $uAdmin, ['attendance.access', 'attendance.holidays_manage']);
t(!att_holiday_save(null, $WED, 'Past', '', $cHol)['ok'], 'past holidays cannot be added');
t(att_holiday_save(null, $MON, 'Founders Day', '', $cHol)['ok'], 'holiday added for Monday');
t(!att_holiday_save(null, $MON, 'Again', '', $cHol)['ok'], 'one holiday per date');
clock("{$MON} 00:10:00");
att_sweep();
t($st($officeB, $MON) === 'holiday', 'Office Staff get Holiday');
t($st($workA1, $MON) === 'not_marked', 'holiday does NOT apply to Field Staff');
t($st($workA2, $MON) === 'leave', 'approved leave becomes Leave (field)');
t($st($officeA, $MON) === 'holiday', 'holiday outranks a pending/approved leave for office (never double counted)');
$hid = (int)Database::fetchValue('SELECT id FROM attendance_holidays');
t(att_holiday_delete($hid, $cHol)['ok'] && $st($officeB, $MON) === 'not_marked', 'removing a holiday on its day restores the default status');

section('Corrections');
$cEdit = $ctx(null, $uAdmin, ['attendance.access', 'attendance.edit']);
t(!att_correct($workA1, $WED, ['status' => 'present', 'checkin' => '08:30', 'checkout' => '17:00', 'reason' => ''], $cEdit)['ok'], 'correction needs a reason');
t(!att_correct($workA1, $WED, ['status' => 'present', 'checkin' => '08:30', 'checkout' => '', 'reason' => 'Supervisor confirmed'], $cEdit)['ok'], 'Present needs both times');
t(!att_correct($workA1, $WED, ['status' => 'present', 'checkin' => '17:30', 'checkout' => '08:30', 'reason' => 'Supervisor confirmed'], $cEdit)['ok'], 'check-out before check-in refused');
t(!att_correct($workA1, $WED, ['status' => 'absent', 'checkin' => '', 'checkout' => '17:00', 'reason' => 'Supervisor confirmed'], $cEdit)['ok'], 'check-out without check-in refused');
t(!att_correct($workA1, $WED, ['status' => 'present', 'checkin' => '08:30', 'checkout' => '17:00', 'reason' => 'Supervisor confirmed'], $cSupA)['ok'], 'no Attendance Edit permission → refused');
$res = att_correct($workA1, $WED, ['status' => 'present', 'checkin' => '08:30', 'checkout' => '17:00', 'reason' => 'Supervisor confirmed on paper register'], $cEdit);
t($res['ok'] && $st($workA1, $WED) === 'present', 'authorised correction applied to a finalised day');
t(Database::fetchValue('SELECT original_value FROM attendance_corrections ORDER BY id DESC LIMIT 1') !== null, 'original value kept in correction history');
t(!att_correct($workA1, $MON, ['status' => 'absent', 'checkin' => '', 'checkout' => '', 'reason' => 'Open day correction'], $cEdit)['ok'], 'today\'s open attendance cannot be edited by correction');
$cSelfEdit = $ctx($supA, $uSupA, ['attendance.access', 'attendance.edit']);
t(!att_correct($supA, $WED, ['status' => 'present', 'checkin' => '08:30', 'checkout' => '17:00', 'reason' => 'Fix my own record'], $cSelfEdit)['ok'], 'nobody corrects their own attendance');

section('Reports & scope');
$f = att_read_report_filters(['period' => 'range', 'from' => $WED, 'to' => $MON]);
$all = att_report_rows($cAdmin, $f, 0);
$supRows = att_report_rows($cSupA, $f, 0);
$uc = array_unique(array_column($supRows, 'uc_name'));
t(count($all) > count($supRows) && $uc === ['UC-A'], 'supervisor sees only UC-A rows; admin sees all (' . count($supRows) . ' vs ' . count($all) . ')');
$f2 = att_read_report_filters(['period' => 'daily', 'date' => $WED, 'uc' => (string)$ucB]);
t(count(att_report_rows($cSupA, $f2, 0)) === 0, 'URL tampering with another UC returns nothing for a supervisor');
$sum = att_report_summary($cAdmin, att_read_report_filters(['period' => 'daily', 'date' => $WED]));
t(array_sum($sum) === 6, 'status partition adds up to the total (6)');
$d = att_dashboard_data($cAdmin, $WED);
t($d['cards']['total'] === 6 && $d['cards']['present'] + $d['cards']['absent'] === 6, 'dashboard totals reconcile');
t(att_dashboard_data($cSupA, $WED)['cards']['total'] === 3, 'supervisor dashboard limited to their UC');
$xl = null;
t(function_exists('att_export_cells'), 'export helpers present');

section('Historical context & transfers');
Database::execute('UPDATE employee_assignments SET unassigned_at = NOW() WHERE employee_id = :e AND unassigned_at IS NULL', ['e' => $workA1]);
Database::insert('employee_assignments', ['employee_id' => $workA1, 'uc_id' => $ucB, 'assigned_at' => date('Y-m-d H:i:s')]);
t((int)Database::fetchValue('SELECT uc_id FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $workA1, 'd' => $WED]) === $ucA, 'transfer does not rewrite the historical UC on past records');
t(att_mark_event($workA1, 'checkin', $cSupA)['code'] === 'not_authorized', 'old supervisor loses access after the transfer');

section('Supervisors mark their own attendance (default)');
clock("{$MON} 09:20:00");
Database::execute("DELETE FROM system_settings WHERE setting_key = 'attendance_self_type_ids'");
att_config_cache(true);
$cSupSelf = $ctx($supA, $uSupA, ['attendance.access', 'attendance.mark_team', 'attendance.mark_self']);
t(att_load_employee($supA)['mode'] === 'self', 'by default a Supervisor is a self-marking employee');
att_generate_rows($MON);
t($st($supA, $MON) === 'not_marked', 'a row exists for the Supervisor');
t(!in_array($supA, array_map('intval', array_column(att_supervised_roster($ucA), 'id')), true), 'the Supervisor is still not on anyone\'s marking list');
t(att_mark_event($supA, 'checkin', $cSupSelf)['ok'] && $st($supA, $MON) === 'checked_in', 'the Supervisor checks himself in');
t(att_mark_event($supA, 'checkin', $cSupB)['code'] === 'not_authorized', 'another Supervisor cannot mark him');
Database::execute('DELETE FROM attendance_records WHERE employee_id IN (:a, :b) AND attendance_date = :d', ['a' => $supA, 'b' => $supB, 'd' => $MON]);
att_save_setting('attendance_self_type_ids', '');
att_config_cache(true);

section('Concurrency (two processes, one check-in)');
clock("{$MON} 08:50:00");
$php = PHP_BINARY;
$script = escapeshellarg(__FILE__);
$out = [];
$cmd = fn(int $i) => sprintf('%s %s --race=%d --confirm-test-database 2>&1', escapeshellarg($php), $script, $i);
$h = [];
for ($i = 0; $i < 3; $i++) {
    $h[$i] = popen($cmd($i), 'r');
}
$wins = 0;
foreach ($h as $p) {
    $o = stream_get_contents($p);
    pclose($p);
    $wins += substr_count($o, 'RACE_OK');
}
t($wins === 1, "three simultaneous check-ins → exactly one succeeds ({$wins})");

section('Issue 1 — Supervisor whose account was created by CNIC (no employees.user_id)');
$TUE = '2026-10-27';
clock("{$TUE} 09:00:00");
$cnicSup = '3520112345671';
$ucX = Database::insert('ucs', ['tehsil_id' => $tehsil, 'name' => 'UC-X (Zeta)', 'code' => 'UCX', 'uc_number' => '9']);
$rxSup = $mkEmp('RashidSup', 'field-staff', $tSup, $ucX);
Database::execute('UPDATE employees SET cnic = :c WHERE id = :i', ['c' => $cnicSup, 'i' => $rxSup]);
$rxW1 = $mkEmp('RX Worker1', 'field-staff', $tWorker, $ucX);
$rxW2 = $mkEmp('RX Worker2', 'field-staff', $tWorker, $ucX);
$rxOff = $mkEmp('RX Inactive', 'field-staff', $tWorker, $ucX);
Database::execute("UPDATE employees SET status = 'inactive' WHERE id = :i", ['i' => $rxOff]);
$uRx = Database::insert('users', ['role_id' => $role, 'name' => 'Rashid', 'cnic' => $cnicSup, 'login_type' => 'cnic', 'password_hash' => 'x', 'status' => 'active']);
t(Database::fetchValue('SELECT user_id FROM employees WHERE id = :i', ['i' => $rxSup]) === null, 'precondition: employees.user_id is NULL (the Users module never sets it)');
[$resolved, $by] = att_resolve_employee($uRx);
t($resolved !== null && (int)$resolved['id'] === $rxSup && $by === 'cnic', 'login CNIC resolves to the Supervisor employee record');
$uOther = Database::insert('users', ['role_id' => $role, 'name' => 'Stranger', 'cnic' => '3520100000777', 'login_type' => 'cnic', 'password_hash' => 'x', 'status' => 'active']);
t(att_resolve_employee($uOther)[0] === null, 'a CNIC that matches no employee resolves to nobody');
Database::execute('UPDATE employees SET user_id = :u WHERE id = :i', ['u' => $uAdmin, 'i' => $rxSup]);
t(att_resolve_employee($uRx)[0] === null, 'an employee already linked to another account is never taken over by CNIC');
Database::execute('UPDATE employees SET user_id = NULL WHERE id = :i', ['i' => $rxSup]);
$cRx = $ctx($rxSup, $uRx, ['attendance.access', 'attendance.mark_team']);
$rosterIds = array_map('intval', array_column(att_supervised_roster($ucX), 'id'));
sort($rosterIds);
t($rosterIds === [$rxW1, $rxW2], 'roster = the 2 ACTIVE workers of his UC (no inactive, no supervisor, no other UC)');
att_sweep();
t(att_mark_event($rxW1, 'checkin', $cRx)['ok'], 'he marks a worker of his own UC');
t(att_mark_event($workA2, 'checkin', $cRx)['code'] === 'not_authorized', 'he cannot mark a worker of another UC');
t(att_mark_event($rxOff, 'checkin', $cRx)['code'] === 'not_authorized', 'he cannot mark an inactive worker');
$cUnlinked = ['user_id' => $uOther, 'is_super' => false, 'employee' => null, 'uc_ids' => [], 'can' => static fn(string $s): bool => in_array($s, ['attendance.access', 'attendance.mark_team'], true)];
t(att_mark_event($rxW2, 'checkin', $cUnlinked)['code'] === 'not_authorized', 'an unlinked non-super account can no longer mark anyone (fail closed)');
t(att_actor_ucs($cUnlinked) === [] && count(att_actor_ucs($cAdmin)) >= 3, 'unlinked account gets no UC list; Super Admin still sees all');
t(count(att_actor_ucs($cRx)) === 1 && (int)att_actor_ucs($cRx)[0]['id'] === $ucX, 'the Supervisor is offered exactly his own UC');

section('Issue 2 — a timing edit takes effect immediately and never rewrites recorded attendance');
$WED2 = '2026-10-28';
clock("{$WED2} 09:00:00");
att_save_setting('attendance_self_type_ids', '');
att_config_cache(true);
att_sweep();
$cOfficeB = $ctx($officeB, $uAdmin, ['attendance.access', 'attendance.mark_self']);
t(att_mark_event($officeA, 'checkin', $cOfficeA)['ok'], 'officeA checks in at 09:00 under the 08:00–10:00 window');
$marked = Database::fetch('SELECT status, checkin_at FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $officeA, 'd' => $WED2]);
clock("{$WED2} 10:15:00");
att_sweep();
t($st($officeB, $WED2) === 'pending_review' && $st($rxW1, $WED2) === 'pending_review', 'window closed at 10:00: unmarked office + UC-X staff are flagged Pending Review');
t($st($workB1, $WED2) === 'not_marked', 'UC-B has its own window (to 11:00), so its worker is still open');
t(att_mark_event($officeB, 'checkin', $cOfficeB)['code'] === 'window_closed', 'check-in is refused after the old window');
$pastSnapshot = Database::fetchValue("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, status, IFNULL(checkin_at, ''), IFNULL(checkout_at, '')) ORDER BY id)) FROM attendance_records WHERE attendance_date < :d", ['d' => $WED2]);
$ucBBefore = att_config_for($pool['field-staff'], $ucB, $WED2);

[$ok, $msg] = att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '11:30', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t($ok && str_contains($msg, 'in force now'), 'admin extends the company check-in to 11:30 at 10:15: ' . $msg);
t(att_config_for($pool['office-staff'], null, $WED2)['checkin_end'] === '11:30:00', "today's window is 11:30 straight away (same request cycle, no cache)");
t($st($officeB, $WED2) === 'not_marked', 'unmarked office case the old window had closed is open again');
t($st($rxW1, $WED2) === 'not_marked', '…and so is the company-timed field case');
t(att_mark_event($officeB, 'checkin', $cOfficeB)['ok'], 'officeB can now check in inside the new window');
$after = Database::fetch('SELECT status, checkin_at FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $officeA, 'd' => $WED2]);
t($after === $marked, "an already-marked record is untouched (status + check-in time identical)");
t(att_config_for($pool['field-staff'], $ucB, $WED2) === $ucBBefore, "UC-B's own override is not affected by the company edit");
t($pastSnapshot === Database::fetchValue("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, status, IFNULL(checkin_at, ''), IFNULL(checkout_at, '')) ORDER BY id)) FROM attendance_records WHERE attendance_date < :d", ['d' => $WED2]), 'past days are byte-for-byte unchanged');
t(att_config_for($pool['office-staff'], null, '2026-10-27')['checkin_end'] === '10:00:00', "yesterday still resolves to the timing that applied to it");

clock("{$WED2} 10:40:00");
[$ok] = att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:30', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t($ok && $st($rxW2, $WED2) === 'pending_review', 'shortening the window below the current time sends unmarked cases to review at once');
t($st($officeA, $WED2) === 'checked_in', 'a shorter window never un-marks someone who already checked in');
t(att_mark_event($rxW2, 'checkin', $cRx)['code'] === 'window_closed', 'a window that is closed stays closed — no bypass');

[$ok] = att_save_timing($ucX, ['checkin_start' => '08:00', 'checkin_end' => '12:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t($ok && $st($rxW2, $WED2) === 'not_marked', 'a UC-specific edit reopens only that UC');
t($st($rxW1, $WED2) === 'not_marked' && att_mark_event($rxW2, 'checkin', $cRx)['ok'], 'UC-X staff can check in under the UC window');
t(att_config_for($pool['office-staff'], null, $WED2)['checkin_end'] === '10:30:00', 'the company default for Office Staff is unchanged by the UC edit');
t($st($officeB, $WED2) === 'checked_in', "other categories' recorded check-ins are untouched");

[$ok, $msg] = att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:30', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], '2026-10-27');
t(!$ok, 'editing a past date is refused');
[$ok, $msg] = att_save_timing(null, ['checkin_start' => '09:00', 'checkin_end' => '09:00', 'checkout_start' => '16:00', 'checkout_end' => '19:00'], null);
t(!$ok, 'invalid windows are still rejected');
[$ok, $msg] = att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '10:30', 'checkout_start' => '16:00', 'checkout_end' => '19:30'], '2026-10-30');
t($ok && str_contains($msg, 'scheduled') && att_config_for($pool['office-staff'], null, $WED2)['checkout_end'] === '19:00:00', 'a future-dated edit is scheduled and leaves today as it is');
t((int)Database::fetchValue("SELECT COUNT(*) FROM activity_log WHERE action = 'timing_changed' AND module = 'attendance'") >= 5 && (int)Database::fetchValue("SELECT COUNT(*) FROM activity_log WHERE action = 'timing_reopened'") >= 2, 'timing changes and reopened cases are audited');
$sw = att_sweep();
t($sw['ran'] && $st($officeA, $WED2) === 'checked_in', 'the scheduler agrees with the new timing and leaves marked records alone');

section('Issue 3 — independent timing per staff category (Office / Field / Transport)');
$D3 = '2026-10-29';
clock("{$D3} 07:00:00");
att_config_cache(true);
$office = $pool['office-staff']; $field = $pool['field-staff']; $transport = $pool['transport-staff'];
$win = static fn(int $p, ?int $uc): string => ($c = att_config_for($p, $uc, att_today())) === null ? 'none'
    : substr($c['checkin_start'], 0, 5) . '-' . substr($c['checkin_end'], 0, 5) . '/' . substr($c['checkout_start'], 0, 5) . '-' . substr($c['checkout_end'], 0, 5);
$companyRowsBefore = Database::fetchValue("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, checkin_start, checkin_end, checkout_start, checkout_end) ORDER BY id)) FROM attendance_timing_configs WHERE scope_key = 'company'");
$base = '08:00-10:30/16:00-19:00';
t($win($office, null) === $base && $win($field, $ucA) === $base && $win($transport, $ucA) === $base, 'baseline: all three categories follow the company default');

[$ok] = att_save_timing(null, ['checkin_start' => '08:30', 'checkin_end' => '09:30', 'checkout_start' => '17:00', 'checkout_end' => '18:00'], null, false, $office);
t($ok && $win($office, null) === '08:30-09:30/17:00-18:00', 'Office timing saved and in force immediately');
t($win($field, $ucA) === $base && $win($transport, $ucA) === $base, 'saving Office left Field and Transport unchanged');
t($companyRowsBefore === Database::fetchValue("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, checkin_start, checkin_end, checkout_start, checkout_end) ORDER BY id)) FROM attendance_timing_configs WHERE scope_key = 'company'"), 'the company default row itself was not modified');

[$ok] = att_save_timing(null, ['checkin_start' => '06:00', 'checkin_end' => '08:00', 'checkout_start' => '14:00', 'checkout_end' => '15:00'], null, false, $field);
t($ok && $win($field, $ucA) === '06:00-08:00/14:00-15:00', 'Field timing saved');
t($win($office, null) === '08:30-09:30/17:00-18:00' && $win($transport, $ucA) === $base, 'saving Field left Office and Transport unchanged');

[$ok] = att_save_timing(null, ['checkin_start' => '07:00', 'checkin_end' => '09:00', 'checkout_start' => '15:00', 'checkout_end' => '16:00'], null, false, $transport);
t($ok && $win($transport, $ucA) === '07:00-09:00/15:00-16:00', 'Transport timing saved');
t($win($office, null) === '08:30-09:30/17:00-18:00' && $win($field, $ucA) === '06:00-08:00/14:00-15:00', 'saving Transport left Office and Field unchanged');

[$ok] = att_save_timing($ucA, ['checkin_start' => '05:00', 'checkin_end' => '06:30', 'checkout_start' => '13:00', 'checkout_end' => '14:00'], null, false, $field);
$ucZ = Database::insert('ucs', ['tehsil_id' => $tehsil, 'name' => 'UC-Z', 'code' => 'UCZ', 'uc_number' => '7']);
t($ok && $win($field, $ucA) === '05:00-06:30/13:00-14:00', 'Field UC-specific override works');
t($win($transport, $ucA) === '07:00-09:00/15:00-16:00' && $win($office, null) === '08:30-09:30/17:00-18:00', 'a Field UC override does not touch Transport in the same UC, or Office');
t($win($field, $ucZ) === '06:00-08:00/14:00-15:00', 'Field in another UC keeps the Field category timing');
t($win($field, $ucX) === '08:00-12:00/16:00-19:00' && $win($transport, $ucX) === '08:00-12:00/16:00-19:00', 'a legacy UC-wide override (pre-existing data) still works and still beats the category timing');
t(!att_save_timing($ucA, ['checkin_start' => '08:00', 'checkin_end' => '09:00', 'checkout_start' => '16:00', 'checkout_end' => '17:00'], null, false, $office)[0], 'Office Staff cannot get a UC override (they have no UC)');
t(!att_save_timing(null, ['checkin_start' => '08:00', 'checkin_end' => '09:00', 'checkout_start' => '16:00', 'checkout_end' => '17:00'], null, false, 99999)[0], 'an unknown category is rejected');

/* Each category's check-in uses ITS OWN window (07:00 now: Office opens 08:30, Field UC-A closed 06:30, Transport open 07:00–09:00). */
att_sweep();
clock("{$D3} 07:30:00");
att_sweep();
t(att_mark_event($officeB, 'checkin', $cOfficeB)['code'] === 'window_not_open', 'Office check-in uses the Office window (opens 08:30)');
t(att_mark_event($workA2, 'checkin', $cSupA)['code'] === 'window_closed', 'Field check-in uses the Field UC window (closed 06:30)');
$tr = att_mark_event($driverA, 'checkin', $cSupA);
t($tr['ok'], 'Transport check-in uses the Transport window (open 07:00–09:00)');
$driverRow = Database::fetch('SELECT status, checkin_at FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $driverA, 'd' => $D3]);
t($st($workA2, $D3) === 'pending_review', 'the scheduler flagged the Field case with the Field window, not the Office/Transport one');
t($st($officeB, $D3) === 'not_marked' && $st($driverA, $D3) === 'checked_in', 'Office is still open and Transport is checked in — each judged on its own window');

/* Editing one category re-opens only that category's cases; recorded events never change. */
$pastSnap = Database::fetchValue("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, status, IFNULL(checkin_at,''), IFNULL(checkout_at,'')) ORDER BY id)) FROM attendance_records WHERE attendance_date < :d", ['d' => $D3]);
[$ok, $msg] = att_save_timing($ucA, ['checkin_start' => '05:00', 'checkin_end' => '09:30', 'checkout_start' => '13:00', 'checkout_end' => '14:00'], null, false, $field);
t($ok && $st($workA2, $D3) === 'not_marked', 'extending the Field UC-A window re-opens the Field case: ' . $msg);
t($st($officeB, $D3) === 'not_marked' && $st($driverA, $D3) === 'checked_in', 'Office and Transport cases untouched by the Field edit');
t(att_mark_event($workA2, 'checkin', $cSupA)['ok'], 'the Field worker can now check in under the new Field window');
t(Database::fetch('SELECT status, checkin_at FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $driverA, 'd' => $D3]) === $driverRow, 'an already-recorded Transport check-in is unchanged');
t($pastSnap === Database::fetchValue("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, status, IFNULL(checkin_at,''), IFNULL(checkout_at,'')) ORDER BY id)) FROM attendance_records WHERE attendance_date < :d", ['d' => $D3]), 'previously recorded days are unchanged by all these edits');
clock("{$D3} 09:20:00");
t(att_mark_event($officeB, 'checkin', $cOfficeB)['ok'], 'Office check-in at 09:20 is inside the Office window (08:30–09:30)');

/* Future-dated change is still a separate, supported feature. */
[$ok, $msg] = att_save_timing(null, ['checkin_start' => '09:00', 'checkin_end' => '10:00', 'checkout_start' => '17:00', 'checkout_end' => '18:30'], '2026-11-02', false, $office);
t($ok && str_contains($msg, 'scheduled') && $win($office, null) === '08:30-09:30/17:00-18:00', 'a future-dated Office change is scheduled and does not touch today');
t(att_config_for($office, null, '2026-11-02')['checkin_start'] === '09:00:00' && att_config_for($field, $ucA, '2026-11-02')['checkin_start'] === '05:00:00', 'on the effective date only Office switches');
[$ok] = att_save_timing(null, [], null, true, $field);
t($ok && $win($field, $ucZ) === $base, '"remove this level" makes a category follow the company default again');
t($win($office, null) === '08:30-09:30/17:00-18:00', '…without affecting Office');

/* Permissions. */
$perm = static fn(array $set): callable => static fn(string $s): bool => in_array($s, $set, true);
$officePerm = 'attendance.timing_manage_office_staff';
t((int)Database::fetchValue("SELECT COUNT(*) FROM permissions WHERE slug IN ('attendance.timing_manage_office_staff','attendance.timing_manage_field_staff','attendance.timing_manage_transport_staff')") === 3, 'one manage permission per category is registered in the existing permission system');
t(att_can_manage_timing($office, $perm(['attendance.timing_manage'])) && att_can_manage_timing(null, $perm(['attendance.timing_manage'])), 'the global permission manages every category and the company default');
t(att_can_manage_timing($office, $perm([$officePerm])) && !att_can_manage_timing($field, $perm([$officePerm])) && !att_can_manage_timing($transport, $perm([$officePerm])), 'the Office permission manages Office only');
t(!att_can_manage_timing(null, $perm([$officePerm])), 'a category permission cannot touch the company default');
t(!att_can_manage_timing($office, $perm(['attendance.timing_view', 'attendance.access'])) && !att_can_manage_timing($office, $perm([])), 'view-only and no-permission users cannot manage');
t(array_column(att_manageable_pools($perm(['attendance.timing_manage_field_staff'])), 'slug') === ['field-staff'], 'the Timing screen offers a Field-only manager just the Field category');

section('Bug — roster shows an employee but marking is refused (employee with several open UC assignments)');
$D4 = '2026-10-30';
clock("{$D4} 09:00:00");
$tMulti = Database::insert('hr_staff_types', ['staff_pool_id' => $pool['field-staff'], 'name' => 'Multi Worker', 'slug' => 'multi-worker', 'assignment_mode' => 'multi', 'status' => 'active']);
$ucP = Database::insert('ucs', ['tehsil_id' => $tehsil, 'name' => 'UC-P', 'code' => 'UCP', 'uc_number' => '31']);
$ucQ = Database::insert('ucs', ['tehsil_id' => $tehsil, 'name' => 'UC-Q', 'code' => 'UCQ', 'uc_number' => '32']);
$supP = $mkEmp('SupP', 'field-staff', $tSup, $ucP);
$supQ = $mkEmp('SupQ', 'field-staff', $tSup, $ucQ);
$shared = $mkEmp('Shared Worker', 'field-staff', $tMulti, $ucP);
Database::insert('employee_assignments', ['employee_id' => $shared, 'uc_id' => $ucQ, 'assigned_at' => '2026-01-01 00:00:00']);
$cP = $ctx($supP, $uSupA, ['attendance.access', 'attendance.mark_team']);
$cQ = $ctx($supQ, $uSupB, ['attendance.access', 'attendance.mark_team']);
t(in_array($shared, array_map('intval', array_column(att_supervised_roster($ucP), 'id')), true) && in_array($shared, array_map('intval', array_column(att_supervised_roster($ucQ), 'id')), true), 'the shared worker is on BOTH Supervisors\' rosters');
foreach ([['P', $cP], ['Q', $cQ]] as [$label, $c]) {
    t(att_mark_authority($c, att_load_employee($shared, att_scope_uc_ids($c))) === null, "Supervisor {$label} is authorised to mark him (roster and authority agree)");
}
att_sweep();
t(att_mark_event($shared, 'checkin', $cQ)['ok'], 'the SECOND UC\'s Supervisor can mark him (this was refused before the fix)');
t((int)Database::fetchValue('SELECT uc_id FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $shared, 'd' => $D4]) === $ucP, 'by design the single daily record stays attributed to the employee\'s primary (lowest) UC, whichever Supervisor marks it');
t(att_mark_event($shared, 'checkin', $cP)['code'] === 'duplicate', 'the other Supervisor cannot create a duplicate record');
t((int)Database::fetchValue('SELECT COUNT(*) FROM attendance_records WHERE employee_id = :e AND attendance_date = :d', ['e' => $shared, 'd' => $D4]) === 1, 'exactly one record exists for the day');
$cOut = $ctx($supQ, $uSupB, ['attendance.access', 'attendance.mark_team']);
$unrelated = $mkEmp('Unrelated Worker', 'field-staff', $tWorker, $ucP);
t(att_mark_event($unrelated, 'checkin', $cOut)['code'] === 'not_authorized', 'an employee who is in none of his UCs is still refused');

echo "\n" . ($fail === 0 ? 'ALL PASSED' : "FAILED: {$fail}") . " — {$pass} checks passed\n";
exit($fail === 0 ? 0 : 1);
