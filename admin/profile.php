<?php
/**
 * The signed-in user's own account: change password, review details.
 * Managing *other* people's accounts belongs to the User Management
 * module in a later phase.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Auth::requireLogin();

$user = Auth::user();

if (is_post()) {
    Security::requireCsrf('admin/profile.php');

    $current = (string)($_POST['current_password'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (!Security::verifyPassword($current, (string)$user['password_hash'])) {
        flash('error', 'Your current password is not correct.');
    } elseif (($problem = Security::passwordProblem($password)) !== null) {
        flash('error', $problem);
    } elseif ($password !== $confirm) {
        flash('error', 'The two new passwords do not match.');
    } elseif (Security::verifyPassword($password, (string)$user['password_hash'])) {
        flash('error', 'Choose a password you have not used here before.');
    } else {
        Database::update('users', [
            'password_hash'        => Security::hashPassword($password),
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'must_change_password' => 0,
        ], ['id' => (int)$user['id']]);

        Database::execute('DELETE FROM remember_tokens WHERE user_id = :id', ['id' => (int)$user['id']]);

        log_event('auth', 'Password changed', ['user_id' => (int)$user['id']]);
        flash('success', 'Your password has been updated.');
    }

    redirect('admin/profile.php');
}

$avatar = avatar_url($user['profile_picture'] ?? null);

$pageTitle = 'My account';
$layout = 'app';

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="grid grid--2">
    <section class="card">
        <h3 class="card__title">Account details</h3>
        <p class="card__subtitle">Editing names and roles arrives with the User Management module.</p>

        <div style="display:flex; align-items:center; gap:16px; margin-bottom:20px">
            <?php if ($avatar !== ''): ?>
                <img src="<?= e($avatar) ?>" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;box-shadow:var(--shadow-raised-sm)">
            <?php else: ?>
                <span class="user-chip__avatar" style="width:64px;height:64px;flex:0 0 64px;font-size:1.1rem">
                    <?= e(initials((string)$user['name'])) ?>
                </span>
            <?php endif; ?>
            <div>
                <strong><?= e((string)$user['name']) ?></strong>
                <div style="color:var(--text-muted);font-size:var(--text-sm)"><?= e((string)($user['role_name'] ?? 'User')) ?></div>
            </div>
        </div>

        <dl class="definition">
            <dt>Sign-in with</dt>
            <dd><?= $user['login_type'] === Auth::LOGIN_EMAIL ? 'Email address' : 'CNIC' ?></dd>

            <dt>Identifier</dt>
            <dd><?= $user['login_type'] === Auth::LOGIN_EMAIL
                    ? e((string)$user['email'])
                    : e(Security::formatCnic((string)$user['cnic'])) ?></dd>

            <dt>Status</dt>
            <dd><span class="badge badge--active"><?= e(ucfirst((string)$user['status'])) ?></span></dd>

            <dt>Password set</dt>
            <dd><?= e(format_datetime($user['password_changed_at'] ?? null)) ?></dd>

            <dt>Last sign-in</dt>
            <dd><?= e(format_datetime($user['last_login_at'] ?? null)) ?></dd>
        </dl>
    </section>

    <section class="card">
        <h3 class="card__title">Change password</h3>
        <p class="card__subtitle">Changing your password signs out every remembered device.</p>

        <form method="post" action="<?= e(base_url('admin/profile.php')) ?>" id="reset-form" novalidate>
            <?= csrf_field() ?>
            <?php
            field_input('current_password', 'Current password', [
                'type' => 'password', 'icon' => 'lock', 'required' => true, 'autocomplete' => 'current-password',
            ]);
            field_input('password', 'New password', [
                'type' => 'password', 'icon' => 'key', 'required' => true,
                'autocomplete' => 'new-password', 'attrs' => 'data-password',
            ]);
            field_strength();
            field_input('password_confirm', 'Retype new password', [
                'type' => 'password', 'icon' => 'key', 'required' => true,
                'autocomplete' => 'new-password', 'attrs' => 'data-password-confirm',
            ]);
            ?>
            <button type="submit" class="btn btn--primary">
                <span class="spinner"></span>
                <span class="btn__label">Update password</span>
            </button>
        </form>
    </section>
</div>

<?php
$extraScripts = ['auth.js'];
require INCLUDES_PATH . '/footer.php';
?>
