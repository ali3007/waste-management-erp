<?php
/**
 * Complaints — workflow engine: audit timeline, SLA, UC assignment and
 * in-system notifications. Functions here change data; permission and
 * scope checks happen before they are called (see lib/access.php).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/* ------------------------------------------------------------------ *
 | Timeline (append-only audit trail)                                  |
 * ------------------------------------------------------------------ */

/** Record one audit event. There is deliberately no update/delete counterpart. */
function cmp_timeline(int $complaintId, string $action, string $title, ?string $old = null, ?string $new = null, ?string $remarks = null, ?int $userId = null): void
{
    Database::insert('complaint_timeline', [
        'complaint_id' => $complaintId, 'action' => $action, 'title' => mb_substr($title, 0, 190),
        'old_value' => $old !== null ? mb_substr($old, 0, 190) : null,
        'new_value' => $new !== null ? mb_substr($new, 0, 190) : null,
        'remarks' => $remarks !== null && $remarks !== '' ? $remarks : null,
        'user_id' => $userId ?? (Auth::id() ?: null), 'created_at' => cmp_now(),
    ]);
}

/* ------------------------------------------------------------------ *
 | SLA                                                                 |
 * ------------------------------------------------------------------ */

function cmp_sla_rule(int $priorityId): ?array
{
    return Database::fetch("SELECT * FROM complaint_sla_rules WHERE priority_id = :p AND status = 'active'", ['p' => $priorityId]);
}

/**
 * (Re)compute and store a complaint's SLA clock from its priority's rule.
 *
 * The clock starts at the complaint's real creation time or at its real
 * assignment time (the rule decides which), or at $restartAt when a
 * resolved complaint is reopened. Due and "nearing" instants are stored,
 * so every SLA count in the dashboard is a plain indexed comparison.
 * A complaint whose priority has no active rule simply has no SLA.
 */
function cmp_apply_sla(int $complaintId, ?string $restartAt = null): void
{
    $c = Database::fetch('SELECT id, priority_id, created_at, assigned_at FROM complaints WHERE id = :id', ['id' => $complaintId]);
    if ($c === null) {
        return;
    }
    $rule = cmp_sla_rule((int)$c['priority_id']);

    $start = null;
    if ($rule !== null) {
        $start = $restartAt ?? ($rule['start_event'] === 'assigned' ? $c['assigned_at'] : $c['created_at']);
    }
    if ($rule === null || $start === null) {
        Database::execute('UPDATE complaints SET sla_start_at = NULL, sla_warn_at = NULL, sla_due_at = NULL WHERE id = :id', ['id' => $complaintId]);
        return;
    }
    $startTs = strtotime((string)$start);
    $totalSeconds = (int)$rule['due_hours'] * 3600;
    $due = $startTs + $totalSeconds;
    $warn = $due - (int)round($totalSeconds * ((int)$rule['warning_percent'] / 100));

    Database::execute(
        'UPDATE complaints SET sla_start_at = :s, sla_warn_at = :w, sla_due_at = :d WHERE id = :id',
        ['s' => date('Y-m-d H:i:s', $startTs), 'w' => date('Y-m-d H:i:s', $warn), 'd' => date('Y-m-d H:i:s', $due), 'id' => $complaintId]
    );
}

/**
 * SLA state of one complaint: none | within | nearing | breached.
 * Closed complaints are judged by when they were finished: resolved
 * after the due time is a breach; rejected complaints have no SLA.
 */
function cmp_sla_state(array $c, ?string $now = null): string
{
    if (empty($c['sla_due_at']) || $c['status'] === CMP_ST_REJECTED) {
        return 'none';
    }
    $now ??= cmp_now();
    if ($c['status'] === CMP_ST_RESOLVED && !empty($c['resolved_at'])) {
        return strtotime((string)$c['resolved_at']) > strtotime((string)$c['sla_due_at']) ? 'breached' : 'within';
    }
    if (strtotime($now) > strtotime((string)$c['sla_due_at'])) {
        return 'breached';
    }
    if (!empty($c['sla_warn_at']) && strtotime($now) >= strtotime((string)$c['sla_warn_at'])) {
        return 'nearing';
    }
    return 'within';
}

/** "3 h 20 m left" / "Overdue by 1 d 2 h" / "Met" / '' — for display. */
function cmp_sla_remaining(array $c, ?string $now = null): string
{
    if (empty($c['sla_due_at']) || $c['status'] === CMP_ST_REJECTED) {
        return '';
    }
    $fmt = static function (int $s): string {
        $s = abs($s);
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            return $d . ' d ' . $h . ' h';
        }
        return $h > 0 ? $h . ' h ' . $m . ' m' : $m . ' m';
    };
    $due = strtotime((string)$c['sla_due_at']);
    if ($c['status'] === CMP_ST_RESOLVED && !empty($c['resolved_at'])) {
        $diff = $due - strtotime((string)$c['resolved_at']);
        return $diff >= 0 ? 'Resolved ' . $fmt($diff) . ' before due' : 'Resolved ' . $fmt($diff) . ' late';
    }
    $diff = $due - strtotime($now ?? cmp_now());
    return $diff >= 0 ? $fmt($diff) . ' left' : 'Overdue by ' . $fmt($diff);
}

function cmp_sla_badge(string $state): string
{
    $map = [
        'within'   => ['Within SLA', '#17a06a'],
        'nearing'  => ['Nearing SLA', '#d9902b'],
        'breached' => ['SLA breached', '#d64550'],
        'none'     => ['No SLA', '#8a948f'],
    ];
    [$label, $color] = $map[$state] ?? $map['none'];
    return '<span class="cmp-pill cmp-pill--outline" style="--pill:' . $color . '">' . e($label) . '</span>';
}

/* ------------------------------------------------------------------ *
 | Notifications (in-system only)                                      |
 * ------------------------------------------------------------------ */

/** Notify specific users about a complaint (duplicates and nobody are fine). */
function cmp_notify(array $userIds, array $c, string $type, string $title, string $message): int
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $count = 0;
    foreach ($userIds as $uid) {
        Database::insert('complaint_notifications', [
            'user_id' => $uid, 'complaint_id' => (int)$c['id'], 'type' => $type,
            'title' => mb_substr($title, 0, 190), 'message' => mb_substr($message, 0, 500), 'is_read' => 0, 'created_at' => cmp_now(),
        ]);
        $count++;
    }
    return $count;
}

/** "CMP-000012 · UC 23 · Garbage Not Collected · Urgent · 04 Oct 2026" */
function cmp_notification_text(array $c): string
{
    return implode(' · ', array_filter([
        (string)$c['complaint_number'], (string)$c['uc_name'], (string)$c['type_name'],
        'Priority: ' . (string)$c['priority_name'], cmp_fmt_date((string)$c['complaint_date']),
    ]));
}

/** Active users who may finally resolve complaints (to be told one is ready). */
function cmp_resolver_user_ids(): array
{
    $rows = Database::fetchAll(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.status = 'active' AND (r.slug = :super OR EXISTS (
                SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = r.id AND p.slug IN ('complaint.resolve', 'complaint.*', '*')))",
        ['super' => Permission::ROLE_SUPER_ADMIN]
    );
    return array_map('intval', array_column($rows, 'id'));
}

/* ------------------------------------------------------------------ *
 | UC assignment                                                       |
 * ------------------------------------------------------------------ */

/**
 * Assign (or reassign) a complaint to a UC and make it visible to — and
 * notify — the Supervisor and Monitoring Officer(s) the HR data says
 * cover that UC.
 *
 * - The previous assignment is CLOSED, never erased (history stays).
 * - Visibility needs no extra work: staff scope is derived live from HR
 *   assignments, so the new UC's staff gain access immediately and the
 *   old UC's staff lose it.
 * - New → Assigned only when someone is actually assigned to the UC; a
 *   complaint for a UC nobody covers stays New so it is easy to spot.
 *
 * Call inside a transaction. $geo is the validated geography chain.
 *
 * @return array{notified:int, staff:array}
 */
function cmp_assign_to_uc(array $c, array $geo, string $reason, bool $isInitial): array
{
    $now = cmp_now();
    $cid = (int)$c['id'];
    $staff = cmp_uc_staff($geo['uc_id']);

    $snapshot = json_encode([
        'supervisors' => array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'code' => $s['employee_code']], $staff['supervisors']),
        'monitors'    => array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'code' => $s['employee_code']], $staff['monitors']),
    ], JSON_UNESCAPED_UNICODE);

    $previous = Database::fetch(
        'SELECT a.*, u.name AS uc_name FROM complaint_assignments a JOIN ucs u ON u.id = a.uc_id WHERE a.complaint_id = :id AND a.ended_at IS NULL',
        ['id' => $cid]
    );
    if ($previous !== null) {
        Database::execute('UPDATE complaint_assignments SET ended_at = :n WHERE id = :id', ['n' => $now, 'id' => (int)$previous['id']]);
    }
    Database::insert('complaint_assignments', [
        'complaint_id' => $cid, 'uc_id' => $geo['uc_id'], 'beat_id' => $geo['beat_id'], 'assigned_by' => Auth::id() ?: null,
        'assigned_at' => $now, 'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null, 'staff_snapshot' => $snapshot,
    ]);

    $hasStaff = !empty($staff['supervisors']) || !empty($staff['monitors']);
    $newStatus = $c['status'];
    if (in_array($c['status'], [CMP_ST_NEW, CMP_ST_ASSIGNED], true)) {
        $newStatus = $hasStaff ? CMP_ST_ASSIGNED : CMP_ST_NEW;
    }

    Database::execute(
        'UPDATE complaints SET region_id = :r, district_id = :d, tehsil_id = :t, uc_id = :uc, beat_id = :b,
                assigned_at = :a, status = :s, updated_at = :u, updated_by = :ub WHERE id = :id',
        ['r' => $geo['region_id'], 'd' => $geo['district_id'], 't' => $geo['tehsil_id'], 'uc' => $geo['uc_id'], 'b' => $geo['beat_id'],
         'a' => $now, 's' => $newStatus, 'u' => $now, 'ub' => Auth::id() ?: null, 'id' => $cid]
    );

    $beatName = $geo['beat_id'] ? (string)Database::fetchValue('SELECT name FROM beats WHERE id = :id', ['id' => $geo['beat_id']]) : '';
    $where = $geo['uc_name'] . ($beatName !== '' ? ' / ' . $beatName : '');

    if ($isInitial) {
        cmp_timeline($cid, 'assigned', 'Complaint assigned to ' . $where, null, $where, $reason ?: null);
    } else {
        cmp_timeline(
            $cid, 'reassigned', 'Complaint reassigned',
            $previous ? (string)$previous['uc_name'] : null, $where, $reason ?: null
        );
    }
    if ($newStatus !== $c['status']) {
        cmp_timeline($cid, 'status', 'Status changed to ' . cmp_status_label($newStatus), cmp_status_label((string)$c['status']), cmp_status_label($newStatus));
    }

    /* Notify the Supervisor and Monitoring Officer(s) with a login. */
    $fresh = cmp_load($cid, true) ?? $c;
    $userIds = [];
    $names = [];
    foreach (array_merge($staff['supervisors'], $staff['monitors']) as $s) {
        $names[] = $s['name'];
        if ($s['user_id'] !== null) {
            $userIds[] = $s['user_id'];
        }
    }
    $userIds = array_values(array_unique(array_diff($userIds, [Auth::id()])));
    $notified = cmp_notify(
        $userIds, $fresh, $isInitial ? 'assigned' : 'reassigned',
        ($isInitial ? 'New complaint assigned to your UC: ' : 'Complaint reassigned to your UC: ') . $fresh['complaint_number'],
        cmp_notification_text($fresh)
    );
    if ($names) {
        cmp_timeline($cid, 'notified', 'Supervisor / Monitoring Officer notified', null, implode(', ', $names), $notified < count($names) ? 'Some staff have no login account, so they could not be sent an in-system notification.' : null);
    }

    return ['notified' => $notified, 'staff' => $staff];
}

/* ------------------------------------------------------------------ *
 | Status helpers                                                      |
 * ------------------------------------------------------------------ */

/** Set the status (and optional extra columns) and log it. Call inside a transaction. */
function cmp_change_status(array $c, string $newStatus, array $columns = [], ?string $remarks = null, ?string $title = null, string $action = 'status'): void
{
    $now = cmp_now();
    $set = array_merge(['status' => $newStatus, 'updated_at' => $now, 'updated_by' => Auth::id() ?: null], $columns);
    Database::update('complaints', $set, ['id' => (int)$c['id']]);
    cmp_timeline(
        (int)$c['id'], $action, $title ?? ('Status changed to ' . cmp_status_label($newStatus)),
        cmp_status_label((string)$c['status']), cmp_status_label($newStatus), $remarks
    );
}

/* ------------------------------------------------------------------ *
 | Duplicate detection                                                 |
 * ------------------------------------------------------------------ */

/**
 * Recent complaints that look like the one being entered. NEVER blocks —
 * the caller shows a warning and the user decides.
 *
 * Similar when, within the look-back window, EITHER the phone matches OR
 * the UC and type match AND the address shares words with an existing
 * complaint's address.
 *
 * @return array<int,array>
 */
function cmp_find_duplicates(?string $phone, int $ucId, int $typeId, string $address, ?int $excludeId = null): array
{
    $days = max(1, (int)cmp_setting_fresh('complaint_duplicate_days', '30'));
    $since = date('Y-m-d', strtotime('-' . $days . ' days'));

    $or = [];
    $p = ['since' => $since, 'uc' => $ucId, 'type' => $typeId, 'excl' => (int)$excludeId];

    $digits = preg_replace('/\D+/', '', (string)$phone) ?? '';
    if (strlen($digits) >= 7) {
        $or[] = "REPLACE(REPLACE(REPLACE(c.phone, '-', ''), ' ', ''), '+', '') LIKE :phone";
        $p['phone'] = '%' . substr($digits, -9);   // tolerate +92 / 0 prefixes
    }

    $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($address)) ?: [];
    $tokens = array_values(array_unique(array_filter($tokens, static fn($t) => mb_strlen($t) >= 4)));
    usort($tokens, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $tokens = array_slice($tokens, 0, 4);
    if ($tokens) {
        $parts = [];
        foreach ($tokens as $i => $tok) {
            $p['t' . $i] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tok) . '%';
            $parts[] = "(CASE WHEN c.location_address LIKE :t{$i} OR c.complainant_address LIKE :t{$i}b THEN 1 ELSE 0 END)";
            $p['t' . $i . 'b'] = $p['t' . $i];
        }
        $need = min(2, count($tokens));
        $or[] = '(c.uc_id = :uc AND c.type_id = :type AND (' . implode(' + ', $parts) . ') >= ' . $need . ')';
    }
    if (!$or) {
        return [];
    }

    $scope = cmp_scope_sql('c');
    $sql = "SELECT c.id, c.complaint_number, c.complaint_date, c.status, c.complainant_name, c.phone, u.name AS uc_name, ty.name AS type_name
              FROM complaints c JOIN ucs u ON u.id = c.uc_id JOIN complaint_types ty ON ty.id = c.type_id
             WHERE c.deleted_at IS NULL AND c.status <> 'rejected' AND c.complaint_date >= :since AND c.id <> :excl"
        . ($scope !== '' ? ' AND ' . $scope : '') . ' AND (' . implode(' OR ', $or) . ')
             ORDER BY c.complaint_date DESC, c.id DESC LIMIT 5';

    /* Drop placeholders the chosen SQL doesn't use (PDO rejects extras). */
    foreach (array_keys($p) as $k) {
        if (!str_contains($sql, ':' . $k)) {
            unset($p[$k]);
        }
    }
    return Database::fetchAll($sql, $p);
}
