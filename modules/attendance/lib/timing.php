<?php
/**
 * Attendance timing — database-driven, versioned, prospective.
 *
 * A configuration is a set of four times (Check-in start/end, Check-out
 * start/end). There is one company-level default and, optionally, one per UC
 * (used by Field and Transport Staff; Office Staff always use the company
 * default). A configuration is a version with an effective_from date; the one
 * that applies to an attendance date is the latest version whose effective_from
 * is on or before that date.
 *
 *   • An ordinary edit is effective TODAY: it is used by the marking screens and
 *     the server-side window check from the next request, and past days keep the
 *     version that applied to them. Records already marked are never changed.
 *   • A later effective date schedules a future change (kept as a feature).
 *   • A date in the past is refused: that would rewrite days already recorded.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const ATT_TIME_KEYS = ['checkin_start', 'checkin_end', 'checkout_start', 'checkout_end'];

/**
 * One scope per independent configuration:
 *   company            fallback for any category that has no timing of its own (the original default)
 *   pool-<P>           a staff category's default (Office / Field / Transport …)
 *   uc-<U>-pool-<P>    one category's override inside one UC
 *   uc-<U>             legacy UC override (all Supervisor-marked categories) — still honoured, no longer created
 */
function att_scope_key(?int $ucId, ?int $poolId = null): string
{
    if ($poolId !== null) {
        return $ucId === null ? 'pool-' . $poolId : 'uc-' . $ucId . '-pool-' . $poolId;
    }
    return $ucId === null ? 'company' : 'uc-' . $ucId;
}

/** Latest version of a scope effective on $date (any kind, including "inherit"). */
function att_timing_version(string $scopeKey, string $date): ?array
{
    return Database::fetch(
        'SELECT * FROM attendance_timing_configs WHERE scope_key = :s AND effective_from <= :d ORDER BY effective_from DESC LIMIT 1',
        ['s' => $scopeKey, 'd' => $date]
    );
}

/** Per-request cache of company versions: only real results are cached, and a save clears it (att_company_config('')). */
function att_company_config(string $date): ?array
{
    static $cache = [];
    if ($date === '') {             // reset hook (also drops the resolved-window memo)
        $cache = [];
        $GLOBALS['__att_cfg_memo'] = [];
        return null;
    }
    if (!isset($cache[$date])) {
        $row = att_timing_version('company', $date);
        if ($row === null) {
            return null;            // never remember "not configured"
        }
        $cache[$date] = $row;
    }
    return $cache[$date];
}

/**
 * The windows that apply to an employee on a date, or null when timing has
 * not been configured (attendance cannot be marked then).
 *
 * The SAME function serves the marking screens, the server-side window check
 * and the scheduler, so they can never disagree. Resolution, most specific first:
 *   1. the category's override for the employee's UC   (uc-<U>-pool-<P>; or legacy uc-<U>)
 *   2. the category's own default                       (pool-<P>)
 *   3. the company default                              (company)
 * A version marked "inherit" cancels that level. Each category resolves on its
 * own scope keys, so editing one category can never change another's windows.
 *
 * @return array{checkin_start:string,checkin_end:string,checkout_start:string,checkout_end:string,source:string,uc_id:?int}|null
 */
function att_config_for(int $poolId, ?int $ucId, string $date): ?array
{
    $memoKey = $poolId . '|' . (string)$ucId . '|' . $date;
    if (isset($GLOBALS['__att_cfg_memo'][$memoKey])) {
        return $GLOBALS['__att_cfg_memo'][$memoKey];
    }
    $company = att_company_config($date);
    if ($company === null) {
        return null;
    }
    $row = null;
    $source = 'company';

    if ($ucId !== null && !att_pool_uses_company_timing($poolId)) {       // Office Staff have no UC level
        $v = att_timing_version(att_scope_key($ucId, $poolId), $date);
        if ($v === null) {
            $v = att_timing_version(att_scope_key($ucId), $date);          // legacy UC-wide override
        }
        if ($v !== null && (int)$v['inherit_company'] === 0) {
            $row = $v;
            $source = 'uc';
        }
    }
    if ($row === null) {
        $p = att_timing_version(att_scope_key(null, $poolId), $date);
        if ($p !== null && (int)$p['inherit_company'] === 0) {
            $row = $p;
            $source = 'category';
        }
    }
    $row ??= $company;

    $out = ['source' => $source, 'uc_id' => $source === 'uc' ? $ucId : null];
    foreach (ATT_TIME_KEYS as $k) {
        $out[$k] = (string)$row[$k];
    }
    return $GLOBALS['__att_cfg_memo'][$memoKey] = $out;
}

/** 'before' | 'open' | 'closed' for one event at a given time of day (inclusive both ends). */
function att_window_state(array $cfg, string $event, string $time): string
{
    $s = $cfg[$event . '_start'];
    $e = $cfg[$event . '_end'];
    if ($time < $s) {
        return 'before';
    }
    return $time <= $e ? 'open' : 'closed';
}

function att_event_label(string $event): string
{
    return $event === 'checkin' ? 'Check-in' : 'Check-out';
}

/** The message shown when an event is refused because of its window. */
function att_window_message(array $cfg, string $event, string $state): string
{
    $label = att_event_label($event);
    $range = att_fmt_time($cfg[$event . '_start']) . ' – ' . att_fmt_time($cfg[$event . '_end']);
    return $state === 'before'
        ? "The {$label} window has not opened yet. It opens at " . att_fmt_time($cfg[$event . '_start']) . " (window {$range})."
        : "The {$label} window closed at " . att_fmt_time($cfg[$event . '_end']) . ". Late attendance cannot be recorded; "
          . 'an authorised reviewer will handle this case.';
}

/**
 * Validate four times. Rules: every time present and valid; each window opens
 * before it closes; and the Check-in window closes BEFORE the Check-out window
 * opens, so the two windows never overlap and a Check-out can never be
 * accepted before the Check-in window is over.
 *
 * @return array{0:array<string,string>,1:string[]} [normalised times, errors]
 */
function att_validate_timing(array $input): array
{
    $t = [];
    $errors = [];
    $names = ['checkin_start' => 'Check-in start', 'checkin_end' => 'Check-in end', 'checkout_start' => 'Check-out start', 'checkout_end' => 'Check-out end'];
    foreach ($names as $k => $label) {
        $v = att_valid_time((string)($input[$k] ?? ''));
        if ($v === null) {
            $errors[] = "{$label} time is required (HH:MM).";
        } else {
            $t[$k] = $v;
        }
    }
    if ($errors) {
        return [$t, $errors];
    }
    if ($t['checkin_start'] >= $t['checkin_end']) {
        $errors[] = 'Check-in must open before it closes.';
    }
    if ($t['checkout_start'] >= $t['checkout_end']) {
        $errors[] = 'Check-out must open before it closes.';
    }
    if ($t['checkin_end'] >= $t['checkout_start']) {
        $errors[] = 'The Check-in window must close before the Check-out window opens (the windows may not overlap).';
    }
    return [$t, $errors];
}

/**
 * Save a new version of ONE scope. ($ucId, $poolId):
 *   (null, null) company default · (null, P) category default · (U, P) category override in one UC.
 * "$inherit" removes that level (the scope then falls back to the next one). Returns [ok, message].
 */
function att_save_timing(?int $ucId, array $input, ?string $effectiveFrom, bool $inherit = false, ?int $poolId = null): array
{
    $scope = att_scope_key($ucId, $poolId);
    $today = att_today();

    if ($ucId !== null && !Database::exists('SELECT id FROM ucs WHERE id = :id', ['id' => $ucId])) {
        return [false, 'That UC does not exist.'];
    }
    if ($poolId !== null) {
        if (!in_array($poolId, array_map(static fn($p) => (int)$p['id'], att_tracked_pools()), true)) {
            return [false, 'That staff category is not part of attendance.'];
        }
        if ($ucId !== null && att_pool_uses_company_timing($poolId)) {
            return [false, 'Office Staff have no UC; their timing is set for the category.'];
        }
    }
    if ($ucId === null && $poolId === null && $inherit) {
        return [false, 'The company default cannot inherit from itself.'];
    }

    $times = array_fill_keys(ATT_TIME_KEYS, null);
    if (!$inherit) {
        [$times, $errors] = att_validate_timing($input);
        if ($errors) {
            return [false, implode(' ', $errors)];
        }
    }

    $hasCompany = Database::exists("SELECT id FROM attendance_timing_configs WHERE scope_key = 'company'");
    if (($ucId !== null || $poolId !== null) && !$hasCompany) {
        return [false, 'Set the company default timing first; category and UC settings override it.'];
    }

    /* Ordinary edits apply immediately (today); a later date schedules a future change. */
    if ($effectiveFrom === null || $effectiveFrom === '') {
        $effectiveFrom = $today;
    }
    $effectiveFrom = att_valid_date($effectiveFrom);
    if ($effectiveFrom === null) {
        return [false, 'Enter a valid effective date.'];
    }
    if ($effectiveFrom < $today) {
        return [false, 'The effective date cannot be in the past: days already recorded keep the timing that applied to them. Choose today (immediate) or a later date.'];
    }
    if ($effectiveFrom > (new DateTimeImmutable($today, att_tz()))->modify('+365 days')->format('Y-m-d')) {
        return [false, 'The effective date is too far in the future.'];
    }
    $immediate = $effectiveFrom === $today;

    att_company_config('');         // drop cached versions: this save changes them
    try {
        Database::beginTransaction();
        $existing = Database::fetch(
            'SELECT * FROM attendance_timing_configs WHERE scope_key = :s AND effective_from = :d FOR UPDATE',
            ['s' => $scope, 'd' => $effectiveFrom]
        );
        $previous = att_timing_version($scope, $today);
        $row = ['scope_key' => $scope, 'uc_id' => $ucId, 'staff_pool_id' => $poolId, 'inherit_company' => $inherit ? 1 : 0,
                'effective_from' => $effectiveFrom, 'created_by' => att_actor_id(), 'created_at' => att_now_sql()] + $times;
        if ($existing !== null) {
            Database::update('attendance_timing_configs', $row, ['id' => (int)$existing['id']]);
            $id = (int)$existing['id'];
        } else {
            $id = Database::insert('attendance_timing_configs', $row);
        }
        $fmt = static fn(?array $r): ?array => $r === null ? null : ($r['inherit_company'] ? ['inherit_company' => true]
            : array_intersect_key($r, array_flip(ATT_TIME_KEYS)) + ['effective_from' => $r['effective_from']]);
        att_audit('timing_changed', att_scope_label($ucId, $poolId) . ' timing ' . ($inherit ? 'set to follow the next level' : 'updated') . ' effective ' . $effectiveFrom, [
            'subject_type' => 'timing', 'subject_id' => $id, 'uc_id' => $ucId, 'employee_id' => null,
            'old' => $fmt($existing ?? $previous), 'new' => $fmt($row + ['inherit_company' => $inherit ? 1 : 0]),
        ]);
        Database::commit();
        att_company_config('');
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('attendance', 'Timing save failed', ['error' => $e->getMessage()]);
        return [false, 'The timing could not be saved. Please try again.'];
    }

    $note = '';
    if ($immediate) {
        try {
            $r = att_reconcile_timing_today();
            if ($r['reopened'] > 0) {
                $note .= " {$r['reopened']} unmarked case(s) that the old window had closed are open again under the new window.";
            }
            if ($r['flagged'] > 0) {
                $note .= " {$r['flagged']} case(s) are now past the new window and were sent to review.";
            }
        } catch (Throwable $e) {
            log_event('attendance', 'Timing reconcile failed', ['error' => $e->getMessage()]);
            $note = ' The timing is saved; today\'s cases will be re-evaluated by the scheduler.';
        }
    }
    return [true, att_scope_label($ucId, $poolId) . ($inherit ? ' now follows the next level up' : ' timing saved') . ($immediate ? ' and is in force now (today).' : ' — scheduled from ' . att_fmt_date($effectiveFrom) . '.') . $note];
}

/** Human label for a scope. */
function att_scope_label(?int $ucId, ?int $poolId): string
{
    $pool = $poolId !== null ? (string)(Database::fetchValue('SELECT name FROM hr_staff_pools WHERE id = :i', ['i' => $poolId]) ?? ('#' . $poolId)) : '';
    $uc = $ucId !== null ? (string)(Database::fetchValue('SELECT name FROM ucs WHERE id = :i', ['i' => $ucId]) ?? ('#' . $ucId)) : '';
    if ($poolId === null && $ucId === null) {
        return 'Company default';
    }
    return $ucId === null ? $pool : ($poolId === null ? "UC {$uc} (all categories)" : "{$pool} in {$uc}");
}

/** Pools the module tracks (Office + Supervisor-marked), as id/name/slug. */
function att_tracked_pools(): array
{
    $ids = array_merge(att_self_pool_ids(), att_supervised_pool_ids());
    return Database::fetchAll('SELECT id, name, slug FROM hr_staff_pools WHERE id IN (' . att_in($ids) . ') ORDER BY name');
}

/** Permission slug that manages ONE category's timing, e.g. attendance.timing_manage_office_staff. */
function att_timing_pool_permission(array $pool): string
{
    return 'attendance.timing_manage_' . str_replace('-', '_', preg_replace('/[^a-z0-9-]/', '', strtolower((string)$pool['slug'])));
}

/**
 * May the holder manage this scope's timing? $poolId null = the company default (needs the
 * global permission). A category is managed with the global permission OR its own.
 * $can is injectable so the rule is testable.
 */
function att_can_manage_timing(?int $poolId, ?callable $can = null): bool
{
    $can ??= static fn(string $s): bool => can($s);
    if ($can('attendance.timing_manage')) {
        return true;
    }
    if ($poolId === null) {
        return false;
    }
    foreach (att_tracked_pools() as $p) {
        if ((int)$p['id'] === $poolId) {
            return $can(att_timing_pool_permission($p));
        }
    }
    return false;
}

/** Tracked pools this holder may manage. */
function att_manageable_pools(?callable $can = null): array
{
    return array_values(array_filter(att_tracked_pools(), static fn(array $p): bool => att_can_manage_timing((int)$p['id'], $can)));
}

/** Every version, newest first, with its category / UC names, for the Timing screen. */
function att_timing_overview(): array
{
    return Database::fetchAll(
        "SELECT c.*, u.name AS uc_name, sp.name AS pool_name, cu.name AS by_name
           FROM attendance_timing_configs c
           LEFT JOIN ucs u ON u.id = c.uc_id
           LEFT JOIN hr_staff_pools sp ON sp.id = c.staff_pool_id
           LEFT JOIN users cu ON cu.id = c.created_by
          ORDER BY (c.scope_key = 'company') DESC, sp.name ASC, u.name ASC, c.effective_from DESC"
    );
}
