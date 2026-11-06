<?php
/**
 * Transport reports.
 *
 * Every report is one definition: a title, its columns and a query built over
 * the SAME vehicle filters and area scope the screens use. The screen, the
 * CSV, the Excel file and the PDF all run trn_report_run(), so an export is
 * always exactly what was on screen, and a restricted user can never export
 * vehicles outside their own area.
 *
 * Historical reports (history, maintenance, fuel) include archived vehicles by
 * default — a vehicle never disappears from history because it was retired.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const TRN_EXPORT_ROW_CAP = 50000;

/** key => [group, title, description, kind(list|summary), uses date range?] */
function trn_report_catalogue(): array
{
    return [
        'vehicles_all'     => ['Vehicle Reports', 'Complete Vehicle List', 'Every vehicle with type, status, ownership, driver and current deployment.', false],
        'vehicles_type'    => ['Vehicle Reports', 'Vehicles by Type', 'Vehicle count per vehicle type.', false],
        'vehicles_status'  => ['Vehicle Reports', 'Vehicles by Status', 'Vehicle count per status.', false],
        'vehicles_owner'   => ['Vehicle Reports', 'Vehicles by Ownership', 'Vehicle count per ownership type.', false],
        'vehicles_region'  => ['Vehicle Reports', 'Vehicles by Region', 'Vehicle count per region (current deployment).', false],
        'vehicles_uc'      => ['Vehicle Reports', 'Vehicles by UC', 'Vehicle count per UC (current deployment).', false],
        'drivers_current'  => ['Driver Reports', 'Current Driver Assignment', 'Which driver holds which vehicle today.', false],
        'drivers_history'  => ['Driver Reports', 'Driver Assignment History', 'Every driver assignment, past and present.', true],
        'deploy_current'   => ['Deployment Reports', 'Current Vehicle Deployment', 'Where each vehicle is deployed today.', false],
        'deploy_history'   => ['Deployment Reports', 'Historical Deployment', 'Every deployment, past and present.', true],
        'maint_vehicle'    => ['Maintenance Reports', 'Vehicle-wise Maintenance', 'Maintenance count and cost per vehicle.', true],
        'maint_date'       => ['Maintenance Reports', 'Date-wise Maintenance', 'Every maintenance record in date order.', true],
        'maint_summary'    => ['Maintenance Reports', 'Maintenance Cost Summary', 'Cost per maintenance type, with the overall total.', true],
        'fuel_vehicle'     => ['Fuel Reports', 'Vehicle-wise Fuel', 'Litres and cost per vehicle.', true],
        'fuel_driver'      => ['Fuel Reports', 'Driver-wise Fuel', 'Litres and cost per driver.', true],
        'fuel_date'        => ['Fuel Reports', 'Date-wise Fuel', 'Every fuel record in date order.', true],
        'fuel_summary'     => ['Fuel Reports', 'Fuel Cost Summary', 'Litres and cost per month and fuel type.', true],
        'fuel_consumption' => ['Fuel Reports', 'Fuel Consumption', 'Distance per litre, only where odometer data is complete.', true],
        'docs_all'         => ['Document Reports', 'All Vehicle Documents', 'Every current vehicle document with its expiry status.', false],
        'docs_expired'     => ['Document Reports', 'Expired Documents', 'Current documents past their expiry date.', false],
        'docs_expiring'    => ['Document Reports', 'Expiring Soon Documents', 'Current documents inside their warning window.', false],
        'docs_missing'     => ['Document Reports', 'Missing Required Documents', 'Required document types a vehicle does not have.', false],
    ];
}

/** Filters for reports: the vehicle filters plus a date range and a document type. */
function trn_read_report_filters(array $src, string $key): array
{
    $f = trn_read_vehicle_filters($src);
    $cat = trn_report_catalogue();
    $isHistory = in_array($key, ['drivers_history', 'deploy_history', 'maint_vehicle', 'maint_date', 'maint_summary',
        'fuel_vehicle', 'fuel_driver', 'fuel_date', 'fuel_summary', 'fuel_consumption'], true);
    if ($isHistory && $f['archived'] === '') {
        $f['archived'] = 'include';
    }
    $f['date_from'] = trn_valid_date((string)($src['date_from'] ?? '')) ?? '';
    $f['date_to'] = trn_valid_date((string)($src['date_to'] ?? '')) ?? '';
    $f['doc_type'] = max(0, (int)($src['doc_type'] ?? 0));
    $f['uses_dates'] = $cat[$key][3] ?? false;
    return $f;
}

/** Query string carrying a report's filters (for links and exports). */
function trn_report_query(array $f, array $extra = []): string
{
    $p = trn_active_vehicle_filters($f);
    unset($p['uses_dates']);
    foreach (['date_from', 'date_to', 'doc_type'] as $k) {
        if (!empty($f[$k])) {
            $p[$k] = $f[$k];
        }
    }
    return trn_qs(array_merge($p, $extra));
}

/**
 * Run one report.
 *
 * @return array{title:string, columns:string[], rows:array<int,array>, total:int, note:string}
 */
function trn_report_run(string $key, array $f, ?int $limit = null, int $offset = 0): array
{
    $cat = trn_report_catalogue();
    if (!isset($cat[$key])) {
        throw new InvalidArgumentException('Unknown report.');
    }
    [$vw, $vp] = trn_vehicle_filter_sql($f);
    $idSql = 'SELECT v.id ' . trn_vehicle_from() . ' WHERE ' . $vw;
    $params = $vp;
    $note = '';

    $dateAnd = static function (string $col) use ($f, &$params): string {
        $sql = '';
        if (!empty($f['date_from'])) {
            $sql .= " AND {$col} >= :r_from";
            $params['r_from'] = $f['date_from'];
        }
        if (!empty($f['date_to'])) {
            $sql .= " AND {$col} <= :r_to";
            $params['r_to'] = $f['date_to'];
        }
        return $sql;
    };

    $def = ['columns' => [], 'sql' => '', 'order' => ''];
    $rowsOverride = null;

    switch ($key) {
        case 'vehicles_all':
            $def = ['columns' => ['Vehicle No.', 'Type', 'Make', 'Model', 'Year', 'Colour', 'Status', 'Ownership', 'Chassis', 'Engine', 'Tracker', 'RFID', 'Current driver', 'Driver CNIC', 'Current deployment', 'Archived'],
                'sql' => "SELECT v.registration_no, vt.name, v.make, v.model, v.manufacture_year, v.color, vs.name, ot.name, v.chassis_no, v.engine_no, v.tracker_no, v.rfid_no,
                                 dr.name, dr.cnic, CONCAT_WS(' / ', uc.name, bt.name), IF(v.deleted_at IS NULL, 'No', 'Yes')
                          " . trn_vehicle_from() . " WHERE {$vw}", 'order' => 'v.registration_no ASC'];
            break;
        case 'vehicles_type':
            $def = ['columns' => ['Vehicle Type', 'Vehicles'], 'sql' => 'SELECT vt.name, COUNT(*) ' . trn_vehicle_from() . " WHERE {$vw} GROUP BY vt.id, vt.name", 'order' => 'COUNT(*) DESC, vt.name ASC'];
            break;
        case 'vehicles_status':
            $def = ['columns' => ['Status', 'Vehicles'], 'sql' => 'SELECT vs.name, COUNT(*) ' . trn_vehicle_from() . " WHERE {$vw} GROUP BY vs.id, vs.name", 'order' => 'COUNT(*) DESC, vs.name ASC'];
            break;
        case 'vehicles_owner':
            $def = ['columns' => ['Ownership', 'Vehicles'], 'sql' => 'SELECT ot.name, COUNT(*) ' . trn_vehicle_from() . " WHERE {$vw} GROUP BY ot.id, ot.name", 'order' => 'COUNT(*) DESC, ot.name ASC'];
            break;
        case 'vehicles_region':
            $def = ['columns' => ['Region', 'Vehicles'], 'sql' => "SELECT COALESCE(rg.name, 'Not deployed'), COUNT(*) " . trn_vehicle_from() . " WHERE {$vw} GROUP BY rg.id, rg.name", 'order' => 'COUNT(*) DESC'];
            break;
        case 'vehicles_uc':
            $def = ['columns' => ['UC', 'Tehsil', 'District', 'Vehicles'], 'sql' => "SELECT COALESCE(uc.name, 'Not deployed'), th.name, ds.name, COUNT(*) " . trn_vehicle_from() . " WHERE {$vw} GROUP BY uc.id, uc.name, th.name, ds.name", 'order' => 'COUNT(*) DESC'];
            break;
        case 'drivers_current':
            $def = ['columns' => ['Vehicle No.', 'Type', 'Driver', 'Employee ID', 'CNIC', 'Phone', 'Since'],
                'sql' => 'SELECT v.registration_no, vt.name, dr.name, dr.employee_code, dr.cnic, dr.phone, da.assigned_date ' . trn_vehicle_from() . " WHERE {$vw} AND da.id IS NOT NULL", 'order' => 'v.registration_no ASC'];
            break;
        case 'drivers_history':
            $def = ['columns' => ['Vehicle No.', 'Driver', 'Employee ID', 'CNIC', 'From', 'To', 'Assigned by', 'Remarks', 'End reason'],
                'sql' => "SELECT v.registration_no, e.name, e.employee_code, e.cnic, a.assigned_date, a.end_date, u.name, a.remarks, a.end_reason
                            FROM vehicle_driver_assignments a JOIN vehicles v ON v.id = a.vehicle_id JOIN employees e ON e.id = a.employee_id
                            LEFT JOIN users u ON u.id = a.assigned_by
                           WHERE a.vehicle_id IN ({$idSql})" . ($f['driver'] > 0 ? ' AND a.employee_id = ' . (int)$f['driver'] : '') . $dateAnd('a.assigned_date'),
                'order' => 'v.registration_no ASC, a.assigned_date DESC'];
            break;
        case 'deploy_current':
            $def = ['columns' => ['Vehicle No.', 'Type', 'Region', 'District', 'Tehsil', 'UC', 'Beat', 'Since', 'Driver'],
                'sql' => 'SELECT v.registration_no, vt.name, rg.name, ds.name, th.name, uc.name, bt.name, dp.start_date, dr.name ' . trn_vehicle_from() . " WHERE {$vw} AND dp.id IS NOT NULL", 'order' => 'rg.name ASC, ds.name ASC, uc.name ASC, v.registration_no ASC'];
            break;
        case 'deploy_history':
            $def = ['columns' => ['Vehicle No.', 'Region', 'District', 'Tehsil', 'UC', 'Beat', 'From', 'To', 'Assigned by', 'Remarks'],
                'sql' => "SELECT v.registration_no, rg.name, ds.name, th.name, uc.name, bt.name, d.start_date, d.end_date, u.name, d.remarks
                            FROM vehicle_deployments d JOIN vehicles v ON v.id = d.vehicle_id
                            JOIN regions rg ON rg.id = d.region_id JOIN districts ds ON ds.id = d.district_id JOIN tehsils th ON th.id = d.tehsil_id JOIN ucs uc ON uc.id = d.uc_id
                            LEFT JOIN beats bt ON bt.id = d.beat_id LEFT JOIN users u ON u.id = d.assigned_by
                           WHERE d.vehicle_id IN ({$idSql})" . $dateAnd('d.start_date'),
                'order' => 'v.registration_no ASC, d.start_date DESC'];
            break;
        case 'maint_vehicle':
            $def = ['columns' => ['Vehicle No.', 'Type', 'Records', 'Total cost', 'Last maintenance'],
                'sql' => "SELECT v.registration_no, vt.name, COUNT(m.id), SUM(m.cost), MAX(m.maintenance_date)
                            FROM vehicle_maintenance m JOIN vehicles v ON v.id = m.vehicle_id JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
                           WHERE m.deleted_at IS NULL AND m.vehicle_id IN ({$idSql})" . $dateAnd('m.maintenance_date') . ' GROUP BY v.id, v.registration_no, vt.name',
                'order' => 'SUM(m.cost) DESC'];
            break;
        case 'maint_date':
            $def = ['columns' => ['Date', 'Vehicle No.', 'Type', 'Description', 'Workshop', 'Odometer', 'Cost', 'Next maintenance', 'Added by'],
                'sql' => "SELECT m.maintenance_date, v.registration_no, mt.name, m.description, m.workshop, m.odometer, m.cost, m.next_maintenance_date, u.name
                            FROM vehicle_maintenance m JOIN vehicles v ON v.id = m.vehicle_id JOIN maintenance_types mt ON mt.id = m.maintenance_type_id LEFT JOIN users u ON u.id = m.added_by
                           WHERE m.deleted_at IS NULL AND m.vehicle_id IN ({$idSql})" . $dateAnd('m.maintenance_date'),
                'order' => 'm.maintenance_date DESC, m.id DESC'];
            break;
        case 'maint_summary':
            $def = ['columns' => ['Maintenance type', 'Records', 'Total cost', 'Average cost'],
                'sql' => "SELECT mt.name, COUNT(m.id), SUM(m.cost), ROUND(AVG(m.cost), 2)
                            FROM vehicle_maintenance m JOIN maintenance_types mt ON mt.id = m.maintenance_type_id
                           WHERE m.deleted_at IS NULL AND m.vehicle_id IN ({$idSql})" . $dateAnd('m.maintenance_date') . ' GROUP BY mt.id, mt.name',
                'order' => 'SUM(m.cost) DESC'];
            break;
        case 'fuel_vehicle':
            $def = ['columns' => ['Vehicle No.', 'Type', 'Fills', 'Litres', 'Total cost', 'Average rate'],
                'sql' => "SELECT v.registration_no, vt.name, COUNT(r.id), SUM(r.quantity), SUM(r.total_cost), ROUND(SUM(r.total_cost) / NULLIF(SUM(r.quantity), 0), 2)
                            FROM vehicle_fuel_records r JOIN vehicles v ON v.id = r.vehicle_id JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
                           WHERE r.deleted_at IS NULL AND r.vehicle_id IN ({$idSql})" . $dateAnd('r.fuel_date') . ' GROUP BY v.id, v.registration_no, vt.name',
                'order' => 'SUM(r.total_cost) DESC'];
            break;
        case 'fuel_driver':
            $def = ['columns' => ['Driver', 'Employee ID', 'Fills', 'Litres', 'Total cost'],
                'sql' => "SELECT COALESCE(e.name, 'No driver recorded'), e.employee_code, COUNT(r.id), SUM(r.quantity), SUM(r.total_cost)
                            FROM vehicle_fuel_records r LEFT JOIN employees e ON e.id = r.driver_id
                           WHERE r.deleted_at IS NULL AND r.vehicle_id IN ({$idSql})" . ($f['driver'] > 0 ? ' AND r.driver_id = ' . (int)$f['driver'] : '') . $dateAnd('r.fuel_date') . ' GROUP BY e.id, e.name, e.employee_code',
                'order' => 'SUM(r.total_cost) DESC'];
            break;
        case 'fuel_date':
            $def = ['columns' => ['Date', 'Vehicle No.', 'Driver', 'Fuel type', 'Litres', 'Rate', 'Total cost', 'Odometer', 'Full tank', 'Station', 'Added by'],
                'sql' => "SELECT r.fuel_date, v.registration_no, e.name, ft.name, r.quantity, r.rate, r.total_cost, r.odometer, IF(r.is_full_tank = 1, 'Yes', 'No'), r.station, u.name
                            FROM vehicle_fuel_records r JOIN vehicles v ON v.id = r.vehicle_id JOIN fuel_types ft ON ft.id = r.fuel_type_id
                            LEFT JOIN employees e ON e.id = r.driver_id LEFT JOIN users u ON u.id = r.added_by
                           WHERE r.deleted_at IS NULL AND r.vehicle_id IN ({$idSql})" . ($f['driver'] > 0 ? ' AND r.driver_id = ' . (int)$f['driver'] : '') . $dateAnd('r.fuel_date'),
                'order' => 'r.fuel_date DESC, r.id DESC'];
            break;
        case 'fuel_summary':
            $def = ['columns' => ['Month', 'Fuel type', 'Fills', 'Litres', 'Total cost'],
                'sql' => "SELECT DATE_FORMAT(r.fuel_date, '%Y-%m'), ft.name, COUNT(r.id), SUM(r.quantity), SUM(r.total_cost)
                            FROM vehicle_fuel_records r JOIN fuel_types ft ON ft.id = r.fuel_type_id
                           WHERE r.deleted_at IS NULL AND r.vehicle_id IN ({$idSql})" . $dateAnd('r.fuel_date') . " GROUP BY DATE_FORMAT(r.fuel_date, '%Y-%m'), ft.id, ft.name",
                'order' => "DATE_FORMAT(r.fuel_date, '%Y-%m') DESC, ft.name ASC"];
            break;
        case 'fuel_consumption':
            $eff = trn_fuel_efficiency($idSql, $vp, $f['date_from'] ?: null, $f['date_to'] ?: null);
            $names = $eff ? Database::fetchAll(
                'SELECT v.id, v.registration_no, vt.name AS type_name FROM vehicles v JOIN vehicle_types vt ON vt.id = v.vehicle_type_id WHERE v.id IN (' . implode(',', array_map('intval', array_keys($eff))) . ') ORDER BY v.registration_no ASC'
            ) : [];
            $rowsOverride = [];
            foreach ($names as $n) {
                $e = $eff[(int)$n['id']];
                $rowsOverride[] = [$n['registration_no'], $n['type_name'], $e['segments'], round($e['distance'], 1), round($e['litres'], 2), $e['kmpl']];
            }
            $def['columns'] = ['Vehicle No.', 'Type', 'Measured stretches', 'Distance (' . trn_distance_unit() . ')', 'Litres', trn_distance_unit() . ' per litre'];
            $note = 'Only stretches between two full-tank fills that both have an odometer reading are measured. Vehicles without such data are left out rather than guessed.';
            break;
        case 'docs_all':
        case 'docs_expired':
        case 'docs_expiring':
            $st = trn_doc_status_sql('d', 'dt');
            $extra = $key === 'docs_expired' ? " AND ({$st}) = 'expired'" : ($key === 'docs_expiring' ? " AND ({$st}) = 'expiring'" : '');
            if ($f['doc_type'] > 0) {
                $extra .= ' AND d.document_type_id = ' . (int)$f['doc_type'];
            }
            $def = ['columns' => ['Vehicle No.', 'Document type', 'Issue date', 'Expiry date', 'Status', 'Uploaded', 'Remarks'],
                'sql' => "SELECT v.registration_no, dt.name, d.issue_date, d.expiry_date, {$st}, d.uploaded_at, d.remarks
                            FROM vehicle_documents d JOIN vehicle_document_types dt ON dt.id = d.document_type_id JOIN vehicles v ON v.id = d.vehicle_id
                           WHERE d.current_flag = 1 AND d.vehicle_id IN ({$idSql}){$extra}",
                'order' => 'd.expiry_date IS NULL ASC, d.expiry_date ASC, v.registration_no ASC'];
            break;
        case 'docs_missing':
            $def = ['columns' => ['Vehicle No.', 'Type', 'Missing document'],
                'sql' => "SELECT v.registration_no, vt.name, dt.name
                            FROM vehicles v JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
                            JOIN vehicle_document_types dt ON dt.is_required = 1 AND dt.status = 'active'
                            LEFT JOIN vehicle_documents d ON d.vehicle_id = v.id AND d.document_type_id = dt.id AND d.current_flag = 1
                           WHERE d.id IS NULL AND v.deleted_at IS NULL AND v.id IN ({$idSql})",
                'order' => 'v.registration_no ASC, dt.name ASC'];
            $note = 'Lists active document types marked "required" in Transport Configuration.';
            break;
    }

    if ($rowsOverride !== null) {
        $total = count($rowsOverride);
        $rows = $limit !== null ? array_slice($rowsOverride, $offset, $limit) : $rowsOverride;
        return ['title' => $cat[$key][1], 'columns' => $def['columns'], 'rows' => $rows, 'total' => $total, 'note' => $note];
    }

    /* FOUND_ROWS() counts the full result even with LIMIT, and — unlike a
       COUNT(*) wrapper — copes with queries that repeat column names. */
    $sql = preg_replace('/^\s*SELECT\s/i', 'SELECT SQL_CALC_FOUND_ROWS ', $def['sql'], 1) . ' ORDER BY ' . $def['order'];
    $sql .= ' LIMIT ' . ($limit !== null ? (int)$limit : TRN_EXPORT_ROW_CAP) . ($limit !== null ? ' OFFSET ' . (int)$offset : '');
    $stmt = Database::query($sql, $params);
    $rows = [];
    while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
        $rows[] = array_map(static fn($v) => $v === null ? '' : $v, $r);
    }
    $total = (int)Database::fetchValue('SELECT FOUND_ROWS()');

    /* Format a few columns for people: CNIC with dashes, status words. */
    foreach ($def['columns'] as $i => $col) {
        if (in_array($col, ['CNIC', 'Driver CNIC'], true)) {
            foreach ($rows as &$row) {
                $row[$i] = $row[$i] !== '' ? Security::formatCnic((string)$row[$i]) : '';
            }
            unset($row);
        }
        if ($col === 'Status' && str_starts_with($key, 'docs_')) {
            foreach ($rows as &$row) {
                $row[$i] = trn_doc_status_label((string)$row[$i]);
            }
            unset($row);
        }
    }

    return ['title' => $cat[$key][1], 'columns' => $def['columns'], 'rows' => $rows, 'total' => $total, 'note' => $note];
}

/* ------------------------------------------------------------------ *
 | Export writers                                                      |
 * ------------------------------------------------------------------ */

/** Neutralise spreadsheet formula injection in a text cell. */
function trn_csv_cell(mixed $v): string
{
    $t = (string)$v;
    return ($t !== '' && in_array($t[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $t : $t;
}

function trn_export_headers(string $mime, string $fileName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
}

function trn_export_csv(array $report, string $fileName): never
{
    trn_export_headers('text/csv; charset=utf-8', $fileName);
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [company()['name'] . ' - ' . $report['title']], ',', '"', '\\');
    fputcsv($out, ['Generated', date('Y-m-d H:i')], ',', '"', '\\');
    fputcsv($out, [], ',', '"', '\\');
    fputcsv($out, $report['columns'], ',', '"', '\\');
    foreach ($report['rows'] as $row) {
        fputcsv($out, array_map('trn_csv_cell', $row), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

function trn_export_xlsx(array $report, string $fileName): never
{
    require_once dirname(__DIR__, 2) . '/complaints/lib/xlsx.php'; // existing streaming .xlsx writer
    $book = new CmpXlsx($report['title']);
    $book->addRow($report['columns'], true);
    foreach ($report['rows'] as $row) {
        $book->addRow(array_map(static fn($v) => is_int($v) || is_float($v) ? $v : (string)$v, $row));
    }
    $file = $book->finish();
    trn_export_headers('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $fileName);
    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
    @unlink($file);
    exit;
}

function trn_export_pdf(array $report, string $fileName): never
{
    require_once dirname(__DIR__, 2) . '/hr/lib/pdf.php';
    $class = hr_pdf_class();
    /** @var TCPDF $pdf */
    $pdf = new $class('L', 'mm', 'A4', true, 'UTF-8', false);
    $company = company();
    $pdf->brand = $company;
    $pdf->docLabel = $report['title'];
    $pdf->generatedOn = date('d M Y, H:i');
    $temps = [];
    $logo = hr_pdf_image(hr_pdf_logo_path(), 400);
    if ($logo !== null) {
        $pdf->logoJpeg = $logo[0];
        $temps[] = $logo[0];
    }
    $pdf->SetCreator((string)$company['name']);
    $pdf->SetTitle($report['title']);
    $pdf->SetMargins(10, 31, 10);
    $pdf->SetHeaderMargin(8);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setFontSubsetting(true);
    $pdf->AddPage();

    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(23, 90, 62);
    $pdf->Cell(0, 8, $report['title'], 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(95, 110, 105);
    $pdf->Cell(0, 5, number_format(count($report['rows'])) . ' row(s)', 0, 1, 'L');
    $pdf->Ln(1);

    $cols = count($report['columns']);
    $w = $pdf->getPageWidth() - 20;
    $fs = $cols > 12 ? 5.6 : ($cols > 8 ? 6.6 : 7.8);

    $head = '<tr>';
    foreach ($report['columns'] as $c) {
        $head .= '<th style="background-color:#17a06a;color:#ffffff;font-weight:bold;">' . htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html = '<table border="0.2" cellpadding="2" cellspacing="0" style="font-size:' . $fs . 'pt;" width="' . $w . '">' . $head . '</tr>';
    foreach ($report['rows'] as $i => $row) {
        $html .= '<tr' . ($i % 2 ? ' style="background-color:#f1f6f3;"' : '') . '>';
        foreach ($row as $v) {
            $html .= '<td>' . htmlspecialchars(is_float($v) ? (string)round($v, 2) : (string)$v, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    $pdf->SetFont('dejavusans', '', $fs);
    $pdf->writeHTML($html, true, false, true, false, '');
    if ($report['note'] !== '') {
        $pdf->Ln(2);
        $pdf->SetFont('dejavusans', 'I', 7);
        $pdf->MultiCell(0, 4, $report['note'], 0, 'L');
    }
    hr_pdf_send($pdf, $fileName, $temps);
}
