<?php
/** Excel / PDF download of the filtered report. Same query and scope as reports.php. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/export.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.reports', 'attendance.export');
$ctx = att_ctx();
$f = att_read_report_filters($_GET);
$format = query('format') === 'pdf' ? 'pdf' : 'xlsx';
att_audit('export', 'Attendance report exported (' . strtoupper($format) . ') for ' . $f['from'] . ' to ' . $f['to'], [
    'subject_type' => 'report', 'subject_id' => null, 'employee_id' => null, 'new' => ['filters' => att_report_filter_text($f), 'format' => $format],
]);
$format === 'pdf' ? att_export_pdf($ctx, $f) : att_export_xlsx($ctx, $f);
