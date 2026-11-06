<?php
/**
 * Creates or updates a Staff Pool or an HR Staff Type.
 * One handler for both, discriminated by `kind` — same "generic,
 * config-driven" approach as the Structure module's node-save.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

if (!is_post()) {
    redirect('modules/hr/types.php');
}

$kind = post('kind');
if (!in_array($kind, ['pool', 'type'], true)) {
    flash('error', 'Unknown record type.');
    redirect('modules/hr/types.php');
}

$table = $kind === 'pool' ? 'hr_staff_pools' : 'hr_staff_types';
$id = (int)post('id');
$isEdit = $id > 0;

Permission::require($isEdit ? 'hrtype.update' : 'hrtype.create');

$backTo = 'modules/hr/types.php' . ($isEdit ? '?edit_kind=' . $kind . '&edit=' . $id : '');
Security::requireCsrf($backTo);

$name = Security::cleanText(post('name'), 80);
$description = Security::cleanMultiline(post('description'), 255);
$poolId = $kind === 'type' ? (int)post('staff_pool_id') : null;
$mode = $kind === 'type' ? post('assignment_mode') : null;

keep_old([
    'kind' => $kind, 'name' => $name, 'description' => $description,
    'staff_pool_id' => (string)$poolId, 'assignment_mode' => (string)$mode,
]);

if ($name === '') {
    flash('error', 'Enter a name.');
    redirect($backTo);
}

if ($kind === 'type') {
    if (!Database::exists('SELECT id FROM hr_staff_pools WHERE id = :id', ['id' => $poolId])) {
        flash('error', 'Choose a valid Staff Pool.');
        redirect($backTo);
    }
    if (!in_array($mode, ['none', 'single', 'multi'], true)) {
        flash('error', 'Choose a valid assignment rule.');
        redirect($backTo);
    }
}

$existing = $isEdit ? Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $id]) : null;
if ($isEdit && $existing === null) {
    flash('error', 'That record could not be found.');
    redirect('modules/hr/types.php');
}

$data = ['name' => $name, 'description' => $description !== '' ? $description : null];
if ($kind === 'type') {
    $data['staff_pool_id'] = $poolId;
    $data['assignment_mode'] = $mode;
}

try {
    if ($isEdit) {
        $data['updated_by'] = Auth::id();
        Database::update($table, $data, ['id' => $id]);
    } else {
        $slugBase = trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
        $slug = $slugBase;
        $suffix = 2;
        while (Database::exists("SELECT id FROM `{$table}` WHERE slug = :slug", ['slug' => $slug])) {
            $slug = $slugBase . '-' . $suffix++;
        }
        $data['slug'] = $slug;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();
        $id = Database::insert($table, $data);
    }

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => $kind . ($isEdit ? '.updated' : '.created'),
        'subject_type' => $kind, 'subject_id' => $id,
        'description' => ($isEdit ? 'Updated ' : 'Created ') . $kind . ' ' . $name,
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

clear_old();
flash('success', ($kind === 'pool' ? 'Staff Pool' : 'HR Type') . ' "' . $name . '" ' . ($isEdit ? 'updated.' : 'created.'));
redirect('modules/hr/types.php');
