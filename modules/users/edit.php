<?php
/**
 * User Management — edit an existing account.
 *
 * Name, phone and avatar are editable for anyone. Role and status change
 * from here only for accounts that are not the Super Administrator and
 * not the person editing themselves — admin/profile.php is where someone
 * manages their own account.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.update');

$id = (int)query('id');
$user = $id > 0 ? users_find($id) : null;

if ($user === null) {
    flash('error', 'That user could not be found.');
    redirect('modules/users/index.php');
}

if (users_is_locked_role($user['role_slug'] ?? null)) {
    flash('error', 'The Super Administrator account cannot be edited here.');
    redirect('modules/users/view.php?id=' . $id);
}

$isSelf = users_is_self($id);
$roles = users_assignable_roles();
$userAvatar = avatar_url($user['profile_picture'] ?? null);

$name = old('name', (string)$user['name']);
$phone = old('phone', (string)($user['phone'] ?? ''));
$roleId = old('role_id', (string)$user['role_id']);
clear_old();

$pageTitle = 'Edit ' . $user['name'];
$layout = 'app';
$extraStyles = ['users.css'];
$extraScripts = ['users.js'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/users/view.php?id=' . $id)) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to profile</span>
</a>

<div class="card" style="max-width:640px">
    <h2 class="card__title">Edit user</h2>
    <p class="card__subtitle">
        <?= $user['login_type'] === Auth::LOGIN_EMAIL ? 'Signs in with ' . e((string)$user['email']) : 'Signs in with ' . e(Security::formatCnic((string)$user['cnic'])) ?>
        — the sign-in identifier itself cannot be changed here.
    </p>

    <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

    <form method="post" id="user-form" action="<?= e(base_url('modules/users/process/update.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <?php field_input('name', 'Full name', ['required' => true, 'icon' => 'user', 'value' => $name, 'maxlength' => 120]); ?>
        <?php field_input('phone', 'Phone', ['icon' => 'phone', 'value' => $phone]); ?>

        <?php if ($isSelf): ?>
            <div class="auth-note" style="margin-bottom:20px">
                You are editing your own account, so role and status stay as they are.
                Sign in as another administrator to change those.
            </div>
        <?php else: ?>
            <div class="field">
                <label class="field__label" for="role_id">Role</label>
                <div class="control">
                    <select class="select" id="role_id" name="role_id">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int)$role['id'] ?>" <?= $roleId === (string)$role['id'] ? 'selected' : '' ?>>
                                <?= e($role['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <span class="field__label">Profile picture</span>
            <div class="uploader" data-uploader>
                <div class="uploader__preview" data-placeholder="<?= e(icon('user')) ?>">
                    <?php if ($userAvatar !== ''): ?>
                        <img src="<?= e($userAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= icon('user') ?>
                    <?php endif; ?>
                </div>
                <div class="uploader__body">
                    <div class="uploader__name">No file selected</div>
                    <p>PNG, JPG, GIF or WEBP up to 2 MB.</p>
                    <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap">
                        <button type="button" class="btn btn--ghost" data-uploader-trigger>Choose image</button>
                        <button type="button" class="btn btn--ghost" data-uploader-clear hidden>Remove</button>
                    </div>
                </div>
                <input type="file" id="profile_picture" name="profile_picture" accept="image/png,image/jpeg,image/gif,image/webp">
            </div>
        </div>

        <button type="submit" class="btn btn--primary">
            <span class="spinner"></span>
            <span class="btn__label">Save changes</span>
        </button>
    </form>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
