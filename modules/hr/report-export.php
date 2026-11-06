<?php
/**
 * CSV exports for HR (spec section 21): the filtered employee list, or the
 * count breakdowns ("summary"). Uses the same filters and area scope as
 * the screens, so the file always matches what the user was looking at.
 *
 *   ?type=employees|summary &<any filter from the filter panel>
 *
 * Needs hr.reports_export. Streams in batches, so thousands of employees
 * don't have to fit in memory. Cells are protected against spreadsheet
 * formula injection, and a UTF-8 BOM makes Excel read non-English text.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hr.reports_export');

$type = query('type') === 'summary' ? 'summary' : 'employees';
$filters = hr_read_filters($_GET);
[$whereSql, $params] = hr_filter_sql($filters);

$stamp = date('Ymd-His');
$fileName = ($type === 'summary' ? 'HR-Summary-' : 'HR-Employees-') . $stamp . '.csv';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
@set_time_limit(120);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

if ($type === 'summary') {
    $company = company();
    hr_csv_row($out, [(string)$company['name'] . ' - HR summary report']);
    hr_csv_row($out, ['Generated', date('Y-m-d H:i')]);
    hr_csv_row($out, ['Employees matching filters', (string)Database::fetchValue("SELECT COUNT(*) FROM employees e WHERE {$whereSql}", $params)]);
    hr_csv_row($out, []);
    $titles = hr_report_titles();
    foreach (hr_report_breakdowns($filters) as $key => $list) {
        hr_csv_row($out, ['By ' . $titles[$key]]);
        hr_csv_row($out, [$titles[$key], 'Context', 'Employees']);
        foreach ($list as $item) {
            hr_csv_row($out, [$item['label'], $item['sub'], (string)$item['count']]);
        }
        hr_csv_row($out, []);
    }
} else {
    hr_csv_row($out, ['Employee ID', 'Name', "Father's Name", 'CNIC', 'Phone', 'Date of Birth', 'CNIC Issue Date', 'Address',
        'Staff Pool', 'Staff Type', 'Region', 'District', 'Tehsil', 'Current Assignment (UC / Beat)', 'Assignment Status', 'Status', 'Added On']);

    $batch = 1000;
    $lastId = 0;
    while (true) {
        $rows = Database::fetchAll(
            hr_employee_rows_sql($whereSql . ' AND e.id > ' . $lastId, 'e.id ASC', 'LIMIT ' . $batch),
            $params
        );
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $lastId = (int)$r['id'];
            hr_csv_row($out, [
                $r['employee_code'], $r['name'], $r['father_name'], Security::formatCnic((string)$r['cnic']), $r['phone'],
                $r['dob'], $r['cnic_issue_date'], str_replace(["\r", "\n"], ' ', (string)$r['address']),
                $r['pool_name'], $r['type_name'], $r['region_name'], $r['district_name'], $r['tehsil_name'],
                $r['assignment_text'],
                $r['is_assigned'] ? 'Assigned' : ($r['assignment_mode'] === 'none' ? 'Not required' : 'Unassigned'),
                ucfirst((string)$r['status']), format_datetime((string)$r['created_at'], 'Y-m-d'),
            ]);
        }
        if (count($rows) < $batch) {
            break;
        }
    }
}

fclose($out);

try {
    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => 'report.exported',
        'description' => 'Exported ' . $type . ' CSV (' . $fileName . ')', 'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    log_event('hr', 'Export log failed', ['error' => $e->getMessage()]);
}
exit;
