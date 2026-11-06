<?php
/**
 * Issues a fresh activation link for a still-pending account.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.create');

if (!is_post()) {
    redirect('modules/users/index.php');
}

$id = (int)post('id');
Security::requireCsrf('modules/users/view.php?id=' . $id);

$user = $id > 0 ? users_find($id) : null;
if ($user === null) {
    flash('error', 'That user could not be found.');
    redirect('modules/users/index.php');
}

if (!users_actor_can_manage_user($user)) {
    flash('error', USERS_DELEGATION_MESSAGE);
    redirect('modules/users/view.php?id=' . $id);
}

if ((string)$user['status'] !== Auth::STATUS_PENDING) {
    flash('error', 'This account is already active.');
    redirect('modules/users/view.php?id=' . $id);
}

$token = users_issue_activation($id);

Database::insert('activity_log', [
    'user_id'      => Auth::id(),
    'module'       => 'users',
    'action'       => 'user.activation_resent',
    'subject_type' => 'user',
    'subject_id'   => $id,
    'description'  => 'Activation link reissued for ' . $user['name'],
    'ip_address'   => client_ip(),
]);

Session::set('users_activation_link', users_activation_link($token));
flash('success', 'A new activation link is ready below.');
redirect('modules/users/view.php?id=' . $id);
