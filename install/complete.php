<?php
/**
 * Completion screen. Reachable only right after a successful install,
 * using the summary the process step left in the session.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

Session::start();

$summary = Session::get('install_summary');

/* Someone browsing here later is sent to the login screen instead. */
if (!is_array($summary)) {
    redirect(IS_INSTALLED ? 'auth/login.php' : 'install/index.php');
}

Session::forget('install_summary');

$pageTitle = 'Installation complete';
$layout = 'install';
$extraStyles = ['auth.css', 'installation.css'];

require INCLUDES_PATH . '/header.php';
?>
<div class="install-card">
    <div class="install-done">
        <div class="install-done__icon"><?= icon('check-big') ?></div>
        <h1 class="step-panel__title">You are ready to go</h1>
        <p class="step-panel__lead">
            <?= e(APP_PRODUCT_NAME) ?> is installed and the Super Administrator account is active.
            Sign in with the email address and password you just chose.
        </p>

        <div class="summary">
            <dl>
                <dt>Company</dt><dd><?= e((string)$summary['company']) ?></dd>
                <dt>Database</dt><dd><?= e((string)$summary['database']) ?></dd>
                <dt>Super Admin</dt><dd><?= e((string)$summary['email']) ?></dd>
                <dt>Installed</dt><dd><?= e(format_datetime((string)$summary['at'])) ?></dd>
                <dt>Version</dt><dd><?= e(APP_VERSION) ?></dd>
            </dl>
        </div>

        <a class="btn btn--primary btn--block" href="<?= e(base_url('auth/login.php')) ?>" style="margin-top:28px">
            <span class="btn__label">Go to login</span>
        </a>

        <div class="auth-note" style="text-align:left">
            <strong>Two things to do next.</strong>
            Delete or rename the <code>install/</code> folder on a production server, and keep a copy of
            <code>database/config.generated.php</code> somewhere safe — it holds your database credentials
            and is the only file that changes when you move servers.
        </div>
    </div>
</div>

<p class="install-footer"><?= e(APP_PRODUCT_NAME) ?> · Version <?= e(APP_VERSION) ?></p>

<?php require INCLUDES_PATH . '/footer.php'; ?>
