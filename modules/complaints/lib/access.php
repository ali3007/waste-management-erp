<?php
/**
 * Complaints — server-side authorization.
 *
 * Two independent questions are asked for every request, and BOTH are
 * answered on the server from the signed-in user's real account — never
 * from a URL id, hidden field, cookie or anything JavaScript sent:
 *
 *   1. PERMISSION — does the user's role grant this action?
 *      (the existing Permission system, complaint.* slugs)
 *   2. SCOPE — may the user see THIS complaint?
 *      A user linked to a field employee (Supervisor / Monitoring Officer,
 *      i.e. an HR staff type that is assigned to UCs) can only see
 *      complaints whose UC is one of the UCs that employee is currently
 *      assigned to in the Phase 3 HR data. Everyone else (administrators,
 *      complaint officers, office staff) is limited only by permissions.
 *
 * Because scope is derived live from HR assignments, a reassigned or
 * removed Supervisor/Monitoring Officer loses access immediately, and a
 * newly assigned one gains it immediately.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * UC ids the signed-in user is limited to, or null when not limited by UC.
 * Super Administrators are never limited.
 *
 * @return int[]|null
 */
function cmp_scope_uc_ids(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    Auth::requireLogin();

    if (Auth::isSuperAdmin()) {
        return $cache = null;
    }
    $employee = Database::fetch(
        'SELECT e.id, st.assignment_mode FROM employees e JOIN hr_staff_types st ON st.id = e.staff_type_id WHERE e.user_id = :uid',
        ['uid' => Auth::id()]
    );
    /* Not a field employee (no HR record, or an office-type record that is
       never assigned to a UC): permissions alone decide. */
    if ($employee === null || !in_array((string)$employee['assignment_mode'], ['single', 'multi'], true)) {
        return $cache = null;
    }
    $rows = Database::fetchAll(
        'SELECT DISTINCT uc_id FROM employee_assignments WHERE employee_id = :id AND unassigned_at IS NULL',
        ['id' => (int)$employee['id']]
    );
    return $cache = array_map('intval', array_column($rows, 'uc_id'));
}

/** Is the signed-in user limited to specific UCs? */
function cmp_is_scoped(): bool
{
    return cmp_scope_uc_ids() !== null;
}

/**
 * SQL fragment limiting a complaints query to the user's UCs ('' when
 * unlimited). The ids are cast to int and imploded — never user input.
 */
function cmp_scope_sql(string $alias = 'c'): string
{
    $ids = cmp_scope_uc_ids();
    if ($ids === null) {
        return '';
    }
    return $ids ? $alias . '.uc_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '1 = 0';
}

/** May the signed-in user see this complaint (by UC)? */
function cmp_in_scope(array $complaint): bool
{
    $ids = cmp_scope_uc_ids();
    return $ids === null || in_array((int)$complaint['uc_id'], $ids, true);
}

/** One complaint with every name needed for display. Null when not found. */
function cmp_load(int $id, bool $includeDeleted = false): ?array
{
    if ($id <= 0) {
        return null;
    }
    $row = Database::fetch(
        'SELECT c.*, s.name AS source_name, ty.name AS type_name, p.name AS priority_name, p.color AS priority_color,
                r.name AS region_name, d.name AS district_name, t.name AS tehsil_name, u.name AS uc_name, b.name AS beat_name,
                cu.name AS created_by_name, du.name AS deleted_by_name
           FROM complaints c
           JOIN complaint_sources s ON s.id = c.source_id
           JOIN complaint_types ty ON ty.id = c.type_id
           JOIN complaint_priorities p ON p.id = c.priority_id
           JOIN regions r ON r.id = c.region_id
           JOIN districts d ON d.id = c.district_id
           JOIN tehsils t ON t.id = c.tehsil_id
           JOIN ucs u ON u.id = c.uc_id
           LEFT JOIN beats b ON b.id = c.beat_id
           LEFT JOIN users cu ON cu.id = c.created_by
           LEFT JOIN users du ON du.id = c.deleted_by
          WHERE c.id = :id',
        ['id' => $id]
    );
    if ($row === null || ($row['deleted_at'] !== null && !$includeDeleted)) {
        return null;
    }
    return $row;
}

/** Stop the request with a 403 (JSON for AJAX, the standard page otherwise). */
function cmp_deny(string $message = 'You do not have access to this complaint.'): never
{
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => $message], 403);
    }
    http_response_code(403);
    require INCLUDES_PATH . '/components/forbidden.php';
    exit;
}

function cmp_not_found(): never
{
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => 'Complaint not found.'], 404);
    }
    flash('error', 'That complaint could not be found.');
    redirect('modules/complaints/complaints.php');
}

/**
 * The one gate every complaint-specific page and endpoint goes through:
 * signed in → holds $permission → complaint exists → UC scope → deleted
 * complaints only for users who may restore.
 */
function cmp_require_access(int $id, string $permission = 'complaint.view'): array
{
    Permission::require($permission);

    $complaint = cmp_load($id, true);
    if ($complaint === null) {
        cmp_not_found();
    }
    if (!cmp_in_scope($complaint)) {
        log_event('complaints', 'Out-of-scope complaint access blocked', [
            'user_id' => Auth::id(), 'complaint_id' => $id, 'uc_id' => (int)$complaint['uc_id'], 'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        cmp_deny();
    }
    if ($complaint['deleted_at'] !== null && !can('complaint.restore')) {
        cmp_not_found();
    }
    return $complaint;
}

/** May the user change the evidence/attachments of this complaint right now? */
function cmp_evidence_open(array $c): bool
{
    if ($c['deleted_at'] !== null || $c['status'] === CMP_ST_REJECTED) {
        return false;
    }
    /* A resolved complaint is locked for field staff; only people who may
       resolve or reopen can still correct its evidence. */
    if ($c['status'] === CMP_ST_RESOLVED) {
        return can('complaint.resolve') || can('complaint.reopen');
    }
    return true;
}

/**
 * Every action the signed-in user may perform on this complaint right now.
 * Scope must already have been checked. The SAME function drives which
 * buttons the page shows and which requests the server accepts, so the
 * two can never disagree.
 *
 * @return string[]
 */
function cmp_actions(array $c): array
{
    if ($c['deleted_at'] !== null) {
        return can('complaint.restore') ? ['restore'] : [];
    }
    $s = (string)$c['status'];
    $a = [];

    if (can('complaint.update') && $s !== CMP_ST_REJECTED) {
        $a[] = 'edit';
    }
    if (can('complaint.workflow')) {
        if (in_array($s, [CMP_ST_NEW, CMP_ST_ASSIGNED, CMP_ST_REOPENED], true)) {
            $a[] = 'start';
        }
        if ($s === CMP_ST_PROGRESS) {
            $a[] = 'ready';
        }
    }
    if (can('complaint.resolve') && $s === CMP_ST_READY) {
        $a[] = 'resolve';
        $a[] = 'return';
    }
    if (can('complaint.reopen') && $s === CMP_ST_RESOLVED) {
        $a[] = 'reopen';
    }
    if (can('complaint.reject') && in_array($s, [CMP_ST_NEW, CMP_ST_ASSIGNED, CMP_ST_PROGRESS], true)) {
        $a[] = 'reject';
    }
    if (can('complaint.assign') && !in_array($s, [CMP_ST_RESOLVED, CMP_ST_REJECTED], true)) {
        $a[] = 'reassign';
    }
    if (can('complaint.remark')) {
        $a[] = 'remark';
    }
    if (can('complaint.delete')) {
        $a[] = 'delete';
    }
    if (cmp_evidence_open($c)) {
        if (can('complaint.evidence_before')) {
            $a[] = 'evidence_before';
        }
        if (can('complaint.evidence_after')) {
            $a[] = 'evidence_after';
        }
    }
    if (can('complaint.attach') && $s !== CMP_ST_REJECTED) {
        $a[] = 'attach';
    }
    return $a;
}

function cmp_can_do(array $c, string $action): bool
{
    return in_array($action, cmp_actions($c), true);
}

/** Abort unless the action is allowed on this complaint right now. */
function cmp_require_action(array $c, string $action): void
{
    if (cmp_can_do($c, $action)) {
        return;
    }
    log_event('complaints', 'Action refused', ['user_id' => Auth::id(), 'complaint_id' => (int)$c['id'], 'action' => $action, 'status' => $c['status']]);
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => 'That action is not available for this complaint.'], 403);
    }
    flash('error', 'That action is not available for this complaint (it may have changed status).');
    redirect('modules/complaints/complaint-view.php?id=' . (int)$c['id']);
}
