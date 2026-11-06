<?php
/**
 * Choose a new password using a reset token.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

$token = query('token');
$reset = $token !== '' ? Auth::findPasswordReset($token) : null;
$done = Session::get('reset_done') === true;
Session::forget('reset_done');

$pageTitle = 'Choose a new password';
$layout = 'auth';
$extraStyles = ['auth.css'];
$extraScripts = ['auth.js'];

require INCLUDES_PATH . '/header.php';
?>
<div class="auth-card">
    <?php if ($done): ?>
        <div class="auth-result">
            <div class="auth-result__icon"><?= icon('check-big') ?></div>
            <h1 class="auth-card__title">Password updated</h1>
            <p class="auth-card__lead">Sign in with your new password. Any remembered devices were signed out.</p>
            <a class="btn btn--primary btn--block" href="<?= e(base_url('auth/login.php')) ?>" style="margin-top:24px">
                <span class="btn__label">Go to sign in</span>
            </a>
        </div>
    <?php elseif ($reset === null): ?>
        <div class="auth-result">
            <div class="auth-result__icon"><?= icon('alert') ?></div>
            <h1 class="auth-card__title">This link no longer works</h1>
            <p class="auth-card__lead">
                Reset links expire after an hour and can only be used once. Request a new one to continue.
            </p>
            <a class="btn btn--primary btn--block" href="<?= e(base_url('auth/forgot-password.php')) ?>" style="margin-top:24px">
                <span class="btn__label">Request a new link</span>
            </a>
        </div>
    <?php else: ?>
        <div class="auth-card__head">
            <div class="auth-logo"><span class="auth-logo__fallback"><?= icon('key') ?></span></div>
            <h1 class="auth-card__title">Choose a new password</h1>
            <p class="auth-card__lead">For <?= e((string)$reset['name']) ?></p>
        </div>

        <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

        <form class="auth-form" id="reset-form" method="post" action="<?= e(base_url('auth/process/reset-password.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <?php
            field_input('password', 'New password', [
                'type' => 'password', 'icon' => 'lock', 'required' => true,
                'autocomplete' => 'new-password', 'attrs' => 'data-password',
            ]);
            field_strength();
            field_input('password_confirm', 'Retype new password', [
                'type' => 'password', 'icon' => 'lock', 'required' => true,
                'autocomplete' => 'new-password', 'attrs' => 'data-password-confirm',
            ]);
            ?>
            <button type="submit" class="btn btn--primary btn--block" style="margin-top:8px">
                <span class="spinner"></span>
                <span class="btn__label">Save new password</span>
            </button>
        </form>

        <div class="auth-card__foot">
            <a href="<?= e(base_url('auth/login.php')) ?>">Back to sign in</a>
            <span>Version <?= e(APP_VERSION) ?></span>
        </div>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
