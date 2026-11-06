<?php
/**
 * Creates a role, or updates an existing one's level, description and
 * permission assignments. A role's name and slug are fixed at creation.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('roles.update');

if (!is_post()) {
    redirect('modules/users/roles.php');
}

Security::requireCsrf('modules/users/roles.php');
users_load_module_permissions();   // so newly installed modules' permissions exist before they are granted

$id = (int)post('id');
$isEdit = $id > 0;
$backTo = $isEdit ? 'modules/users/roles.php?edit=' . $id : 'modules/users/roles.php';

$level = (int)post('level');
$description = Security::cleanMultiline(post('description'), 255);
$submittedPermissions = array_filter(array_map('strval', $_POST['permissions'] ?? []));

if ($level < 1 || $level > 99) {
    flash('error', 'Level must be between 1 and 99.');
    redirect($backTo);
}

$role = $isEdit ? Database::fetch('SELECT * FROM roles WHERE id = :id', ['id' => $id]) : null;

/* Delegation: nobody below Super Admin may raise their own access or hand out permissions they lack. */
if (!Auth::isSuperAdmin()) {
    $notMine = array_values(array_diff($submittedPermissions, Permission::forCurrentUser()));
    if ($isEdit && $role !== null && (int)$role['id'] === (int)(Auth::user()['role_id'] ?? 0)) {
        flash('error', 'You cannot change the permissions of your own role.');
        redirect($backTo);
    }
    if ($isEdit && $role !== null && !users_actor_can_manage_role((int)$role['id'])) {
        flash('error', USERS_DELEGATION_MESSAGE);
        redirect($backTo);
    }
    if ($notMine !== [] && !in_array('*', Permission::forCurrentUser(), true)) {
        log_event('security', 'Blocked attempt to grant permissions the user does not hold', ['user_id' => Auth::id(), 'slugs' => $notMine]);
        flash('error', 'You cannot grant permissions you do not hold yourself: ' . implode(', ', array_slice($notMine, 0, 5)) . (count($notMine) > 5 ? '…' : ''));
        redirect($backTo);
    }
}

if ($isEdit) {
    if ($role === null) {
        flash('error', 'That role could not be found.');
        redirect('modules/users/roles.php');
    }
    if (users_is_locked_role((string)$role['slug'])) {
        flash('error', 'The Super Administrator role cannot be edited.');
        redirect('modules/users/roles.php');
    }
} else {
    $name = Security::cleanText(post('name'), 80);
    keep_old(['name' => $name, 'level' => (string)$level, 'description' => $description]);

    if ($name === '') {
        flash('error', 'Enter a role name.');
        redirect($backTo);
    }

    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
    if ($slug === '' || $slug === Permission::ROLE_SUPER_ADMIN) {
        flash('error', 'Choose a different role name.');
        redirect($backTo);
    }

    /* Keep the slug unique if two roles would otherwise collide. */
    $baseSlug = $slug;
    $suffix = 2;
    while (Database::exists('SELECT id FROM roles WHERE slug = :slug', ['slug' => $slug])) {
        $slug = $baseSlug . '-' . $suffix++;
    }
}

try {
    Database::beginTransaction();

    if ($isEdit) {
        Database::update('roles', ['level' => $level, 'description' => $description ?: null], ['id' => $id]);
    } else {
        $id = Database::insert('roles', [
            'name' => $name, 'slug' => $slug, 'description' => $description ?: null,
            'level' => $level, 'is_system' => 0,
        ]);
    }

    /* Sync permissions: grant everything submitted, revoke everything else. */
    $allSlugs = array_column(Database::fetchAll('SELECT slug FROM permissions'), 'slug');
    foreach ($allSlugs as $slug) {
        if (in_array($slug, $submittedPermissions, true)) {
            Permission::grant($id, $slug);
        } else {
            Permission::revoke($id, $slug);
        }
    }

    Database::insert('activity_log', [
        'user_id'      => Auth::id(),
        'module'       => 'users',
        'action'       => $isEdit ? 'role.updated' : 'role.created',
        'subject_type' => 'role',
        'subject_id'   => $id,
        'description'  => ($isEdit ? 'Updated role ' : 'Created role ') . ($role['name'] ?? $name ?? ''),
        'ip_address'   => client_ip(),
    ]);

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

clear_old();
flash('success', $isEdit ? 'Role updated.' : 'Role created. It is ready to assign to a user.');
redirect('modules/users/roles.php');
