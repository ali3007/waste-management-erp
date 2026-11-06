<?php
/** Attendance Setup actions: categories, run the scheduler now, new URL token. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = 'modules/attendance/config.php';
att_post_guard(['attendance.config'], $back);

$ids = static function (string $key): string {
    $raw = $_POST[$key] ?? [];
    return implode(',', is_array($raw) ? att_id_list(implode(',', array_map('strval', $raw))) : []);
};

switch (post('action')) {
    case 'categories':
        $self = $ids('self_pools');
        $sup = $ids('supervised_pools');
        $overlap = array_intersect(att_id_list($self), att_id_list($sup));
        if ($overlap) {
            att_back($back, 'error', 'A pool cannot be both self-attendance and supervisor-marked.');
        }
        $old = att_config_cache();
        att_save_setting('attendance_self_pool_ids', $self);
        att_save_setting('attendance_supervised_pool_ids', $sup);
        att_save_setting('attendance_supervisor_type_ids', $ids('supervisor_types'));
        att_save_setting('attendance_self_type_ids', $ids('self_types'));
        att_audit('config_changed', 'Attendance categories updated', ['subject_type' => 'config', 'employee_id' => null, 'old' => $old, 'new' => att_config_cache()]);
        att_back($back, 'success', 'Categories saved.');
    case 'run_sweep':
        $r = att_sweep();
        att_audit('sweep_manual', 'Scheduler run manually', ['subject_type' => 'config', 'employee_id' => null, 'new' => ['ran' => $r['ran'], 'days' => count($r['days'])]]);
        att_back($back, $r['ran'] ? 'success' : 'warning', $r['ran']
            ? 'Scheduler finished: ' . count($r['days']) . ' day(s) finalised, ' . $r['today_rows'] . ' new row(s) today, ' . $r['today_changes'] . ' status change(s).'
            : ('The scheduler did not run: ' . ($r['reason'] ?? 'unknown reason')));
    case 'new_token':
        $token = Security::randomToken(24);
        att_save_setting('attendance_cron_token_hash', Security::hashToken($token));
        att_audit('config_changed', 'Scheduler URL token regenerated', ['subject_type' => 'config', 'employee_id' => null]);
        $_SESSION['att_new_token'] = $token;
        att_back($back, 'success', 'A new token was created. Copy it from the next screen — it is shown once.');
    default:
        att_back($back, 'error', 'Unknown action.');
}
