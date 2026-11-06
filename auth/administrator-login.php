<?php
/**
 * "Administrator Login" — a bookmarkable URL, nothing more.
 *
 * There is deliberately one login form and one authentication path for
 * every role (see auth/login.php); Auth::homeFor() already sends an
 * Administrator to their own dashboard after signing in there. This file
 * exists only so a link or bookmark literally named "Administrator Login"
 * still works, without a second copy of any login logic.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

redirect('auth/login.php');
