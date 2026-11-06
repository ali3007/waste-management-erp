<?php
/**
 * User Management — create a new account.
 *
 * Mirrors tools/create-user.php exactly: the account starts pending,
 * with no usable password, and a one-time activation link. Nobody who
 * creates an account — including this screen — ever sees or sets its
 * password.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.create');

$roles = users_assignable_roles();
$loginType = old('login_type', Auth::LOGIN_CNIC);

$pageTitle = 'Add user';
$layout = 'app';
$extraStyles = ['users.css'];
$extraScripts = ['users.js'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/users/index.php')) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to users</span>
</a>

<div class="card" style="max-width:640px">
    <h2 class="card__title">Add a user</h2>
    <p class="card__subtitle">
        The account starts pending. You'll get a one-time activation link to send them —
        they choose their own password when they open it.
    </p>

    <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

    <form method="post" id="user-form" action="<?= e(base_url('modules/users/process/create.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <?php field_input('name', 'Full name', ['required' => true, 'icon' => 'user', 'value' => old('name'), 'maxlength' => 120]); ?>

        <div class="field">
            <span class="field__label">Signs in with <span class="field__required">*</span></span>
            <div style="display:flex; gap:16px; margin-top:4px">
                <label class="checkbox">
                    <input type="radio" name="login_type" value="cnic" <?= $loginType === Auth::LOGIN_CNIC ? 'checked' : '' ?> onchange="document.getElementById('field-cnic').hidden=false;document.getElementById('field-email').hidden=true">
                    <span class="checkbox__box" aria-hidden="true"></span>
                    <span>CNIC (staff)</span>
                </label>
                <label class="checkbox">
                    <input type="radio" name="login_type" value="email" <?= $loginType === Auth::LOGIN_EMAIL ? 'checked' : '' ?> onchange="document.getElementById('field-email').hidden=false;document.getElementById('field-cnic').hidden=true">
                    <span class="checkbox__box" aria-hidden="true"></span>
                    <span>Email (administrator)</span>
                </label>
            </div>
        </div>

        <div id="field-cnic" <?= $loginType === Auth::LOGIN_EMAIL ? 'hidden' : '' ?>>
            <?php field_input('cnic', 'CNIC', ['icon' => 'id-card', 'value' => old('cnic'), 'placeholder' => '00000-0000000-0', 'attrs' => 'data-identifier-field']); ?>
        </div>
        <div id="field-email" <?= $loginType === Auth::LOGIN_CNIC ? 'hidden' : '' ?>>
            <?php field_input('email', 'Email address', ['type' => 'email', 'icon' => 'mail', 'value' => old('email'), 'autocomplete' => 'off']); ?>
        </div>

        <?php field_input('phone', 'Phone (optional)', ['icon' => 'phone', 'value' => old('phone')]); ?>

        <div class="field">
            <label class="field__label" for="role_id">Role <span class="field__required">*</span></label>
            <div class="control">
                <select class="select" id="role_id" name="role_id" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int)$role['id'] ?>" <?= old('role_id') === (string)$role['id'] ? 'selected' : '' ?>>
                            <?= e($role['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (count($roles) <= 1): ?>
                <p class="field__hint">
                    Only one role exists yet. Add roles like Supervisor or HR Manager from
                    <a href="<?= e(base_url('modules/users/roles.php')) ?>">Roles &amp; permissions</a> — new ones appear here right away.
                </p>
            <?php endif; ?>
            <p class="field__error"></p>
        </div>

        <?php field_uploader('profile_picture', 'Profile picture (optional)', 'PNG, JPG, GIF or WEBP up to 2 MB.', 'user'); ?>

        <div class="field">
            <label class="field__label" for="days">Activation link expires after</label>
            <div class="control">
                <select class="select" id="days" name="days">
                    <?php foreach ([3, 7, 14, 30] as $option): ?>
                        <option value="<?= $option ?>" <?= (old('days', '7') === (string)$option) ? 'selected' : '' ?>><?= $option ?> days</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn--primary">
            <span class="spinner"></span>
            <span class="btn__label">Create user</span>
        </button>
    </form>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
