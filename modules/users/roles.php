<?php
/**
 * User Management — roles and permissions.
 *
 * Lists roles, lets a Super Admin create new ones (Supervisor, HR
 * Manager, Field Staff — anything the brief mentioned as "future roles"),
 * and edits what each role can do. The permission matrix is built from
 * whatever is in the `permissions` table, grouped by module, so every
 * later phase's permissions appear here automatically without this file
 * changing.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('roles.view');
users_load_module_permissions();   // list every module's permissions, even modules not opened yet

$editingId = (int)query('edit');
$editingRole = $editingId > 0 ? Database::fetch('SELECT * FROM roles WHERE id = :id', ['id' => $editingId]) : null;

if ($editingId > 0 && $editingRole === null) {
    flash('error', 'That role could not be found.');
    redirect('modules/users/roles.php');
}

if ($editingRole !== null && users_is_locked_role((string)$editingRole['slug'])) {
    flash('error', 'The Super Administrator role always holds every permission and cannot be edited.');
    redirect('modules/users/roles.php');
}

/* Roles list with a live count of assigned users. */
$roles = Database::fetchAll(
    'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
       FROM roles r ORDER BY r.level DESC, r.name ASC'
);

/* All known permissions, grouped by module, for the create/edit form. */
$allPermissions = Database::fetchAll('SELECT * FROM permissions ORDER BY module ASC, action ASC');
$grouped = [];
foreach ($allPermissions as $permission) {
    $grouped[$permission['module']][] = $permission;
}

$grantedSlugs = [];
if ($editingRole !== null) {
    $grantedSlugs = array_column(
        Database::fetchAll(
            'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :id',
            ['id' => (int)$editingRole['id']]
        ),
        'slug'
    );
}

$formName = old('name', (string)($editingRole['name'] ?? ''));
$formLevel = old('level', (string)($editingRole['level'] ?? '10'));
$formDescription = old('description', (string)($editingRole['description'] ?? ''));
clear_old();

$pageTitle = 'Roles & permissions';
$layout = 'app';
$extraStyles = ['users.css'];
$extraScripts = ['users.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="grid grid--2">
    <section class="card">
        <h3 class="card__title">Roles</h3>
        <p class="card__subtitle">Level decides seniority when two roles are compared; the Super Administrator sits above all of these at level 100.</p>

        <?php foreach ($roles as $role): ?>
            <div class="role-card">
                <div>
                    <div class="role-card__name"><?= e($role['name']) ?></div>
                    <div class="role-card__meta">
                        Level <?= (int)$role['level'] ?> · <?= (int)$role['user_count'] ?> <?= (int)$role['user_count'] === 1 ? 'user' : 'users' ?>
                        <?= $role['slug'] === Permission::ROLE_SUPER_ADMIN ? ' · Full access, always' : '' ?>
                    </div>
                </div>
                <div class="role-card__spacer"></div>
                <div class="role-card__actions">
                    <?php if ($role['slug'] !== Permission::ROLE_SUPER_ADMIN && can('roles.update')): ?>
                        <a class="icon-btn" href="<?= e(base_url('modules/users/roles.php?edit=' . (int)$role['id'])) ?>" title="Edit" aria-label="Edit <?= e($role['name']) ?>">
                            <?= icon('pencil') ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$role['is_system'] && (int)$role['user_count'] === 0 && can('roles.update')): ?>
                        <form method="post" action="<?= e(base_url('modules/users/process/role-delete.php')) ?>" data-confirm="Delete the &quot;<?= e($role['name']) ?>&quot; role? This cannot be undone.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$role['id'] ?>">
                            <button type="submit" class="icon-btn icon-btn--danger" title="Delete" aria-label="Delete <?= e($role['name']) ?>">
                                <?= icon('trash') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if (can('roles.update')): ?>
        <section class="card">
            <h3 class="card__title"><?= $editingRole !== null ? 'Edit ' . e($editingRole['name']) : 'Create a role' ?></h3>
            <p class="card__subtitle">
                <?= $editingRole !== null
                    ? 'Its name stays fixed; adjust what it can do below.'
                    : 'Add roles like Supervisor, HR Manager or Field Staff as later phases need them.' ?>
            </p>

            <form method="post" action="<?= e(base_url('modules/users/process/role-save.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <?php if ($editingRole !== null): ?>
                    <input type="hidden" name="id" value="<?= (int)$editingRole['id'] ?>">
                <?php endif; ?>

                <?php if ($editingRole === null): ?>
                    <?php field_input('name', 'Role name', ['required' => true, 'icon' => 'shield', 'value' => $formName, 'maxlength' => 80, 'attrs' => 'id="role_name"']); ?>
                    <p class="field__hint" style="margin-top:-12px;margin-bottom:16px">
                        Slug: <code id="role-slug-preview">role-slug</code>
                    </p>
                <?php else: ?>
                    <div class="field">
                        <span class="field__label">Role name</span>
                        <div class="control"><input class="input" value="<?= e($editingRole['name']) ?>" disabled></div>
                    </div>
                <?php endif; ?>

                <?php field_input('level', 'Level (1–99)', ['required' => true, 'icon' => 'layers', 'value' => $formLevel, 'inputmode' => 'numeric', 'hint' => 'Higher numbers outrank lower ones. 100 is reserved for Super Admin.']); ?>
                <?php field_textarea('description', 'Description', ['value' => $formDescription, 'rows' => 2]); ?>

                <div class="field">
                    <span class="field__label">Permissions</span>
                    <div class="matrix">
                        <?php foreach ($grouped as $module => $permissions): ?>
                            <div class="matrix__group">
                                <div class="matrix__head">
                                    <span><?= e(ucfirst($module)) ?></span>
                                    <label class="checkbox">
                                        <input type="checkbox" data-matrix-toggle>
                                        <span class="checkbox__box" aria-hidden="true"></span>
                                        <span>All</span>
                                    </label>
                                </div>
                                <div class="matrix__actions">
                                    <?php foreach ($permissions as $permission): ?>
                                        <label class="checkbox">
                                            <input type="checkbox" name="permissions[]" value="<?= e($permission['slug']) ?>" data-matrix-item
                                                <?= in_array($permission['slug'], $grantedSlugs, true) ? 'checked' : '' ?>>
                                            <span class="checkbox__box" aria-hidden="true"></span>
                                            <span><?= e($permission['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:flex; gap:10px; margin-top:20px">
                    <button type="submit" class="btn btn--primary">
                        <span class="btn__label"><?= $editingRole !== null ? 'Save role' : 'Create role' ?></span>
                    </button>
                    <?php if ($editingRole !== null): ?>
                        <a class="btn btn--ghost" href="<?= e(base_url('modules/users/roles.php')) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
