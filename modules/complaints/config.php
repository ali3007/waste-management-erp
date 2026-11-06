<?php
/**
 * Complaints → Configuration.
 *
 * One page, several tabs. Nothing here is pre-filled with business data:
 * Complaint Sources, Types and Priorities start EMPTY and are created by an
 * authorized user, as are SLA timings and which HR staff types count as
 * Supervisor / Monitoring Officer.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.config');

$tabs = [
    'sources' => 'Complaint Sources', 'types' => 'Complaint Types', 'priorities' => 'Priorities',
    'sla' => 'SLA Rules', 'statuses' => 'Status labels', 'settings' => 'Settings',
];
$tab = array_key_exists(query('tab'), $tabs) ? query('tab') : 'sources';
$editId = (int)query('edit');

$lookupTables = ['sources' => 'complaint_sources', 'types' => 'complaint_types', 'priorities' => 'complaint_priorities'];
$singular = ['sources' => 'source', 'types' => 'type', 'priorities' => 'priority'];

$rows = [];
$editRow = null;
if (isset($lookupTables[$tab])) {
    $table = $lookupTables[$tab];
    $fk = ['sources' => 'source_id', 'types' => 'type_id', 'priorities' => 'priority_id'][$tab];
    $rows = Database::fetchAll(
        "SELECT x.*, (SELECT COUNT(*) FROM complaints c WHERE c.{$fk} = x.id) AS used_count FROM {$table} x ORDER BY x.sort_order ASC, x.name ASC"
    );
    if ($editId > 0) {
        foreach ($rows as $r) {
            if ((int)$r['id'] === $editId) {
                $editRow = $r;
            }
        }
    }
}

$pageTitle = 'Complaint configuration';
$layout = 'app';

require INCLUDES_PATH . '/header.php';
$post = base_url('modules/complaints/process/config-save.php');
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<nav class="cmp-tabs" aria-label="Configuration sections">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="cmp-tab <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(base_url('modules/complaints/config.php?tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (isset($lookupTables[$tab])): ?>
    <?php $one = $singular[$tab]; ?>
    <div class="grid grid--2 cmp-config-grid">
        <section class="card">
            <h2 class="card__title"><?= $editRow ? 'Edit ' . e($one) : 'Add ' . e($one) ?></h2>
            <form method="post" action="<?= e($post) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="entity" value="<?= e($tab) ?>">
                <input type="hidden" name="op" value="save">
                <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
                <div class="field">
                    <label class="field__label" for="name">Name <span class="field__required">*</span></label>
                    <div class="control"><input class="input" id="name" name="name" maxlength="120" required value="<?= e((string)($editRow['name'] ?? '')) ?>"></div>
                </div>
                <div class="field">
                    <label class="field__label" for="description">Description</label>
                    <div class="control"><input class="input" id="description" name="description" maxlength="255" value="<?= e((string)($editRow['description'] ?? '')) ?>"></div>
                </div>
                <?php if ($tab === 'priorities'): ?>
                    <div class="field">
                        <label class="field__label" for="color">Colour</label>
                        <input class="cmp-color" type="color" id="color" name="color" value="<?= e((string)($editRow['color'] ?? '#17a06a')) ?>">
                        <p class="field__hint">Used on badges and charts.</p>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label class="field__label" for="sort_order">Display order</label>
                    <div class="control"><input class="input" type="number" id="sort_order" name="sort_order" min="0" max="9999" value="<?= (int)($editRow['sort_order'] ?? 0) ?>"></div>
                    <p class="field__hint">Lower numbers appear first in dropdowns.</p>
                </div>
                <?php if ($editRow): ?>
                    <div class="field">
                        <label class="field__label" for="status">Status</label>
                        <div class="control">
                            <select class="select" id="status" name="status">
                                <option value="active" <?= $editRow['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $editRow['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="toolbar" style="margin:12px 0 0">
                    <button type="submit" class="btn btn--primary"><?= icon('check-big') ?><span class="btn__label"><?= $editRow ? 'Save changes' : 'Add ' . e($one) ?></span></button>
                    <?php if ($editRow): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/complaints/config.php?tab=' . $tab)) ?>"><span class="btn__label">Cancel</span></a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="card">
            <h2 class="card__title"><?= e($tabs[$tab]) ?> (<?= count($rows) ?>)</h2>
            <?php if (!$rows): ?>
                <div class="empty-state"><?= icon('tag') ?><h3 style="margin-bottom:6px">None yet</h3>
                    <p style="margin:0">Add the first <?= e($one) ?> using the form. Complaints can't be created until at least one exists.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Name</th><th>Used</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="is-wrap">
                                        <?php if ($tab === 'priorities'): ?><?= cmp_priority_badge((string)$r['name'], (string)$r['color']) ?><?php else: ?><strong><?= e((string)$r['name']) ?></strong><?php endif; ?>
                                        <?php if ($r['description']): ?><span class="cell-sub"><?= e((string)$r['description']) ?></span><?php endif; ?>
                                    </td>
                                    <td><?= number_format((int)$r['used_count']) ?> complaint<?= (int)$r['used_count'] === 1 ? '' : 's' ?></td>
                                    <td><span class="badge <?= $r['status'] === 'active' ? 'badge--active' : 'badge--inactive' ?>"><?= $r['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
                                    <td>
                                        <div class="cell-actions">
                                            <a class="icon-btn" href="<?= e(base_url('modules/complaints/config.php?tab=' . $tab . '&edit=' . (int)$r['id'])) ?>" title="Edit" aria-label="Edit"><?= icon('pencil') ?></a>
                                            <form method="post" action="<?= e($post) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="entity" value="<?= e($tab) ?>"><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="icon-btn <?= $r['status'] === 'active' ? 'icon-btn--danger' : '' ?>" title="<?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?>" aria-label="Toggle"><?= icon($r['status'] === 'active' ? 'ban' : 'check') ?></button>
                                            </form>
                                            <form method="post" action="<?= e($post) ?>" data-confirm="Delete '<?= e((string)$r['name']) ?>'? <?= (int)$r['used_count'] > 0 ? 'It is used by complaints, so this will be refused — deactivate it instead.' : 'This cannot be undone.' ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="entity" value="<?= e($tab) ?>"><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="icon-btn icon-btn--danger" title="Delete" aria-label="Delete" <?= (int)$r['used_count'] > 0 ? 'disabled' : '' ?>><?= icon('trash') ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="filter-hint">Items used by complaints can't be deleted (history is preserved) — deactivate them to hide them from new complaints.</p>
            <?php endif; ?>
        </section>
    </div>

<?php elseif ($tab === 'sla'): ?>
    <?php
    $priorities = Database::fetchAll(
        "SELECT p.id, p.name, p.color, p.status AS pstatus, r.due_hours, r.warning_percent, r.start_event, r.status AS rstatus
           FROM complaint_priorities p LEFT JOIN complaint_sla_rules r ON r.priority_id = p.id ORDER BY p.sort_order ASC, p.name ASC"
    );
    ?>
    <section class="card">
        <h2 class="card__title">SLA rules by priority</h2>
        <p class="card__subtitle">An SLA rule says how many hours a complaint of that priority may stay open. The clock starts at the complaint's real creation time or its real assignment time, and a reopened complaint gets a fresh clock. A priority with no rule simply has no SLA. Saving a rule re-calculates open complaints of that priority.</p>
        <?php if (!$priorities): ?>
            <div class="empty-state"><?= icon('clock') ?><h3 style="margin-bottom:6px">No priorities yet</h3>
                <p style="margin:0">Create priorities first, then define their SLA here.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Priority</th><th>Resolve within (hours)</th><th>"Nearing" when remaining ≤ (%)</th><th>Clock starts at</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($priorities as $p): ?>
                            <?php $fid = 'sla-' . (int)$p['id']; ?>
                            <tr>
                                <td><?= cmp_priority_badge((string)$p['name'], (string)$p['color']) ?><?= $p['pstatus'] === 'inactive' ? ' <span class="cell-sub">inactive</span>' : '' ?></td>
                                <td><input class="input" form="<?= $fid ?>" style="max-width:130px" type="number" name="due_hours" min="1" max="87600" value="<?= $p['due_hours'] !== null ? (int)$p['due_hours'] : '' ?>" placeholder="e.g. 24" aria-label="Due hours"></td>
                                <td><input class="input" form="<?= $fid ?>" style="max-width:110px" type="number" name="warning_percent" min="1" max="99" value="<?= (int)($p['warning_percent'] ?? 25) ?>" aria-label="Warning percent"></td>
                                <td>
                                    <select class="select" form="<?= $fid ?>" name="start_event" aria-label="Clock start">
                                        <option value="created" <?= ($p['start_event'] ?? 'created') === 'created' ? 'selected' : '' ?>>Complaint creation</option>
                                        <option value="assigned" <?= ($p['start_event'] ?? '') === 'assigned' ? 'selected' : '' ?>>UC assignment</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="cell-actions">
                                        <button type="submit" form="<?= $fid ?>" name="op" value="save" class="btn btn--primary"><span class="btn__label">Save</span></button>
                                        <?php if ($p['due_hours'] !== null): ?>
                                            <button type="submit" form="<?= $fid ?>" name="op" value="remove" class="btn btn--ghost" formnovalidate><span class="btn__label">Remove rule</span></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php foreach ($priorities as $p): ?>
                <form id="sla-<?= (int)$p['id'] ?>" method="post" action="<?= e($post) ?>" hidden>
                    <?= csrf_field() ?>
                    <input type="hidden" name="entity" value="sla"><input type="hidden" name="priority_id" value="<?= (int)$p['id'] ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'statuses'): ?>
    <section class="card" style="max-width:720px">
        <h2 class="card__title">Workflow status labels</h2>
        <p class="card__subtitle">The seven workflow states are fixed (the workflow logic depends on them), but you can rename them and change their colour.</p>
        <?php foreach (cmp_statuses() as $slug => $st): ?>
            <form class="cmp-status-row" method="post" action="<?= e($post) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="entity" value="statuses"><input type="hidden" name="op" value="save"><input type="hidden" name="slug" value="<?= e((string)$slug) ?>">
                <input class="cmp-color" type="color" name="color" value="<?= e((string)$st['color']) ?>" aria-label="Colour">
                <input class="input" name="label" maxlength="60" value="<?= e((string)$st['label']) ?>" aria-label="Label" required>
                <code class="cell-sub"><?= e((string)$slug) ?></code>
                <button type="submit" class="btn"><span class="btn__label">Save</span></button>
            </form>
        <?php endforeach; ?>
    </section>

<?php else: /* settings */ ?>
    <?php
    $types = Database::fetchAll('SELECT st.id, st.name, sp.name AS pool_name, st.assignment_mode FROM hr_staff_types st JOIN hr_staff_pools sp ON sp.id = st.staff_pool_id ORDER BY sp.name, st.name');
    $supIds = cmp_staff_type_ids('supervisor');
    $moIds = cmp_staff_type_ids('monitor');
    $supSaved = cmp_setting_fresh('complaint_supervisor_type_ids') !== null;
    $moSaved = cmp_setting_fresh('complaint_mo_type_ids') !== null;
    $prefix = (string)cmp_setting_fresh('complaint_number_prefix', 'CMP-');
    $padding = (int)cmp_setting_fresh('complaint_number_padding', '6');
    $dupDays = (int)cmp_setting_fresh('complaint_duplicate_days', '30');
    $nextNumber = (int)Database::fetchValue("SELECT last_value FROM complaint_sequences WHERE name = 'complaint'") + 1;
    ?>
    <form method="post" action="<?= e($post) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="entity" value="settings"><input type="hidden" name="op" value="save">
        <div class="grid grid--2 cmp-config-grid">
            <section class="card">
                <h2 class="card__title">Complaint numbering</h2>
                <div class="field-row">
                    <div class="field"><label class="field__label" for="prefix">Prefix</label>
                        <div class="control"><input class="input" id="prefix" name="prefix" maxlength="12" value="<?= e($prefix) ?>"></div></div>
                    <div class="field"><label class="field__label" for="padding">Digits</label>
                        <div class="control"><input class="input" type="number" id="padding" name="padding" min="1" max="12" value="<?= $padding ?>"></div></div>
                </div>
                <p class="field__hint">The next complaint will be <strong><?= e($prefix . str_pad((string)$nextNumber, max(1, $padding), '0', STR_PAD_LEFT)) ?></strong>. Numbers are generated by the system, are always unique and never reused. Changing the format only affects new complaints.</p>

                <h2 class="card__title" style="margin-top:24px">Duplicate warning</h2>
                <div class="field"><label class="field__label" for="dup_days">Look back (days)</label>
                    <div class="control"><input class="input" type="number" id="dup_days" name="dup_days" min="1" max="3650" value="<?= $dupDays ?>"></div>
                    <p class="field__hint">When a complaint is created, similar complaints from this many days back (same phone, or same UC + type + similar address) trigger a warning. Nothing is blocked automatically.</p></div>
            </section>

            <section class="card">
                <h2 class="card__title">Who is the Supervisor / Monitoring Officer?</h2>
                <p class="card__subtitle">A complaint's UC is matched to the HR employees assigned to that UC (Employees → Assigned). Choose which HR staff types count as each role. They are notified and can see the complaint.</p>
                <?php if (!$types): ?>
                    <p class="field__hint">No HR staff types exist yet — create them under Employees → HR Types.</p>
                <?php else: ?>
                    <h3 style="margin:8px 0 6px;font-size:var(--text-base)">Supervisor types <?= $supSaved ? '' : '<span class="cell-sub">(auto-detected from the type named “Supervisor” until you save)</span>' ?></h3>
                    <div class="cmp-checks">
                        <?php foreach ($types as $t): ?>
                            <label><input type="checkbox" name="supervisor_types[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $supIds, true) ? 'checked' : '' ?>> <?= e((string)$t['name']) ?> <span class="cell-sub"><?= e((string)$t['pool_name']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <h3 style="margin:16px 0 6px;font-size:var(--text-base)">Monitoring Officer types <?= $moSaved ? '' : '<span class="cell-sub">(auto-detected from the type named “Monitoring Officer” until you save)</span>' ?></h3>
                    <div class="cmp-checks">
                        <?php foreach ($types as $t): ?>
                            <label><input type="checkbox" name="mo_types[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $moIds, true) ? 'checked' : '' ?>> <?= e((string)$t['name']) ?> <span class="cell-sub"><?= e((string)$t['pool_name']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <div class="toolbar" style="margin-top:var(--space-4)">
            <button type="submit" class="btn btn--primary"><?= icon('check-big') ?><span class="btn__label">Save settings</span></button>
        </div>
    </form>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
