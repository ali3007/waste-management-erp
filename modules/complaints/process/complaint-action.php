<?php
/**
 * Every status change and administrative action on one complaint.
 *
 *   op = start | ready | return | resolve | reopen | reject | reassign |
 *        remark | delete | restore
 *
 * The server decides — from the signed-in user's REAL permissions, the
 * complaint's REAL current status and the user's UC scope — whether the
 * action is allowed (cmp_require_action). The id and op in the request are
 * only a request. Monitoring Officers and Supervisors hold no
 * complaint.resolve permission, so the server refuses "resolve" for them
 * regardless of what the page or a hand-made request says.
 *
 * Reasons are mandatory where the spec requires them (reject, reopen,
 * return, reassign, delete) and are written to the audit timeline.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)post('id');
$op = post('op');
$backTo = 'modules/complaints/complaint-view.php?id=' . $id;

if (!is_post()) {
    redirect($backTo);
}
Auth::requireLogin();
Security::requireCsrf($backTo);

$complaint = cmp_require_access($id, 'complaint.view');
if (!in_array($op, ['start', 'ready', 'return', 'resolve', 'reopen', 'reject', 'reassign', 'remark', 'delete', 'restore'], true)) {
    redirect($backTo);
}
cmp_require_action($complaint, $op);

$ajax = is_ajax();
$reason = Security::cleanMultiline(post('reason'), 1000);
$ok = static function (string $message) use ($ajax, $id, $backTo): never {
    if ($ajax) {
        json_response(['ok' => true, 'message' => $message]);
    }
    flash('success', $message);
    redirect($backTo);
};
$fail = static function (string $message) use ($ajax, $backTo): never {
    if ($ajax) {
        json_response(['ok' => false, 'message' => $message], 422);
    }
    flash('error', $message);
    redirect($backTo);
};

$now = cmp_now();
$number = (string)$complaint['complaint_number'];

try {
    Database::beginTransaction();

    switch ($op) {
        case 'start':
            cmp_change_status($complaint, CMP_ST_PROGRESS, ['started_at' => $complaint['started_at'] ?: $now], $reason ?: null, 'Work started');
            $msg = 'Work started on ' . $number . '.';
            break;

        case 'ready':
            $afterCount = (int)Database::fetchValue("SELECT COUNT(*) FROM complaint_evidence WHERE complaint_id = :c AND kind = 'after'", ['c' => $id]);
            if ($afterCount < 1) {
                Database::rollBack();
                $fail('Add at least one After picture before marking the complaint Ready for Resolution.');
            }
            cmp_change_status($complaint, CMP_ST_READY, ['ready_at' => $now], $reason ?: null, 'Complaint marked Ready for Resolution');
            $fresh = cmp_load($id, true) ?? $complaint;
            cmp_notify(
                array_diff(cmp_resolver_user_ids(), [Auth::id()]), $fresh, 'ready',
                'Ready for resolution: ' . $number, cmp_notification_text($fresh)
            );
            $msg = $number . ' is now Ready for Resolution.';
            break;

        case 'return':
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the reason for sending the complaint back to the field.');
            }
            cmp_change_status($complaint, CMP_ST_PROGRESS, ['ready_at' => null], $reason, 'Returned to field for more work');
            $msg = $number . ' was sent back to the field.';
            break;

        case 'resolve':
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the resolution remarks.');
            }
            $cycle = (int)Database::fetchValue('SELECT COUNT(*) FROM complaint_resolution WHERE complaint_id = :c', ['c' => $id]) + 1;
            Database::insert('complaint_resolution', [
                'complaint_id' => $id, 'cycle_no' => $cycle, 'resolved_by' => Auth::id(), 'resolved_at' => $now, 'remarks' => $reason, 'state' => 'active',
            ]);
            cmp_change_status($complaint, CMP_ST_RESOLVED, ['resolved_at' => $now], $reason, 'Complaint resolved');
            $msg = $number . ' resolved.';
            break;

        case 'reopen':
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the reason for reopening this complaint.');
            }
            $resolution = Database::fetch("SELECT id FROM complaint_resolution WHERE complaint_id = :c AND state = 'active' ORDER BY id DESC LIMIT 1", ['c' => $id]);
            if ($resolution !== null) {
                Database::execute("UPDATE complaint_resolution SET state = 'reopened', reopened_at = :n WHERE id = :id", ['n' => $now, 'id' => (int)$resolution['id']]);
            }
            Database::insert('complaint_reopen_history', [
                'complaint_id' => $id, 'resolution_id' => $resolution['id'] ?? null, 'previous_status' => CMP_ST_RESOLVED,
                'new_status' => CMP_ST_REOPENED, 'reason' => $reason, 'reopened_by' => Auth::id(), 'reopened_at' => $now,
            ]);
            /* Previous resolution history stays; the complaint just isn't "resolved" any more. */
            cmp_change_status($complaint, CMP_ST_REOPENED, ['resolved_at' => null, 'ready_at' => null], $reason, 'Complaint reopened');
            cmp_apply_sla($id, $now);   // a reopened complaint gets a fresh SLA clock
            $fresh = cmp_load($id, true) ?? $complaint;
            $staff = cmp_uc_staff((int)$complaint['uc_id']);
            $uids = [];
            foreach (array_merge($staff['supervisors'], $staff['monitors']) as $s) {
                if ($s['user_id'] !== null) {
                    $uids[] = $s['user_id'];
                }
            }
            cmp_notify(array_diff($uids, [Auth::id()]), $fresh, 'reopened', 'Complaint reopened: ' . $number, cmp_notification_text($fresh));
            $msg = $number . ' reopened.';
            break;

        case 'reject':
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the reason for rejecting this complaint.');
            }
            cmp_change_status($complaint, CMP_ST_REJECTED, ['rejected_at' => $now], $reason, 'Complaint rejected');
            $msg = $number . ' rejected.';
            break;

        case 'reassign':
            $geo = cmp_validate_geography((int)post('region_id'), (int)post('district_id'), (int)post('tehsil_id'), (int)post('uc_id'), (int)post('beat_id') ?: null);
            if ($geo === null) {
                Database::rollBack();
                $fail('Invalid administrative hierarchy — choose an active Region, District, Tehsil and UC that belong together.');
            }
            if ($geo['uc_id'] === (int)$complaint['uc_id'] && (int)($geo['beat_id'] ?? 0) === (int)($complaint['beat_id'] ?? 0)) {
                Database::rollBack();
                $fail('The complaint is already assigned there.');
            }
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the reason for reassigning this complaint.');
            }
            if (($scope = cmp_scope_uc_ids()) !== null && !in_array($geo['uc_id'], $scope, true)) {
                Database::rollBack();
                cmp_deny('That UC is outside your area.');
            }
            cmp_assign_to_uc($complaint, $geo, $reason, false);
            cmp_apply_sla($id);
            $msg = $number . ' reassigned to ' . $geo['uc_name'] . '.';
            break;

        case 'remark':
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the remark.');
            }
            Database::execute('UPDATE complaints SET updated_at = :n WHERE id = :id', ['n' => $now, 'id' => $id]);
            cmp_timeline($id, 'remark', 'Remark added', null, null, $reason);
            $msg = 'Remark added.';
            break;

        case 'delete':
            if ($reason === '') {
                Database::rollBack();
                $fail('Enter the reason for deleting this complaint.');
            }
            Database::execute(
                'UPDATE complaints SET deleted_at = :n, deleted_by = :u, delete_reason = :r, updated_at = :n2 WHERE id = :id',
                ['n' => $now, 'u' => Auth::id(), 'r' => mb_substr($reason, 0, 255), 'n2' => $now, 'id' => $id]
            );
            cmp_timeline($id, 'deleted', 'Complaint deleted (soft delete — history kept)', null, null, $reason);
            Database::commit();
            flash('success', $number . ' deleted. It is kept in the system and can be restored by authorized users.');
            if ($ajax) {
                json_response(['ok' => true, 'redirect' => base_url('modules/complaints/index.php')]);
            }
            redirect(can('complaint.restore') ? $backTo : 'modules/complaints/index.php');

        case 'restore':
            Database::execute('UPDATE complaints SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = :n WHERE id = :id', ['n' => $now, 'id' => $id]);
            cmp_timeline($id, 'restored', 'Complaint restored', null, null, $reason ?: null);
            $msg = $number . ' restored.';
            break;

        default:
            Database::rollBack();
            redirect($backTo);
    }

    Database::commit();
} catch (Throwable $e) {
    if (Database::pdo()->inTransaction()) {
        Database::rollBack();
    }
    log_event('complaints', 'Complaint action failed', ['id' => $id, 'op' => $op, 'error' => $e->getMessage()]);
    $fail(Database::friendlyError($e));
}

$ok($msg);
