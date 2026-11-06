<?php
/**
 * Application navigation.
 *
 * Phase 1 ships only the items the authentication shell needs. A future
 * module registers itself in the $modules array (or, later, from the
 * `modules` table) without this file being restructured.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/* Folder + filename (e.g. "modules/structure/index.php"), not just the
   filename — two modules can both have an index.php, and a bare
   basename comparison would highlight both at once. */
$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$currentScript = implode('/', array_slice(explode('/', trim($scriptPath, '/')), -2));
$companyData = company();
$logo = company_logo_url();

/* The Administrator role has its own dashboard; every other role
   (Super Admin included) keeps the one this link always pointed to. */
$dashboardScript = 'admin/' . (Auth::isAdministrator() ? 'administrator-dashboard.php' : 'dashboard.php');
$dashboardHref = base_url($dashboardScript);

/** Transport menu entry (empty array when the user has no Transport permission). */
require_once MODULES_PATH . '/transport/nav.php';
function trn_nav_entry(): array
{
    $items = trn_nav_items();
    if (!$items) {
        return [];
    }
    $first = array_shift($items);
    return [[
        'label' => 'Transport', 'icon' => 'truck', 'href' => $first['href'], 'script' => $first['script'], 'permission' => null,
        'children' => array_map(static fn(array $i): array => $i, $items),
    ]];
}

/** Attendance menu entry (added in 1.11.0; empty when the user has no Attendance permission). */
require_once MODULES_PATH . '/attendance/nav.php';
function att_nav_entry(): array
{
    $items = att_nav_items();
    if (!$items) {
        return [];
    }
    $first = array_shift($items);
    return [[
        'label' => 'Attendance', 'icon' => 'clock', 'href' => $first['href'], 'script' => $first['script'], 'permission' => null,
        'children' => $items,
    ]];
}

/** Communication menu entry (added in 1.13.0; empty when the user has no Communication permission). */
require_once MODULES_PATH . '/comms/nav.php';
function comms_nav_entry(): array
{
    $items = comms_nav_items();
    if (!$items) {
        return [];
    }
    $first = array_shift($items);
    return [[
        'label' => 'Communication', 'icon' => 'mail', 'href' => $first['href'], 'script' => $first['script'], 'permission' => null,
        'children' => $items,
    ]];
}

/** Items available now. */
$navigation = [
    ['label' => 'Dashboard', 'icon' => 'grid', 'href' => $dashboardHref, 'script' => $dashboardScript, 'permission' => null],
    ['label' => 'Structure', 'icon' => 'layers', 'href' => base_url('modules/structure/index.php'), 'script' => 'structure/index.php', 'permission' => 'structure.view'],
    ['label' => 'HR Dashboard', 'icon' => 'chart', 'href' => base_url('modules/hr/dashboard.php'), 'script' => 'hr/dashboard.php', 'permission' => 'hr.dashboard'],
    [
        'label' => 'Employees', 'icon' => 'id-card', 'href' => base_url('modules/hr/employees.php'),
        'script' => 'hr/employees.php', 'permission' => 'employee.view',
        'children' => [
            ['label' => 'Add employee', 'href' => base_url('modules/hr/employee-create.php'), 'script' => 'hr/employee-create.php', 'permission' => 'employee.create'],
            ['label' => 'Unassigned', 'href' => base_url('modules/hr/unassigned.php'), 'script' => 'hr/unassigned.php', 'permission' => 'assignment.view'],
            ['label' => 'Assigned', 'href' => base_url('modules/hr/assigned.php'), 'script' => 'hr/assigned.php', 'permission' => 'assignment.view'],
            ['label' => 'Bulk import (CSV)', 'href' => base_url('modules/hr/import.php'), 'script' => 'hr/import.php', 'permission' => 'hr.import'],
            ['label' => 'HR Types', 'href' => base_url('modules/hr/types.php'), 'script' => 'hr/types.php', 'permission' => 'hrtype.view'],
            ['label' => 'Reports', 'href' => base_url('modules/hr/reports.php'), 'script' => 'hr/reports.php', 'permission' => 'hr.reports'],
        ],
    ],
    [
        'label' => 'Complaints', 'icon' => 'alert', 'href' => base_url('modules/complaints/index.php'),
        'script' => 'complaints/index.php', 'permission' => 'complaint.view',
        'children' => [
            ['label' => 'Add complaint', 'href' => base_url('modules/complaints/complaint-create.php'), 'script' => 'complaints/complaint-create.php', 'permission' => 'complaint.create'],
            ['label' => 'All complaints & reports', 'href' => base_url('modules/complaints/complaints.php'), 'script' => 'complaints/complaints.php', 'permission' => 'complaint.view'],
            ['label' => 'Notifications', 'href' => base_url('modules/complaints/notifications.php'), 'script' => 'complaints/notifications.php', 'permission' => 'complaint.view'],
            ['label' => 'PowerPoint templates', 'href' => base_url('modules/complaints/ppt-templates.php'), 'script' => 'complaints/ppt-templates.php', 'permission' => 'complaint.ppt_templates'],
            ['label' => 'Configuration', 'href' => base_url('modules/complaints/config.php'), 'script' => 'complaints/config.php', 'permission' => 'complaint.config'],
        ],
    ],
    /* Transport (added in 1.10.0): children come from modules/transport/nav.php
       and only include what the signed-in user may open. */
    ...trn_nav_entry(),
    ...att_nav_entry(),
    ...comms_nav_entry(),
    ['label' => 'Users', 'icon' => 'users', 'href' => base_url('modules/users/index.php'), 'script' => 'users/index.php', 'permission' => 'users.view'],
    ['label' => 'Roles & permissions', 'icon' => 'shield', 'href' => base_url('modules/users/roles.php'), 'script' => 'users/roles.php', 'permission' => 'roles.view'],
    ['label' => 'My account', 'icon' => 'user', 'href' => base_url('admin/profile.php'), 'script' => 'admin/profile.php', 'permission' => null],
];

/** Placeholders so the shell shows where later phases will plug in. */
$upcoming = [];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
        <?php if ($logo !== ''): ?>
            <img class="sidebar__brand-mark" src="<?= e($logo) ?>" alt="">
        <?php else: ?>
            <span class="sidebar__brand-mark"><?= icon('recycle') ?></span>
        <?php endif; ?>
        <span class="sidebar__brand-text">
            <?= e($companyData['name'] ?: APP_PRODUCT_NAME) ?>
            <small><?= e(APP_PRODUCT_SHORT) ?> <?= e(APP_VERSION) ?></small>
        </span>
    </div>

    <nav class="nav" aria-label="Main">
        <?php foreach ($navigation as $item): ?>
            <?php if ($item['permission'] !== null && !can($item['permission'])) { continue; } ?>
            <a class="nav__link <?= $currentScript === $item['script'] ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>">
                <?= icon($item['icon']) ?>
                <span><?= e($item['label']) ?></span>
            </a>
            <?php foreach ($item['children'] ?? [] as $child): ?>
                <?php if ($child['permission'] !== null && !can($child['permission'])) { continue; } ?>
                <a class="nav__link nav__link--sub <?= $currentScript === $child['script'] ? 'is-active' : '' ?>" href="<?= e($child['href']) ?>">
                    <span><?= e($child['label']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php if ($upcoming): ?><div class="nav__section">Coming in later phases</div><?php endif; ?>
        <?php foreach ($upcoming as $label): ?>
            <span class="nav__link is-locked">
                <?= icon('layers') ?>
                <span><?= e($label) ?></span>
                <span class="nav__badge">Soon</span>
            </span>
        <?php endforeach; ?>
    </nav>
</aside>
