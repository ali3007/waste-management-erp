<?php
/**
 * Creates a password reset token.
 *
 * The response is identical whether or not the account exists, so this
 * page cannot be used to discover which identifiers are registered.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

if (!is_post()) {
    redirect('auth/forgot-password.php');
}

Security::requireCsrf('auth/forgot-password.php');

$identifier = post('identifier');

try {
    $reset = Auth::createPasswordReset($identifier);

    if ($reset !== null) {
        $link = base_url('auth/reset-password.php') . '?token=' . rawurlencode($reset['token']);

        /*
         * Phase 2 sends this link by email or SMS. Until then it is logged
         * for the administrator, and shown on screen in development only.
         */
        log_event('auth', 'Password reset link issued', [
            'user_id' => (int)$reset['user']['id'],
            'expires' => date('c', time() + PASSWORD_RESET_TTL),
        ]);

        if (APP_ENV === 'development') {
            Session::set('reset_dev_link', $link);
        }
    }
} catch (Throwable $e) {
    log_event('auth', 'Password reset request failed', ['error' => $e->getMessage()]);
}

Session::set('reset_requested', true);
redirect('auth/forgot-password.php');
