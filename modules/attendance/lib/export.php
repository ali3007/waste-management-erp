<?php
/**
 * Report exports. They run the SAME query as the screen (att_report_rows with
 * the actor's own scope), so a file can never contain a row the user could not
 * see on screen, and a hand-edited URL cannot widen it.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const ATT_EXPORT_MAX_ROWS = 50000;

function att_export_headers(): array
{
    return ['Date', 'Employee ID', 'Employee Name', 'Category', 'Staff Type', 'UC', 'Supervisor', 'Check-in', 'Check-out', 'Status', 'Notes'];
}

function att_export_cells(array $r): array
{
    return [
        date('Y-m-d', strtotime((string)$r['attendance_date'])),
        (string)($r['employee_code'] ?? ''),
        (string)$r['employee_name'],
        (string)$r['pool_name'],
        (string)$r['type_name'],
        (string)($r['uc_name'] ?? ''),
        (string)($r['supervisor_name'] ?? ''),
        $r['checkin_at'] ? date('H:i', strtotime((string)$r['checkin_at'])) : '',
        $r['checkout_at'] ? date('H:i', strtotime((string)$r['checkout_at'])) : '',
        att_status_label((string)$r['status']),
        att_record_note($r),
    ];
}

/** Send a finished temp file to the browser and stop. */
function att_send_file(string $path, string $name, string $mime): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    @unlink($path);
    exit;
}

function att_export_xlsx(array $ctx, array $f): never
{
    require_once __DIR__ . '/xlsx.php';
    @set_time_limit(180);
    $rows = att_report_rows($ctx, $f, ATT_EXPORT_MAX_ROWS);
    $x = new AttXlsx('Attendance');
    $x->addRow([(string)company()['name'] . ' — ' . att_report_title($f)], false, true);
    $x->addRow(['Period', $f['from'] === $f['to'] ? $f['from'] : $f['from'] . ' to ' . $f['to']]);
    $x->addRow(['Filters', att_report_filter_text($f)]);
    $x->addRow(['Generated', att_now()->format('Y-m-d H:i') . ' (Asia/Karachi)']);
    $x->addRow(['Rows', count($rows)]);
    $x->addRow([]);
    $x->addRow(att_export_headers(), true);
    foreach ($rows as $r) {
        $x->addRow(att_export_cells($r));
    }
    att_send_file($x->finish(), 'Attendance-' . $f['from'] . ($f['to'] !== $f['from'] ? '_to_' . $f['to'] : '') . '.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

function att_export_pdf(array $ctx, array $f): never
{
    require_once dirname(__DIR__, 2) . '/hr/lib/pdf.php';
    @set_time_limit(240);
    $rows = att_report_rows($ctx, $f, 5000);          // PDFs are for reading; Excel carries the full set

    $class = hr_pdf_class();
    /** @var TCPDF $pdf */
    $pdf = new $class('L', 'mm', 'A4', true, 'UTF-8', false);
    $company = company();
    $pdf->brand = $company;
    $pdf->docLabel = att_report_title($f) . ' · ' . $f['from'] . ($f['to'] !== $f['from'] ? ' to ' . $f['to'] : '');
    $pdf->generatedOn = att_now()->format('d M Y, H:i') . ' PKT';
    $pdf->letterhead = true;
    $temps = [];
    $logo = hr_pdf_image(hr_pdf_logo_path(), 400);
    if ($logo !== null) {
        $pdf->logoJpeg = $logo[0];
        $temps[] = $logo[0];
    }
    $pdf->SetCreator((string)$company['name']);
    $pdf->SetTitle(att_report_title($f));
    $pdf->SetMargins(10, 31, 10);
    $pdf->SetHeaderMargin(8);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->setFontSubsetting(true);
    $pdf->AddPage();

    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(31, 42, 39);
    $pdf->Cell(0, 7, att_report_title($f), 0, 1);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(95, 110, 105);
    $pdf->MultiCell(0, 4.5, 'Period: ' . ($f['from'] === $f['to'] ? $f['from'] : $f['from'] . ' to ' . $f['to']) . '   ·   ' . att_report_filter_text($f)
        . '   ·   Generated: ' . att_now()->format('Y-m-d H:i') . ' (Asia/Karachi)   ·   Rows: ' . count($rows) . (count($rows) >= 5000 ? ' (first 5,000 — use Excel for the full set)' : ''), 0, 'L');
    $pdf->Ln(2);

    $heads = ['Date', 'ID', 'Employee', 'Category', 'UC', 'Supervisor', 'In', 'Out', 'Status', 'Notes'];
    $w = [21, 20, 44, 26, 34, 34, 14, 14, 24, 46];
    $draw = static function () use ($pdf, $heads, $w): void {
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetFillColor(23, 160, 106);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($heads as $i => $h) {
            $pdf->Cell($w[$i], 6.5, $h, 0, 0, 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(31, 42, 39);
    };
    $draw();
    $fill = false;
    foreach ($rows as $r) {
        if ($pdf->GetY() > $pdf->getPageHeight() - 28) {
            $pdf->AddPage();
            $draw();
        }
        $c = att_export_cells($r);
        $line = [$c[0], $c[1], $c[2], $c[3], $c[5], $c[6], $c[7], $c[8], $c[9], $c[10]];
        $pdf->SetFillColor(244, 247, 245);
        foreach ($line as $i => $v) {
            $pdf->Cell($w[$i], 5.6, mb_strimwidth($v, 0, (int)($w[$i] * 0.62), '…'), 0, 0, 'L', $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
    if (!$rows) {
        $pdf->Ln(4);
        $pdf->Cell(0, 6, 'No attendance records match these filters.', 0, 1);
    }
    hr_pdf_send($pdf, 'Attendance-' . $f['from'] . ($f['to'] !== $f['from'] ? '_to_' . $f['to'] : '') . '.pdf', $temps);
}
