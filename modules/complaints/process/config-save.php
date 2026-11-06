<?php
/**
 * Saves Complaint configuration: sources, types, priorities, SLA rules,
 * status labels and module settings. Needs complaint.config.
 *
 * Deleting is only allowed when nothing uses the row; otherwise the row is
 * kept (history is never broken) and the user is told to deactivate it.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.config');

$entity = post('entity');
$op = post('op');
$tabs = ['sources', 'types', 'priorities', 'sla', 'statuses', 'settings'];
if (!in_array($entity, $tabs, true)) {
    redirect('modules/complaints/config.php');
}
$back = 'modules/complaints/config.php?tab=' . $entity;

if (!is_post()) {
    redirect($back);
}
Security::requireCsrf($back);

$lookup = ['sources' => ['complaint_sources', 'source_id', 'source'], 'types' => ['complaint_types', 'type_id', 'type'], 'priorities' => ['complaint_priorities', 'priority_id', 'priority']];

try {
    /* ---------------- sources / types / priorities ---------------- */
    if (isset($lookup[$entity])) {
        [$table, $fk, $label] = $lookup[$entity];
        $id = (int)post('id');

        if ($op === 'save') {
            $name = Security::cleanText(post('name'), 120);
            $description = Security::cleanText(post('description'), 255);
            $sort = max(0, min(9999, (int)post('sort_order')));
            if ($name === '') {
                flash('error', 'Enter a name.');
                redirect($back . ($id ? '&edit=' . $id : ''));
            }
            $dup = Database::exists("SELECT id FROM {$table} WHERE name = :n AND id != :id", ['n' => $name, 'id' => $id]);
            if ($dup) {
                flash('error', 'A ' . $label . ' with that name already exists.');
                redirect($back . ($id ? '&edit=' . $id : ''));
            }
            $data = ['name' => $name, 'description' => $description !== '' ? $description : null, 'sort_order' => $sort];
            if ($entity === 'priorities') {
                $color = post('color');
                $data['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#17a06a';
            }
            if ($id > 0) {
                if (!Database::exists("SELECT id FROM {$table} WHERE id = :id", ['id' => $id])) {
                    flash('error', 'Record not found.');
                    redirect($back);
                }
                $status = post('status') === 'inactive' ? 'inactive' : 'active';
                $data['status'] = $status;
                Database::update($table, $data, ['id' => $id]);
                flash('success', ucfirst($label) . ' updated.');
            } else {
                $data['created_by'] = Auth::id();
                Database::insert($table, $data);
                flash('success', ucfirst($label) . ' "' . $name . '" added.');
            }
        } elseif ($op === 'toggle') {
            Database::execute("UPDATE {$table} SET status = IF(status = 'active', 'inactive', 'active') WHERE id = :id", ['id' => $id]);
            flash('success', 'Status updated.');
        } elseif ($op === 'delete') {
            $used = (int)Database::fetchValue("SELECT COUNT(*) FROM complaints WHERE {$fk} = :id", ['id' => $id]);
            if ($used > 0) {
                flash('error', 'This ' . $label . ' is used by ' . number_format($used) . ' complaint(s) and cannot be deleted — deactivate it instead. Historical data is preserved.');
            } else {
                Database::execute("DELETE FROM {$table} WHERE id = :id", ['id' => $id]);
                flash('success', ucfirst($label) . ' deleted.');
            }
        }
        redirect($back);
    }

    /* ---------------- SLA rules ---------------- */
    if ($entity === 'sla') {
        $priorityId = (int)post('priority_id');
        if (!Database::exists('SELECT id FROM complaint_priorities WHERE id = :id', ['id' => $priorityId])) {
            flash('error', 'Priority not found.');
            redirect($back);
        }
        if ($op === 'remove') {
            Database::execute('DELETE FROM complaint_sla_rules WHERE priority_id = :p', ['p' => $priorityId]);
            flash('success', 'SLA rule removed. Complaints of this priority now have no SLA.');
        } else {
            $hours = (int)post('due_hours');
            $warn = (int)post('warning_percent');
            $start = post('start_event') === 'assigned' ? 'assigned' : 'created';
            if ($hours < 1 || $hours > 87600) {
                flash('error', 'Enter the number of hours a complaint of this priority may stay open (1 or more).');
                redirect($back);
            }
            $warn = max(1, min(99, $warn ?: 25));
            Database::execute(
                "INSERT INTO complaint_sla_rules (priority_id, due_hours, warning_percent, start_event, status, updated_by)
                 VALUES (:p, :h, :w, :s, 'active', :u)
                 ON DUPLICATE KEY UPDATE due_hours = VALUES(due_hours), warning_percent = VALUES(warning_percent),
                                         start_event = VALUES(start_event), status = 'active', updated_by = VALUES(updated_by)",
                ['p' => $priorityId, 'h' => $hours, 'w' => $warn, 's' => $start, 'u' => Auth::id()]
            );
            flash('success', 'SLA rule saved.');
        }
        /* Re-calculate the clock of still-open complaints of this priority.
           Resolved/rejected/deleted ones keep the times they were judged by. */
        $ids = Database::fetchAll(
            "SELECT id FROM complaints WHERE priority_id = :p AND deleted_at IS NULL AND status NOT IN ('resolved','rejected')",
            ['p' => $priorityId]
        );
        foreach ($ids as $row) {
            cmp_apply_sla((int)$row['id']);
        }
        redirect($back);
    }

    /* ---------------- status labels ---------------- */
    if ($entity === 'statuses') {
        $slug = post('slug');
        $label = Security::cleanText(post('label'), 60);
        $color = post('color');
        if (!array_key_exists($slug, cmp_statuses()) || $label === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            flash('error', 'Enter a label and choose a colour.');
            redirect($back);
        }
        Database::execute('UPDATE complaint_statuses SET label = :l, color = :c WHERE slug = :s', ['l' => $label, 'c' => $color, 's' => $slug]);
        flash('success', 'Status label saved.');
        redirect($back);
    }

    /* ---------------- settings ---------------- */
    if ($entity === 'settings') {
        $prefix = preg_replace('/[^A-Za-z0-9\-_\/]/', '', Security::cleanText(post('prefix'), 12)) ?? '';
        $padding = max(1, min(12, (int)post('padding') ?: 6));
        $dupDays = max(1, min(3650, (int)post('dup_days') ?: 30));

        $validTypeIds = array_map('intval', array_column(Database::fetchAll('SELECT id FROM hr_staff_types'), 'id'));
        $pick = static function (string $field) use ($validTypeIds): string {
            $raw = $_POST[$field] ?? [];
            $ids = is_array($raw) ? array_map('intval', $raw) : [];
            return implode(',', array_values(array_unique(array_intersect($ids, $validTypeIds))));
        };

        Database::beginTransaction();
        cmp_save_setting('complaint_number_prefix', $prefix);
        cmp_save_setting('complaint_number_padding', (string)$padding);
        cmp_save_setting('complaint_duplicate_days', (string)$dupDays);
        cmp_save_setting('complaint_supervisor_type_ids', $pick('supervisor_types'));
        cmp_save_setting('complaint_mo_type_ids', $pick('mo_types'));
        Database::commit();
        flash('success', 'Settings saved.');
        redirect($back);
    }
} catch (Throwable $e) {
    if (Database::pdo()->inTransaction()) {
        Database::rollBack();
    }
    log_event('complaints', 'Config save failed', ['entity' => $entity, 'op' => $op, 'error' => $e->getMessage()]);
    flash('error', Database::friendlyError($e));
    redirect($back);
}

redirect($back);
