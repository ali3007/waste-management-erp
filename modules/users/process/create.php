<?php
/**
 * Handles new-user submissions from modules/users/create.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.create');

if (!is_post()) {
    redirect('modules/users/create.php');
}

Security::requireCsrf('modules/users/create.php');

$name = Security::cleanText(post('name'), 120);
$loginType = post('login_type') === Auth::LOGIN_EMAIL ? Auth::LOGIN_EMAIL : Auth::LOGIN_CNIC;
$email = mb_strtolower(Security::cleanText(post('email'), 190));
$cnic = Security::normalizeCnic(post('cnic'));
$phone = Security::cleanText(post('phone'), 30);
$roleId = (int)post('role_id');
$days = max(1, min(30, (int)post('days', '7')));

keep_old([
    'name' => $name, 'login_type' => $loginType, 'email' => post('email'),
    'cnic' => post('cnic'), 'phone' => $phone, 'role_id' => (string)$roleId, 'days' => (string)$days,
]);

/* ------------------------------------------------------------------ *
 | Validate                                                            |
 * ------------------------------------------------------------------ */
if ($name === '') {
    flash('error', 'Enter the person\'s full name.');
    redirect('modules/users/create.php');
}

if ($loginType === Auth::LOGIN_EMAIL) {
    if (!Security::isEmail($email)) {
        flash('error', 'Enter a valid email address.');
        redirect('modules/users/create.php');
    }
} elseif (!Security::isCnic($cnic)) {
    flash('error', 'Enter a valid 13-digit CNIC.');
    redirect('modules/users/create.php');
}

$role = Database::fetch('SELECT * FROM roles WHERE id = :id LIMIT 1', ['id' => $roleId]);
if ($role === null || users_is_locked_role((string)$role['slug'])) {
    flash('error', 'Choose a valid role. The Super Administrator role cannot be assigned here.');
    redirect('modules/users/create.php');
}

if (!users_actor_can_manage_role((int)$role['id'])) {
    log_event('security', 'Blocked attempt to create an account with a role above the creator\'s own access', ['user_id' => Auth::id(), 'role_id' => (int)$role['id']]);
    flash('error', 'You can only assign roles whose permissions you hold yourself.');
    redirect('modules/users/create.php');
}

$column = $loginType === Auth::LOGIN_EMAIL ? 'email' : 'cnic';
$value = $loginType === Auth::LOGIN_EMAIL ? $email : $cnic;

if (Database::exists("SELECT id FROM users WHERE {$column} = :value", ['value' => $value])) {
    flash('error', 'An account with that ' . strtoupper($column) . ' already exists.');
    redirect('modules/users/create.php');
}

/* ------------------------------------------------------------------ *
 | Optional avatar                                                     |
 * ------------------------------------------------------------------ */
$picture = null;
if (!empty($_FILES['profile_picture']['name'])) {
    $stored = Security::storeImage($_FILES['profile_picture'], AVATAR_PATH, 'avatar');
    if (!$stored['ok']) {
        flash('error', $stored['message']);
        redirect('modules/users/create.php');
    }
    $picture = $stored['file'];
}

/* ------------------------------------------------------------------ *
 | Create                                                               |
 * ------------------------------------------------------------------ */
$token = Security::randomToken();

try {
    $userId = Database::insert('users', [
        'role_id'               => (int)$role['id'],
        'name'                  => $name,
        'email'                 => $loginType === Auth::LOGIN_EMAIL ? $email : null,
        'cnic'                  => $loginType === Auth::LOGIN_CNIC ? $cnic : null,
        'phone'                 => $phone !== '' ? $phone : null,
        'password_hash'         => '',
        'login_type'            => $loginType,
        'status'                => Auth::STATUS_PENDING,
        'profile_picture'       => $picture,
        'activation_token_hash' => Security::hashToken($token),
        'activation_expires_at' => date('Y-m-d H:i:s', time() + ($days * 86400)),
        'created_by'            => Auth::id(),
    ]);

    Database::insert('activity_log', [
        'user_id'      => Auth::id(),
        'module'       => 'users',
        'action'       => 'user.created',
        'subject_type' => 'user',
        'subject_id'   => $userId,
        'description'  => 'Created account for ' . $name,
        'ip_address'   => client_ip(),
    ]);
} catch (Throwable $e) {
    if (isset($picture)) {
        Security::deleteUpload(AVATAR_PATH, $picture);
    }
    flash('error', Database::friendlyError($e));
    redirect('modules/users/create.php');
}

clear_old();
Session::set('users_activation_link', users_activation_link($token));
flash('success', $name . '\'s account has been created. Send them the activation link below.');
redirect('modules/users/view.php?id=' . $userId);
