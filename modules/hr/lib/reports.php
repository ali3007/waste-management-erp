<?php
/**
 * Report breakdowns (spec section 21) and CSV helpers.
 *
 * Every breakdown is computed over EXACTLY the employees the filters
 * select (same WHERE as the list and the export), so the "Total" always
 * equals the number of rows in the employee table below it.
 *
 * Meaning of each grouping:
 *   Pool / Type / Region / District / Tehsil / Status → the employee's own record.
 *   UC / Beat → their CURRENT assignment (an employee holding two UCs
 *   counts once under each; the Unassigned row counts those with none).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * @return array<string, array<int, array{label:string, sub:string, count:int, link:array}>>
 */
function hr_report_breakdowns(array $filters): array
{
    [$where, $params] = hr_filter_sql($filters);

    /* UC/Beat grouping joins current assignments. When the user filtered
       by a UC/Beat (or is area-scoped), the join is narrowed the same way,
       so a multi-UC employee doesn't leak counts for UCs outside the
       filter. Values are cast ints — no user text is concatenated. */
    $joinExtra = '';
    if ($filters['uc'] > 0) {
        $joinExtra .= ' AND a.uc_id = ' . (int)$filters['uc'];
    }
    if ($filters['beat'] > 0) {
        $joinExtra .= ' AND a.beat_id = ' . (int)$filters['beat'];
    }
    $aScope = hr_assignment_scope_sql('a');
    if ($aScope !== '') {
        $joinExtra .= ' AND ' . $aScope;
    }

    $out = [];

    $simple = static function (string $sql) use ($params): array {
        return Database::fetchAll($sql, $params);
    };

    $out['pool'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => '', 'count' => (int)$r['cnt'], 'link' => ['pool' => (int)$r['id']]],
        $simple("SELECT sp.id, sp.name, COUNT(*) AS cnt FROM employees e JOIN hr_staff_pools sp ON sp.id = e.staff_pool_id WHERE {$where} GROUP BY sp.id, sp.name ORDER BY cnt DESC, sp.name ASC")
    );
    $out['type'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => $r['pool_name'], 'count' => (int)$r['cnt'], 'link' => ['type' => (int)$r['id']]],
        $simple("SELECT st.id, st.name, sp.name AS pool_name, COUNT(*) AS cnt FROM employees e JOIN hr_staff_types st ON st.id = e.staff_type_id JOIN hr_staff_pools sp ON sp.id = st.staff_pool_id WHERE {$where} GROUP BY st.id, st.name, sp.name ORDER BY cnt DESC, st.name ASC")
    );
    $out['status'] = array_map(
        static fn($r) => ['label' => ucfirst((string)$r['status']), 'sub' => '', 'count' => (int)$r['cnt'], 'link' => ['status' => (string)$r['status']]],
        $simple("SELECT e.status, COUNT(*) AS cnt FROM employees e WHERE {$where} GROUP BY e.status ORDER BY e.status ASC")
    );
    $out['region'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => '', 'count' => (int)$r['cnt'], 'link' => ['region' => (int)$r['id']]],
        $simple("SELECT r.id, r.name, COUNT(*) AS cnt FROM employees e JOIN regions r ON r.id = e.region_id WHERE {$where} GROUP BY r.id, r.name ORDER BY cnt DESC, r.name ASC")
    );
    $out['district'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => $r['region_name'], 'count' => (int)$r['cnt'], 'link' => ['region' => (int)$r['region_id'], 'district' => (int)$r['id']]],
        $simple("SELECT d.id, d.name, d.region_id, r.name AS region_name, COUNT(*) AS cnt FROM employees e JOIN districts d ON d.id = e.district_id JOIN regions r ON r.id = d.region_id WHERE {$where} GROUP BY d.id, d.name, d.region_id, r.name ORDER BY cnt DESC, d.name ASC")
    );
    $out['tehsil'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => $r['district_name'], 'count' => (int)$r['cnt'], 'link' => ['region' => (int)$r['region_id'], 'district' => (int)$r['district_id'], 'tehsil' => (int)$r['id']]],
        $simple("SELECT t.id, t.name, t.district_id, d.name AS district_name, d.region_id, COUNT(*) AS cnt FROM employees e JOIN tehsils t ON t.id = e.tehsil_id JOIN districts d ON d.id = t.district_id WHERE {$where} GROUP BY t.id, t.name, t.district_id, d.name, d.region_id ORDER BY cnt DESC, t.name ASC")
    );
    $out['uc'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => $r['tehsil_name'], 'count' => (int)$r['cnt'], 'link' => ['uc' => (int)$r['id']]],
        $simple("SELECT u.id, u.name, t.name AS tehsil_name, COUNT(DISTINCT e.id) AS cnt
                   FROM employees e
                   JOIN employee_assignments a ON a.employee_id = e.id AND a.unassigned_at IS NULL{$joinExtra}
                   JOIN ucs u ON u.id = a.uc_id JOIN tehsils t ON t.id = u.tehsil_id
                  WHERE {$where} GROUP BY u.id, u.name, t.name ORDER BY cnt DESC, u.name ASC")
    );
    $out['beat'] = array_map(
        static fn($r) => ['label' => $r['name'], 'sub' => $r['uc_name'], 'count' => (int)$r['cnt'], 'link' => ['uc' => (int)$r['uc_id'], 'beat' => (int)$r['id']]],
        $simple("SELECT b.id, b.name, b.uc_id, u.name AS uc_name, COUNT(DISTINCT e.id) AS cnt
                   FROM employees e
                   JOIN employee_assignments a ON a.employee_id = e.id AND a.unassigned_at IS NULL AND a.beat_id IS NOT NULL{$joinExtra}
                   JOIN beats b ON b.id = a.beat_id JOIN ucs u ON u.id = b.uc_id
                  WHERE {$where} GROUP BY b.id, b.name, b.uc_id, u.name ORDER BY cnt DESC, b.name ASC")
    );

    return $out;
}

/** Titles for the breakdown tables, in display order. */
function hr_report_titles(): array
{
    return [
        'pool' => 'Staff Pool', 'type' => 'Staff Type', 'status' => 'Status',
        'region' => 'Region', 'district' => 'District', 'tehsil' => 'Tehsil',
        'uc' => 'UC (current assignment)', 'beat' => 'Beat (current assignment)',
    ];
}

/** Neutralise spreadsheet formula injection in a CSV cell. */
function hr_csv_cell(mixed $value): string
{
    $text = (string)$value;
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $text;
    }
    return $text;
}

/** Write one CSV row (with formula protection) to the output stream. */
function hr_csv_row($handle, array $cells): void
{
    fputcsv($handle, array_map('hr_csv_cell', $cells), ',', '"', '\\');
}
