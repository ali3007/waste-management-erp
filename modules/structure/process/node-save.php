<?php
/**
 * Creates or updates a record at any Structure level (Region, District,
 * Tehsil or UC — Beat/Site join this same handler in a later stage).
 * The level comes from the form, never trusted blindly: it's validated
 * against STRUCTURE_LEVELS before anything else happens.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

if (!is_post()) {
    redirect('modules/structure/index.php');
}

$level = post('level');
$config = structure_level_config($level);
if ($config === null) {
    flash('error', 'Unknown record type.');
    redirect('modules/structure/index.php');
}

$id = (int)post('id');
$isEdit = $id > 0;
$parentId = $config['parent_column'] !== null ? (int)post('parent_id') : null;

Permission::require($isEdit ? 'structure.update' : 'structure.create');
Security::requireCsrf(structure_back_url($level, $parentId, $isEdit ? ['edit' => (string)$id] : ['add' => '1']));

$name = Security::cleanText(post('name'), 120);
$code = mb_strtoupper(Security::cleanText(post('code'), 30));
$description = Security::cleanMultiline(post('description'), 255);
$ucNumber = !empty($config['has_uc_number']) ? Security::cleanText(post('uc_number'), 20) : null;
$hasSiteType = !empty($config['has_site_type']);
$hasCoordinates = !empty($config['has_coordinates']);
$hasAddress = !empty($config['has_address']);
$codeColumn = $config['code_column'] ?? 'code';
$hasDescription = $config['has_description'] ?? true;

$backTo = structure_back_url($level, $parentId, $isEdit ? ['edit' => (string)$id] : ['add' => '1']);
keep_old([
    'name' => $name, 'code' => $code, 'description' => $description, 'uc_number' => $ucNumber ?? '',
    'site_type_id' => post('site_type_id'), 'latitude' => post('latitude'), 'longitude' => post('longitude'),
    'address' => post('address'),
]);

if ($name === '') {
    flash('error', 'Enter a name.');
    redirect($backTo);
}
if ($code === '') {
    flash('error', 'Enter a ' . $config['code_label'] . '.');
    redirect($backTo);
}

$siteTypeId = null;
if ($hasSiteType) {
    $siteTypeId = (int)post('site_type_id');
    if (!Database::exists('SELECT id FROM site_types WHERE id = :id', ['id' => $siteTypeId])) {
        flash('error', 'Choose a Site Type.');
        redirect($backTo);
    }
}

/* Coordinates are optional, but a value that's present must be a real
   number within a valid latitude/longitude range — never trusted as
   plain text, and never silently clamped or guessed. */
$latitude = null;
$longitude = null;
if ($hasCoordinates) {
    $rawLat = trim(post('latitude'));
    $rawLng = trim(post('longitude'));

    if ($rawLat !== '') {
        if (!is_numeric($rawLat) || (float)$rawLat < -90 || (float)$rawLat > 90) {
            flash('error', 'Latitude must be a number between -90 and 90.');
            redirect($backTo);
        }
        $latitude = (float)$rawLat;
    }
    if ($rawLng !== '') {
        if (!is_numeric($rawLng) || (float)$rawLng < -180 || (float)$rawLng > 180) {
            flash('error', 'Longitude must be a number between -180 and 180.');
            redirect($backTo);
        }
        $longitude = (float)$rawLng;
    }
}

/* A parent is required for every level except Region, and it must be
   a real, existing row. Creating (not editing) under an inactive
   parent is explicitly disallowed, even if the button was hidden. */
$parentRow = null;
if ($config['parent_column'] !== null) {
    $parentLevelConfig = structure_level_config($config['parent_level']);
    $parentRow = Database::fetch("SELECT * FROM `{$parentLevelConfig['table']}` WHERE id = :id", ['id' => $parentId]);

    if ($parentRow === null) {
        flash('error', 'Choose a valid parent record.');
        redirect('modules/structure/index.php');
    }
    if (!$isEdit && (string)$parentRow['status'] !== 'active') {
        flash('error', 'New records cannot be added under an inactive ' . $parentLevelConfig['label'] . '.');
        redirect(structure_back_url($config['parent_level'], structure_grandparent_id($config['parent_level'], $parentRow)));
    }
}

/* Existing row, for edit — and to detect a code actually changing
   before running the uniqueness check against itself. */
$existing = $isEdit ? structure_find($level, $id) : null;
if ($isEdit && $existing === null) {
    flash('error', 'That record could not be found.');
    redirect('modules/structure/index.php');
}

/* Code must be unique among siblings under the same parent (or,
   for Region, globally — it has no parent to scope by). */
$uniqueSql = "SELECT id FROM `{$config['table']}` WHERE `{$codeColumn}` = :code";
$uniqueParams = ['code' => $code];
if ($config['parent_column'] !== null) {
    $uniqueSql .= " AND `{$config['parent_column']}` = :parent_id";
    $uniqueParams['parent_id'] = $parentId;
}
if ($isEdit) {
    $uniqueSql .= ' AND id != :id';
    $uniqueParams['id'] = $id;
}
if (Database::exists($uniqueSql, $uniqueParams)) {
    flash('error', 'That ' . $config['code_label'] . ' is already in use here.');
    redirect($backTo);
}

$data = ['name' => $name, $codeColumn => $code];
if ($hasDescription) {
    $data['description'] = $description !== '' ? $description : null;
}
if (!empty($config['has_uc_number'])) {
    $data['uc_number'] = $ucNumber !== '' ? $ucNumber : null;
}
if ($hasSiteType) {
    $data['site_type_id'] = $siteTypeId;
}
if ($hasCoordinates) {
    $data['latitude'] = $latitude;
    $data['longitude'] = $longitude;
}
if ($hasAddress) {
    $address = Security::cleanText(post('address'), 255);
    $data['address'] = $address !== '' ? $address : null;
}

try {
    if ($isEdit) {
        $data['updated_by'] = Auth::id();
        Database::update($config['table'], $data, ['id' => $id]);
        $action = "{$level}.updated";
    } else {
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();
        if ($config['parent_column'] !== null) {
            $data[$config['parent_column']] = $parentId;
        }
        $id = Database::insert($config['table'], $data);
        $action = "{$level}.created";
    }

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'structure', 'action' => $action,
        'subject_type' => $level, 'subject_id' => $id,
        'description' => ucfirst($level) . ' ' . $name . ($isEdit ? ' updated' : ' created'),
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

clear_old();
flash('success', $config['label'] . ' "' . $name . '" ' . ($isEdit ? 'updated.' : 'created.'));
redirect(structure_back_url($level, $parentId));

/* ------------------------------------------------------------------ *
 | Local helpers                                                       |
 * ------------------------------------------------------------------ */

function structure_back_url(string $level, ?int $parentId, array $extra = []): string
{
    $params = array_filter(array_merge(['level' => $level, 'parent' => $parentId], $extra), static fn($v) => $v !== null && $v !== '');
    return base_url('modules/structure/index.php') . '?' . http_build_query($params);
}

/** The parent's own parent id, so a rejected create can send the user
    back to a page that still makes sense (the parent's own list). */
function structure_grandparent_id(string $level, array $row): ?int
{
    $config = structure_level_config($level);
    return $config['parent_column'] !== null ? (int)$row[$config['parent_column']] : null;
}
