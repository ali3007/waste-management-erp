<?php
/**
 * Login handler. Validates, authenticates, then redirects.
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

Security::requireCsrf('auth/login.php');

$identifier = post('identifier');
$password = (string)($_POST['password'] ?? '');
$remember = isset($_POST['remember']);

keep_old(['identifier' => $identifier]);

$result = Auth::attempt($identifier, $password, $remember);

if (!$result['ok']) {
    flash('error', $result['message'] ?? 'Sign-in failed.');
    redirect('auth/login.php');
}

clear_old();
redirect($result['redirect'] ?? base_url('admin/dashboard.php'));
