<?php
/**
 * One definition of "which employees match these filters", shared by
 * All Employees, Reports, CSV export and (for scope) the dashboard — so a
 * screen and its export can never disagree about who is in the result.
 *
 * Filter meaning (also stated on the screens):
 *   - Region / District / Tehsil  → the employee's registered administrative
 *     area (the one chosen on the Add Employee form).
 *   - UC / Beat                   → the employee's CURRENT assignment.
 *   - Status                      → active / inactive.
 *   - Assignment                  → assigned (has an open assignment) or
 *     unassigned (has none).
 * All filters combine with AND. Area-based access control (spec section
 * 29) is always applied on top, inside the same SQL.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const HR_FILTER_KEYS = ['q', 'pool', 'type', 'region', 'district', 'tehsil', 'uc', 'beat', 'status', 'assign'];

/** Read and sanitise filters from a request array ($_GET). */
function hr_read_filters(array $src): array
{
    $text = static fn(string $k): string => isset($src[$k]) && is_string($src[$k]) ? trim($src[$k]) : '';

    $f = [
        'q'        => Security::cleanText($text('q'), 120),
        'pool'     => max(0, (int)$text('pool')),
        'type'     => max(0, (int)$text('type')),
        'region'   => max(0, (int)$text('region')),
        'district' => max(0, (int)$text('district')),
        'tehsil'   => max(0, (int)$text('tehsil')),
        'uc'       => max(0, (int)$text('uc')),
        'beat'     => max(0, (int)$text('beat')),
        'status'   => in_array($text('status'), ['active', 'inactive'], true) ? $text('status') : '',
        'assign'   => in_array($text('assign'), ['assigned', 'unassigned'], true) ? $text('assign') : '',
    ];
    return $f;
}

/** Only the filters that are actually set — for building links/exports. */
function hr_active_filters(array $f): array
{
    return array_filter($f, static fn($v) => $v !== '' && $v !== 0);
}

/** Query string for a filter set plus overrides (e.g. page, export flag). */
function hr_filter_query(array $f, array $extra = []): string
{
    $params = array_merge(hr_active_filters($f), $extra);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return $params ? '?' . http_build_query($params) : '';
}

/**
 * WHERE clause + parameters for a filter set.
 *
 * @return array{0:string,1:array} [sql (never empty — at least '1=1'), params]
 */
function hr_filter_sql(array $f, string $e = 'e'): array
{
    $where = ['1=1'];
    $params = [];

    if ($f['q'] !== '') {
        $or = [$e . '.name LIKE :fq1', $e . '.employee_code LIKE :fq2', $e . '.phone LIKE :fq3', $e . '.father_name LIKE :fq4'];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $params['fq1'] = $params['fq2'] = $params['fq3'] = $params['fq4'] = $like;

        /* People type a CNIC with or without dashes; the column holds
           digits only, so search it by digits. */
        $digits = Security::normalizeCnic($f['q']);
        if ($digits !== '') {
            $or[] = $e . '.cnic LIKE :fq5';
            $params['fq5'] = '%' . $digits . '%';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    foreach (['pool' => 'staff_pool_id', 'type' => 'staff_type_id', 'region' => 'region_id', 'district' => 'district_id', 'tehsil' => 'tehsil_id'] as $key => $column) {
        if ($f[$key] > 0) {
            $where[] = $e . '.' . $column . ' = :f_' . $key;
            $params['f_' . $key] = $f[$key];
        }
    }

    if ($f['uc'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM employee_assignments fa1 WHERE fa1.employee_id = ' . $e . '.id AND fa1.unassigned_at IS NULL AND fa1.uc_id = :f_uc)';
        $params['f_uc'] = $f['uc'];
    }
    if ($f['beat'] > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM employee_assignments fa2 WHERE fa2.employee_id = ' . $e . '.id AND fa2.unassigned_at IS NULL AND fa2.beat_id = :f_beat)';
        $params['f_beat'] = $f['beat'];
    }
    if ($f['status'] !== '') {
        $where[] = $e . '.status = :f_status';
        $params['f_status'] = $f['status'];
    }
    if ($f['assign'] === 'assigned') {
        $where[] = 'EXISTS (SELECT 1 FROM employee_assignments fa3 WHERE fa3.employee_id = ' . $e . '.id AND fa3.unassigned_at IS NULL)';
    } elseif ($f['assign'] === 'unassigned') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM employee_assignments fa4 WHERE fa4.employee_id = ' . $e . '.id AND fa4.unassigned_at IS NULL)';
    }

    $scope = hr_scope_sql($e);
    if ($scope !== '') {
        $where[] = $scope;
    }

    return [implode(' AND ', $where), $params];
}

/** The employee row used by lists, reports and exports. */
function hr_employee_rows_sql(string $whereSql, string $orderBy = 'e.name ASC', string $limitSql = ''): string
{
    return "SELECT e.id, e.employee_code, e.name, e.father_name, e.cnic, e.phone, e.status, e.dob, e.address,
                   e.cnic_issue_date, e.created_at,
                   sp.name AS pool_name, st.name AS type_name, st.assignment_mode,
                   r.name AS region_name, d.name AS district_name, t.name AS tehsil_name,
                   (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.name, IF(b.name IS NULL, '', CONCAT(' / ', b.name))) ORDER BY u.name SEPARATOR '; ')
                      FROM employee_assignments a
                      JOIN ucs u ON u.id = a.uc_id
                      LEFT JOIN beats b ON b.id = a.beat_id
                     WHERE a.employee_id = e.id AND a.unassigned_at IS NULL) AS assignment_text,
                   EXISTS (SELECT 1 FROM employee_assignments a2 WHERE a2.employee_id = e.id AND a2.unassigned_at IS NULL) AS is_assigned
              FROM employees e
              JOIN hr_staff_pools sp ON sp.id = e.staff_pool_id
              JOIN hr_staff_types st ON st.id = e.staff_type_id
              JOIN regions r ON r.id = e.region_id
              JOIN districts d ON d.id = e.district_id
              JOIN tehsils t ON t.id = e.tehsil_id
             WHERE {$whereSql}
             ORDER BY {$orderBy} {$limitSql}";
}

/**
 * Option lists for the filter form's cascading dropdowns. They are
 * rendered server-side from the current filter values, so each change
 * submits the form and the dependent lists re-render — no client-side
 * dataset to ship and no extra endpoint to protect.
 */
function hr_filter_options(array $f): array
{
    $opt = [
        'pools'     => Database::fetchAll('SELECT id, name FROM hr_staff_pools ORDER BY name ASC'),
        'types'     => Database::fetchAll(
            'SELECT id, name FROM hr_staff_types' . ($f['pool'] > 0 ? ' WHERE staff_pool_id = :p' : '') . ' ORDER BY name ASC',
            $f['pool'] > 0 ? ['p' => $f['pool']] : []
        ),
        'regions'   => Database::fetchAll('SELECT id, name FROM regions ORDER BY name ASC'),
        'districts' => [], 'tehsils' => [], 'ucs' => [], 'beats' => [],
        'ucs_truncated' => false,
    ];

    if ($f['region'] > 0) {
        $opt['districts'] = Database::fetchAll('SELECT id, name FROM districts WHERE region_id = :r ORDER BY name ASC', ['r' => $f['region']]);
    }
    if ($f['district'] > 0) {
        $opt['tehsils'] = Database::fetchAll('SELECT id, name FROM tehsils WHERE district_id = :d ORDER BY name ASC', ['d' => $f['district']]);
    }

    /* UC list: narrowest known parent wins; with no parent at all, list
       every UC (capped, so a country-wide install never ships thousands
       of options in one dropdown). */
    $ucSql = 'SELECT u.id, u.name, t.name AS tehsil_name FROM ucs u JOIN tehsils t ON t.id = u.tehsil_id';
    $ucParams = [];
    if ($f['tehsil'] > 0) {
        $ucSql .= ' WHERE u.tehsil_id = :t';
        $ucParams['t'] = $f['tehsil'];
    } elseif ($f['district'] > 0) {
        $ucSql .= ' WHERE t.district_id = :d';
        $ucParams['d'] = $f['district'];
    } elseif ($f['region'] > 0) {
        $ucSql .= ' JOIN districts dd ON dd.id = t.district_id WHERE dd.region_id = :r';
        $ucParams['r'] = $f['region'];
    }
    $opt['ucs'] = Database::fetchAll($ucSql . ' ORDER BY t.name ASC, u.name ASC LIMIT 501', $ucParams);
    if (count($opt['ucs']) > 500) {
        array_pop($opt['ucs']);
        $opt['ucs_truncated'] = true;
    }

    if ($f['uc'] > 0) {
        $opt['beats'] = Database::fetchAll('SELECT id, name FROM beats WHERE uc_id = :u ORDER BY name ASC', ['u' => $f['uc']]);
    }

    return $opt;
}
