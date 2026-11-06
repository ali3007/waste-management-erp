<?php
/**
 * User Management — list, search and filter.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('users.view');

/* ------------------------------------------------------------------ *
 | Filters                                                             |
 * ------------------------------------------------------------------ */
$search = Security::cleanText(query('q'), 190);
$roleFilter = query('role');
$statusFilter = query('status');
$page = max(1, (int)query('page', '1'));
$perPage = 20;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.cnic LIKE :search_digits)';
    $params['search'] = '%' . $search . '%';
    $params['search_digits'] = '%' . Security::normalizeCnic($search) . '%';
}

if ($roleFilter !== '' && ctype_digit($roleFilter)) {
    $where[] = 'u.role_id = :role_id';
    $params['role_id'] = (int)$roleFilter;
}

$validStatuses = [Auth::STATUS_ACTIVE, Auth::STATUS_PENDING, Auth::STATUS_INACTIVE, Auth::STATUS_BLOCKED];
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$total = (int)Database::fetchValue("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$users = Database::fetchAll(
    "SELECT u.*, r.name AS role_name, r.slug AS role_slug
       FROM users u
       LEFT JOIN roles r ON r.id = u.role_id
      WHERE {$whereSql}
      ORDER BY u.created_at DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$roles = Permission::roles();

/** Preserve the current filters across pagination and sort links. */
function users_query_link(array $overrides): string
{
    $params = array_filter(array_merge([
        'q' => query('q'),
        'role' => query('role'),
        'status' => query('status'),
        'page' => query('page'),
    ], $overrides), static fn($v) => $v !== '' && $v !== null);

    return base_url('modules/users/index.php') . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = 'Users';
$layout = 'app';
$extraStyles = ['users.css'];
$extraScripts = ['users.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar">
    <form class="toolbar__search" method="get" action="<?= e(base_url('modules/users/index.php')) ?>">
        <div class="control">
            <span class="control__icon"><?= icon('search') ?></span>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name, email or CNIC">
        </div>
        <?php if ($roleFilter !== ''): ?><input type="hidden" name="role" value="<?= e($roleFilter) ?>"><?php endif; ?>
        <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
    </form>

    <div class="toolbar__filter">
        <div class="control">
            <select class="select" onchange="window.location.href=this.value">
                <option value="<?= e(users_query_link(['role' => ''])) ?>">All roles</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e(users_query_link(['role' => (string)$role['id']])) ?>" <?= $roleFilter === (string)$role['id'] ? 'selected' : '' ?>>
                        <?= e($role['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="toolbar__filter">
        <div class="control">
            <select class="select" onchange="window.location.href=this.value">
                <option value="<?= e(users_query_link(['status' => ''])) ?>">All statuses</option>
                <?php foreach ($validStatuses as $status): ?>
                    <option value="<?= e(users_query_link(['status' => $status])) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= e(users_status_label($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="toolbar__spacer"></div>

    <?php if (can('users.create')): ?>
        <a class="btn btn--primary" href="<?= e(base_url('modules/users/create.php')) ?>">
            <?= icon('plus') ?>
            <span class="btn__label">Add user</span>
        </a>
    <?php endif; ?>
</div>

<?php if (empty($users)): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('users') ?>
            <h3 style="margin-bottom:6px"><?= $total === 0 && $search === '' && $roleFilter === '' && $statusFilter === '' ? 'No users yet' : 'No matching users' ?></h3>
            <p style="margin:0">
                <?= $total === 0 && $search === '' && $roleFilter === '' && $statusFilter === ''
                    ? 'Add the first account — an administrator or a member of your field staff.'
                    : 'Try a different search term or clear the filters.' ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list">
        <?php foreach ($users as $row): ?>
            <?php
            $avatar = avatar_url($row['profile_picture'] ?? null);
            $identifier = $row['login_type'] === Auth::LOGIN_EMAIL
                ? (string)$row['email']
                : Security::formatCnic((string)$row['cnic']);
            ?>
            <div class="data-row">
                <div class="data-row__person">
                    <?php if ($avatar !== ''): ?>
                        <img class="data-row__avatar" src="<?= e($avatar) ?>" alt="">
                    <?php else: ?>
                        <span class="data-row__avatar"><?= e(initials((string)$row['name'])) ?></span>
                    <?php endif; ?>
                    <div class="data-row__name">
                        <strong><?= e((string)$row['name']) ?></strong>
                        <span><?= e($identifier) ?></span>
                    </div>
                </div>

                <div class="data-row__meta">
                    <span class="badge"><?= e((string)($row['role_name'] ?? 'No role')) ?></span>
                </div>

                <div class="data-row__meta">
                    <span class="badge <?= e(users_status_badge((string)$row['status'])) ?>"><?= e(users_status_label((string)$row['status'])) ?></span>
                </div>

                <div class="data-row__meta">
                    <?= e(format_datetime($row['last_login_at'] ?? null, 'd M Y')) ?>
                </div>

                <div class="data-row__actions">
                    <a class="icon-btn" href="<?= e(base_url('modules/users/view.php?id=' . (int)$row['id'])) ?>" title="View" aria-label="View <?= e((string)$row['name']) ?>">
                        <?= icon('search') ?>
                    </a>
                    <?php if (can('users.update') && !users_is_locked_role($row['role_slug'] ?? null)): ?>
                        <a class="icon-btn" href="<?= e(base_url('modules/users/edit.php?id=' . (int)$row['id'])) ?>" title="Edit" aria-label="Edit <?= e((string)$row['name']) ?>">
                            <?= icon('pencil') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="is-current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(users_query_link(['page' => (string)$p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
