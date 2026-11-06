<?php
/**
 * 403 page, shown when a signed-in user lacks a permission.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$pageTitle = 'Access denied';
$layout = 'auth';
require INCLUDES_PATH . '/header.php';
?>
<div class="auth-card auth-result">
    <div class="auth-result__icon"><?= icon('shield') ?></div>
    <h1 class="auth-card__title">You do not have access to this page</h1>
    <p class="auth-card__lead">Your role does not include this permission. Ask a system administrator if you need it.</p>
    <a class="btn btn--primary btn--block" href="<?= e(base_url('admin/dashboard.php')) ?>" style="margin-top:24px">
        <span class="btn__label">Back to dashboard</span>
    </a>
</div>
<?php require INCLUDES_PATH . '/footer.php'; ?>
