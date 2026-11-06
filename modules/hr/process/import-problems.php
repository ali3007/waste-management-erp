<?php
/**
 * Download the rows that were skipped or had errors, with the reason, so
 * they can be fixed in the same file and uploaded again.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hr.import');

$staged = hr_import_load(query('t'));
if ($staged === null) {
    flash('error', 'That import preview has expired. Upload the file again.');
    redirect('modules/hr/import.php');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="employee-import-problems.csv"');
header('Cache-Control: private, no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$cols = hr_import_columns();
hr_csv_row($out, array_merge(['Row', 'Result', 'Reason'], array_map(static fn($c) => $c[1], $cols)));
foreach (['errors' => 'Error', 'skipped' => 'Skipped'] as $key => $label) {
    foreach ($staged['result'][$key] ?? [] as $item) {
        $line = [$item['row'], $label, $item['reason']];
        foreach ($cols as [$k]) {
            $line[] = $item['data'][$k] ?? '';
        }
        hr_csv_row($out, $line);
    }
}
fclose($out);
exit;
