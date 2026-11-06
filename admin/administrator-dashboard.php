<?php
/**
 * The Administrator's own dashboard.
 *
 * Distinct from admin/dashboard.php (Super Admin's), which now denies
 * the Administrator role outright. Super Admin may still open this page
 * for oversight — the restriction requirements asked for is one-directional
 * (Administrator must not reach Super Admin's pages), not both ways.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Auth::requireRole(['admin', Permission::ROLE_SUPER_ADMIN]);

$user = Auth::user();
$companyData = company();

/* What this account can actually do, beyond simply being signed in —
   shown plainly so "Administrator has only Administrator permissions"
   is something the person can see, not just a rule enforced silently. */
$grantedPermissions = [];
try {
    $grantedPermissions = Database::fetchAll(
        'SELECT p.name, p.module FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
          WHERE rp.role_id = :role_id
          ORDER BY p.module, p.action',
        ['role_id' => (int)$user['role_id']]
    );
} catch (Throwable $e) {
    log_event('dashboard', 'Could not load administrator permissions', ['error' => $e->getMessage()]);
}

$pageTitle = 'Administrator Dashboard';
$layout = 'app';

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="card" style="margin-bottom:24px">
    <h2 class="card__title">Welcome, <?= e(explode(' ', (string)$user['name'])[0]) ?></h2>
    <p class="card__subtitle" style="margin-bottom:0">
        You're signed in as an <span class="badge badge--active">Administrator</span> for
        <?= e($companyData['name']) ?>. This is a separate dashboard from the Super Administrator's —
        pages reserved for that role are not reachable from here, even by URL.
    </p>
</div>

<div class="grid grid--2">
    <section class="card">
        <h3 class="card__title">Your account</h3>
        <dl class="definition">
            <dt>Name</dt>
            <dd><?= e((string)$user['name']) ?></dd>

            <dt>Role</dt>
            <dd><?= e((string)($user['role_name'] ?? 'Administrator')) ?></dd>

            <dt>Sign-in identifier</dt>
            <dd>
                <?= $user['login_type'] === Auth::LOGIN_EMAIL
                    ? e((string)$user['email'])
                    : e(Security::formatCnic((string)$user['cnic'])) ?>
            </dd>

            <dt>Status</dt>
            <dd><span class="badge badge--active"><?= e(ucfirst((string)$user['status'])) ?></span></dd>

            <dt>Last sign-in</dt>
            <dd><?= e(format_datetime($user['last_login_at'] ?? null)) ?></dd>
        </dl>
    </section>

    <section class="card">
        <h3 class="card__title">Your permissions</h3>
        <p class="card__subtitle">
            Granted by the Super Administrator through Roles &amp; permissions — this account never
            has Super Admin privileges automatically.
        </p>
        <?php if (empty($grantedPermissions)): ?>
            <div class="auth-note">
                No additional permissions have been granted yet. Ask your Super Administrator to assign
                some from <strong>Roles &amp; permissions</strong>, or explore
                <a href="<?= e(base_url('admin/profile.php')) ?>">My account</a> in the meantime.
            </div>
        <?php else: ?>
            <ul class="requirements" style="margin:0">
                <?php foreach ($grantedPermissions as $permission): ?>
                    <li class="is-pass">
                        <?= icon('check-big') ?>
                        <span><?= e((string)$permission['name']) ?></span>
                        <span><?= e(ucfirst((string)$permission['module'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
