<?php
/**
 * Attendance — server-side authorization.
 *
 * Three independent questions, all answered from the signed-in user's real
 * account (never from a URL id, hidden field or anything JavaScript sent):
 *   1. PERMISSION — the existing User Access & Permissions system (attendance.*).
 *   2. SCOPE      — a user linked to a field employee (e.g. a Supervisor) is
 *                   limited to the UC(s) that employee is CURRENTLY assigned to.
 *   3. STATE      — target and actor employees must be active; a Supervisor may
 *                   never mark themselves; Office Staff may only mark themselves.
 *
 * The decision logic takes an explicit "context" array so it is a pure function
 * (and therefore unit-testable); att_ctx() builds the context for the request.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * @return array{user_id:int,is_super:bool,employee:?array,uc_ids:int[],can:callable}
 */
function att_ctx(): array
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }
    Auth::requireLogin();
    [$employee, $linkedBy] = att_resolve_employee(Auth::id());
    $ucIds = [];
    if ($employee !== null) {
        $employee['id'] = (int)$employee['id'];
        $ucIds = array_map('intval', array_column(Database::fetchAll(
            'SELECT DISTINCT uc_id FROM employee_assignments WHERE employee_id = :e AND unassigned_at IS NULL',
            ['e' => $employee['id']]
        ), 'uc_id'));
    }
    return $ctx = [
        'user_id' => Auth::id(), 'is_super' => Auth::isSuperAdmin(),
        'employee' => $employee, 'uc_ids' => $ucIds, 'linked_by' => $linkedBy,
        'can' => static fn(string $slug): bool => can($slug),
    ];
}

/**
 * Which employee is this signed-in user?
 *
 * 1. employees.user_id = the user (the explicit link — HR → Edit Employee → Login account).
 * 2. Otherwise, for a CNIC-login account, the employee with the SAME CNIC. The Users module
 *    creates the account with the CNIC but never sets employees.user_id, so without this a
 *    Supervisor whose account was created afterwards was not recognised as an employee at all.
 *    Both CNIC columns are unique 13-digit values, so the match is exact; an employee that is
 *    already linked to a different account is never taken over. Read-only: nothing is written.
 *
 * @return array{0:?array,1:?string} [employee row, 'user_id'|'cnic'|null]
 */
function att_resolve_employee(int $userId): array
{
    $cols = 'e.id, e.status, e.staff_pool_id, e.staff_type_id, st.assignment_mode';
    $join = 'FROM employees e JOIN hr_staff_types st ON st.id = e.staff_type_id';
    $e = Database::fetch("SELECT {$cols} {$join} WHERE e.user_id = :u", ['u' => $userId]);
    if ($e !== null) {
        return [$e, 'user_id'];
    }
    $cnic = Database::fetchValue("SELECT cnic FROM users WHERE id = :u AND login_type = 'cnic'", ['u' => $userId]);
    if ($cnic !== null && $cnic !== '') {
        $e = Database::fetch("SELECT {$cols} {$join} WHERE e.cnic = :c AND e.user_id IS NULL", ['c' => (string)$cnic]);
        if ($e !== null) {
            return [$e, 'cnic'];
        }
    }
    return [null, null];
}

/** May this actor mark / submit leave for OTHER people? Unlinked non-super-admin accounts may not: with no employee there is no UC scope to limit them. */
function att_has_team_scope(array $ctx): bool
{
    return $ctx['is_super'] || $ctx['employee'] !== null;
}

/**
 * UC ids the actor is limited to, or null when not limited by UC.
 * Limited = linked to an employee whose staff type is assigned to UCs
 * (Supervisor, Monitoring Officer…). A deactivated employee loses all scope.
 *
 * @return int[]|null
 */
function att_scope_uc_ids(array $ctx): ?array
{
    if ($ctx['is_super'] || $ctx['employee'] === null) {
        return null;
    }
    if (!in_array((string)$ctx['employee']['assignment_mode'], ['single', 'multi'], true)) {
        return null;               // office-type record: permissions alone decide
    }
    if ((string)$ctx['employee']['status'] !== 'active') {
        return [];
    }
    return $ctx['uc_ids'];
}

/** SQL fragment limiting a query to employees CURRENTLY assigned inside the actor's UCs ('' = unlimited). */
function att_scope_sql(array $ctx, string $employeeIdExpr): string
{
    $ids = att_scope_uc_ids($ctx);
    if ($ids === null) {
        return '';
    }
    if (!$ids) {
        return '1 = 0';
    }
    return 'EXISTS (SELECT 1 FROM employee_assignments sa WHERE sa.employee_id = ' . $employeeIdExpr
        . ' AND sa.unassigned_at IS NULL AND sa.uc_id IN (' . att_in($ids) . '))';
}

function att_employee_in_scope(array $ctx, int $employeeId): bool
{
    $sql = att_scope_sql($ctx, 'e.id');
    return $sql === '' || Database::exists('SELECT e.id FROM employees e WHERE e.id = :id AND ' . $sql, ['id' => $employeeId]);
}

/** Is the actor the person this employee record belongs to? */
function att_is_self(array $ctx, int $employeeId): bool
{
    return $ctx['employee'] !== null && (int)$ctx['employee']['id'] === $employeeId;
}

/**
 * One employee with pool/type/mode and current UC(s) — or null.
 *
 * An employee can hold SEVERAL open assignments (HR "multi" types), so `uc_ids` lists them all and
 * every authorisation decision uses that list. `uc_id` is the single UC the record/windows are
 * attributed to: the first of $preferUcs (the acting Supervisor's UCs) the employee belongs to,
 * otherwise the lowest open UC. Using only the lowest UC made a Supervisor see an employee on his
 * roster and then be refused when marking them.
 *
 * @param int[]|null $preferUcs
 */
function att_load_employee(int $id, ?array $preferUcs = null): ?array
{
    if ($id <= 0) {
        return null;
    }
    $e = Database::fetch(
        "SELECT e.id, e.employee_code, e.name, e.status, e.user_id, e.staff_pool_id, e.staff_type_id,
                e.region_id, e.district_id, e.tehsil_id,
                sp.name AS pool_name, st.name AS type_name
           FROM employees e
           JOIN hr_staff_pools sp ON sp.id = e.staff_pool_id
           JOIN hr_staff_types st ON st.id = e.staff_type_id
          WHERE e.id = :id",
        ['id' => $id]
    );
    if ($e === null) {
        return null;
    }
    foreach (['id', 'staff_pool_id', 'staff_type_id'] as $k) {
        $e[$k] = (int)$e[$k];
    }
    $ucs = array_map('intval', array_column(Database::fetchAll(
        'SELECT DISTINCT uc_id FROM employee_assignments WHERE employee_id = :e AND unassigned_at IS NULL ORDER BY uc_id',
        ['e' => $id]
    ), 'uc_id'));
    $e['uc_ids'] = $ucs;
    $e['uc_id'] = $ucs ? $ucs[0] : null;
    if ($preferUcs) {
        foreach ($ucs as $u) {
            if (in_array($u, $preferUcs, true)) {
                $e['uc_id'] = $u;
                break;
            }
        }
    }
    $e['mode'] = att_employee_mode($e['staff_pool_id'], $e['staff_type_id']);
    return $e;
}

/**
 * May this actor mark attendance for this employee? null = yes, otherwise the
 * message to show. Pure: depends only on its arguments (plus category config).
 */
function att_mark_authority(array $ctx, array $employee): ?string
{
    if ($employee['status'] !== 'active') {
        return 'This employee is inactive, so attendance cannot be marked.';
    }
    $can = $ctx['can'];
    $mode = $employee['mode'] ?? null;
    if ($mode === null) {
        return 'This employee is not part of an attendance category.';
    }
    if ($mode === 'self') {
        if (!att_is_self($ctx, (int)$employee['id'])) {
            return 'You can only mark your own attendance.';
        }
        if (!$can('attendance.mark_self')) {
            return 'You do not have permission to mark your own attendance.';
        }
        if ((string)$ctx['employee']['status'] !== 'active') {
            return 'Your employee record is inactive.';
        }
        return null;
    }
    /* supervised */
    if (!$can('attendance.mark_team')) {
        return 'You do not have permission to mark attendance for other staff.';
    }
    if (att_is_self($ctx, (int)$employee['id'])) {
        return 'You cannot mark your own attendance from the Supervisor screen.';
    }
    if (!att_has_team_scope($ctx)) {
        return 'Your login is not linked to an employee record, so your UC cannot be determined. Ask HR to link your account (Edit Employee → Login account).';
    }
    if ($ctx['employee'] !== null && (string)$ctx['employee']['status'] !== 'active') {
        return 'Your employee record is inactive.';
    }
    $scope = att_scope_uc_ids($ctx);
    if ($scope !== null && !array_intersect($employee['uc_ids'] ?? [], $scope)) {
        return 'This employee is not assigned to your UC.';
    }
    return null;
}

/**
 * Active, currently-assigned, supervised employees the actor may mark,
 * for one UC. Generated from the live HR assignments — never a stored list.
 */
function att_supervised_roster(int $ucId, ?int $poolId = null): array
{
    $cfg = att_config_cache();
    $params = ['uc' => $ucId];
    $poolSql = '';
    if ($poolId !== null) {
        $poolSql = ' AND e.staff_pool_id = :pool';
        $params['pool'] = $poolId;
    }
    return Database::fetchAll(
        "SELECT DISTINCT e.id, e.employee_code, e.name, e.staff_pool_id, e.staff_type_id, sp.name AS pool_name, st.name AS type_name
           FROM employee_assignments a
           JOIN employees e ON e.id = a.employee_id AND e.status = 'active'
           JOIN hr_staff_pools sp ON sp.id = e.staff_pool_id
           JOIN hr_staff_types st ON st.id = e.staff_type_id
          WHERE a.uc_id = :uc AND a.unassigned_at IS NULL
            AND e.staff_pool_id IN (" . att_in($cfg['supervised_pools']) . ')
            AND e.staff_type_id NOT IN (' . att_in($cfg['supervisor_types']) . ')
            AND e.staff_type_id NOT IN (' . att_in($cfg['self_types']) . ")
            {$poolSql}
          ORDER BY sp.name, e.name",
        $params
    );
}

/** UCs the actor may work with on the Supervisor screen (null scope = every active UC). */
function att_actor_ucs(array $ctx): array
{
    if (!att_has_team_scope($ctx)) {
        return [];
    }
    $scope = att_scope_uc_ids($ctx);
    $sql = "SELECT u.id, u.name, u.code FROM ucs u JOIN tehsils t ON t.id = u.tehsil_id WHERE u.status = 'active'";
    if ($scope !== null) {
        $sql .= ' AND u.id IN (' . att_in($scope) . ')';
    }
    return Database::fetchAll($sql . ' ORDER BY u.name');
}

/** Employee id of the active Supervisor currently assigned to a UC (first if several), or null. */
function att_supervisor_of_uc(?int $ucId): ?int
{
    if ($ucId === null) {
        return null;
    }
    $v = Database::fetchValue(
        "SELECT MIN(e.id) FROM employee_assignments a JOIN employees e ON e.id = a.employee_id AND e.status = 'active'
          WHERE a.uc_id = :uc AND a.unassigned_at IS NULL AND e.staff_type_id IN (" . att_in(att_supervisor_type_ids()) . ')',
        ['uc' => $ucId]
    );
    return $v !== null ? (int)$v : null;
}

/** Stop with 403 (JSON for AJAX) — used when an id in the request is out of scope. */
function att_deny(string $message = 'You do not have access to this record.'): never
{
    log_event('attendance', 'Out-of-scope access blocked', ['user_id' => Auth::id(), 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => $message], 403);
    }
    http_response_code(403);
    require INCLUDES_PATH . '/components/forbidden.php';
    exit;
}

/** A UC filter value, kept only if the actor may see that UC (0 = no filter). */
function att_scoped_uc(int $ucId, array $ctx): int
{
    if ($ucId <= 0) {
        return 0;
    }
    $scope = att_scope_uc_ids($ctx);
    return ($scope === null || in_array($ucId, $scope, true)) ? $ucId : 0;
}
