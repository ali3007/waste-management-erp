<?php
/**
 * Dashboard numbers — computed from the stored attendance rows, in ONE
 * aggregate query over the (date, status) / (date, uc) indexes, never by
 * loading history. Status counts are a true partition (every row has exactly
 * one status), so they always add up to the total; "Check-in completed" and
 * "Check-out completed" are event counts and are labelled as such.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** @return array{where:string,params:array} */
function att_dashboard_where(array $ctx, string $date, int $poolId, int $ucId): array
{
    $where = ['a.attendance_date = :d'];
    $params = ['d' => $date];
    $scope = att_scope_sql($ctx, 'a.employee_id');
    if ($scope !== '') {
        $where[] = $scope;
    }
    if ($date >= att_today()) {
        $where[] = "e.status = 'active'";           // today/future: only people who can still mark
    }
    if ($poolId > 0) {
        $where[] = 'a.staff_pool_id = :pool';
        $params['pool'] = $poolId;
    }
    if ($ucId > 0) {
        $where[] = 'a.uc_id = :uc';
        $params['uc'] = $ucId;
    }
    return ['where' => implode(' AND ', $where), 'params' => $params];
}

function att_dashboard_data(array $ctx, string $date, int $poolId = 0, int $ucId = 0): array
{
    $w = att_dashboard_where($ctx, $date, $poolId, $ucId);
    $c = Database::fetch(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(a.checkin_at IS NOT NULL), 0) AS checkin_done,
                COALESCE(SUM(a.checkout_at IS NOT NULL), 0) AS checkout_done,
                COALESCE(SUM(a.status = 'present'), 0) AS present,
                COALESCE(SUM(a.status = 'absent'), 0) AS absent,
                COALESCE(SUM(a.status = 'incomplete'), 0) AS incomplete,
                COALESCE(SUM(a.status = 'pending_review'), 0) AS pending_review,
                COALESCE(SUM(a.status = 'leave'), 0) AS `leave`,
                COALESCE(SUM(a.status = 'off_day'), 0) AS off_day,
                COALESCE(SUM(a.status = 'holiday'), 0) AS holiday,
                COALESCE(SUM(a.status IN ('not_marked','checked_in')), 0) AS awaiting
           FROM attendance_records a JOIN employees e ON e.id = a.employee_id
          WHERE {$w['where']}",
        $w['params']
    ) ?? [];
    $cards = [];
    foreach (['total', 'checkin_done', 'checkout_done', 'present', 'absent', 'incomplete', 'pending_review', 'leave', 'off_day', 'holiday', 'awaiting'] as $k) {
        $cards[$k] = (int)($c[$k] ?? 0);
    }
    $byPool = Database::fetchAll(
        "SELECT sp.id, sp.name, COUNT(*) AS total, SUM(a.status = 'present') AS present, SUM(a.status = 'absent') AS absent,
                SUM(a.status IN ('incomplete','pending_review')) AS review, SUM(a.status = 'leave') AS `leave`
           FROM attendance_records a JOIN employees e ON e.id = a.employee_id JOIN hr_staff_pools sp ON sp.id = a.staff_pool_id
          WHERE {$w['where']} GROUP BY sp.id, sp.name ORDER BY sp.name",
        $w['params']
    );
    return [
        'date' => $date, 'date_label' => att_fmt_date($date), 'configured' => att_company_config($date) !== null,
        'cards' => $cards, 'by_pool' => $byPool, 'is_today' => $date === att_today(),
        'generated_at' => att_now()->format('h:i:s A'),
        'open_reviews' => (int)Database::fetchValue(
            "SELECT COUNT(*) FROM attendance_records a JOIN employees e ON e.id = a.employee_id
              WHERE a.review_state = 'pending' AND a.finalized_at IS NULL" . (($s = att_scope_sql($ctx, 'a.employee_id')) !== '' ? ' AND ' . $s : '')
        ),
    ];
}
