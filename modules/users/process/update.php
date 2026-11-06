<?php
/**
 * Handles edit-user submissions from modules/users/edit.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.update');

if (!is_post()) {
    redirect('modules/users/index.php');
}

$id = (int)post('id');
$backTo = 'modules/users/edit.php?id=' . $id;

Security::requireCsrf($backTo);

$user = $id > 0 ? users_find($id) : null;
if ($user === null) {
    flash('error', 'That user could not be found.');
    redirect('modules/users/index.php');
}

if (users_is_locked_role($user['role_slug'] ?? null)) {
    flash('error', 'The Super Administrator account cannot be edited here.');
    redirect('modules/users/index.php');
}

if (!users_actor_can_manage_user($user)) {
    flash('error', USERS_DELEGATION_MESSAGE);
    redirect('modules/users/index.php');
}

$isSelf = users_is_self($id);

$name = Security::cleanText(post('name'), 120);
$phone = Security::cleanText(post('phone'), 30);

keep_old(['name' => $name, 'phone' => $phone, 'role_id' => post('role_id')]);

if ($name === '') {
    flash('error', 'Enter the person\'s full name.');
    redirect($backTo);
}

$update = [
    'name'  => $name,
    'phone' => $phone !== '' ? $phone : null,
];

/* Role changes never apply to your own account, even if the form was
   tampered with — the same rule the page itself enforces. */
if (!$isSelf) {
    $roleId = (int)post('role_id');
    $role = Database::fetch('SELECT * FROM roles WHERE id = :id LIMIT 1', ['id' => $roleId]);

    if ($role === null || users_is_locked_role((string)$role['slug'])) {
        flash('error', 'Choose a valid role.');
        redirect($backTo);
    }

    if ((int)$role['id'] !== (int)$user['role_id'] && !users_actor_can_manage_role((int)$role['id'])) {
        log_event('security', 'Blocked attempt to give an account a role above the editor\'s own access', ['user_id' => Auth::id(), 'target' => $id, 'role_id' => (int)$role['id']]);
        flash('error', 'You can only assign roles whose permissions you hold yourself.');
        redirect($backTo);
    }
    $update['role_id'] = (int)$role['id'];
}

/* Optional new avatar. */
$oldPicture = $user['profile_picture'] ?? null;
if (!empty($_FILES['profile_picture']['name'])) {
    $stored = Security::storeImage($_FILES['profile_picture'], AVATAR_PATH, 'avatar');
    if (!$stored['ok']) {
        flash('error', $stored['message']);
        redirect($backTo);
    }
    $update['profile_picture'] = $stored['file'];
}

try {
    Database::update('users', $update, ['id' => $id]);

    /* Remove the replaced file only when no other account still points at it. */
    if (isset($update['profile_picture']) && $oldPicture
        && !Database::exists('SELECT id FROM users WHERE profile_picture = :f AND id <> :id', ['f' => $oldPicture, 'id' => $id])) {
        Security::deleteUpload(AVATAR_PATH, $oldPicture);
    }

    Database::insert('activity_log', [
        'user_id'      => Auth::id(),
        'module'       => 'users',
        'action'       => 'user.updated',
        'subject_type' => 'user',
        'subject_id'   => $id,
        'description'  => 'Updated details for ' . $name,
        'ip_address'   => client_ip(),
    ]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

clear_old();
flash('success', 'Account updated.');
redirect('modules/users/view.php?id=' . $id);
