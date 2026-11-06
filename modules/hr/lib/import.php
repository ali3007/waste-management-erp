<?php
/**
 * Bulk employee import from CSV (spec sections 22–25).
 *
 * Workflow: template → upload → validate → preview → confirm → import.
 * Nothing is written to the employee table until the user confirms the
 * preview, and only rows that passed validation are ever written — a bad
 * row is reported with its row number and reason, never imported silently.
 *
 * Row outcomes:
 *   valid   → will be imported
 *   skipped → the CNIC already exists (in the database, or earlier in the
 *             same file) — never duplicated
 *   error   → anything else wrong (reason shown per row)
 *
 * UC and Beat are deliberately NOT part of the file: imported employees
 * start in Unassigned Employees and are assigned afterwards (spec 25).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const HR_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const HR_IMPORT_MAX_ROWS = 5000;
const HR_IMPORT_DIR = STORAGE_PATH . '/imports';

/** Template columns: [key, header, required?, note]. */
function hr_import_columns(): array
{
    return [
        ['name',            'Employee Name',   true,  'Full name'],
        ['father_name',     "Father's Name",   true,  'Full name'],
        ['cnic',            'CNIC',            true,  '13 digits, with or without dashes. Format this column as Text in Excel.'],
        ['dob',             'Date of Birth',   false, 'YYYY-MM-DD (DD/MM/YYYY also accepted)'],
        ['cnic_issue_date', 'CNIC Issue Date', false, 'YYYY-MM-DD (DD/MM/YYYY also accepted)'],
        ['phone',           'Phone',           false, 'Digits, +, - and spaces'],
        ['address',         'Address',         false, 'Free text'],
        ['region',          'Region',          true,  'Must match an existing Region name'],
        ['district',        'District',        true,  'Must belong to that Region'],
        ['tehsil',          'Tehsil',          true,  'Must belong to that District'],
        ['staff_pool',      'Staff Pool',      true,  'Must match an existing Staff Pool'],
        ['staff_type',      'Staff Type',      true,  'Must belong to that Staff Pool'],
    ];
}

/** Header text people might use → internal key. */
function hr_import_header_key(string $header): ?string
{
    $h = mb_strtolower(trim($header));
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
    $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
    $h = trim($h, '_');

    static $aliases = [
        'name' => 'name', 'employee_name' => 'name', 'full_name' => 'name',
        'father_name' => 'father_name', 'fathers_name' => 'father_name', 'father_s_name' => 'father_name', 'father' => 'father_name',
        'cnic' => 'cnic', 'cnic_no' => 'cnic', 'cnic_number' => 'cnic', 'nic' => 'cnic',
        'dob' => 'dob', 'date_of_birth' => 'dob', 'birth_date' => 'dob',
        'cnic_issue_date' => 'cnic_issue_date', 'issue_date' => 'cnic_issue_date', 'cnic_issue' => 'cnic_issue_date',
        'phone' => 'phone', 'phone_no' => 'phone', 'phone_number' => 'phone', 'mobile' => 'phone', 'contact' => 'phone',
        'address' => 'address',
        'region' => 'region', 'district' => 'district', 'tehsil' => 'tehsil',
        'staff_pool' => 'staff_pool', 'pool' => 'staff_pool', 'hr_pool' => 'staff_pool',
        'staff_type' => 'staff_type', 'type' => 'staff_type', 'hr_type' => 'staff_type', 'designation' => 'staff_type',
    ];
    return $aliases[$h] ?? null;
}

/** Send the blank template (header + one deliberately-invalid example row). */
function hr_import_send_template(): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employee-import-template.csv"');
    header('Cache-Control: private, no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(static fn($c) => $c[1], hr_import_columns()), ',', '"', '\\');
    /* The example uses made-up area names on purpose: if it is left in
       the file it fails validation ("Invalid Region") instead of being
       imported as a fake employee. */
    fputcsv($out, [
        'EXAMPLE - delete this row', 'Example Father', '3310112345679', '1990-05-14', '2015-03-02', '0300-1234567',
        'House 1, Example Street', 'Example Region', 'Example District', 'Example Tehsil', 'Example Pool', 'Example Type',
    ], ',', '"', '\\');
    fclose($out);
    exit;
}

/** Valid names, for the on-screen reference list. */
function hr_import_reference(): array
{
    return [
        'regions' => Database::fetchAll(
            "SELECT r.name AS region, d.name AS district, GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') AS tehsils
               FROM regions r
               JOIN districts d ON d.region_id = r.id AND d.status = 'active'
               LEFT JOIN tehsils t ON t.district_id = d.id AND t.status = 'active'
              WHERE r.status = 'active'
              GROUP BY r.id, r.name, d.id, d.name ORDER BY r.name, d.name"
        ),
        'types' => Database::fetchAll(
            "SELECT sp.name AS pool, GROUP_CONCAT(st.name ORDER BY st.name SEPARATOR ', ') AS types
               FROM hr_staff_pools sp
               LEFT JOIN hr_staff_types st ON st.staff_pool_id = sp.id AND st.status = 'active'
              WHERE sp.status = 'active' GROUP BY sp.id, sp.name ORDER BY sp.name"
        ),
    ];
}

/** Read a file into UTF-8 text, tolerating a BOM and Excel's Windows-1252. */
function hr_import_read_utf8(string $path): ?string
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    return $raw;
}

/** Comma, semicolon or tab — whichever the header line uses most. */
function hr_import_detect_delimiter(string $firstLine): string
{
    $best = ',';
    $max = -1;
    foreach ([',', ';', "\t"] as $d) {
        $n = substr_count($firstLine, $d);
        if ($n > $max) {
            $max = $n;
            $best = $d;
        }
    }
    return $best;
}

/** Normalise a date cell to Y-m-d, or null when it isn't a real date. */
function hr_import_parse_date(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y) || $y < 1900) {
        return null;
    }
    $iso = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    return $iso <= date('Y-m-d') ? $iso : null;
}

/**
 * Parse + validate an uploaded CSV.
 *
 * @return array{ok:bool, message?:string, result?:array}
 */
function hr_import_analyse(string $path): array
{
    $text = hr_import_read_utf8($path);
    if ($text === null) {
        return ['ok' => false, 'message' => 'The file is empty or could not be read.'];
    }

    $firstLine = strtok($text, "\n") ?: '';
    $delimiter = hr_import_detect_delimiter($firstLine);

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $text);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter, '"', '\\');
    if (!is_array($header) || $header === [null]) {
        fclose($stream);
        return ['ok' => false, 'message' => 'The file has no header row. Use the CSV template.'];
    }

    $map = []; // column index → key
    foreach ($header as $i => $cell) {
        $key = hr_import_header_key((string)$cell);
        if ($key !== null && !in_array($key, $map, true)) {
            $map[$i] = $key;
        }
    }
    $missing = [];
    foreach (hr_import_columns() as [$key, $label, $required]) {
        if ($required && !in_array($key, $map, true)) {
            $missing[] = $label;
        }
    }
    if ($missing) {
        fclose($stream);
        return ['ok' => false, 'message' => 'The file is missing required column(s): ' . implode(', ', $missing) . '. Download the template to see the expected headers.'];
    }

    /* Lookups, loaded once (case-insensitive by lower-cased name). */
    $regions = [];
    foreach (Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active'") as $r) {
        $regions[mb_strtolower($r['name'])] = (int)$r['id'];
    }
    $districts = [];
    foreach (Database::fetchAll("SELECT id, name, region_id FROM districts WHERE status = 'active'") as $r) {
        $districts[(int)$r['region_id']][mb_strtolower($r['name'])] = (int)$r['id'];
    }
    $tehsils = [];
    foreach (Database::fetchAll("SELECT id, name, district_id FROM tehsils WHERE status = 'active'") as $r) {
        $tehsils[(int)$r['district_id']][mb_strtolower($r['name'])] = (int)$r['id'];
    }
    $pools = [];
    foreach (Database::fetchAll("SELECT id, name FROM hr_staff_pools WHERE status = 'active'") as $r) {
        $pools[mb_strtolower($r['name'])] = (int)$r['id'];
    }
    $types = [];
    foreach (Database::fetchAll("SELECT id, name, staff_pool_id FROM hr_staff_types WHERE status = 'active'") as $r) {
        $types[(int)$r['staff_pool_id']][mb_strtolower($r['name'])] = (int)$r['id'];
    }

    $existing = []; // cnic → "EMP-00012 (Name)" — filled lazily in chunks
    $seenInFile = []; // cnic → first row number

    $valid = [];
    $skipped = [];
    $errors = [];
    $total = 0;
    $rowNumber = 1; // header was row 1, so the first data row is row 2 (as shown in Excel)

    while (($cells = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
        $rowNumber++;
        if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
            continue; // blank line
        }
        $total++;
        if ($total > HR_IMPORT_MAX_ROWS) {
            fclose($stream);
            return ['ok' => false, 'message' => 'The file has more than ' . number_format(HR_IMPORT_MAX_ROWS) . ' rows. Split it into smaller files and import them one at a time.'];
        }

        $row = array_fill_keys(array_column(hr_import_columns(), 0), '');
        foreach ($map as $i => $key) {
            $row[$key] = trim((string)($cells[$i] ?? ''));
        }
        $original = $row;
        $problems = [];

        $name = Security::cleanText($row['name'], 120);
        $father = Security::cleanText($row['father_name'], 120);
        if ($name === '') { $problems[] = 'Employee Name missing'; }
        elseif (mb_strlen($row['name']) > 120) { $problems[] = 'Employee Name is longer than 120 characters'; }
        if ($father === '') { $problems[] = "Father's Name missing"; }
        elseif (mb_strlen($row['father_name']) > 120) { $problems[] = "Father's Name is longer than 120 characters"; }

        /* CNIC */
        $rawCnic = $row['cnic'];
        $cnic = Security::normalizeCnic($rawCnic);
        $cnicOk = false;
        if ($rawCnic === '') {
            $problems[] = 'CNIC missing';
        } elseif (preg_match('/^\d(\.\d+)?[eE]\+?\d+$/', $rawCnic)) {
            $problems[] = 'Invalid CNIC (Excel turned it into scientific notation — format the CNIC column as Text and re-enter it)';
        } elseif (!Security::isCnic($cnic)) {
            $problems[] = 'Invalid CNIC (must be 13 digits)';
        } else {
            $cnicOk = true;
        }

        /* Dates */
        $dob = null;
        if ($row['dob'] !== '') {
            $dob = hr_import_parse_date($row['dob']);
            if ($dob === null) { $problems[] = 'Invalid Date of Birth "' . $row['dob'] . '" (use YYYY-MM-DD, not in the future)'; }
        }
        $issue = null;
        if ($row['cnic_issue_date'] !== '') {
            $issue = hr_import_parse_date($row['cnic_issue_date']);
            if ($issue === null) { $problems[] = 'Invalid CNIC Issue Date "' . $row['cnic_issue_date'] . '" (use YYYY-MM-DD, not in the future)'; }
        }

        $phone = Security::cleanText($row['phone'], 30);
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) {
            $problems[] = 'Invalid Phone number';
        }
        $address = Security::cleanMultiline($row['address'], 255);

        /* Administrative hierarchy: each level must belong to the one above. */
        $regionId = $districtId = $tehsilId = 0;
        if ($row['region'] === '') {
            $problems[] = 'Region missing';
        } elseif (!isset($regions[mb_strtolower($row['region'])])) {
            $problems[] = 'Invalid Region "' . $row['region'] . '"';
        } else {
            $regionId = $regions[mb_strtolower($row['region'])];
            if ($row['district'] === '') {
                $problems[] = 'District missing';
            } elseif (!isset($districts[$regionId][mb_strtolower($row['district'])])) {
                $problems[] = 'Invalid District "' . $row['district'] . '" for Region "' . $row['region'] . '"';
            } else {
                $districtId = $districts[$regionId][mb_strtolower($row['district'])];
                if ($row['tehsil'] === '') {
                    $problems[] = 'Tehsil missing';
                } elseif (!isset($tehsils[$districtId][mb_strtolower($row['tehsil'])])) {
                    $problems[] = 'Invalid Tehsil "' . $row['tehsil'] . '" for District "' . $row['district'] . '"';
                } else {
                    $tehsilId = $tehsils[$districtId][mb_strtolower($row['tehsil'])];
                }
            }
        }
        /* HR classification */
        $poolId = $typeId = 0;
        if ($row['staff_pool'] === '') {
            $problems[] = 'Staff Pool missing';
        } elseif (!isset($pools[mb_strtolower($row['staff_pool'])])) {
            $problems[] = 'Invalid Staff Pool "' . $row['staff_pool'] . '"';
        } else {
            $poolId = $pools[mb_strtolower($row['staff_pool'])];
            if ($row['staff_type'] === '') {
                $problems[] = 'Staff Type missing';
            } elseif (!isset($types[$poolId][mb_strtolower($row['staff_type'])])) {
                $problems[] = 'Invalid Staff Type "' . $row['staff_type'] . '" for Staff Pool "' . $row['staff_pool'] . '"';
            } else {
                $typeId = $types[$poolId][mb_strtolower($row['staff_type'])];
            }
        }

        /* Duplicate protection (spec 24) — checked for any readable CNIC,
           because a duplicate is skipped no matter what else is wrong. */
        if ($cnicOk) {
            if (!array_key_exists($cnic, $existing)) {
                $found = Database::fetch('SELECT employee_code, name FROM employees WHERE cnic = :c', ['c' => $cnic]);
                $existing[$cnic] = $found ? trim(($found['employee_code'] ?: '') . ' ' . $found['name']) : null;
            }
            if ($existing[$cnic] !== null) {
                $skipped[] = ['row' => $rowNumber, 'name' => $name, 'cnic' => $cnic, 'reason' => 'Duplicate CNIC — already exists in the system (' . $existing[$cnic] . ')', 'data' => $original];
                continue;
            }
            /* Only rows that will actually be imported "claim" a CNIC, so a
               bad first row never causes a good second row to be skipped. */
            if (isset($seenInFile[$cnic])) {
                $skipped[] = ['row' => $rowNumber, 'name' => $name, 'cnic' => $cnic, 'reason' => 'Duplicate CNIC — already appears in row ' . $seenInFile[$cnic] . ' of this file', 'data' => $original];
                continue;
            }
        }

        if ($problems) {
            $errors[] = ['row' => $rowNumber, 'name' => $name, 'cnic' => $cnic, 'reason' => implode('; ', $problems), 'data' => $original];
            continue;
        }

        $seenInFile[$cnic] = $rowNumber;
        $valid[] = [
            'row' => $rowNumber, 'name' => $name, 'father_name' => $father, 'cnic' => $cnic,
            'dob' => $dob, 'cnic_issue_date' => $issue, 'phone' => $phone !== '' ? $phone : null,
            'address' => $address !== '' ? $address : null,
            'region_id' => $regionId, 'district_id' => $districtId, 'tehsil_id' => $tehsilId,
            'staff_pool_id' => $poolId, 'staff_type_id' => $typeId,
            'region' => $row['region'], 'district' => $row['district'], 'tehsil' => $row['tehsil'],
            'pool' => $row['staff_pool'], 'type' => $row['staff_type'],
        ];
    }
    fclose($stream);

    if ($total === 0) {
        return ['ok' => false, 'message' => 'The file has a header but no employee rows.'];
    }

    return ['ok' => true, 'result' => [
        'total' => $total, 'valid' => $valid, 'skipped' => $skipped, 'errors' => $errors,
    ]];
}

/* ------------------------------------------------------------------ *
 | Staging area between "preview" and "confirm"                        |
 * ------------------------------------------------------------------ */

function hr_import_dir(): string
{
    if (!is_dir(HR_IMPORT_DIR)) {
        @mkdir(HR_IMPORT_DIR, 0775, true);
    }
    return HR_IMPORT_DIR;
}

/** Save an analysis result; returns the token that identifies it. */
function hr_import_stage(array $result, string $originalName): string
{
    $dir = hr_import_dir();

    /* Housekeeping: abandoned previews older than a day are removed. */
    foreach (glob($dir . '/imp-*.json') ?: [] as $old) {
        if (@filemtime($old) !== false && filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }

    $token = bin2hex(random_bytes(16));
    $payload = json_encode([
        'user_id' => Auth::id(), 'created' => time(), 'file' => hr_clean_original_name($originalName), 'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false || @file_put_contents($dir . '/imp-' . $token . '.json', $payload, LOCK_EX) === false) {
        throw new RuntimeException('The import could not be staged (is storage/imports writable?).');
    }
    return $token;
}

/** Load a staged import for the CURRENT user only. */
function hr_import_load(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $path = hr_import_dir() . '/imp-' . $token . '.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || (int)($data['user_id'] ?? 0) !== Auth::id() || (int)($data['created'] ?? 0) < time() - 86400) {
        return null;
    }
    return $data;
}

function hr_import_discard(string $token): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $token)) {
        @unlink(hr_import_dir() . '/imp-' . $token . '.json');
    }
}
