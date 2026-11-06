<?php
/**
 * Assigned Employees — one row per currently-open assignment, not per
 * employee. A 'multi' staff type (e.g. Monitoring Officer with several
 * UCs) naturally appears once per UC, which is what makes "who covers
 * UC 15" answerable directly from this list.
 *
 * Region / District / Tehsil here are the ASSIGNMENT's chain (read from
 * the UC the employee works in), which is what the spec's Assigned list
 * asks to see. Area-based access control is applied in the query itself.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('assignment.view');

$filters = hr_read_filters($_GET);
$filters['assign'] = '';

$where = ['a.unassigned_at IS NULL'];
$params = [];

if ($filters['q'] !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
    $or = ['e.name LIKE :q1', 'e.employee_code LIKE :q2', 'e.phone LIKE :q3', 'u.name LIKE :q4', 'b.name LIKE :q5'];
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
    $digits = Security::normalizeCnic($filters['q']);
    if ($digits !== '') {
        $or[] = 'e.cnic LIKE :q6';
        $params['q6'] = '%' . $digits . '%';
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
$map = ['pool' => 'e.staff_pool_id', 'type' => 'e.staff_type_id', 'region' => 'rg.id', 'district' => 'ds.id', 'tehsil' => 'th.id', 'uc' => 'a.uc_id', 'beat' => 'a.beat_id'];
foreach ($map as $key => $column) {
    if ($filters[$key] > 0) {
        $where[] = $column . ' = :f_' . $key;
        $params['f_' . $key] = $filters[$key];
    }
}
if ($filters['status'] !== '') {
    $where[] = 'e.status = :f_status';
    $params['f_status'] = $filters['status'];
}

/* Area-based access control: a scoped user sees only rows for their own UCs. */
$accessibleUcIds = hr_accessible_uc_ids();
if ($accessibleUcIds !== null) {
    $where[] = empty($accessibleUcIds) ? '1 = 0' : 'a.uc_id IN (' . implode(',', array_map('intval', $accessibleUcIds)) . ')';
}
$whereSql = implode(' AND ', $where);

$from = 'FROM employee_assignments a
         JOIN employees e ON e.id = a.employee_id
         JOIN hr_staff_pools sp ON sp.id = e.staff_pool_id
         JOIN hr_staff_types st ON st.id = e.staff_type_id
         JOIN ucs u ON u.id = a.uc_id
         JOIN tehsils th ON th.id = u.tehsil_id
         JOIN districts ds ON ds.id = th.district_id
         JOIN regions rg ON rg.id = ds.region_id
         LEFT JOIN beats b ON b.id = a.beat_id';

$page = max(1, (int)query('page', '1'));
$perPage = 25;
$total = (int)Database::fetchValue("SELECT COUNT(*) {$from} WHERE {$whereSql}", $params);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rows = Database::fetchAll(
    "SELECT a.id, a.employee_id, a.assigned_at, e.name AS employee_name, e.employee_code, e.cnic, e.status AS employee_status,
            sp.name AS pool_name, st.name AS type_name, st.assignment_mode,
            rg.name AS region_name, ds.name AS district_name, th.name AS tehsil_name, u.name AS uc_name, b.name AS beat_name
       {$from} WHERE {$whereSql}
      ORDER BY e.name ASC, u.name ASC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$filterOptions = hr_filter_options($filters);
$filterAction = base_url('modules/hr/assigned.php');
$filterHideAssign = true;
$filterHelp = 'Region, District and Tehsil here are the area of the UC the employee is assigned to.';

$pageTitle = 'Assigned Employees';
$layout = 'app';
$extraScripts = ['hr.js'];

require INCLUDES_PATH . '/header.php';
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="toolbar">
    <div class="toolbar__spacer" style="margin-left:0"></div>
    <a class="btn btn--ghost" href="<?= e(base_url('modules/hr/unassigned.php')) ?>"><span class="btn__label">View Unassigned →</span></a>
</div>

<?php require __DIR__ . '/partials/filter-form.php'; ?>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state">
            <?= icon('layers') ?>
            <h3 style="margin-bottom:6px">No assignments found</h3>
            <p style="margin:0"><?= empty(hr_active_filters($filters)) ? 'Assign someone from Unassigned Employees to see them here.' : 'Try clearing a filter.' ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="table-meta">
        <span><?= number_format($total) ?> active assignment<?= $total === 1 ? '' : 's' ?></span>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Employee</th><th>ID</th><th>CNIC</th><th>Pool</th><th>Type</th>
                    <th>Region</th><th>District</th><th>Tehsil</th><th>UC</th><th>Beat</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="is-wrap"><strong><?= e((string)$row['employee_name']) ?></strong>
                            <span class="cell-sub">Since <?= e(format_datetime((string)$row['assigned_at'], 'd M Y')) ?></span></td>
                        <td><?= e((string)$row['employee_code']) ?></td>
                        <td><?= e(Security::formatCnic((string)$row['cnic'])) ?></td>
                        <td><?= e((string)$row['pool_name']) ?></td>
                        <td><?= e((string)$row['type_name']) ?></td>
                        <td><?= e((string)$row['region_name']) ?></td>
                        <td><?= e((string)$row['district_name']) ?></td>
                        <td><?= e((string)$row['tehsil_name']) ?></td>
                        <td><?= e((string)$row['uc_name']) ?></td>
                        <td><?= $row['beat_name'] ? e((string)$row['beat_name']) : '—' ?></td>
                        <td><span class="badge <?= e(hr_status_badge((string)$row['employee_status'])) ?>"><?= e(hr_status_label((string)$row['employee_status'])) ?></span></td>
                        <td>
                            <div class="cell-actions">
                                <a class="icon-btn" href="<?= e(base_url('modules/hr/employee-view.php?id=' . (int)$row['employee_id'])) ?>" title="View profile" aria-label="View profile"><?= icon('search') ?></a>
                                <?php if (can('assignment.update')): ?>
                                    <a class="btn" href="<?= e(base_url('modules/hr/employee-assign.php?id=' . (int)$row['employee_id'])) ?>">
                                        <span class="btn__label"><?= $row['assignment_mode'] === 'multi' ? 'Manage' : 'Reassign' ?></span>
                                    </a>
                                    <form method="post" action="<?= e(base_url('modules/hr/process/assignment-remove.php')) ?>"
                                          data-confirm="Remove <?= e((string)$row['employee_name']) ?> from <?= e((string)$row['uc_name']) ?>? They will move back to Unassigned if this was their only assignment.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="assignment_id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Remove assignment" aria-label="Remove assignment"><?= icon('ban') ?></button>
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
            <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                <?php if ($p === $page): ?><span class="is-current"><?= $p ?></span>
                <?php else: ?><a href="<?= e($filterAction . hr_filter_query($filters, ['page' => $p])) ?>"><?= $p ?></a><?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
