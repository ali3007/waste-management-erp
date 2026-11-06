<?php
/**
 * Sign out and return to the entrance.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

Session::start();
Auth::logout();
Session::start();

flash('success', 'You have been signed out.');
redirect('auth/login.php');
