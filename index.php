<?php
/**
 * Single entry point.
 * Not installed -> installation wizard. Installed -> login or dashboard.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

Security::sendSecurityHeaders();

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

if (Auth::check()) {
    redirect('admin/dashboard.php');
}

redirect('auth/login.php');
