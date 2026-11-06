<?php
/**
 * The main entrance to the ERP.
 *
 * One identifier field serves both account types: an email address for
 * the Super Administrator, a CNIC for everyone else.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Session::start();

if (Auth::check()) {
    redirect('admin/dashboard.php');
}

$companyData = company();
$logo = company_logo_url();
$identifier = old('identifier');
clear_old();

$pageTitle = 'Sign in';
$layout = 'auth';
$extraStyles = ['auth.css'];
$extraScripts = ['auth.js'];

require INCLUDES_PATH . '/header.php';
?>
<div class="auth-card">
    <div class="auth-card__head">
        <div class="auth-logo">
            <?php if ($logo !== ''): ?>
                <img src="<?= e($logo) ?>" alt="<?= e($companyData['name']) ?>">
            <?php else: ?>
                <span class="auth-logo__fallback"><?= e(initials($companyData['name'] ?: APP_PRODUCT_NAME)) ?></span>
            <?php endif; ?>
        </div>
        <h1 class="auth-card__title">Solid Waste<br>Management System</h1>
        <?php if ($companyData['name'] !== '' && $companyData['name'] !== APP_PRODUCT_NAME): ?>
            <p class="auth-card__company"><?= e($companyData['name']) ?></p>
        <?php endif; ?>
    </div>

    <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

    <form class="auth-form" id="login-form" method="post" action="<?= e(base_url('auth/process/login.php')) ?>" novalidate>
        <?= csrf_field() ?>

        <?php
        field_input('identifier', 'Email / CNIC', [
            'icon' => 'id-card',
            'value' => $identifier,
            'required' => true,
            'autocomplete' => 'username',
            'placeholder' => 'you@company.com or 00000-0000000-0',
            'maxlength' => 190,
            'attrs' => 'data-identifier-field aria-describedby="identifier-hint"',
            'hint' => 'Administrators sign in with email; staff sign in with CNIC.',
        ]);

        field_input('password', 'Password', [
            'type' => 'password',
            'icon' => 'lock',
            'required' => true,
            'autocomplete' => 'current-password',
            'placeholder' => 'Your password',
        ]);
        ?>

        <div class="auth-form__options">
            <label class="checkbox">
                <input type="checkbox" name="remember" value="1">
                <span class="checkbox__box" aria-hidden="true"></span>
                <span>Remember me</span>
            </label>
            <a class="auth-form__link" href="<?= e(base_url('auth/forgot-password.php')) ?>">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn--primary btn--block">
            <span class="spinner"></span>
            <span class="btn__label">Sign in</span>
        </button>
    </form>

    <div class="auth-card__foot">
        <span>Version <?= e(APP_VERSION) ?></span>
        <?php if ($companyData['phone'] !== '' || $companyData['email'] !== ''): ?>
            <span>Need help? <?= e($companyData['phone'] !== '' ? $companyData['phone'] : $companyData['email']) ?></span>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
