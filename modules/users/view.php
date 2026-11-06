<?php
/**
 * User Management — account detail.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.view');

$id = (int)query('id');
$user = $id > 0 ? users_find($id) : null;

if ($user === null) {
    flash('error', 'That user could not be found.');
    redirect('modules/users/index.php');
}

$activationLink = Session::get('users_activation_link');
Session::forget('users_activation_link');

$recentActivity = Database::fetchAll(
    'SELECT * FROM activity_log WHERE subject_type = :type AND subject_id = :id ORDER BY created_at DESC LIMIT 10',
    ['type' => 'user', 'id' => $id]
);

$userAvatar = avatar_url($user['profile_picture'] ?? null);
$identifier = $user['login_type'] === Auth::LOGIN_EMAIL
    ? (string)$user['email']
    : Security::formatCnic((string)$user['cnic']);

$locked = users_is_locked_role($user['role_slug'] ?? null);
$isSelf = users_is_self((int)$user['id']);

$pageTitle = (string)$user['name'];
$layout = 'app';
$extraStyles = ['users.css'];
$extraScripts = ['users.js'];

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost" href="<?= e(base_url('modules/users/index.php')) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?>
    <span class="btn__label">Back to users</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<?php if ($activationLink): ?>
    <div class="card" style="margin-bottom:20px">
        <h3 class="card__title"><?= icon('mail') ?> Send this link to <?= e((string)$user['name']) ?></h3>
        <p class="card__subtitle">
            This is shown once — copy it now. They open it, choose their own password, then sign in at
            <?= e(base_url('auth/login.php')) ?> like everyone else. If this gets lost, come back to this
            page and use "Resend activation link" below.
        </p>
        <div class="link-panel">
            <input type="text" id="activation-link" value="<?= e((string)$activationLink) ?>" readonly>
            <button type="button" class="btn" data-copy-target="activation-link">
                <?= icon('copy') ?>
                <span class="btn__label">Copy</span>
            </button>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid--2">
    <section class="card">
        <div style="display:flex; align-items:center; gap:16px; margin-bottom:20px">
            <?php if ($userAvatar !== ''): ?>
                <img class="avatar-lg" src="<?= e($userAvatar) ?>" alt="">
            <?php else: ?>
                <span class="avatar-lg"><?= e(initials((string)$user['name'])) ?></span>
            <?php endif; ?>
            <div>
                <h2 style="margin:0"><?= e((string)$user['name']) ?></h2>
                <span class="badge" style="margin-top:6px"><?= e((string)($user['role_name'] ?? 'No role')) ?></span>
                <span class="badge <?= e(users_status_badge((string)$user['status'])) ?>" style="margin-top:6px;margin-left:6px">
                    <?= e(users_status_label((string)$user['status'])) ?>
                </span>
            </div>
        </div>

        <dl class="definition">
            <dt>Signs in with</dt>
            <dd><?= $user['login_type'] === Auth::LOGIN_EMAIL ? 'Email address' : 'CNIC' ?></dd>

            <dt>Identifier</dt>
            <dd><?= e($identifier) ?></dd>

            <dt>Phone</dt>
            <dd><?= e((string)($user['phone'] ?: '—')) ?></dd>

            <dt>Created</dt>
            <dd><?= e(format_datetime($user['created_at'] ?? null)) ?></dd>

            <dt>Activated</dt>
            <dd><?= e(format_datetime($user['activated_at'] ?? null)) ?></dd>

            <dt>Last sign-in</dt>
            <dd><?= e(format_datetime($user['last_login_at'] ?? null)) ?> <?= $user['last_login_ip'] ? '· ' . e((string)$user['last_login_ip']) : '' ?></dd>
        </dl>

        <?php if ($locked): ?>
            <div class="auth-note" style="margin-top:20px">
                This is the Super Administrator account. Its role and status cannot be changed from this screen.
            </div>
        <?php elseif ($isSelf): ?>
            <div class="auth-note" style="margin-top:20px">
                This is your own account. Change your role or status from another administrator's session.
            </div>
        <?php else: ?>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:20px">
                <?php if (can('users.update')): ?>
                    <a class="btn" href="<?= e(base_url('modules/users/edit.php?id=' . (int)$user['id'])) ?>">
                        <?= icon('pencil') ?>
                        <span class="btn__label">Edit</span>
                    </a>
                <?php endif; ?>

                <?php if (can('users.create') && $user['status'] === Auth::STATUS_PENDING): ?>
                    <form method="post" action="<?= e(base_url('modules/users/process/resend-activation.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                        <button type="submit" class="btn">
                            <?= icon('refresh') ?>
                            <span class="btn__label">Resend activation link</span>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (can('users.delete') && $user['status'] !== Auth::STATUS_PENDING): ?>
                    <?php if ($user['status'] === Auth::STATUS_ACTIVE): ?>
                        <form method="post" action="<?= e(base_url('modules/users/process/status.php')) ?>" data-confirm="Deactivate this account? They will not be able to sign in.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                            <input type="hidden" name="status" value="inactive">
                            <button type="submit" class="btn">
                                <?= icon('ban') ?>
                                <span class="btn__label">Deactivate</span>
                            </button>
                        </form>
                        <form method="post" action="<?= e(base_url('modules/users/process/status.php')) ?>" data-confirm="Block this account? They will not be able to sign in until unblocked.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                            <input type="hidden" name="status" value="blocked">
                            <button type="submit" class="btn btn--danger">
                                <?= icon('ban') ?>
                                <span class="btn__label">Block</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(base_url('modules/users/process/status.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                            <input type="hidden" name="status" value="active">
                            <button type="submit" class="btn btn--primary">
                                <?= icon('check') ?>
                                <span class="btn__label">Reactivate</span>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3 class="card__title">Recent activity</h3>
        <?php if (empty($recentActivity)): ?>
            <p class="card__subtitle" style="margin-bottom:0">Nothing recorded yet.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($recentActivity as $entry): ?>
                    <div class="timeline__item">
                        <span><strong><?= e((string)$entry['action']) ?></strong> — <?= e((string)($entry['description'] ?? '')) ?></span>
                        <span><?= e(format_datetime($entry['created_at'] ?? null, 'd M, H:i')) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
