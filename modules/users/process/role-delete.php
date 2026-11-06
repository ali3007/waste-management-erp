<?php
/**
 * Deletes a custom role that no user currently holds.
 * System roles (Super Admin, Administrator) can never be deleted.
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

$id = (int)post('id');
$role = $id > 0 ? Database::fetch('SELECT * FROM roles WHERE id = :id', ['id' => $id]) : null;

if ($role === null) {
    flash('error', 'That role could not be found.');
    redirect('modules/users/roles.php');
}

if (!users_actor_can_manage_role((int)$role['id'])) {
    flash('error', USERS_DELEGATION_MESSAGE);
    redirect('modules/users/roles.php');
}

if ($role['is_system']) {
    flash('error', 'System roles cannot be deleted.');
    redirect('modules/users/roles.php');
}

$userCount = (int)Database::fetchValue('SELECT COUNT(*) FROM users WHERE role_id = :id', ['id' => $id]);
if ($userCount > 0) {
    flash('error', 'This role is still assigned to ' . $userCount . ' user(s). Reassign them first.');
    redirect('modules/users/roles.php');
}

try {
    Database::execute('DELETE FROM roles WHERE id = :id', ['id' => $id]);

    Database::insert('activity_log', [
        'user_id'     => Auth::id(),
        'module'      => 'users',
        'action'      => 'role.deleted',
        'description' => 'Deleted role ' . $role['name'],
        'ip_address'  => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect('modules/users/roles.php');
}

flash('success', 'Role deleted.');
redirect('modules/users/roles.php');
