<?php
/**
 * Unassigned Employees.
 *
 * Every new (or imported) employee lands here automatically. Types whose
 * assignment rule is "none" (typically Office Staff) still appear, per the
 * spec's literal instruction — they just get no Assign button, since
 * there's nothing to assign them to.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('assignment.view');

$filters = hr_read_filters($_GET);
$filters['assign'] = 'unassigned';   // this screen is always the unassigned slice
$filters['uc'] = 0;
$filters['beat'] = 0;
[$whereSql, $params] = hr_filter_sql($filters);

$page = max(1, (int)query('page', '1'));
$perPage = 25;

$total = (int)Database::fetchValue("SELECT COUNT(*) FROM employees e WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rows = Database::fetchAll(hr_employee_rows_sql($whereSql, 'e.created_at DESC, e.id DESC', "LIMIT {$perPage} OFFSET {$offset}"), $params);

$filterOptions = hr_filter_options($filters);
$filterAction = base_url('modules/hr/unassigned.php');
$filterHideAssign = true;
$filterHideUcBeat = true;
$filterHelp = 'Region, District and Tehsil match where the employee is registered.';

$pageTitle = 'Unassigned Employees';
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/assigned.php')) ?>"><span class="btn__label">View Assigned →</span></a>
</div>

<?php require __DIR__ . '/partials/filter-form.php'; ?>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('check-big') ?>
            <h3 style="margin-bottom:6px">Nothing unassigned</h3>
            <p style="margin:0">Every employee matching these filters currently has a UC/Beat.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-meta">
        <span><?= number_format($total) ?> unassigned employee<?= $total === 1 ? '' : 's' ?></span>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Employee</th><th>CNIC</th><th>Staff Type</th><th>Region</th><th>District</th><th>Tehsil</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="is-wrap">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <span class="cell-sub"><?= e((string)$row['employee_code']) ?></span>
                        </td>
                        <td><?= e(Security::formatCnic((string)$row['cnic'])) ?></td>
                        <td><?= e((string)$row['type_name']) ?><span class="cell-sub"><?= e((string)$row['pool_name']) ?></span></td>
                        <td><?= e((string)$row['region_name']) ?></td>
                        <td><?= e((string)$row['district_name']) ?></td>
                        <td><?= e((string)$row['tehsil_name']) ?></td>
                        <td>
                            <?php if ($row['assignment_mode'] === 'none'): ?>
                                <span class="badge badge--inactive">No assignment needed</span>
                            <?php else: ?>
                                <span class="badge badge--pending">Unassigned</span>
                            <?php endif; ?>
                            <?php if ($row['status'] === 'inactive'): ?><span class="badge badge--inactive">Inactive</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <a class="icon-btn" href="<?= e(base_url('modules/hr/employee-view.php?id=' . (int)$row['id'])) ?>" title="View profile" aria-label="View profile"><?= icon('search') ?></a>
                                <?php if ($row['assignment_mode'] !== 'none' && $row['status'] === 'active' && can('assignment.create')): ?>
                                    <a class="btn btn--primary" href="<?= e(base_url('modules/hr/employee-assign.php?id=' . (int)$row['id'])) ?>"><span class="btn__label">Assign</span></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                <?php if ($p === $page): ?><span class="is-current"><?= $p ?></span>
                <?php else: ?><a href="<?= e($filterAction . hr_filter_query($filters, ['page' => $p, 'assign' => ''])) ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
