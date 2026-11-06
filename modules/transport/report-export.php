<?php
/**
 * Export a report as Excel, CSV or PDF. Same query, same filters and the same
 * area scope as the screen — a restricted user can only ever export what they
 * can see. Each export is written to the audit trail.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.reports_export');

$key = query('report');
$format = in_array(query('format'), ['csv', 'xlsx', 'pdf'], true) ? query('format') : 'xlsx';
if (!isset(trn_report_catalogue()[$key])) {
    trn_back('modules/transport/reports.php', 'error', 'Unknown report.');
}

$f = trn_read_report_filters($_GET, $key);
$report = trn_report_run($key, $f, null, 0);

try {
    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'transport', 'action' => 'report.exported', 'subject_type' => 'report', 'subject_id' => 0,
        'description' => mb_substr('Exported "' . $report['title'] . '" as ' . strtoupper($format) . ' (' . count($report['rows']) . ' rows)', 0, 250), 'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    log_event('transport', 'Export log failed', ['error' => $e->getMessage()]);
}

$name = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($report['title'])) . '-' . date('Ymd-His') . '.' . $format;
match ($format) {
    'csv' => trn_export_csv($report, $name),
    'pdf' => trn_export_pdf($report, $name),
    default => trn_export_xlsx($report, $name),
};
