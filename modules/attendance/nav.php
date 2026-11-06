<?php
/**
 * Attendance navigation — one definition for the sidebar and the module's
 * landing redirect. Only items the signed-in user may open appear. Plain
 * functions, no database access, safe to include from the sidebar.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** @return array<int,array{label:string,href:string,script:string,permission:string}> */
function att_nav_items(): array
{
    if (!can('attendance.access')) {
        return [];
    }
    /* label, file, any-of permissions */
    $defs = [
        ['My Attendance',          'my-attendance.php', ['attendance.mark_self']],
        ['Staff Attendance',       'supervisor.php',    ['attendance.mark_team']],
        ['Attendance Dashboard',   'dashboard.php',     ['attendance.dashboard']],
        ['Attendance Reports',     'reports.php',       ['attendance.reports']],
        ['Employee History',       'history.php',       ['attendance.history_view']],
        ['Leave Requests',         'leave.php',         ['attendance.leave_view', 'attendance.leave_submit_self', 'attendance.leave_submit_proxy', 'attendance.leave_approve']],
        ['Pending Review',         'review.php',        ['attendance.review']],
        ['Corrections',            'corrections.php',   ['attendance.edit']],
        ['Holidays',               'holidays.php',      ['attendance.holidays_manage', 'attendance.mark_self']],
        ['Timing Settings',        'timing.php',        ['attendance.timing_view', 'attendance.timing_manage']],
        ['Attendance Audit',       'audit.php',         ['attendance.audit']],
        ['Attendance Setup',       'config.php',        ['attendance.config']],
    ];
    /* A per-category timing permission (attendance.timing_manage_<category>) also opens Timing Settings. */
    $poolTiming = false;
    foreach (Permission::forCurrentUser() as $slug) {
        if ($slug === '*' || str_starts_with($slug, 'attendance.timing_manage')) {
            $poolTiming = true;
            break;
        }
    }
    $items = [];
    foreach ($defs as [$label, $file, $any]) {
        if ($file === 'timing.php' && $poolTiming) {
            $items[] = ['label' => $label, 'href' => base_url('modules/attendance/' . $file), 'script' => 'attendance/' . $file, 'permission' => 'attendance.timing_manage'];
            continue;
        }
        foreach ($any as $perm) {
            if (can($perm)) {
                $items[] = ['label' => $label, 'href' => base_url('modules/attendance/' . $file), 'script' => 'attendance/' . $file, 'permission' => $perm];
                break;
            }
        }
    }
    return $items;
}
