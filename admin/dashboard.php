<?php
/**
 * The protected shell every future module will live inside.
 * Phase 1 shows system status only — no business modules.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Auth::requireLogin();

/* This is the Super Administrator's dashboard. The Administrator role has
   its own (admin/administrator-dashboard.php) and is denied here, even via
   a direct URL — every other role's access is unchanged from before. */
if (Auth::isAdministrator()) {
    http_response_code(403);
    require INCLUDES_PATH . '/components/forbidden.php';
    exit;
}

$user = Auth::user();
$companyData = company();

/* A small amount of real system information, so the shell is not a stub. */
$stats = [
    'users' => 0,
    'roles' => 0,
    'recent_logins' => 0,
];

try {
    $stats['users'] = (int)Database::fetchValue('SELECT COUNT(*) FROM users');
    $stats['roles'] = (int)Database::fetchValue('SELECT COUNT(*) FROM roles');
    $stats['recent_logins'] = (int)Database::fetchValue(
        'SELECT COUNT(*) FROM login_attempts WHERE successful = 1 AND attempted_at > (NOW() - INTERVAL 7 DAY)'
    );
} catch (Throwable $e) {
    log_event('dashboard', 'Could not load statistics', ['error' => $e->getMessage()]);
}

$installerPresent = is_dir(ROOT_PATH . '/install');

$pageTitle = 'Dashboard';
$layout = 'app';

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<?php if ($installerPresent && Auth::isSuperAdmin()): ?>
    <div class="alert alert--warning" role="alert">
        <?= icon('shield') ?>
        <span>
            The <code>install/</code> folder is still on the server. It refuses to run again, but deleting or
            renaming it on a production server removes the surface entirely.
        </span>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:24px">
    <h2 class="card__title">Welcome back, <?= e(explode(' ', (string)$user['name'])[0]) ?></h2>
    <p class="card__subtitle" style="margin-bottom:0">
        <?= e(APP_PRODUCT_NAME) ?> is installed and running for <?= e($companyData['name']) ?>.
        The business modules arrive in the phases ahead; this screen confirms the foundation is in place.
    </p>
</div>

<div class="grid grid--3" style="margin-bottom:24px">
    <div class="card card--flat">
        <div class="stat__label">User accounts</div>
        <div class="stat__value"><?= e((string)$stats['users']) ?></div>
        <div class="stat__meta">Created from inside the ERP</div>
    </div>
    <div class="card card--flat">
        <div class="stat__label">Roles defined</div>
        <div class="stat__value"><?= e((string)$stats['roles']) ?></div>
        <div class="stat__meta">Permissions are assigned per role</div>
    </div>
    <div class="card card--flat">
        <div class="stat__label">Sign-ins this week</div>
        <div class="stat__value"><?= e((string)$stats['recent_logins']) ?></div>
        <div class="stat__meta">Successful authentications</div>
    </div>
</div>

<div class="grid grid--2">
    <section class="card">
        <h3 class="card__title">Your account</h3>
        <p class="card__subtitle">Signed in as the <?= e((string)($user['role_name'] ?? 'user')) ?>.</p>
        <dl class="definition">
            <dt>Name</dt>
            <dd><?= e((string)$user['name']) ?></dd>

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
        <h3 class="card__title">System</h3>
        <p class="card__subtitle">What Phase 1 put in place.</p>
        <dl class="definition">
            <dt>Product</dt>
            <dd><?= e(APP_PRODUCT_NAME) ?></dd>

            <dt>Version</dt>
            <dd><?= e(APP_VERSION) ?></dd>

            <dt>Installed</dt>
            <dd><?= e(format_datetime(setting('installed_at'))) ?></dd>

            <dt>Environment</dt>
            <dd><?= e(ucfirst(APP_ENV)) ?></dd>

            <dt>Base URL</dt>
            <dd style="word-break:break-all"><?= e(base_url()) ?></dd>
        </dl>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
