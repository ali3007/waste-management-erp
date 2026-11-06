<?php
/**
 * Complaints — export rows (CSV / Excel / PDF list).
 *
 * The export uses EXACTLY the dashboard/report filters and the user's UC
 * scope (lib/filters.php), so a download always matches what was on screen
 * and can never contain a complaint the user may not see.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const CMP_EXPORT_MAX_ROWS = 100000;
const CMP_EXPORT_PDF_MAX_ROWS = 1500;

function cmp_export_headings(): array
{
    return ['Complaint No.', 'Complaint Date', 'Complaint Time', 'Complainant', 'Phone', 'Complainant Address', 'Source', 'Type', 'Priority',
            'Status', 'Region', 'District', 'Tehsil', 'UC', 'Beat', 'Location / Address', 'Latitude', 'Longitude',
            'Supervisor(s)', 'Monitoring Officer(s)', 'Created At', 'Assigned At', 'Resolved At', 'SLA State', 'SLA Due', 'Before Pictures', 'After Pictures', 'Description'];
}

/** Names of the Supervisor/Monitoring Officer(s) of a UC, cached per export. */
function cmp_export_staff(int $ucId, array &$cache): array
{
    if (!isset($cache[$ucId])) {
        $s = cmp_uc_staff($ucId);
        $cache[$ucId] = [
            implode('; ', array_column($s['supervisors'], 'name')),
            implode('; ', array_column($s['monitors'], 'name')),
        ];
    }
    return $cache[$ucId];
}

/** One export row as a plain array of cell values. */
function cmp_export_row(array $r, array &$staffCache, string $now): array
{
    [$sup, $mo] = cmp_export_staff((int)$r['uc_id'], $staffCache);
    $sla = cmp_sla_state($r, $now);
    return [
        $r['complaint_number'], $r['complaint_date'], $r['complaint_time'] ? substr((string)$r['complaint_time'], 0, 5) : '',
        $r['complainant_name'], $r['phone'], $r['complainant_address'], $r['source_name'], $r['type_name'], $r['priority_name'],
        cmp_status_label((string)$r['status']) . ($r['deleted_at'] ? ' (deleted)' : ''),
        $r['region_name'], $r['district_name'], $r['tehsil_name'], $r['uc_name'], $r['beat_name'], $r['location_address'],
        $r['latitude'], $r['longitude'], $sup, $mo, $r['created_at'], $r['assigned_at'], $r['resolved_at'],
        $sla === 'none' ? '' : ucfirst($sla), $r['sla_due_at'], (int)$r['before_count'], (int)$r['after_count'],
        str_replace(["\r\n", "\r", "\n"], ' ', (string)$r['description']),
    ];
}

/**
 * Walk every matching complaint in id order, 500 at a time (keyset
 * pagination — constant memory and no OFFSET cost). $fn gets each row;
 * return false from it to stop. Returns the number of rows visited.
 */
function cmp_each_complaint(array $filters, callable $fn, int $max = CMP_EXPORT_MAX_ROWS): int
{
    [$where, $params] = cmp_filter_sql($filters);
    $last = 0;
    $count = 0;
    while ($count < $max) {
        $batch = min(500, $max - $count);
        $rows = Database::fetchAll(
            cmp_list_select() . " WHERE {$where} AND c.id > {$last} ORDER BY c.id ASC LIMIT {$batch}",
            $params
        );
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            $last = (int)$row['id'];
            $count++;
            if ($fn($row) === false) {
                return $count;
            }
        }
        if (count($rows) < $batch) {
            break;
        }
    }
    return $count;
}

/** Neutralise spreadsheet formula injection in a CSV cell. */
function cmp_csv_cell(mixed $value): string
{
    $text = (string)$value;
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $text;
    }
    return $text;
}

/** Human description of the active filters, for report headers. */
function cmp_filter_summary(array $f): string
{
    $parts = [];
    if ($f['date_from'] !== '' || $f['date_to'] !== '') {
        $parts[] = 'Dates: ' . ($f['date_from'] ?: '…') . ' to ' . ($f['date_to'] ?: '…');
    }
    $lookups = ['region' => ['regions', 'Region'], 'district' => ['districts', 'District'], 'tehsil' => ['tehsils', 'Tehsil'], 'uc' => ['ucs', 'UC'], 'beat' => ['beats', 'Beat'],
                'source' => ['complaint_sources', 'Source'], 'type' => ['complaint_types', 'Type'], 'priority' => ['complaint_priorities', 'Priority']];
    foreach ($lookups as $key => [$table, $label]) {
        if ($f[$key] > 0) {
            $parts[] = $label . ': ' . (string)Database::fetchValue("SELECT name FROM {$table} WHERE id = :id", ['id' => $f[$key]]);
        }
    }
    if ($f['status'] !== '') { $parts[] = 'Status: ' . cmp_status_label($f['status']); }
    if ($f['sla'] !== '') { $parts[] = 'SLA: ' . $f['sla']; }
    foreach (['q' => 'Search', 'number' => 'Number', 'complainant' => 'Complainant', 'phone' => 'Phone'] as $k => $label) {
        if ($f[$k] !== '') { $parts[] = $label . ': ' . $f[$k]; }
    }
    return $parts ? implode(' · ', $parts) : 'No filters (all complaints you may see)';
}
