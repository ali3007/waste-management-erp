<?php
/**
 * Optional links from a task to existing ERP records. A link NEVER grants access: the label and
 * address are shown only to a viewer who holds that module's own view permission (and, for
 * complaints, whose UC scope covers the record). Deleted/hidden records degrade to a neutral text
 * and the task and its history are untouched.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

function comms_link_types(): array
{
    return [
        'complaint' => ['label' => 'Complaint', 'perm' => 'complaint.view'],
        'employee'  => ['label' => 'Employee', 'perm' => 'employee.view'],
        'vehicle'   => ['label' => 'Vehicle', 'perm' => 'transport.view'],
        'uc'        => ['label' => 'Union Council', 'perm' => 'structure.view'],
    ];
}

/** Does the SESSION user's UC scope (field staff) include this complaint? Only meaningful for the signed-in user. */
function comms_complaint_in_scope(int $me, array $complaint): bool
{
    if (PHP_SAPI === 'cli' || !Auth::check() || (int)Auth::id() !== $me) {
        return true;   // permission already checked; scope is session-based
    }
    if (!function_exists('cmp_in_scope')) {
        require_once MODULES_PATH . '/complaints/lib/access.php';
    }
    return cmp_in_scope($complaint);
}

/** @return array{label:string,href:string}|null null when missing/deleted */
function comms_link_record(string $type, int $id): ?array
{
    try {
        switch ($type) {
            case 'complaint':
                $r = Database::fetch('SELECT id, complaint_number, uc_id, deleted_at FROM complaints WHERE id = :i', ['i' => $id]);
                return $r && $r['deleted_at'] === null ? ['label' => (string)$r['complaint_number'], 'href' => 'modules/complaints/complaint-view.php?id=' . $id, '_row' => $r] : null;
            case 'employee':
                $r = Database::fetch('SELECT id, employee_code, name FROM employees WHERE id = :i', ['i' => $id]);
                return $r ? ['label' => trim($r['employee_code'] . ' ' . $r['name']), 'href' => 'modules/hr/employee-view.php?id=' . $id] : null;
            case 'vehicle':
                $r = Database::fetch('SELECT id, registration_no, deleted_at FROM vehicles WHERE id = :i', ['i' => $id]);
                return $r && $r['deleted_at'] === null ? ['label' => (string)$r['registration_no'], 'href' => 'modules/transport/vehicle-view.php?id=' . $id] : null;
            case 'uc':
                $r = Database::fetch('SELECT id, name, code FROM ucs WHERE id = :i', ['i' => $id]);
                return $r ? ['label' => trim($r['name'] . ($r['code'] ? ' (' . $r['code'] . ')' : '')), 'href' => 'modules/structure/index.php'] : null;
        }
    } catch (Throwable $e) {
        return null;   // module not installed yet
    }
    return null;
}

function comms_link_can_view(int $me, string $type, int $id): bool
{
    $t = comms_link_types()[$type] ?? null;
    if ($t === null || !comms_actor_can_slug($me, $t['perm'])) {
        return false;
    }
    $rec = comms_link_record($type, $id);
    if ($rec === null) {
        return false;
    }
    return $type !== 'complaint' || comms_complaint_in_scope($me, $rec['_row']);
}

/** Live check of an arbitrary permission slug for a user (Super Admin passes; active accounts only). */
function comms_actor_can_slug(int $userId, string $slug): bool
{
    return (bool)Database::exists(
        "SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.id = :u AND u.status = 'active'
            AND (r.slug = :super OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = r.id AND (p.slug = :s OR p.slug = :w)))",
        ['u' => $userId, 'super' => Permission::ROLE_SUPER_ADMIN, 's' => $slug, 'w' => explode('.', $slug)[0] . '.*']
    );
}

/** What the VIEWER may see of a task's link. */
function comms_link_resolve(int $viewer, ?string $type, ?int $id): ?array
{
    if ($type === null || $id === null) {
        return null;
    }
    $t = comms_link_types()[$type] ?? null;
    if ($t === null) {
        return ['label' => 'Linked record', 'href' => null, 'available' => false, 'note' => 'Unsupported record type'];
    }
    if (!comms_actor_can_slug($viewer, $t['perm'])) {
        return ['label' => $t['label'], 'href' => null, 'available' => false, 'note' => 'You do not have access to this record'];
    }
    $rec = comms_link_record($type, $id);
    if ($rec === null) {
        return ['label' => $t['label'], 'href' => null, 'available' => false, 'note' => 'This record is no longer available'];
    }
    if ($type === 'complaint' && !comms_complaint_in_scope($viewer, $rec['_row'])) {
        return ['label' => $t['label'], 'href' => null, 'available' => false, 'note' => 'You do not have access to this record'];
    }
    return ['label' => $t['label'] . ': ' . $rec['label'], 'href' => $rec['href'], 'available' => true, 'note' => ''];
}

/** Search records the actor may link. */
function comms_link_search(int $me, string $type, string $q): array
{
    $t = comms_link_types()[$type] ?? null;
    $q = trim($q);
    if ($t === null || mb_strlen($q) < 2 || !comms_actor_can_slug($me, $t['perm'])) {
        return [];
    }
    $like = '%' . addcslashes($q, '%_\\') . '%';
    try {
        $rows = match ($type) {
            'complaint' => Database::fetchAll('SELECT id, complaint_number AS label, uc_id, 1 AS _c FROM complaints WHERE deleted_at IS NULL AND (complaint_number LIKE :a OR complainant_name LIKE :b) ORDER BY id DESC LIMIT 15', ['a' => $like, 'b' => $like]),
            'employee'  => Database::fetchAll("SELECT id, CONCAT(employee_code, ' ', name) AS label FROM employees WHERE name LIKE :a OR employee_code LIKE :b ORDER BY name LIMIT 15", ['a' => $like, 'b' => $like]),
            'vehicle'   => Database::fetchAll('SELECT id, registration_no AS label FROM vehicles WHERE deleted_at IS NULL AND registration_no LIKE :a ORDER BY registration_no LIMIT 15', ['a' => $like]),
            'uc'        => Database::fetchAll("SELECT id, CONCAT(name, IF(code IS NULL OR code = '', '', CONCAT(' (', code, ')'))) AS label FROM ucs WHERE name LIKE :a OR code LIKE :b ORDER BY name LIMIT 15", ['a' => $like, 'b' => $like]),
        };
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if ($type === 'complaint' && !comms_complaint_in_scope($me, ['id' => $r['id'], 'uc_id' => $r['uc_id']])) {
            continue;
        }
        $out[] = ['id' => (int)$r['id'], 'label' => (string)$r['label']];
    }
    return $out;
}
