<?php
/**
 * Password recovery request.
 *
 * Phase 1 establishes the token foundation. Mail delivery is deliberately
 * left to a later phase: the token is created, hashed and stored, and the
 * link is handed over through whatever channel the next phase adds.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

$sent = Session::get('reset_requested') === true;
Session::forget('reset_requested');

$devLink = Session::get('reset_dev_link');
Session::forget('reset_dev_link');

$pageTitle = 'Reset your password';
$layout = 'auth';
$extraStyles = ['auth.css'];
$extraScripts = ['auth.js'];

require INCLUDES_PATH . '/header.php';
?>
<div class="auth-card">
    <?php if ($sent): ?>
        <div class="auth-result">
            <div class="auth-result__icon"><?= icon('mail') ?></div>
            <h1 class="auth-card__title">Check with your administrator</h1>
            <p class="auth-card__lead">
                If that email address or CNIC belongs to an account, a reset link has been created for it.
                The link is valid for one hour and can be used once.
            </p>

            <?php if (APP_ENV === 'development' && $devLink): ?>
                <div class="dev-token">
                    <strong>Development mode.</strong> Email delivery arrives in a later phase, so the link is shown here:
                    <br><?= e((string)$devLink) ?>
                </div>
            <?php endif; ?>

            <a class="btn btn--block" href="<?= e(base_url('auth/login.php')) ?>" style="margin-top:24px">
                <span class="btn__label">Back to sign in</span>
            </a>
        </div>
    <?php else: ?>
        <div class="auth-card__head">
            <div class="auth-logo">
                <?php $logo = company_logo_url(); ?>
                <?php if ($logo !== ''): ?>
                    <img src="<?= e($logo) ?>" alt="">
                <?php else: ?>
                    <span class="auth-logo__fallback"><?= icon('key') ?></span>
                <?php endif; ?>
            </div>
            <h1 class="auth-card__title">Reset your password</h1>
            <p class="auth-card__lead">Enter the email address or CNIC you sign in with.</p>
        </div>

        <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

        <form class="auth-form" id="forgot-form" method="post" action="<?= e(base_url('auth/process/forgot-password.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php
            field_input('identifier', 'Email / CNIC', [
                'icon' => 'id-card',
                'required' => true,
                'autocomplete' => 'username',
                'placeholder' => 'you@company.com or 00000-0000000-0',
                'attrs' => 'data-identifier-field',
            ]);
            ?>
            <button type="submit" class="btn btn--primary btn--block" style="margin-top:8px">
                <span class="spinner"></span>
                <span class="btn__label">Send reset link</span>
            </button>
        </form>

        <div class="auth-card__foot">
            <a href="<?= e(base_url('auth/login.php')) ?>">Back to sign in</a>
            <span>Version <?= e(APP_VERSION) ?></span>
        </div>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
