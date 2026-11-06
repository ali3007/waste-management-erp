<?php
/**
 * AJAX endpoint — record ONE Check-in or Check-out.
 *
 * POST: employee_id (Supervisor screen; ignored/forced to self for Office Staff
 * "own" requests), event = checkin|checkout, csrf token. Everything is decided
 * on the server by att_mark_event(): the browser's clock, button state and any
 * hidden field are never trusted. No bulk or multi-employee input is accepted.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    att_json(false, 'The system is not installed.', [], 503);
}
if (!is_post()) {
    att_json(false, 'Invalid request.', [], 405);
}
if (!Auth::check()) {
    att_json(false, 'Your session has expired. Please sign in again.', [], 401);
}
if (!can('attendance.access') || !(can('attendance.mark_self') || can('attendance.mark_team'))) {
    att_json(false, 'You do not have permission to mark attendance.', [], 403);
}
if (!Security::verifyCsrf()) {
    att_json(false, 'Your session expired. Reload the page and try again.', [], 419);
}

$ctx = att_ctx();
$event = post('event');
$self = post('own') === '1';
$employeeId = $self ? (int)($ctx['employee']['id'] ?? 0) : (int)post('employee_id');
if ($employeeId <= 0) {
    att_json(false, $self ? 'Your account is not linked to an employee record.' : 'Choose an employee.', [], 422);
}

$res = att_mark_event($employeeId, $event, $ctx);

/* Only describe the employee's day to someone who may mark them — a refused or forged
   request must never reveal another person's status or times. */
$employee = att_load_employee($employeeId, att_scope_uc_ids($ctx));
$view = ($employee !== null && att_mark_authority($ctx, $employee) === null) ? att_day_view($employee) : null;
$rec = $view['record'] ?? null;
$payload = ['employee_id' => $employeeId, 'row' => $view === null ? null : [
    'status' => $view['state'], 'status_html' => att_status_badge((string)$view['state']),
    'checkin' => att_clock($rec['checkin_at'] ?? null), 'checkout' => att_clock($rec['checkout_at'] ?? null),
    'can_checkin' => $view['can_checkin'], 'can_checkout' => $view['can_checkout'], 'hint' => $view['hint'],
]];
att_json($res['ok'], $res['message'], $payload, $res['ok'] ? 200 : (in_array($res['code'], ['not_authorized'], true) ? 403 : 409));
