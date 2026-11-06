<?php
/**
 * Attendance reports — Daily / Weekly / Monthly / custom range.
 *
 * One query builder feeds the on-screen report AND both exports, so a
 * downloaded file always equals what the same user sees. Visibility is applied
 * in SQL from the signed-in user's own scope (att_scope_sql) — a UC, employee id
 * or any other value in the request can only NARROW the result, never widen it.
 * Each row carries the category / UC / Supervisor that applied on that date.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const ATT_REPORT_MAX_DAYS = 366;

/**
 * @return array{period:string,date:string,from:string,to:string,q:string,pool:int,region:int,district:int,tehsil:int,uc:int,supervisor:int,status:string,error:?string}
 */
function att_read_report_filters(array $src): array
{
    $get = static fn(string $k): string => isset($src[$k]) && is_string($src[$k]) ? trim($src[$k]) : '';
    $period = in_array($get('period'), ['daily', 'weekly', 'monthly', 'range'], true) ? $get('period') : 'daily';
    $date = att_valid_date($get('date')) ?? att_today();
    $d = new DateTimeImmutable($date, att_tz());
    $error = null;

    switch ($period) {
        case 'weekly':      // Monday – Sunday containing the date
            $from = $d->modify('monday this week')->format('Y-m-d');
            $to = $d->modify('monday this week')->modify('+6 days')->format('Y-m-d');
            break;
        case 'monthly':
            $from = $d->format('Y-m-01');
            $to = $d->format('Y-m-t');
            break;
        case 'range':
            $from = att_valid_date($get('from')) ?? $date;
            $to = att_valid_date($get('to')) ?? $from;
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }
            if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= ATT_REPORT_MAX_DAYS) {
                $to = (new DateTimeImmutable($from))->modify('+' . (ATT_REPORT_MAX_DAYS - 1) . ' days')->format('Y-m-d');
                $error = 'The range was limited to ' . ATT_REPORT_MAX_DAYS . ' days.';
            }
            break;
        default:
            $from = $to = $date;
    }
    $status = $get('status');
    return [
        'period' => $period, 'date' => $date, 'from' => $from, 'to' => $to, 'q' => mb_substr($get('q'), 0, 80),
        'pool' => (int)$get('pool'), 'region' => (int)$get('region'), 'district' => (int)$get('district'),
        'tehsil' => (int)$get('tehsil'), 'uc' => (int)$get('uc'), 'supervisor' => (int)$get('supervisor'),
        'status' => isset(att_status_labels()[$status]) ? $status : '', 'error' => $error,
    ];
}

/** @return array{from:string,where:string,params:array} */
function att_report_sql(array $ctx, array $f): array
{
    $where = ['a.attendance_date BETWEEN :from AND :to'];
    $params = ['from' => $f['from'], 'to' => $f['to']];
    if (($s = att_scope_sql($ctx, 'a.employee_id')) !== '') {
        $where[] = $s;
    }
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $where[] = '(e.name LIKE :q1 OR e.employee_code LIKE :q2 OR e.cnic LIKE :q3)';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
    }
    if ($f['pool'] > 0) {
        $where[] = 'a.staff_pool_id = :pool';
        $params['pool'] = $f['pool'];
    }
    if ($f['uc'] > 0) {
        $where[] = 'a.uc_id = :uc';
        $params['uc'] = $f['uc'];
    }
    if ($f['tehsil'] > 0) {
        $where[] = 'COALESCE(ut.id, e.tehsil_id) = :tehsil';
        $params['tehsil'] = $f['tehsil'];
    }
    if ($f['district'] > 0) {
        $where[] = 'COALESCE(ud.id, e.district_id) = :district';
        $params['district'] = $f['district'];
    }
    if ($f['region'] > 0) {
        $where[] = 'COALESCE(ud.region_id, e.region_id) = :region';
        $params['region'] = $f['region'];
    }
    if ($f['supervisor'] > 0) {
        $where[] = 'a.supervisor_employee_id = :sup';
        $params['sup'] = $f['supervisor'];
    }
    if ($f['status'] !== '') {
        $where[] = 'a.status = :status';
        $params['status'] = $f['status'];
    }
    $from = ' FROM attendance_records a
              JOIN employees e ON e.id = a.employee_id
              JOIN hr_staff_pools sp ON sp.id = a.staff_pool_id
              JOIN hr_staff_types st ON st.id = a.staff_type_id
              LEFT JOIN ucs u ON u.id = a.uc_id
              LEFT JOIN tehsils ut ON ut.id = u.tehsil_id
              LEFT JOIN districts ud ON ud.id = ut.district_id
              LEFT JOIN employees se ON se.id = a.supervisor_employee_id
              LEFT JOIN attendance_leave_requests lv ON lv.id = a.leave_request_id
              LEFT JOIN attendance_holidays h ON h.id = a.holiday_id';
    return ['from' => $from, 'where' => implode(' AND ', $where), 'params' => $params];
}

function att_report_count(array $ctx, array $f): int
{
    $q = att_report_sql($ctx, $f);
    return (int)Database::fetchValue('SELECT COUNT(*)' . $q['from'] . ' WHERE ' . $q['where'], $q['params']);
}

/** @return array<int,array> one page of rows (limit 0 = all, for exports) */
function att_report_rows(array $ctx, array $f, int $limit = 50, int $offset = 0): array
{
    $q = att_report_sql($ctx, $f);
    return Database::fetchAll(
        'SELECT a.id, a.attendance_date, a.status, a.resolution_source, a.review_state, a.review_reason, a.checkin_at, a.checkout_at,
                e.id AS employee_id, e.name AS employee_name, e.employee_code, e.status AS employee_status,
                sp.name AS pool_name, st.name AS type_name, u.name AS uc_name, u.code AS uc_code, se.name AS supervisor_name,
                lv.reason AS leave_reason, h.title AS holiday_title'
        . $q['from'] . ' WHERE ' . $q['where'] . ' ORDER BY a.attendance_date ASC, e.name ASC, e.id ASC'
        . ($limit > 0 ? ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset : ''),
        $q['params']
    );
}

/** Status counts for the filtered set. */
function att_report_summary(array $ctx, array $f): array
{
    $q = att_report_sql($ctx, $f);
    $rows = Database::fetchAll('SELECT a.status, COUNT(*) AS n' . $q['from'] . ' WHERE ' . $q['where'] . ' GROUP BY a.status', $q['params']);
    $out = array_fill_keys(array_keys(att_status_labels()), 0);
    foreach ($rows as $r) {
        $out[$r['status']] = (int)$r['n'];
    }
    return $out;
}

/** Human summary of the active filters, used in exports and under the report title. */
function att_report_filter_text(array $f): string
{
    $parts = [];
    $name = static fn(string $sql, int $id): string => (string)(Database::fetchValue($sql, ['id' => $id]) ?? ('#' . $id));
    if ($f['q'] !== '') {
        $parts[] = 'Employee: ' . $f['q'];
    }
    if ($f['pool'] > 0) {
        $parts[] = 'Category: ' . $name('SELECT name FROM hr_staff_pools WHERE id = :id', $f['pool']);
    }
    if ($f['region'] > 0) {
        $parts[] = 'Region: ' . $name('SELECT name FROM regions WHERE id = :id', $f['region']);
    }
    if ($f['district'] > 0) {
        $parts[] = 'District: ' . $name('SELECT name FROM districts WHERE id = :id', $f['district']);
    }
    if ($f['tehsil'] > 0) {
        $parts[] = 'Tehsil: ' . $name('SELECT name FROM tehsils WHERE id = :id', $f['tehsil']);
    }
    if ($f['uc'] > 0) {
        $parts[] = 'UC: ' . $name('SELECT name FROM ucs WHERE id = :id', $f['uc']);
    }
    if ($f['supervisor'] > 0) {
        $parts[] = 'Supervisor: ' . $name('SELECT name FROM employees WHERE id = :id', $f['supervisor']);
    }
    if ($f['status'] !== '') {
        $parts[] = 'Status: ' . att_status_label($f['status']);
    }
    return $parts ? implode(' | ', $parts) : 'No additional filters';
}

function att_report_title(array $f): string
{
    return match ($f['period']) {
        'weekly'  => 'Weekly Attendance Report',
        'monthly' => 'Monthly Attendance Report',
        'range'   => 'Attendance Report',
        default   => 'Daily Attendance Report',
    };
}

/** Filter dropdown data, restricted to what the actor may see. */
function att_filter_options(array $ctx, array $f): array
{
    $scope = att_scope_uc_ids($ctx);
    $o = ['pools' => Database::fetchAll("SELECT id, name FROM hr_staff_pools WHERE id IN (" . att_in(array_merge(att_self_pool_ids(), att_supervised_pools_for_filter())) . ') ORDER BY name')];
    $ucSql = "SELECT u.id, u.name, u.tehsil_id FROM ucs u WHERE 1=1" . ($scope !== null ? ' AND u.id IN (' . att_in($scope) . ')' : '');
    $o['regions'] = Database::fetchAll(
        'SELECT DISTINCT r.id, r.name FROM regions r JOIN districts d ON d.region_id = r.id JOIN tehsils t ON t.district_id = d.id JOIN ucs u ON u.tehsil_id = t.id'
        . ($scope !== null ? ' WHERE u.id IN (' . att_in($scope) . ')' : '') . ' ORDER BY r.name'
    );
    $o['districts'] = $f['region'] > 0 ? Database::fetchAll(
        'SELECT DISTINCT d.id, d.name FROM districts d JOIN tehsils t ON t.district_id = d.id JOIN ucs u ON u.tehsil_id = t.id WHERE d.region_id = :r'
        . ($scope !== null ? ' AND u.id IN (' . att_in($scope) . ')' : '') . ' ORDER BY d.name', ['r' => $f['region']]) : [];
    $o['tehsils'] = $f['district'] > 0 ? Database::fetchAll(
        'SELECT DISTINCT t.id, t.name FROM tehsils t JOIN ucs u ON u.tehsil_id = t.id WHERE t.district_id = :d'
        . ($scope !== null ? ' AND u.id IN (' . att_in($scope) . ')' : '') . ' ORDER BY t.name', ['d' => $f['district']]) : [];
    $o['ucs'] = $f['tehsil'] > 0 ? Database::fetchAll($ucSql . ' AND u.tehsil_id = :t ORDER BY u.name', ['t' => $f['tehsil']])
        : ($scope !== null ? Database::fetchAll($ucSql . ' ORDER BY u.name') : []);
    $supSql = "SELECT DISTINCT e.id, e.name FROM employees e";
    if ($scope !== null) {
        $supSql .= ' JOIN employee_assignments a ON a.employee_id = e.id AND a.unassigned_at IS NULL AND a.uc_id IN (' . att_in($scope) . ')';
    }
    $o['supervisors'] = Database::fetchAll($supSql . ' WHERE e.staff_type_id IN (' . att_in(att_supervisor_type_ids()) . ') ORDER BY e.name');
    return $o;
}

/** Pools that may appear in a filter: every pool the module tracks. */
function att_supervised_pools_for_filter(): array
{
    return att_supervised_pool_ids();
}
