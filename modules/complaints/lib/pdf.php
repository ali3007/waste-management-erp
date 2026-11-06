<?php
/**
 * Complaints — PDF documents, built on the TCPDF copy bundled in
 * /libs/tcpdf and the company letterhead class already used by the HR
 * module's PDFs (modules/hr/lib/HrPdf.php). Both documents use the company
 * name, logo, address and contact details configured at installation.
 *
 *   cmp_pdf_complaint()  one complaint: details, assignment, SLA,
 *                        resolution, Before/After picture grids, timeline
 *   cmp_pdf_list()       the filtered complaint list (landscape table)
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

require_once ROOT_PATH . '/modules/hr/lib/pdf.php';   // TCPDF boot, image + section helpers

/** A letterhead document. $orientation 'P' or 'L'. @return array{0:TCPDF,1:array} [pdf, temp files] */
function cmp_pdf_new(string $title, string $label, string $orientation = 'P'): array
{
    $class = hr_pdf_class();
    /** @var TCPDF $pdf */
    $pdf = new $class($orientation, 'mm', 'A4', true, 'UTF-8', false);
    $company = company();
    $pdf->brand = $company;
    $pdf->docLabel = $label;
    $pdf->generatedOn = date('d M Y, H:i');
    $pdf->letterhead = true;

    $temps = [];
    $logo = hr_pdf_image(hr_pdf_logo_path(), 400);
    if ($logo !== null) {
        $pdf->logoJpeg = $logo[0];
        $temps[] = $logo[0];
    }
    $pdf->SetCreator((string)$company['name']);
    $pdf->SetAuthor((string)$company['name']);
    $pdf->SetTitle($title);
    $pdf->SetMargins(15, 31, 15);
    $pdf->SetHeaderMargin(8);
    $pdf->SetAutoPageBreak(true, 22);
    $pdf->setFontSubsetting(true);
    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->AddPage();
    return [$pdf, $temps];
}

/** A coloured pill-like label in the current flow. */
function cmp_pdf_badge(TCPDF $pdf, string $text, string $hex): void
{
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = strlen($hex) === 6 ? [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))] : [23, 160, 106];
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor($r, $g, $b);
    $pdf->Cell($pdf->GetStringWidth($text) + 8, 6, $text, 0, 0, 'C', true);
    $pdf->Cell(2, 6, '', 0, 0);
}

/** Lay out a list of pictures in a grid (3 per row) with a numbered caption. */
function cmp_pdf_picture_grid(TCPDF $pdf, int $complaintId, array $rows, array &$temps, string $heading): void
{
    /* Keep the heading together with its first row of pictures. */
    $usableW = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
    $cellH = (($usableW - 8) / 3) * 0.75;
    if ($pdf->GetY() + 9 + ($rows ? $cellH + 8 : 8) > $pdf->getPageHeight() - 24) {
        $pdf->AddPage();
    }
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 9.5);
    $pdf->SetFillColor(231, 243, 237);
    $pdf->SetTextColor(23, 90, 62);
    $pdf->Cell(0, 7, '  ' . $heading . ' (' . count($rows) . ')', 0, 1, 'L', true);
    $pdf->Ln(2);

    if (!$rows) {
        $pdf->SetFont('dejavusans', 'I', 8.5);
        $pdf->SetTextColor(130, 140, 136);
        $pdf->Cell(0, 6, 'No pictures.', 0, 1, 'L');
        return;
    }

    $left = $pdf->getMargins()['left'];
    $usable = $pdf->getPageWidth() - $left - $pdf->getMargins()['right'];
    $gap = 4;
    $perRow = 3;
    $w = ($usable - $gap * ($perRow - 1)) / $perRow;
    $h = $w * 0.75;
    $col = 0;
    $rowTop = $pdf->GetY();

    foreach ($rows as $i => $row) {
        if ($col === 0) {
            if ($pdf->GetY() + $h + 8 > $pdf->getPageHeight() - 24) {
                $pdf->AddPage();
            }
            $rowTop = $pdf->GetY();
        }
        $x = $left + $col * ($w + $gap);
        $src = cmp_file_path($complaintId, (string)$row['preview_name']) ?? cmp_file_path($complaintId, (string)$row['file_name']);
        $img = $src !== null ? hr_pdf_image($src, 720) : null;

        $pdf->SetDrawColor(200, 208, 205);
        $pdf->Rect($x, $rowTop, $w, $h);
        if ($img !== null) {
            $temps[] = $img[0];
            $ratio = $img[1] / $img[2];
            $iw = $w;
            $ih = $w / $ratio;
            if ($ih > $h) {
                $ih = $h;
                $iw = $h * $ratio;
            }
            $pdf->Image($img[0], $x + ($w - $iw) / 2, $rowTop + ($h - $ih) / 2, $iw, $ih, 'JPG');
        }
        $pdf->SetXY($x, $rowTop + $h + 0.5);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(110, 124, 119);
        $pdf->Cell($w, 4, '#' . ($i + 1) . '  ' . mb_substr((string)$row['original_name'], 0, 34), 0, 0, 'L');

        $col++;
        if ($col === $perRow) {
            $col = 0;
            $pdf->SetY($rowTop + $h + 6);
        }
    }
    if ($col !== 0) {
        $pdf->SetY($rowTop + $h + 6);
    }
}

/** One complaint as a PDF download. */
function cmp_pdf_complaint(array $c): never
{
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    $id = (int)$c['id'];
    $number = (string)$c['complaint_number'];
    [$pdf, $temps] = cmp_pdf_new('Complaint ' . $number, 'Complaint Report · ' . $number);

    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetTextColor(110, 124, 119);
    $pdf->Cell(0, 5, 'COMPLAINT REPORT', 0, 1, 'L');
    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->SetTextColor(25, 35, 31);
    $pdf->Cell(0, 9, $number, 0, 1, 'L');
    cmp_pdf_badge($pdf, cmp_status_label((string)$c['status']), cmp_status_color((string)$c['status']));
    cmp_pdf_badge($pdf, (string)$c['priority_name'], (string)$c['priority_color']);
    $sla = cmp_sla_state($c);
    if ($sla !== 'none') {
        cmp_pdf_badge($pdf, ['within' => 'Within SLA', 'nearing' => 'Nearing SLA', 'breached' => 'SLA breached'][$sla], ['within' => '17a06a', 'nearing' => 'd9902b', 'breached' => 'd64550'][$sla]);
    }
    $pdf->Ln(8);

    hr_pdf_section($pdf, 'Complainant', [
        ['Name', $c['complainant_name']], ['Phone', $c['phone'] ?? ''], ['Address', $c['complainant_address'] ?? ''],
    ]);
    hr_pdf_section($pdf, 'Complaint', [
        ['Source', $c['source_name']], ['Type', $c['type_name']], ['Priority', $c['priority_name']],
        ['Complaint date / time', cmp_fmt_date((string)$c['complaint_date']) . ($c['complaint_time'] ? ', ' . substr((string)$c['complaint_time'], 0, 5) : '')],
        ['Description', $c['description']],
    ]);

    $loc = [['Region', $c['region_name']], ['District', $c['district_name']], ['Tehsil', $c['tehsil_name']], ['UC', $c['uc_name']],
            ['Beat', $c['beat_name'] ?? ''], ['Address', $c['location_address'] ?? '']];
    if ($c['latitude'] !== null && $c['longitude'] !== null) {
        $loc[] = ['GPS', $c['latitude'] . ', ' . $c['longitude']];
    }
    hr_pdf_section($pdf, 'Location', $loc);

    $staff = cmp_uc_staff((int)$c['uc_id']);
    hr_pdf_section($pdf, 'Assignment', [
        ['Supervisor', implode(', ', array_map(static fn($s) => $s['name'] . ' (' . $s['employee_code'] . ')', $staff['supervisors']))],
        ['Monitoring Officer', implode(', ', array_map(static fn($s) => $s['name'] . ' (' . $s['employee_code'] . ')', $staff['monitors']))],
        ['Assigned at', $c['assigned_at'] ? format_datetime((string)$c['assigned_at']) : ''],
    ]);

    $slaRows = [['Created', format_datetime((string)$c['created_at'])]];
    if ($c['sla_due_at']) {
        $slaRows[] = ['SLA due', format_datetime((string)$c['sla_due_at'])];
        $slaRows[] = ['SLA state', ['within' => 'Within SLA', 'nearing' => 'Nearing SLA', 'breached' => 'SLA breached', 'none' => '—'][$sla] . ' — ' . cmp_sla_remaining($c)];
    }
    if ($c['resolved_at']) {
        $slaRows[] = ['Resolved at', format_datetime((string)$c['resolved_at'])];
    }
    hr_pdf_section($pdf, 'Timing', $slaRows);

    $resolutions = Database::fetchAll('SELECT r.*, u.name AS by_name FROM complaint_resolution r LEFT JOIN users u ON u.id = r.resolved_by WHERE r.complaint_id = :c ORDER BY r.id ASC', ['c' => $id]);
    if ($resolutions) {
        $rows = [];
        foreach ($resolutions as $r) {
            $rows[] = ['Resolution ' . (int)$r['cycle_no'], format_datetime((string)$r['resolved_at']) . ' by ' . ($r['by_name'] ?? '—') . ($r['state'] === 'reopened' ? ' (later reopened)' : '') . "\n" . $r['remarks']];
        }
        hr_pdf_section($pdf, 'Resolution', $rows);
    }

    cmp_pdf_picture_grid($pdf, $id, cmp_evidence_list($id, 'before'), $temps, 'Before evidence');
    cmp_pdf_picture_grid($pdf, $id, cmp_evidence_list($id, 'after'), $temps, 'After / resolution evidence');

    $timeline = Database::fetchAll('SELECT t.*, u.name AS user_name FROM complaint_timeline t LEFT JOIN users u ON u.id = t.user_id WHERE t.complaint_id = :c ORDER BY t.created_at ASC, t.id ASC', ['c' => $id]);
    $rows = [];
    foreach ($timeline as $t) {
        $detail = $t['title'] . (($t['old_value'] !== null || $t['new_value'] !== null) ? ' (' . ($t['old_value'] ?? '') . ($t['old_value'] !== null && $t['new_value'] !== null ? ' → ' : '') . ($t['new_value'] ?? '') . ')' : '') . ($t['remarks'] ? "\n" . $t['remarks'] : '');
        $rows[] = [format_datetime((string)$t['created_at'], 'd M Y H:i'), $detail . ' — ' . ($t['user_name'] ?? 'System')];
    }
    hr_pdf_section($pdf, 'Timeline', $rows);

    hr_pdf_send($pdf, 'Complaint-' . hr_pdf_slug($number) . '.pdf', $temps);
}

/** The filtered list as a landscape PDF table. */
function cmp_pdf_list(array $filters): never
{
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    [$pdf, $temps] = cmp_pdf_new('Complaints report', 'Complaints report', 'L');
    $pdf->SetFont('dejavusans', 'B', 15);
    $pdf->SetTextColor(25, 35, 31);
    $pdf->Cell(0, 8, 'Complaints report', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(110, 124, 119);
    $pdf->MultiCell(0, 4, cmp_filter_summary($filters), 0, 'L', false, 1);
    $pdf->Ln(2);

    $cols = [['No.', 27], ['Date', 21], ['Complainant', 40], ['Type', 38], ['Priority', 22], ['UC / Beat', 38], ['Status', 26], ['SLA', 22], ['Pics B/A', 17], ['Source', 26]];
    $drawHeader = static function () use ($pdf, $cols): void {
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetFillColor(23, 160, 106);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($cols as [$name, $w]) {
            $pdf->Cell($w, 7, $name, 0, 0, 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(30, 40, 36);
    };
    $drawHeader();

    $now = cmp_now();
    $fill = false;
    $count = cmp_each_complaint($filters, function (array $r) use ($pdf, $cols, $drawHeader, &$fill, $now): bool {
        if ($pdf->GetY() > $pdf->getPageHeight() - 28) {
            $pdf->AddPage();
            $drawHeader();
        }
        $sla = cmp_sla_state($r, $now);
        $cells = [
            (string)$r['complaint_number'], cmp_fmt_date((string)$r['complaint_date']), (string)$r['complainant_name'], (string)$r['type_name'],
            (string)$r['priority_name'], (string)$r['uc_name'] . ($r['beat_name'] ? ' / ' . $r['beat_name'] : ''),
            cmp_status_label((string)$r['status']), $sla === 'none' ? '—' : ucfirst($sla), (int)$r['before_count'] . ' / ' . (int)$r['after_count'], (string)$r['source_name'],
        ];
        $pdf->SetFillColor(246, 249, 248);
        foreach ($cols as $i => [$name, $w]) {
            $maxChars = (int)floor($w / 1.55);
            $txt = mb_strlen($cells[$i]) > $maxChars ? mb_substr($cells[$i], 0, $maxChars - 1) . '…' : $cells[$i];
            $pdf->Cell($w, 6, $txt, 0, 0, 'L', $fill);
        }
        $pdf->Ln();
        $fill = !$fill;
        return true;
    }, CMP_EXPORT_PDF_MAX_ROWS);

    $pdf->Ln(3);
    $pdf->SetFont('dejavusans', 'I', 7.5);
    $pdf->SetTextColor(130, 140, 136);
    $total = (int)Database::fetchValue('SELECT COUNT(*) FROM complaints c WHERE ' . cmp_filter_sql($filters)[0], cmp_filter_sql($filters)[1]);
    $note = $total . ' complaint' . ($total === 1 ? '' : 's') . ' match.';
    if ($total > $count) {
        $note .= ' This PDF shows the first ' . $count . '; use the Excel/CSV export for the complete list.';
    }
    $pdf->MultiCell(0, 4, $note, 0, 'L');

    hr_pdf_send($pdf, 'Complaints-' . date('Ymd-His') . '.pdf', $temps);
}
