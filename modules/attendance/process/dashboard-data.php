<?php
/** JSON for the dashboard's 30-second refresh. Same scope rules as the page. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED || !Auth::check()) {
    att_json(false, 'Sign in again.', [], 401);
}
if (!can('attendance.access') || !can('attendance.dashboard')) {
    att_json(false, 'Not allowed.', [], 403);
}
att_lazy_sweep();
$ctx = att_ctx();
$date = att_valid_date(query('date')) ?? att_today();
json_response(['ok' => true] + att_dashboard_data($ctx, $date, (int)query('pool'), att_scoped_uc((int)query('uc'), $ctx)));
