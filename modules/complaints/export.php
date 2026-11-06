<?php
/**
 * Export the filtered complaint list.
 *
 *   ?format=csv|xlsx|pdf  &<any filter from the filter panel>
 *
 * Needs complaint.reports. Same filters and UC scope as the screen (see
 * lib/filters.php). CSV and Excel stream in batches, so very large result
 * sets do not exhaust memory; CSV cells are protected against formula
 * injection and Excel text is stored as inline strings (never formulas).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/xlsx.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.reports');

$format = in_array(query('format'), ['csv', 'xlsx', 'pdf'], true) ? query('format') : 'csv';
$filters = cmp_read_filters($_GET);
$stamp = date('Ymd-His');
$uid = (int)Auth::id();   // read now: the session is released before the file is streamed
$headings = cmp_export_headings();
$staffCache = [];
$now = cmp_now();

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$log = static function (int $rows) use ($format, $filters, $uid): void {
    try {
        Database::insert('activity_log', [
            'user_id' => $uid ?: null, 'module' => 'complaints', 'action' => 'report.exported',
            'description' => 'Exported ' . $rows . ' complaint(s) as ' . strtoupper($format) . ' — ' . mb_substr(cmp_filter_summary($filters), 0, 150), 'ip_address' => client_ip(),
        ]);
    } catch (Throwable $e) {
        log_event('complaints', 'Export log failed', ['error' => $e->getMessage()]);
    }
};

if ($format === 'pdf') {
    require_once __DIR__ . '/lib/pdf.php';
    $log((int)Database::fetchValue('SELECT COUNT(*) FROM complaints c WHERE ' . cmp_filter_sql($filters)[0], cmp_filter_sql($filters)[1]));
    cmp_pdf_list($filters);
}

/* Everything that needs the session (permissions, UC scope) is evaluated
   NOW, before the lock is released and before any output starts. */
cmp_filter_sql($filters);
cmp_scope_uc_ids();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($format === 'xlsx') {
    $book = new CmpXlsx('Complaints');
    $book->addRow($headings, true);
    $count = cmp_each_complaint($filters, static function (array $r) use ($book, &$staffCache, $now): bool {
        $book->addRow(cmp_export_row($r, $staffCache, $now));
        return true;
    });
    $file = $book->finish();
    $log($count);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Complaints-' . $stamp . '.xlsx"');
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: private, no-store');
    readfile($file);
    @unlink($file);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Complaints-' . $stamp . '.csv"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads non-English text correctly
fputcsv($out, $headings, ',', '"', '\\');
$count = cmp_each_complaint($filters, static function (array $r) use ($out, &$staffCache, $now): bool {
    fputcsv($out, array_map('cmp_csv_cell', cmp_export_row($r, $staffCache, $now)), ',', '"', '\\');
    return true;
});
fclose($out);
$log($count);
exit;
