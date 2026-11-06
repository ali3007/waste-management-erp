<?php
/**
 * Page head and opening markup.
 *
 * $layout = 'auth'    centred card (login, installer, activation)
 * $layout = 'app'     sidebar + top bar shell (protected pages)
 *
 * Set $pageTitle, $layout and optionally $bodyClass before including.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

require_once INCLUDES_PATH . '/components/icons.php';
require_once INCLUDES_PATH . '/components/field.php';

Security::sendSecurityHeaders();

$layout       = $layout ?? 'auth';
$pageTitle    = $pageTitle ?? APP_PRODUCT_NAME;
$bodyClass    = $bodyClass ?? '';
$companyData  = company();
$companyName  = $companyData['name'] !== '' ? $companyData['name'] : APP_PRODUCT_NAME;
$extraStyles  = $extraStyles ?? [];
?>
<!DOCTYPE html>
<html lang="en" class="theme-preload">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> · <?= e($companyName) ?></title>

    <link rel="icon" href="<?= e(asset('icons/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/themes.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($extraStyles as $styleFile): ?>
        <link rel="stylesheet" href="<?= e(asset('css/' . $styleFile)) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">

    <script>
        /* Applies the stored theme before first paint so the page never
           flashes the wrong colours. Full behaviour lives in theme.js. */
        (function () {
            try {
                var stored = window.localStorage.getItem('swms.theme');
                if (stored === 'light' || stored === 'dark') {
                    document.documentElement.setAttribute('data-theme', stored);
                }
            } catch (e) {}
        })();
    </script>
</head>
<body class="<?= e($bodyClass) ?>">

<?php if ($layout === 'app'): ?>
    <div class="sidebar-backdrop"></div>
    <div class="shell">
        <?php require INCLUDES_PATH . '/sidebar.php'; ?>
        <div class="main">
            <header class="topbar">
                <button type="button" class="sidebar-toggle" aria-expanded="false" aria-label="Open navigation">
                    <?= icon('menu') ?>
                </button>
                <h1 class="topbar__title"><?= e($pageTitle) ?></h1>
                <div class="topbar__spacer"></div>
                <div class="topbar__actions">
                    <?php if (can('complaint.view')): ?>
                        <link rel="stylesheet" href="<?= e(asset('css/notifications.css')) ?>">
                        <div class="notif-bell" data-notif-bell data-url="<?= e(base_url('modules/complaints/process/notifications.php')) ?>" data-csrf="<?= e(Security::csrfToken()) ?>">
                            <button type="button" class="icon-btn" aria-haspopup="true" aria-expanded="false" aria-label="Complaint notifications" title="Notifications">
                                <?= icon('bell') ?><span class="notif-bell__count" hidden>0</span>
                            </button>
                            <div class="notif-bell__panel" hidden>
                                <div class="notif-bell__head"><strong>Notifications</strong><button type="button" data-notif-all>Mark all read</button></div>
                                <div class="notif-bell__list" data-notif-list><p class="notif-bell__empty">Loading…</p></div>
                                <a class="notif-bell__foot" href="<?= e(base_url('modules/complaints/notifications.php')) ?>">View all notifications</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php require INCLUDES_PATH . '/components/theme-toggle.php'; ?>
                    <?php
                    /* Header-local names: this file is included INTO the page's scope, so a variable called
                       $avatar/$currentUser here silently overwrote the page's own (every user's Edit/View
                       page then showed the signed-in user's picture). */
                    $headerUser = Auth::user();
                    $headerAvatar = avatar_url($headerUser['profile_picture'] ?? null);
                    ?>
                    <div class="user-chip">
                        <?php if ($headerAvatar !== ''): ?>
                            <img class="user-chip__avatar" src="<?= e($headerAvatar) ?>" alt="">
                        <?php else: ?>
                            <span class="user-chip__avatar"><?= e(initials((string)($headerUser['name'] ?? '?'))) ?></span>
                        <?php endif; ?>
                        <span class="user-chip__meta">
                            <strong><?= e((string)($headerUser['name'] ?? '')) ?></strong>
                            <span><?= e((string)($headerUser['role_name'] ?? 'User')) ?></span>
                        </span>
                    </div>
                    <a class="icon-btn icon-btn--danger" href="<?= e(base_url('auth/logout.php')) ?>" title="Sign out" aria-label="Sign out">
                        <?= icon('logout') ?>
                    </a>
                </div>
            </header>
            <main class="content">
<?php else: ?>
    <div class="auth-page__toolbar">
        <?php require INCLUDES_PATH . '/components/theme-toggle.php'; ?>
    </div>
    <main class="<?= $layout === 'install' ? 'install-page' : 'auth-page' ?>">
<?php endif; ?>
