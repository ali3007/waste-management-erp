<?php
/**
 * All Employees — search + filter + paginate.
 *
 * Every new employee lands in Unassigned until Employee Assignment gives
 * them a UC/Beat. Assigned/Unassigned is derived from employee_assignments
 * at query time, never a stored column, so it can't drift out of sync.
 * The filter/scope SQL is shared with Reports and CSV export
 * (lib/filters.php) so the list and its exports always agree.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.view');

$filters = hr_read_filters($_GET);
[$whereSql, $params] = hr_filter_sql($filters);

$page = max(1, (int)query('page', '1'));
$perPage = 25;

$total = (int)Database::fetchValue("SELECT COUNT(*) FROM employees e WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rows = Database::fetchAll(hr_employee_rows_sql($whereSql, 'e.name ASC, e.id ASC', "LIMIT {$perPage} OFFSET {$offset}"), $params);

$filterOptions = hr_filter_options($filters);
$filterAction = base_url('modules/hr/employees.php');

$pageTitle = 'Employees';
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <?php if (can('hr.reports_export')): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/report-export.php') . hr_filter_query($filters, ['type' => 'employees'])) ?>">
            <?= icon('download') ?><span class="btn__label">Export CSV</span>
        </a>
    <?php endif; ?>
    <?php if (can('hr.import')): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/import.php')) ?>">
            <?= icon('upload') ?><span class="btn__label">Bulk import (CSV)</span>
        </a>
    <?php endif; ?>
    <?php if (can('employee.create')): ?>
        <a class="btn btn--primary" href="<?= e(base_url('modules/hr/employee-create.php')) ?>">
            <?= icon('plus') ?><span class="btn__label">Add employee</span>
        </a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/filter-form.php'; ?>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('users') ?>
            <h3 style="margin-bottom:6px"><?= empty(hr_active_filters($filters)) ? 'No employees yet' : 'No employees match these filters' ?></h3>
            <p style="margin:0"><?= empty(hr_active_filters($filters)) ? 'Add the first one using the button above, or import a CSV.' : 'Try clearing a filter.' ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="table-meta">
        <span><?= number_format($total) ?> employee<?= $total === 1 ? '' : 's' ?> found</span>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>ID</th>
                    <th>CNIC</th>
                    <th>Staff Pool / Type</th>
                    <th>Registered area</th>
                    <th>Current assignment</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="is-wrap">
                            <strong><?= e((string)$row['name']) ?></strong>
                            <span class="cell-sub">s/o <?= e((string)$row['father_name']) ?><?= $row['phone'] ? ' · ' . e((string)$row['phone']) : '' ?></span>
                        </td>
                        <td><?= e((string)$row['employee_code']) ?></td>
                        <td><?= e(Security::formatCnic((string)$row['cnic'])) ?></td>
                        <td>
                            <?= e((string)$row['type_name']) ?>
                            <span class="cell-sub"><?= e((string)$row['pool_name']) ?></span>
                        </td>
                        <td>
                            <?= e((string)$row['tehsil_name']) ?>
                            <span class="cell-sub"><?= e((string)$row['district_name']) ?>, <?= e((string)$row['region_name']) ?></span>
                        </td>
                        <td class="is-wrap">
                            <?php if ($row['is_assigned']): ?>
                                <?= e((string)$row['assignment_text']) ?>
                            <?php elseif ($row['assignment_mode'] === 'none'): ?>
                                <span class="cell-sub">No assignment needed</span>
                            <?php else: ?>
                                <span class="badge badge--pending">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= e(hr_status_badge((string)$row['status'])) ?>"><?= e(hr_status_label((string)$row['status'])) ?></span></td>
                        <td>
                            <div class="cell-actions">
                                <a class="icon-btn" href="<?= e(base_url('modules/hr/employee-view.php?id=' . (int)$row['id'])) ?>" title="View profile" aria-label="View profile"><?= icon('search') ?></a>
                                <?php if (can('employee.update')): ?>
                                    <a class="icon-btn" href="<?= e(base_url('modules/hr/employee-edit.php?id=' . (int)$row['id'])) ?>" title="Edit" aria-label="Edit"><?= icon('pencil') ?></a>
                                <?php endif; ?>
                                <?php if (can('employee.delete')): ?>
                                    <form method="post" action="<?= e(base_url('modules/hr/process/employee-status.php')) ?>"
                                          data-confirm="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?> <?= e((string)$row['name']) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>">
                                        <button type="submit" class="icon-btn <?= $row['status'] === 'active' ? 'icon-btn--danger' : '' ?>"
                                                title="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
                                                aria-label="<?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                            <?= icon($row['status'] === 'active' ? 'ban' : 'check') ?>
                                        </button>
                                    </form>
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
            <?php
            /* Window of pages around the current one — a 5,000-employee
               company would otherwise print 200 page links. */
            $from = max(1, $page - 3);
            $to = min($totalPages, $page + 3);
            ?>
            <?php if ($from > 1): ?><a href="<?= e($filterAction . hr_filter_query($filters, ['page' => 1])) ?>">1</a><?php if ($from > 2): ?><span>…</span><?php endif; ?><?php endif; ?>
            <?php for ($p = $from; $p <= $to; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="is-current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e($filterAction . hr_filter_query($filters, ['page' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($to < $totalPages): ?><?php if ($to < $totalPages - 1): ?><span>…</span><?php endif; ?><a href="<?= e($filterAction . hr_filter_query($filters, ['page' => $totalPages])) ?>"><?= $totalPages ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
