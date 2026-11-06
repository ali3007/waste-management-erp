<?php
/** Transport Audit Trail — who changed what, when, with old/new values and reasons. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.audit');

$q = Security::cleanText(query('q'), 100);
$action = Security::cleanText(query('action'), 60);
$from = trn_valid_date(query('date_from')) ?? '';
$to = trn_valid_date(query('date_to')) ?? '';
$userId = (int)query('user');

$where = ["a.module = 'transport'"];
$params = [];
/* Area-restricted users only see activity on vehicles inside their area. */
$scope = trn_scope_sql('v');
if ($scope !== '') {
    $where[] = '(c.vehicle_id IS NOT NULL AND EXISTS (SELECT 1 FROM vehicles v WHERE v.id = c.vehicle_id AND ' . $scope . '))';
}
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where[] = '(a.description LIKE :q OR c.reason LIKE :q2)';
    $params += ['q' => $like, 'q2' => $like];
}
if ($action !== '') { $where[] = 'a.action = :ac'; $params['ac'] = $action; }
if ($userId > 0) { $where[] = 'a.user_id = :u'; $params['u'] = $userId; }
if ($from !== '') { $where[] = 'a.created_at >= :f'; $params['f'] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'a.created_at <= :t'; $params['t'] = $to . ' 23:59:59'; }

$base = 'FROM activity_log a LEFT JOIN transport_audit_changes c ON c.activity_log_id = a.id LEFT JOIN users u ON u.id = a.user_id LEFT JOIN vehicles vv ON vv.id = c.vehicle_id WHERE ' . implode(' AND ', $where);
$total = (int)Database::fetchValue("SELECT COUNT(*) {$base}", $params);
$pg = trn_paginate($total, 30);
$rows = Database::fetchAll("SELECT a.id, a.action, a.description, a.created_at, a.ip_address, u.name AS user_name, c.old_value, c.new_value, c.reason, vv.id AS vid, vv.registration_no {$base} ORDER BY a.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$actions = Database::fetchAll("SELECT DISTINCT action FROM activity_log WHERE module = 'transport' ORDER BY action");
$users = Database::fetchAll("SELECT DISTINCT u.id, u.name FROM activity_log a JOIN users u ON u.id = a.user_id WHERE a.module = 'transport' ORDER BY u.name LIMIT 300");
$self = base_url('modules/transport/audit.php');
$qsBase = ['q' => $q, 'action' => $action, 'user' => $userId, 'date_from' => $from, 'date_to' => $to];

$pageTitle = 'Transport Audit Trail';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form class="card filter-panel" method="get" action="<?= e($self) ?>">
    <div class="filter-panel__grid">
        <div class="field filter-panel__search"><label class="field__label" for="a_q">Search</label>
            <div class="control"><span class="control__icon"><?= icon('search') ?></span><input class="input" type="search" id="a_q" name="q" value="<?= e($q) ?>" placeholder="Description or reason…"></div></div>
        <div class="field"><label class="field__label" for="a_action">Action</label><div class="control"><select class="select" id="a_action" name="action" data-autosubmit>
            <option value="">All actions</option><?php foreach ($actions as $a): ?><option value="<?= e((string)$a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>><?= e((string)$a['action']) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="field__label" for="a_user">User</label><div class="control"><select class="select" id="a_user" name="user" data-autosubmit>
            <option value="">Anyone</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="field__label" for="a_from">From</label><div class="control"><input class="input" type="date" id="a_from" name="date_from" value="<?= e($from) ?>"></div></div>
        <div class="field"><label class="field__label" for="a_to">To</label><div class="control"><input class="input" type="date" id="a_to" name="date_to" value="<?= e($to) ?>"></div></div>
    </div>
    <div class="filter-panel__actions"><button type="submit" class="btn btn--primary"><?= icon('search') ?><span class="btn__label">Apply filters</span></button>
        <?php if (array_filter($qsBase)): ?><a class="btn btn--ghost" href="<?= e($self) ?>"><span class="btn__label">Clear all</span></a><?php endif; ?></div>
</form>
<?php if (!$rows): ?><div class="card"><div class="empty-state"><?= icon('clock') ?><h3 style="margin-bottom:6px">No activity found</h3></div></div><?php else: ?>
<div class="table-meta"><span><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
<div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
    <tr><td><?= e(format_datetime((string)$r['created_at'], 'd M Y, H:i')) ?></td><td><?= e((string)($r['user_name'] ?: 'System')) ?><span class="cell-sub"><?= e((string)$r['ip_address']) ?></span></td>
        <td><span class="badge badge--inactive"><?= e((string)$r['action']) ?></span></td>
        <td class="is-wrap"><?= e((string)$r['description']) ?>
            <?php if ($r['vid'] && can('transport.details')): ?> <a href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$r['vid'])) ?>"><?= e((string)$r['registration_no']) ?></a><?php endif; ?>
            <?php if ($r['reason']): ?><div class="trn-diff">Reason: <?= e((string)$r['reason']) ?></div><?php endif; ?>
            <?php if ($r['old_value'] || $r['new_value']): ?><div class="trn-diff"><?= e(trn_diff_text($r['old_value'], $r['new_value'])) ?></div><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php trn_pagination($pg, static fn(int $p): string => $self . trn_qs($qsBase + ['page' => $p])); endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
