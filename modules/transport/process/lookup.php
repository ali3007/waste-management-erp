<?php
/**
 * AJAX: search-as-you-type for the vehicle and driver pickers.
 *   ?kind=vehicle&q=…   (needs any Transport view permission; area-scoped)
 *   ?kind=driver&q=…    (needs driver / fuel / assignment permission)
 * At most 15 rows; text only, never HTML.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
Auth::requireLogin();

$kind = query('kind');
$q = Security::cleanText(query('q'), 80);
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$items = [];

if ($kind === 'vehicle') {
    $any = false;
    foreach (['view', 'details', 'documents_manage', 'driver_assign', 'deployment_manage', 'maintenance_manage', 'fuel_manage', 'driver_view', 'deployment_view', 'maintenance_view', 'fuel_view', 'documents_view'] as $a) {
        $any = $any || can('transport.' . $a);
    }
    if (!$any) {
        json_response(['ok' => false, 'message' => 'You do not have access to this action.'], 403);
    }
    $scope = trn_scope_sql('v');
    $params = [];
    $where = ['v.deleted_at IS NULL'];
    if ($q !== '') {
        $where[] = '(v.registration_no LIKE :a OR v.registration_key LIKE :k OR v.tracker_no LIKE :b OR v.rfid_no LIKE :c OR v.chassis_no LIKE :d)';
        $params += ['a' => $like, 'b' => $like, 'c' => $like, 'd' => $like, 'k' => '%' . trn_norm_key($q) . '%'];
    }
    if ($scope !== '') {
        $where[] = $scope;
    }
    $rows = Database::fetchAll(
        'SELECT v.id, v.registration_no, vt.name AS type_name, vs.name AS status_name
           FROM vehicles v JOIN vehicle_types vt ON vt.id = v.vehicle_type_id JOIN vehicle_statuses vs ON vs.id = v.status_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY v.registration_no ASC LIMIT 15',
        $params
    );
    foreach ($rows as $r) {
        $items[] = ['id' => (int)$r['id'], 'label' => (string)$r['registration_no'], 'sub' => $r['type_name'] . ' · ' . $r['status_name']];
    }
} elseif ($kind === 'driver') {
    if (!can('transport.driver_view') && !can('transport.driver_assign') && !can('transport.fuel_manage') && !can('transport.view')) {
        json_response(['ok' => false, 'message' => 'You do not have access to this action.'], 403);
    }
    $params = [];
    $where = ["e.status = 'active'", trn_driver_eligibility_sql('e')];
    if ($q !== '') {
        $where[] = '(e.name LIKE :a OR e.employee_code LIKE :b OR e.phone LIKE :c' . (Security::normalizeCnic($q) !== '' ? ' OR e.cnic LIKE :d' : '') . ')';
        $params += ['a' => $like, 'b' => $like, 'c' => $like];
        if (Security::normalizeCnic($q) !== '') {
            $params['d'] = '%' . Security::normalizeCnic($q) . '%';
        }
    }
    foreach (Database::fetchAll('SELECT e.id, e.name, e.employee_code, e.cnic FROM employees e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.name ASC LIMIT 15', $params) as $r) {
        $items[] = ['id' => (int)$r['id'], 'label' => (string)$r['name'], 'sub' => trim((string)$r['employee_code'] . ' · ' . Security::formatCnic((string)$r['cnic']), ' ·')];
    }
} else {
    json_response(['ok' => false, 'message' => 'Unknown lookup.'], 400);
}

json_response(['ok' => true, 'items' => $items]);
