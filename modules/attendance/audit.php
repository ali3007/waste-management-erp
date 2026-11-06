<?php
/**
 * Attendance Audit — read-only trail of every attendance action: marking,
 * refused attempts, scheduler transitions, reviews, corrections, leave, holidays,
 * timing and exports. Nothing here can be edited or deleted from the application.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
att_require('attendance.audit');
$ctx = att_ctx();

$action = preg_match('/^[a-z_]{1,40}$/', query('action')) ? query('action') : '';
$outcome = in_array(query('outcome'), ['success', 'blocked', 'failed'], true) ? query('outcome') : '';
$from = att_valid_date(query('from')) ?? '';
$to = att_valid_date(query('to')) ?? '';
$q = trim(query('q'));

$where = ["l.module = 'attendance'"];
$params = [];
if (($s = att_scope_sql($ctx, 'c.employee_id')) !== '') {
    $where[] = '(' . $s . ' OR c.employee_id IS NULL)';
}
if ($action !== '') { $where[] = 'l.action = :a'; $params['a'] = $action; }
if ($outcome !== '') { $where[] = 'c.outcome = :o'; $params['o'] = $outcome; }
if ($from !== '') { $where[] = 'DATE(l.created_at) >= :f'; $params['f'] = $from; }
if ($to !== '') { $where[] = 'DATE(l.created_at) <= :t'; $params['t'] = $to; }
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
    $where[] = '(e.name LIKE :q1 OR e.employee_code LIKE :q2 OR l.description LIKE :q3)';
    $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
}
$from_sql = 'FROM activity_log l LEFT JOIN attendance_audit_changes c ON c.activity_log_id = l.id LEFT JOIN employees e ON e.id = c.employee_id LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where);
$total = (int)Database::fetchValue('SELECT COUNT(*) ' . $from_sql, $params);
$pg = att_paginate($total, 40);
$rows = Database::fetchAll('SELECT l.id, l.action, l.description, l.created_at, l.ip_address, u.name AS user_name, c.outcome, c.attendance_date, c.reason, c.old_value, c.new_value, e.name AS employee_name, e.employee_code '
    . $from_sql . ' ORDER BY l.id DESC LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'], $params);
$actions = array_column(Database::fetchAll("SELECT DISTINCT action FROM activity_log WHERE module = 'attendance' ORDER BY action"), 'action');
$keep = ['action' => $action, 'outcome' => $outcome, 'from' => $from, 'to' => $to, 'q' => $q];

$pageTitle = 'Attendance Audit';
$layout = 'app';
$extraScripts = ['attendance.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form method="get" class="card filter-panel" style="margin-bottom:var(--space-4)">
    <div class="att-form-grid">
        <div class="field"><label class="field__label" for="q">Search</label><div class="control"><input class="input" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Employee or text"></div></div>
        <div class="field"><label class="field__label" for="action">Action</label><div class="control"><select class="select" id="action" name="action"><option value="">All actions</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $a)) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="field__label" for="outcome">Outcome</label><div class="control"><select class="select" id="outcome" name="outcome"><option value="">Any</option>
            <?php foreach (['success', 'blocked', 'failed'] as $o): ?><option value="<?= e($o) ?>" <?= $o === $outcome ? 'selected' : '' ?>><?= e(ucfirst($o)) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="field__label" for="from">From</label><div class="control"><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div></div>
        <div class="field"><label class="field__label" for="to">To</label><div class="control"><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div></div>
        <div><button class="btn btn--primary" type="submit">Filter</button> <a class="btn btn--ghost" href="<?= e(base_url('modules/attendance/audit.php')) ?>">Reset</a></div>
    </div>
</form>
<section class="card">
    <div class="table-meta"><span>Newest first</span><span><?= (int)$total ?> entries</span></div>
    <?php if (!$rows): ?><p class="att-empty">No audit entries match.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Action</th><th>Employee / date</th><th>By</th><th>Details</th><th>Outcome</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td><?= e(att_fmt_dt($r['created_at'])) ?></td><td><?= e(str_replace('_', ' ', $r['action'])) ?></td>
            <td class="is-wrap"><?= $r['employee_name'] ? e($r['employee_name']) . '<span class="cell-sub">' . e((string)$r['employee_code']) . ($r['attendance_date'] ? ' · ' . e($r['attendance_date']) : '') . '</span>' : '—' ?></td>
            <td><?= e((string)($r['user_name'] ?? 'System / scheduler')) ?><span class="cell-sub"><?= e((string)$r['ip_address']) ?></span></td>
            <td class="is-wrap"><?= e($r['description']) ?><?= $r['reason'] ? '<span class="cell-sub">Reason: ' . e($r['reason']) . '</span>' : '' ?>
                <?php if ($r['old_value'] || $r['new_value']): ?><span class="att-diff"><?= e(trim(($r['old_value'] ? 'before ' . $r['old_value'] : '') . ($r['new_value'] ? "\nafter  " . $r['new_value'] : ''))) ?></span><?php endif; ?></td>
            <td><?php if ($r['outcome']): ?><span class="badge <?= $r['outcome'] === 'success' ? 'badge--active' : 'badge--blocked' ?>"><?= e($r['outcome']) ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= att_pager($pg, base_url('modules/attendance/audit.php'), $keep) ?>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
