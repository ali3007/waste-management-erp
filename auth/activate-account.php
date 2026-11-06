<?php
/**
 * Account activation.
 *
 * An administrator creates a pending account in a later phase; the person
 * follows their activation link here and sets their own password. No
 * administrator ever handles a user's password.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

$token = query('token');
$account = $token !== '' ? Auth::findActivation($token) : null;
$done = Session::get('activation_done') === true;
Session::forget('activation_done');

$pageTitle = 'Activate your account';
$layout = 'auth';
$extraStyles = ['auth.css'];
$extraScripts = ['auth.js'];

require INCLUDES_PATH . '/header.php';
?>
<div class="auth-card">
    <?php if ($done): ?>
        <div class="auth-result">
            <div class="auth-result__icon"><?= icon('check-big') ?></div>
            <h1 class="auth-card__title">Your account is active</h1>
            <p class="auth-card__lead">Sign in with your CNIC and the password you just chose.</p>
            <a class="btn btn--primary btn--block" href="<?= e(base_url('auth/login.php')) ?>" style="margin-top:24px">
                <span class="btn__label">Go to sign in</span>
            </a>
        </div>
    <?php elseif ($account === null): ?>
        <div class="auth-result">
            <div class="auth-result__icon"><?= icon('alert') ?></div>
            <h1 class="auth-card__title">This activation link no longer works</h1>
            <p class="auth-card__lead">
                The link may have expired, or the account may already be active.
                Ask your administrator to send a new invitation.
            </p>
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
                    <span class="auth-logo__fallback"><?= icon('user') ?></span>
                <?php endif; ?>
            </div>
            <h1 class="auth-card__title">Set your password</h1>
            <p class="auth-card__lead">
                Welcome, <?= e((string)$account['name']) ?>. Choose a password to finish setting up your account.
            </p>
        </div>

        <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

        <form class="auth-form" id="activate-form" method="post" action="<?= e(base_url('auth/process/activate-account.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="auth-note" style="margin-bottom:20px">
                You will sign in with your CNIC:
                <strong><?= e(Security::formatCnic((string)($account['cnic'] ?? ''))) ?></strong>
            </div>

            <?php
            field_input('password', 'Password', [
                'type' => 'password', 'icon' => 'lock', 'required' => true,
                'autocomplete' => 'new-password', 'attrs' => 'data-password',
            ]);
            field_strength();
            field_input('password_confirm', 'Retype password', [
                'type' => 'password', 'icon' => 'lock', 'required' => true,
                'autocomplete' => 'new-password', 'attrs' => 'data-password-confirm',
            ]);
            ?>

            <button type="submit" class="btn btn--primary btn--block" style="margin-top:8px">
                <span class="spinner"></span>
                <span class="btn__label">Activate account</span>
            </button>
        </form>

        <div class="auth-card__foot">
            <span>Version <?= e(APP_VERSION) ?></span>
        </div>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
