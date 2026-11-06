<?php
/**
 * Changes an account's status: active, inactive or blocked.
 * Pending accounts are excluded — activation happens only through the
 * activation link, never a button here.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.delete');

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

if (users_is_locked_role($user['role_slug'] ?? null)) {
    flash('error', 'The Super Administrator account\'s status cannot be changed.');
    redirect('modules/users/index.php');
}

if (!users_actor_can_manage_user($user)) {
    flash('error', USERS_DELEGATION_MESSAGE);
    redirect('modules/users/view.php?id=' . $id);
}

if (users_is_self($id)) {
    flash('error', 'You cannot change the status of your own account.');
    redirect('modules/users/view.php?id=' . $id);
}

if ((string)$user['status'] === Auth::STATUS_PENDING) {
    flash('error', 'This account has not been activated yet. Resend the activation link instead.');
    redirect('modules/users/view.php?id=' . $id);
}

$newStatus = post('status');
$allowed = [Auth::STATUS_ACTIVE, Auth::STATUS_INACTIVE, Auth::STATUS_BLOCKED];

if (!in_array($newStatus, $allowed, true)) {
    flash('error', 'That is not a valid status.');
    redirect('modules/users/view.php?id=' . $id);
}

try {
    Database::update('users', ['status' => $newStatus], ['id' => $id]);

    /* A status change should sign the person out everywhere. */
    Database::execute('DELETE FROM remember_tokens WHERE user_id = :id', ['id' => $id]);

    Database::insert('activity_log', [
        'user_id'      => Auth::id(),
        'module'       => 'users',
        'action'       => 'user.status_changed',
        'subject_type' => 'user',
        'subject_id'   => $id,
        'description'  => $user['name'] . ' set to ' . $newStatus,
        'ip_address'   => client_ip(),
    ]);

    log_event('users', 'Status changed', ['target' => $id, 'by' => Auth::id(), 'status' => $newStatus]);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect('modules/users/view.php?id=' . $id);
}

flash('success', $user['name'] . ' is now ' . strtolower(users_status_label($newStatus)) . '.');
redirect('modules/users/view.php?id=' . $id);
