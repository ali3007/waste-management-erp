<?php
/**
 * Completes an account activation.
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
$backToForm = 'auth/activate-account.php?token=' . rawurlencode($token);

Security::requireCsrf($backToForm);

$password = (string)($_POST['password'] ?? '');
$confirm = (string)($_POST['password_confirm'] ?? '');

$account = Auth::findActivation($token);

if ($account === null) {
    flash('error', 'That activation link has expired or has already been used.');
    redirect('auth/login.php');
}

if (($problem = Security::passwordProblem($password)) !== null) {
    flash('error', $problem);
    redirect($backToForm);
}

if ($password !== $confirm) {
    flash('error', 'The two passwords do not match.');
    redirect($backToForm);
}

if (!Auth::completeActivation($account, $password)) {
    flash('error', 'The account could not be activated. Try again.');
    redirect($backToForm);
}

Session::set('activation_done', true);
redirect('auth/activate-account.php');
