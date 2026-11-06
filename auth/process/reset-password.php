<?php
/**
 * Consumes a reset token and stores the new password hash.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

if (!is_post()) {
    redirect('auth/login.php');
}

$token = post('token');
$backToForm = 'auth/reset-password.php?token=' . rawurlencode($token);

Security::requireCsrf($backToForm);

$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['password_confirm'] ?? '');

$reset = Auth::findPasswordReset($token);

if ($reset === null) {
    flash('error', 'That reset link has expired. Request a new one.');
    redirect('auth/forgot-password.php');
}

if (($problem = Security::passwordProblem($password)) !== null) {
    flash('error', $problem);
    redirect($backToForm);
}

if ($password !== $confirm) {
    flash('error', 'The two passwords do not match.');
    redirect($backToForm);
}

if (!Auth::completePasswordReset($reset, $password)) {
    flash('error', 'The password could not be saved. Try again.');
    redirect($backToForm);
}

Session::set('reset_done', true);
redirect('auth/reset-password.php');
