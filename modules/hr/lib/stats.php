<?php
/**
 * Live HR numbers for the dashboard (spec sections 19 and 20).
 * Everything is computed from the database on each request — nothing is
 * cached or hard-coded — and every query honours the signed-in user's
 * area scope, so a field-linked user's dashboard counts only their own UCs.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Limit an aggregate over employee_assignments (alias $a) to the user's UCs. */
function hr_assignment_scope_sql(string $a = 'a'): string
{
    $ids = hr_accessible_uc_ids();
    if ($ids === null) {
        return '';
    }
    return empty($ids) ? '1 = 0' : $a . '.uc_id IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/** @return array<string,mixed> */
function hr_dashboard_stats(): array
{
    $scope = hr_scope_sql('e');
    $scopeAnd = $scope !== '' ? ' AND ' . $scope : '';
    $scopeWhere = $scope !== '' ? ' WHERE ' . $scope : '';

    $totals = Database::fetch(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(e.status = 'active'), 0) AS active,
                COALESCE(SUM(e.status = 'inactive'), 0) AS inactive,
                COALESCE(SUM(EXISTS (SELECT 1 FROM employee_assignments x WHERE x.employee_id = e.id AND x.unassigned_at IS NULL)), 0) AS assigned
           FROM employees e{$scopeWhere}"
    ) ?: ['total' => 0, 'active' => 0, 'inactive' => 0, 'assigned' => 0];

    $noAssignmentNeeded = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM employees e JOIN hr_staff_types st ON st.id = e.staff_type_id
          WHERE st.assignment_mode = 'none'
            AND NOT EXISTS (SELECT 1 FROM employee_assignments x WHERE x.employee_id = e.id AND x.unassigned_at IS NULL){$scopeAnd}"
    );

    $pools = Database::fetchAll(
        "SELECT sp.id, sp.name, sp.status, COUNT(e.id) AS cnt
           FROM hr_staff_pools sp
           LEFT JOIN employees e ON e.staff_pool_id = sp.id" . ($scope !== '' ? " AND {$scope}" : '') . "
          GROUP BY sp.id, sp.name, sp.status
          ORDER BY sp.name ASC"
    );

    $typeRows = Database::fetchAll(
        "SELECT st.id, st.name, st.status, st.staff_pool_id, COUNT(e.id) AS cnt
           FROM hr_staff_types st
           LEFT JOIN employees e ON e.staff_type_id = st.id" . ($scope !== '' ? " AND {$scope}" : '') . "
          GROUP BY st.id, st.name, st.status, st.staff_pool_id
          ORDER BY st.name ASC"
    );
    $typesByPool = [];
    foreach ($typeRows as $row) {
        /* An inactive type with nobody in it is noise; keep it only if it
           still has employees, so the counts always add up. */
        if ($row['status'] === 'inactive' && (int)$row['cnt'] === 0) {
            continue;
        }
        $typesByPool[(int)$row['staff_pool_id']][] = $row;
    }

    $aScope = hr_assignment_scope_sql('a');
    $aScopeAnd = $aScope !== '' ? ' AND ' . $aScope : '';

    /* Active employees only: an inactive person with an old open
       assignment shouldn't make a UC look staffed. */
    $ucs = Database::fetchAll(
        "SELECT u.id, u.name, t.name AS tehsil_name, COUNT(DISTINCT a.employee_id) AS cnt
           FROM employee_assignments a
           JOIN employees e ON e.id = a.employee_id AND e.status = 'active'
           JOIN ucs u ON u.id = a.uc_id
           JOIN tehsils t ON t.id = u.tehsil_id
          WHERE a.unassigned_at IS NULL{$aScopeAnd}
          GROUP BY u.id, u.name, t.name
          ORDER BY cnt DESC, u.name ASC"
    );
    $beats = Database::fetchAll(
        "SELECT b.id, b.name, u.name AS uc_name, COUNT(DISTINCT a.employee_id) AS cnt
           FROM employee_assignments a
           JOIN employees e ON e.id = a.employee_id AND e.status = 'active'
           JOIN beats b ON b.id = a.beat_id
           JOIN ucs u ON u.id = a.uc_id
          WHERE a.unassigned_at IS NULL{$aScopeAnd}
          GROUP BY b.id, b.name, u.name
          ORDER BY cnt DESC, b.name ASC"
    );

    return [
        'total' => (int)$totals['total'],
        'active' => (int)$totals['active'],
        'inactive' => (int)$totals['inactive'],
        'assigned' => (int)$totals['assigned'],
        'unassigned' => (int)$totals['total'] - (int)$totals['assigned'],
        'no_assignment_needed' => $noAssignmentNeeded,
        'pools' => $pools,
        'types_by_pool' => $typesByPool,
        'ucs' => $ucs,
        'beats' => $beats,
    ];
}
