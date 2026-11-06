<?php
/**
 * Employee Profile PDF and Employment Letter (spec sections 17, 18, 34).
 *
 * Built on TCPDF, bundled in /libs/tcpdf so nothing needs Composer or an
 * internet connection on XAMPP or a production server. Both documents are
 * generated from the database at the moment of the request — nothing about
 * an individual employee is stored in, or hard-coded into, a template — and
 * both use the company name, logo, address and contact details configured
 * during installation (company()).
 *
 * Text is rendered in DejaVu Sans, which carries a wide Unicode range, so
 * accented Latin and most symbols print correctly. (Urdu/Arabic script
 * would need a shaping-capable font; see README.)
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Load TCPDF once, with its configuration pinned to this project. */
function hr_pdf_boot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    $root = ROOT_PATH . '/libs/tcpdf/';
    if (!is_file($root . 'tcpdf.php')) {
        throw new RuntimeException('The PDF library is missing (libs/tcpdf).');
    }

    $cache = STORAGE_PATH . '/cache/tcpdf/';
    if (!is_dir($cache)) {
        @mkdir($cache, 0775, true);
    }
    if (!is_dir($cache) || !is_writable($cache)) {
        $cache = rtrim(sys_get_temp_dir(), '/\\') . '/swms-tcpdf/';
        @mkdir($cache, 0775, true);
    }

    define('K_TCPDF_EXTERNAL_CONFIG', true);
    define('K_PATH_MAIN', $root);
    define('K_PATH_URL', $root);
    define('K_PATH_FONTS', $root . 'fonts/');
    define('K_PATH_CACHE', $cache);
    define('K_PATH_URL_CACHE', $cache);
    define('K_PATH_IMAGES', $root);
    define('K_BLANK_IMAGE', $root . 'blank.gif');
    define('PDF_PAGE_FORMAT', 'A4');
    define('PDF_PAGE_ORIENTATION', 'P');
    define('PDF_CREATOR', 'SWMS');
    define('PDF_AUTHOR', 'SWMS');
    define('PDF_UNIT', 'mm');
    define('PDF_MARGIN_HEADER', 5);
    define('PDF_MARGIN_FOOTER', 10);
    define('PDF_MARGIN_TOP', 27);
    define('PDF_MARGIN_BOTTOM', 25);
    define('PDF_MARGIN_LEFT', 15);
    define('PDF_MARGIN_RIGHT', 15);
    define('PDF_FONT_NAME_MAIN', 'dejavusans');
    define('PDF_FONT_SIZE_MAIN', 10);
    define('PDF_FONT_NAME_DATA', 'dejavusans');
    define('PDF_FONT_SIZE_DATA', 8);
    define('PDF_FONT_MONOSPACED', 'courier');
    define('PDF_IMAGE_SCALE_RATIO', 1.25);
    define('HEAD_MAGNIFICATION', 1.1);
    define('K_CELL_HEIGHT_RATIO', 1.25);
    define('K_TITLE_MAGNIFICATION', 1.3);
    define('K_SMALL_RATIO', 2 / 3);
    define('K_THAI_TOPCHARS', true);
    define('K_TCPDF_CALLS_IN_HTML', false);
    define('K_TCPDF_THROW_EXCEPTION_ERROR', true);

    require_once $root . 'tcpdf.php';
}

/**
 * Prepare an image file for TCPDF: re-encode through GD to a clean JPEG
 * (flattening transparency onto white). That also makes GIF/WEBP work and
 * guarantees TCPDF never parses an untrusted file format itself.
 * Returns [path, w, h] of a temp file the caller must unlink, or null.
 */
function hr_pdf_image(?string $path, int $maxSide = 900): ?array
{
    if ($path === null || !is_file($path) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $bytes = @file_get_contents($path);
    $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $tmp = tempnam(sys_get_temp_dir(), 'hrimg');
    if ($tmp === false) {
        imagedestroy($dst);
        return null;
    }
    $ok = imagejpeg($dst, $tmp, 88);
    imagedestroy($dst);
    if (!$ok) {
        @unlink($tmp);
        return null;
    }
    return [$tmp, $nw, $nh];
}

/** Path of the configured company logo, or null. */
function hr_pdf_logo_path(): ?string
{
    $logo = company()['logo'];
    if ($logo === '' || str_contains($logo, '/') || str_contains($logo, '\\') || str_contains($logo, '..')) {
        return null;
    }
    $path = COMPANY_LOGO_PATH . '/' . $logo;
    return is_file($path) ? $path : null;
}

/** The letterhead/footer subclass of TCPDF (lib/HrPdf.php). */
function hr_pdf_class(): string
{
    hr_pdf_boot();
    if (!class_exists('HrPdf', false)) {
        require_once __DIR__ . '/HrPdf.php';
    }
    return 'HrPdf';
}

/** Create a configured document. */
function hr_pdf_new(string $title, string $docLabel, bool $letterhead = true): array
{
    $class = hr_pdf_class();
    /** @var TCPDF $pdf */
    $pdf = new $class('P', 'mm', 'A4', true, 'UTF-8', false);

    $company = company();
    $pdf->brand = $company;
    $pdf->docLabel = $docLabel;
    $pdf->generatedOn = date('d M Y, H:i');
    $pdf->letterhead = $letterhead;

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

/** Send the PDF to the browser as a download. */
function hr_pdf_send(TCPDF $pdf, string $fileName, array $temps): never
{
    $data = $pdf->Output($fileName, 'S');
    foreach ($temps as $t) {
        @unlink($t);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}

/** "Name" → safe file-name fragment. */
function hr_pdf_slug(string $text): string
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '';
    return trim($slug, '-') ?: 'employee';
}

/** A two-column "Label | Value" table block with a shaded section title. */
function hr_pdf_section(TCPDF $pdf, string $title, array $rows): void
{
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 9.5);
    $pdf->SetFillColor(231, 243, 237);
    $pdf->SetTextColor(23, 90, 62);
    $pdf->Cell(0, 7, '  ' . $title, 0, 1, 'L', true);
    $pdf->Ln(1);

    $labelW = 48;
    $valueW = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'] - $labelW;
    foreach ($rows as [$label, $value]) {
        $value = (string)$value === '' ? '—' : (string)$value;
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(110, 124, 119);
        $startY = $pdf->GetY();
        $pdf->MultiCell($labelW, 6, $label, 0, 'L', false, 0, '', '', true, 0, false, true, 6, 'T');
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(30, 40, 36);
        $pdf->MultiCell($valueW, 6, $value, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T');
        $pdf->SetDrawColor(228, 233, 231);
        $pdf->Line($pdf->getMargins()['left'], $pdf->GetY() + 0.5, $pdf->getPageWidth() - $pdf->getMargins()['right'], $pdf->GetY() + 0.5);
        $pdf->Ln(1);
    }
}

/** Load everything either document needs about one employee. */
function hr_pdf_employee_data(int $id): ?array
{
    $employee = hr_find_employee($id);
    if ($employee === null) {
        return null;
    }
    $assignments = Database::fetchAll(
        'SELECT a.assigned_at, u.name AS uc_name, b.name AS beat_name,
                t.name AS tehsil_name, d.name AS district_name, r.name AS region_name
           FROM employee_assignments a
           JOIN ucs u ON u.id = a.uc_id
           JOIN tehsils t ON t.id = u.tehsil_id
           JOIN districts d ON d.id = t.district_id
           JOIN regions r ON r.id = d.region_id
           LEFT JOIN beats b ON b.id = a.beat_id
          WHERE a.employee_id = :id AND a.unassigned_at IS NULL
          ORDER BY a.assigned_at ASC',
        ['id' => $id]
    );
    return ['employee' => $employee, 'assignments' => $assignments];
}

/** Date like "14 Mar 2026", or '' when empty. */
function hr_pdf_date(?string $value): string
{
    return $value ? format_datetime($value, 'd M Y') : '';
}

/**
 * Employee Profile PDF (spec section 17). Always includes document
 * INFORMATION (what is on file) — never the files themselves.
 */
function hr_generate_profile_pdf(int $employeeId, bool $includeDocuments): never
{
    $data = hr_pdf_employee_data($employeeId);
    if ($data === null) {
        throw new RuntimeException('Employee not found.');
    }
    $emp = $data['employee'];
    $assignments = $data['assignments'];

    [$pdf, $temps] = hr_pdf_new('Employee Profile - ' . $emp['name'], 'Employee Profile · ' . ($emp['employee_code'] ?: ''));

    /* Title row: photo on the right, name/ID on the left. */
    $top = $pdf->GetY();
    $photo = null;
    if (!empty($emp['employee_picture'])) {
        $photo = hr_pdf_image(hr_doc_path((string)$emp['employee_picture']), 500);
    }
    $pageW = $pdf->getPageWidth();
    $right = $pdf->getMargins()['right'];

    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetTextColor(110, 124, 119);
    $pdf->Cell(0, 5, 'EMPLOYEE PROFILE', 0, 1, 'L');
    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->SetTextColor(25, 35, 31);
    $pdf->MultiCell($pageW - 15 - $right - 38, 9, (string)$emp['name'], 0, 'L', false, 1);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetTextColor(70, 84, 79);
    $pdf->Cell(0, 6, (string)$emp['employee_code'] . '   ·   ' . (string)$emp['staff_type_name'], 0, 1, 'L');

    $isActive = $emp['status'] === 'active';
    $pdf->Ln(1);
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor($isActive ? 23 : 150, $isActive ? 160 : 110, $isActive ? 106 : 110);
    $pdf->Cell(24, 6, $isActive ? 'ACTIVE' : 'INACTIVE', 0, 0, 'C', true);

    if ($photo !== null) {
        $temps[] = $photo[0];
        $boxW = 30;
        $boxH = 38;
        $x = $pageW - $right - $boxW;
        $pdf->SetDrawColor(200, 208, 205);
        $pdf->Rect($x - 0.5, $top - 0.5, $boxW + 1, $boxH + 1);
        $ratio = $photo[1] / $photo[2];
        $w = $boxW;
        $h = $boxW / $ratio;
        if ($h > $boxH) {
            $h = $boxH;
            $w = $boxH * $ratio;
        }
        $pdf->Image($photo[0], $x + ($boxW - $w) / 2, $top + ($boxH - $h) / 2, $w, $h, 'JPG');
    }
    $pdf->SetY(max($pdf->GetY(), $top + 40));

    hr_pdf_section($pdf, 'Personal information', [
        ['Employee Name', $emp['name']],
        ["Father's Name", $emp['father_name']],
        ['CNIC', Security::formatCnic((string)$emp['cnic'])],
        ['CNIC Issue Date', hr_pdf_date($emp['cnic_issue_date'])],
        ['Date of Birth', hr_pdf_date($emp['dob'])],
        ['Phone', $emp['phone'] ?? ''],
        ['Address', $emp['address'] ?? ''],
    ]);

    hr_pdf_section($pdf, 'Employment information', [
        ['Employee ID', $emp['employee_code']],
        ['Staff Pool', $emp['staff_pool_name']],
        ['Staff Type', $emp['staff_type_name']],
        ['Employee Status', $isActive ? 'Active' : 'Inactive'],
        ['Registered area', $emp['tehsil_name'] . ', ' . $emp['district_name'] . ', ' . $emp['region_name']],
        ['Record created', hr_pdf_date($emp['created_at'])],
    ]);

    /* Administrative assignment */
    if ($emp['assignment_mode'] === 'none') {
        hr_pdf_section($pdf, 'Administrative assignment', [['Assignment', 'Not required for this staff type']]);
    } elseif (empty($assignments)) {
        hr_pdf_section($pdf, 'Administrative assignment', [['Assignment', 'Unassigned']]);
    } else {
        foreach ($assignments as $i => $a) {
            hr_pdf_section($pdf, 'Administrative assignment' . (count($assignments) > 1 ? ' ' . ($i + 1) : ''), [
                ['Region', $a['region_name']],
                ['District', $a['district_name']],
                ['Tehsil', $a['tehsil_name']],
                ['UC', $a['uc_name']],
                ['Beat', $a['beat_name'] ?? ''],
                ['Assigned since', hr_pdf_date((string)$a['assigned_at'])],
            ]);
        }
    }

    if ($includeDocuments) {
        $docs = Database::fetchAll('SELECT title, original_name, file_size, uploaded_at FROM employee_documents WHERE employee_id = :id ORDER BY uploaded_at ASC', ['id' => $employeeId]);
        $rows = [
            ['Employee Picture', !empty($emp['employee_picture']) ? 'On file' : 'Not uploaded'],
            ['CNIC Picture', !empty($emp['cnic_picture']) ? 'On file' : 'Not uploaded'],
        ];
        foreach ($docs as $d) {
            $rows[] = [(string)($d['title'] ?: 'Document'), $d['original_name'] . ' (' . hr_pdf_date((string)$d['uploaded_at']) . ')'];
        }
        if (empty($docs)) {
            $rows[] = ['Other documents', 'None uploaded'];
        }
        hr_pdf_section($pdf, 'Documents on file', $rows);
    }

    $pdf->Ln(4);
    $pdf->SetFont('dejavusans', 'I', 7.5);
    $pdf->SetTextColor(130, 140, 136);
    $pdf->MultiCell(0, 4, 'This profile was generated from the HR database on ' . date('d M Y, H:i') . ' and reflects the record at that moment.', 0, 'L');

    hr_pdf_send($pdf, 'Employee-Profile-' . hr_pdf_slug((string)$emp['employee_code'] ?: (string)$emp['name']) . '.pdf', $temps);
}

/**
 * Employment letter (spec section 18). Wording uses only facts held in
 * the database; it makes no claim about joining date or salary because
 * the HR record doesn't store them.
 */
function hr_generate_employment_letter(int $employeeId, string $issuedBy): never
{
    $data = hr_pdf_employee_data($employeeId);
    if ($data === null) {
        throw new RuntimeException('Employee not found.');
    }
    $emp = $data['employee'];
    $assignments = $data['assignments'];
    $company = company();

    [$pdf, $temps] = hr_pdf_new('Employment Letter - ' . $emp['name'], 'Employment Letter · ' . ($emp['employee_code'] ?: ''));

    $ref = 'HR/' . date('Y') . '/' . ($emp['employee_code'] ?: str_pad((string)$employeeId, 5, '0', STR_PAD_LEFT)) . '/' . date('md');

    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->SetTextColor(40, 50, 46);
    $pdf->Cell(95, 6, 'Ref: ' . $ref, 0, 0, 'L');
    $pdf->Cell(0, 6, 'Date: ' . date('d F Y'), 0, 1, 'R');
    $pdf->Ln(8);

    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->SetTextColor(25, 35, 31);
    $pdf->Cell(0, 8, 'TO WHOM IT MAY CONCERN', 0, 1, 'C');
    $pdf->SetFont('dejavusans', 'B', 10.5);
    $pdf->Cell(0, 7, 'Subject: Employment Certificate', 0, 1, 'C');
    $pdf->Ln(6);

    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $assignmentText = '';
    if ($emp['assignment_mode'] !== 'none' && !empty($assignments)) {
        $parts = [];
        foreach ($assignments as $a) {
            $parts[] = $h((string)$a['uc_name']) . ($a['beat_name'] ? ' (' . $h((string)$a['beat_name']) . ')' : '')
                . ', Tehsil ' . $h((string)$a['tehsil_name']) . ', District ' . $h((string)$a['district_name']);
        }
        $assignmentText = ' The employee is currently assigned to ' . implode('; ', $parts) . '.';
    }

    $body = '<p style="text-align:justify; line-height:1.7">'
        . 'This is to certify that <b>' . $h((string)$emp['name']) . '</b>, ' . 'whose father\'s name is <b>' . $h((string)$emp['father_name']) . '</b>, '
        . 'holder of CNIC No. <b>' . $h(Security::formatCnic((string)$emp['cnic'])) . '</b>, '
        . 'is an employee of <b>' . $h((string)$company['name']) . '</b> '
        . 'under Employee ID <b>' . $h((string)$emp['employee_code']) . '</b>, '
        . 'serving in the capacity of <b>' . $h((string)$emp['staff_type_name']) . '</b> '
        . 'in the ' . $h((string)$emp['staff_pool_name']) . ' category.'
        . $assignmentText
        . '</p>'
        . '<p style="text-align:justify; line-height:1.7">'
        . 'The employee\'s record with the company is currently <b>' . ($emp['status'] === 'active' ? 'active' : 'inactive') . '</b>.'
        . '</p>'
        . '<p style="text-align:justify; line-height:1.7">'
        . 'This letter is issued on the request of the employee for whatever lawful purpose it may serve, and is based on the company\'s HR records as on the date of issue. '
        . 'It does not constitute a guarantee of future employment or a financial commitment of any kind.'
        . '</p>';

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->writeHTML($body, true, false, true, false, '');

    /* Signature area */
    $pdf->Ln(22);
    $y = $pdf->GetY();
    if ($y > 235) {
        $pdf->AddPage();
        $y = $pdf->GetY() + 10;
    }
    $pdf->SetDrawColor(80, 90, 86);
    $pdf->Line(15, $y, 80, $y);
    $pdf->SetXY(15, $y + 1);
    $pdf->SetFont('dejavusans', 'B', 9.5);
    $pdf->Cell(65, 5, 'Authorized Signatory', 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->SetTextColor(100, 112, 107);
    $pdf->Cell(65, 5, 'Human Resources Department', 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->Cell(65, 5, (string)$company['name'], 0, 1, 'L');

    $pdf->SetXY(125, $y);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(150, 160, 156);
    $pdf->Cell(70, 22, '[ Company stamp ]', 1, 0, 'C');

    $pdf->Ln(30);
    $pdf->SetFont('dejavusans', 'I', 7.5);
    $pdf->SetTextColor(130, 140, 136);
    $pdf->MultiCell(0, 4, 'Computer-generated on ' . date('d M Y, H:i') . ($issuedBy !== '' ? ' by ' . $issuedBy : '') . '. Reference ' . $ref . '.', 0, 'L');

    hr_pdf_send($pdf, 'Employment-Letter-' . hr_pdf_slug((string)$emp['employee_code'] ?: (string)$emp['name']) . '.pdf', $temps);
}
