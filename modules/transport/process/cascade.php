<?php
/**
 * AJAX: active children of a geography level for the Region → District →
 * Tehsil → UC → Beat selects. Reads the existing Phase 2 tables; a restricted
 * user only receives UCs inside their own area.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
Auth::requireLogin();

$allowed = false;
foreach (['view', 'create', 'update', 'deployment_view', 'deployment_manage', 'dashboard', 'reports', 'history'] as $a) {
    $allowed = $allowed || can('transport.' . $a);
}
if (!$allowed) {
    json_response(['ok' => false, 'message' => 'You do not have access to this data.'], 403);
}

$level = query('level');
$parent = (int)query('parent');
if ($parent <= 0) {
    json_response(['ok' => true, 'items' => []]);
}

$map = [
    'district' => ['districts', 'region_id'],
    'tehsil'   => ['tehsils', 'district_id'],
    'uc'       => ['ucs', 'tehsil_id'],
    'beat'     => ['beats', 'uc_id'],
];
if (!isset($map[$level])) {
    json_response(['ok' => false, 'message' => 'Unknown level.'], 400);
}
[$table, $col] = $map[$level];
$sql = "SELECT id, name FROM `{$table}` WHERE `{$col}` = :p AND status = 'active'";
if ($level === 'uc') {
    $ids = hr_accessible_uc_ids();
    if ($ids !== null) {
        $sql .= $ids ? ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')' : ' AND 1 = 0';
    }
}
json_response(['ok' => true, 'items' => Database::fetchAll($sql . ' ORDER BY name ASC', ['p' => $parent])]);
