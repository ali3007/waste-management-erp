<?php
/**
 * First-run installation wizard.
 * Refuses to run once the application has been installed.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (IS_INSTALLED) {
    redirect('auth/login.php');
}

Session::start();

/* Requirement probe — shown on the welcome step. */
$requirements = [
    ['label' => 'PHP 8.0 or newer', 'pass' => PHP_VERSION_ID >= 80000, 'detail' => PHP_VERSION],
    ['label' => 'PDO MySQL driver', 'pass' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? 'Available' : 'Missing'],
    ['label' => 'mbstring extension', 'pass' => extension_loaded('mbstring'), 'detail' => extension_loaded('mbstring') ? 'Available' : 'Missing'],
    ['label' => 'GD or image support', 'pass' => function_exists('getimagesize'), 'detail' => function_exists('getimagesize') ? 'Available' : 'Missing'],
    ['label' => 'database/ is writable', 'pass' => is_writable(DATABASE_PATH), 'detail' => is_writable(DATABASE_PATH) ? 'Writable' : 'Not writable'],
    ['label' => 'uploads/ is writable', 'pass' => is_writable(UPLOAD_PATH), 'detail' => is_writable(UPLOAD_PATH) ? 'Writable' : 'Not writable'],
    ['label' => 'storage/logs is writable', 'pass' => is_writable(LOG_PATH), 'detail' => is_writable(LOG_PATH) ? 'Writable' : 'Not writable'],
];

$blocked = false;
foreach ($requirements as $requirement) {
    if (!$requirement['pass']) {
        $blocked = true;
        break;
    }
}

$pageTitle = 'Install';
$layout = 'install';
$extraStyles = ['auth.css', 'installation.css'];
$extraScripts = ['installation.js'];

require INCLUDES_PATH . '/header.php';
?>
<div class="install-card" id="install-wizard"
     data-endpoint="<?= e(base_url('install/process.php')) ?>"
     data-token="<?= e(csrf_token()) ?>">

    <div class="install-card__head">
        <span class="install-card__mark"><?= icon('recycle') ?></span>
        <div>
            <h1><?= e(APP_PRODUCT_NAME) ?></h1>
            <p>Set-up wizard · Version <?= e(APP_VERSION) ?></p>
        </div>
    </div>

    <ol class="steps" aria-label="Installation progress">
        <?php
        $stepLabels = ['Welcome', 'Database', 'Company', 'Administrator', 'Done'];
        foreach ($stepLabels as $index => $label):
            ?>
            <li class="steps__item <?= $index === 0 ? 'is-active' : '' ?>" data-step="<?= $index + 1 ?>">
                <span class="steps__dot"><?= $index + 1 ?></span>
                <span><?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php require INCLUDES_PATH . '/components/alerts.php'; ?>

    <!-- Step 1 — Welcome -->
    <section class="step-panel is-active" data-step="1">
        <h2 class="step-panel__title">Welcome</h2>
        <p class="step-panel__lead">
            This wizard configures your database, records your company details and creates the first
            administrator account. It takes a couple of minutes and only runs once.
        </p>

        <ul class="requirements">
            <?php foreach ($requirements as $requirement): ?>
                <li class="<?= $requirement['pass'] ? 'is-pass' : 'is-fail' ?>">
                    <?= icon($requirement['pass'] ? 'check-big' : 'alert') ?>
                    <span><?= e($requirement['label']) ?></span>
                    <span><?= e($requirement['detail']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($blocked): ?>
            <div class="alert alert--warning" role="alert">
                <?= icon('alert') ?>
                <span>Fix the items marked above, then reload this page. Folder permissions usually need 775.</span>
            </div>
        <?php endif; ?>
    </section>

    <!-- Step 2 — Database -->
    <section class="step-panel" data-step="2">
        <h2 class="step-panel__title">Database connection</h2>
        <p class="step-panel__lead">
            Enter the MySQL details for this installation. On XAMPP the defaults below usually work;
            on a production server your host supplies them. The database must already exist.
        </p>

        <div class="field-row">
            <?php
            field_input('db_host', 'Database host', [
                'value' => 'localhost', 'required' => true, 'icon' => 'database', 'placeholder' => 'localhost',
            ]);
            field_input('db_port', 'Port', ['value' => '3306', 'placeholder' => '3306', 'inputmode' => 'numeric']);
            ?>
        </div>

        <?php
        field_input('db_name', 'Database name', [
            'required' => true, 'icon' => 'layers', 'placeholder' => 'swms',
        ]);
        ?>

        <div class="field-row">
            <?php
            field_input('db_user', 'Database username', [
                'required' => true, 'icon' => 'user', 'value' => 'root', 'autocomplete' => 'off',
            ]);
            field_input('db_pass', 'Database password', [
                'type' => 'password', 'icon' => 'lock', 'autocomplete' => 'new-password',
                'hint' => 'Leave blank if the account has no password.',
            ]);
            ?>
        </div>
    </section>

    <!-- Step 3 — Company -->
    <section class="step-panel" data-step="3">
        <h2 class="step-panel__title">Company information</h2>
        <p class="step-panel__lead">
            These details appear on the sign-in screen and throughout the system. You can change them later.
        </p>

        <?php
        field_input('company_name', 'Company name', [
            'required' => true, 'icon' => 'building', 'placeholder' => 'Your company name', 'maxlength' => 120,
        ]);

        field_uploader('company_logo', 'Company logo (optional)', 'PNG, JPG, GIF or WEBP up to 2 MB. A square or wide logo both work.');

        field_textarea('company_address', 'Address', ['placeholder' => 'Street, city, province', 'rows' => 2]);
        ?>

        <div class="field-row">
            <?php
            field_input('company_phone', 'Contact number', ['icon' => 'phone', 'placeholder' => '+92 300 0000000']);
            field_input('company_email', 'Contact email', ['type' => 'email', 'icon' => 'mail', 'placeholder' => 'info@company.com']);
            ?>
        </div>

        <?php field_input('company_website', 'Website', ['placeholder' => 'https://company.com']); ?>
    </section>

    <!-- Step 4 — Super Administrator -->
    <section class="step-panel" data-step="4">
        <h2 class="step-panel__title">Super Administrator</h2>
        <p class="step-panel__lead">
            This is the highest-level account in the system. It signs in with an email address and password,
            and it can create every other user from inside the ERP.
        </p>

        <?php
        field_input('admin_name', 'Full name', ['required' => true, 'icon' => 'user', 'maxlength' => 120]);
        field_input('admin_email', 'Email address', [
            'type' => 'email', 'required' => true, 'icon' => 'mail',
            'autocomplete' => 'off', 'hint' => 'Used as the sign-in identifier for this account.',
        ]);
        field_input('admin_password', 'Password', [
            'type' => 'password', 'required' => true, 'icon' => 'lock', 'autocomplete' => 'new-password',
        ]);
        field_strength();
        field_input('admin_password_confirm', 'Retype password', [
            'type' => 'password', 'required' => true, 'icon' => 'lock', 'autocomplete' => 'new-password',
        ]);
        field_uploader('admin_picture', 'Profile picture (optional)', 'PNG, JPG, GIF or WEBP up to 2 MB.', 'user');
        ?>
    </section>

    <!-- Step 5 — Finishing (the browser is redirected to complete.php) -->
    <section class="step-panel" data-step="5">
        <div class="install-done">
            <div class="install-done__icon"><?= icon('check-big') ?></div>
            <h2 class="step-panel__title">Setting things up</h2>
            <p class="step-panel__lead">Creating tables and saving your settings…</p>
        </div>
    </section>

    <div class="step-actions">
        <button type="button" class="btn" id="step-back" style="visibility:hidden">
            <span class="btn__label">Back</span>
        </button>
        <button type="button" class="btn btn--primary" id="step-next" <?= $blocked ? 'disabled' : '' ?>>
            <span class="spinner"></span>
            <span class="btn__label">Continue</span>
        </button>
    </div>
</div>

<p class="install-footer"><?= e(APP_PRODUCT_NAME) ?> · Version <?= e(APP_VERSION) ?></p>

<?php require INCLUDES_PATH . '/footer.php'; ?>
