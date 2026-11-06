<?php
/**
 * Live Transport numbers for the dashboard (and fuel efficiency for reports).
 *
 * Everything is computed from the database on each request, over exactly the
 * vehicles the filters select and the signed-in user may see. Nothing is
 * cached or hard-coded.
 *
 * Definitions (also shown on the dashboard):
 *   Total       every vehicle that is not archived
 *   Active      status belongs to the "In service" behaviour group
 *   Assigned    has a current driver
 *   Available   In service AND no current driver
 *   Under maintenance / Out of service / Inactive   by status behaviour group
 *   Deployed    has a current deployment (a UC)
 * The date range applies to maintenance and fuel only.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Months (YYYY-MM) between two dates inclusive, capped at 36. */
function trn_month_range(string $from, string $to): array
{
    $a = new DateTimeImmutable(substr($from, 0, 7) . '-01');
    $b = new DateTimeImmutable(substr($to, 0, 7) . '-01');
    $out = [];
    while ($a <= $b && count($out) < 36) {
        $out[] = $a->format('Y-m');
        $a = $a->modify('+1 month');
    }
    return $out;
}

/** Read dashboard date range (defaults: last 12 months). */
function trn_read_range(array $src): array
{
    $from = trn_valid_date((string)($src['date_from'] ?? ''));
    $to = trn_valid_date((string)($src['date_to'] ?? ''));
    $custom = $from !== null || $to !== null;
    $to ??= trn_today();
    $from ??= (new DateTimeImmutable($to))->modify('-11 months')->format('Y-m-01');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return ['from' => $from, 'to' => $to, 'custom' => $custom];
}

/** @return array<string,mixed> */
function trn_dashboard_data(array $f, string $from, string $to): array
{
    [$where, $params] = trn_vehicle_filter_sql($f);
    $from_ = trn_vehicle_from();
    $idSql = "SELECT v.id {$from_} WHERE {$where}";
    $today = trn_today();

    $c = Database::fetch(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(vs.category = 'active'), 0) AS active,
                COALESCE(SUM(da.id IS NOT NULL), 0) AS assigned,
                COALESCE(SUM(vs.category = 'active' AND da.id IS NULL), 0) AS available,
                COALESCE(SUM(vs.category = 'maintenance'), 0) AS maintenance,
                COALESCE(SUM(vs.category = 'out_of_service'), 0) AS out_of_service,
                COALESCE(SUM(vs.category = 'inactive'), 0) AS inactive,
                COALESCE(SUM(dp.id IS NOT NULL), 0) AS deployed
           {$from_} WHERE {$where}",
        $params
    ) ?: [];
    $cards = array_map('intval', $c + ['total' => 0]);
    foreach (['total', 'active', 'assigned', 'available', 'maintenance', 'out_of_service', 'inactive', 'deployed'] as $k) {
        $cards[$k] = (int)($cards[$k] ?? 0);
    }

    $docs = Database::fetch(
        "SELECT COALESCE(SUM(st = 'expiring'), 0) AS expiring, COALESCE(SUM(st = 'expired'), 0) AS expired
           FROM (SELECT " . trn_doc_status_sql('vdoc', 'vdt') . " AS st
                   FROM vehicle_documents vdoc JOIN vehicle_document_types vdt ON vdt.id = vdoc.document_type_id
                  WHERE vdoc.current_flag = 1 AND vdoc.vehicle_id IN ({$idSql})) x",
        $params
    ) ?: ['expiring' => 0, 'expired' => 0];
    $cards['docs_expiring'] = (int)$docs['expiring'];
    $cards['docs_expired'] = (int)$docs['expired'];

    $group = static function (string $labelExpr, string $idExpr, string $order = 'cnt DESC') use ($from_, $where, $params): array {
        return Database::fetchAll(
            "SELECT {$idExpr} AS gid, {$labelExpr} AS label, COUNT(*) AS cnt {$from_} WHERE {$where}
              GROUP BY gid, label ORDER BY {$order}, label ASC LIMIT 40",
            $params
        );
    };
    $series = static fn(array $rows, ?string $sub = null): array => array_map(
        static fn($r) => ['label' => (string)$r['label'], 'value' => (int)$r['cnt'], 'id' => $r['gid'] !== null ? (int)$r['gid'] : null],
        $rows
    );

    $charts = [];
    $charts['by_type'] = $series($group('vt.name', 'vt.id'));
    $charts['by_status'] = $series($group('vs.name', 'vs.id'));
    $charts['by_region'] = $series($group("COALESCE(rg.name, 'Not deployed')", 'rg.id'));
    $charts['by_district'] = $series($group("COALESCE(ds.name, 'Not deployed')", 'ds.id'));
    $charts['by_tehsil'] = $series($group("COALESCE(th.name, 'Not deployed')", 'th.id'));
    $charts['by_uc'] = $series($group("COALESCE(uc.name, 'Not deployed')", 'uc.id'));

    $charts['deployment'] = [
        ['label' => 'Deployed', 'value' => $cards['deployed']],
        ['label' => 'Not deployed', 'value' => $cards['total'] - $cards['deployed']],
    ];
    $charts['drivers'] = [
        ['label' => 'With a driver', 'value' => $cards['assigned']],
        ['label' => 'Without a driver', 'value' => $cards['total'] - $cards['assigned']],
    ];

    /* Monthly cost / consumption over the chosen range. */
    $months = trn_month_range($from, $to);
    $monthly = static function (string $sql, array $extra = []) use ($params, $months, $from, $to): array {
        $rows = Database::fetchAll($sql, $params + ['d_from' => $from, 'd_to' => $to] + $extra);
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['ym']] = (float)$r['val'];
        }
        $out = [];
        foreach ($months as $m) {
            $out[] = ['label' => (new DateTimeImmutable($m . '-01'))->format('M Y'), 'value' => round($map[$m] ?? 0, 2)];
        }
        return $out;
    };
    $charts['maintenance_cost'] = $monthly(
        "SELECT DATE_FORMAT(m.maintenance_date, '%Y-%m') AS ym, SUM(m.cost) AS val FROM vehicle_maintenance m
          WHERE m.deleted_at IS NULL AND m.maintenance_date BETWEEN :d_from AND :d_to AND m.vehicle_id IN ({$idSql}) GROUP BY ym"
    );
    $charts['fuel_cost'] = $monthly(
        "SELECT DATE_FORMAT(r.fuel_date, '%Y-%m') AS ym, SUM(r.total_cost) AS val FROM vehicle_fuel_records r
          WHERE r.deleted_at IS NULL AND r.fuel_date BETWEEN :d_from AND :d_to AND r.vehicle_id IN ({$idSql}) GROUP BY ym"
    );
    $charts['fuel_litres'] = $monthly(
        "SELECT DATE_FORMAT(r.fuel_date, '%Y-%m') AS ym, SUM(r.quantity) AS val FROM vehicle_fuel_records r
          WHERE r.deleted_at IS NULL AND r.fuel_date BETWEEN :d_from AND :d_to AND r.vehicle_id IN ({$idSql}) GROUP BY ym"
    );

    /* One query each: a named parameter may appear only once per statement. */
    $range = ['d_from' => $from, 'd_to' => $to];
    $tot = [
        'maint' => Database::fetchValue("SELECT COALESCE(SUM(m.cost),0) FROM vehicle_maintenance m WHERE m.deleted_at IS NULL AND m.maintenance_date BETWEEN :d_from AND :d_to AND m.vehicle_id IN ({$idSql})", $params + $range),
        'fuel_cost' => Database::fetchValue("SELECT COALESCE(SUM(r.total_cost),0) FROM vehicle_fuel_records r WHERE r.deleted_at IS NULL AND r.fuel_date BETWEEN :d_from AND :d_to AND r.vehicle_id IN ({$idSql})", $params + $range),
        'fuel_qty' => Database::fetchValue("SELECT COALESCE(SUM(r.quantity),0) FROM vehicle_fuel_records r WHERE r.deleted_at IS NULL AND r.fuel_date BETWEEN :d_from AND :d_to AND r.vehicle_id IN ({$idSql})", $params + $range),
    ];

    /* Documents that need attention. */
    $attention = Database::fetchAll(
        "SELECT * FROM (SELECT v.id AS vehicle_id, v.registration_no, vdt.name AS type_name, vdoc.expiry_date,
                               " . trn_doc_status_sql('vdoc', 'vdt') . " AS st
                          FROM vehicle_documents vdoc
                          JOIN vehicle_document_types vdt ON vdt.id = vdoc.document_type_id
                          JOIN vehicles v ON v.id = vdoc.vehicle_id
                         WHERE vdoc.current_flag = 1 AND vdoc.vehicle_id IN ({$idSql})) x
          WHERE st IN ('expired','expiring') ORDER BY expiry_date ASC LIMIT 10",
        $params
    );

    return [
        'cards' => $cards,
        'totals' => ['maintenance_cost' => (float)$tot['maint'], 'fuel_cost' => (float)$tot['fuel_cost'], 'fuel_litres' => (float)$tot['fuel_qty']],
        'charts' => $charts,
        'attention' => $attention,
        'range' => ['from' => $from, 'to' => $to],
        'today' => $today,
    ];
}

/**
 * Fuel efficiency per vehicle, from consecutive FULL-tank fills that both have
 * an odometer reading. Litres used for a stretch = every fill after the
 * earlier full-tank anchor up to and including the later one. A stretch is
 * only counted when its data is complete; otherwise nothing is reported, so
 * no misleading figure is ever shown.
 *
 * @return array<int,array{distance:float,litres:float,kmpl:?float,segments:int}> keyed by vehicle id
 */
function trn_fuel_efficiency(string $vehicleIdSql, array $params, ?string $from = null, ?string $to = null): array
{
    $rows = Database::fetchAll(
        "SELECT r.vehicle_id, r.fuel_date, r.quantity, r.odometer, r.is_full_tank
           FROM vehicle_fuel_records r
          WHERE r.deleted_at IS NULL AND r.vehicle_id IN ({$vehicleIdSql})
          ORDER BY r.vehicle_id ASC, r.fuel_date ASC, r.id ASC",
        $params
    );

    $out = [];
    $state = [];
    foreach ($rows as $r) {
        $vid = (int)$r['vehicle_id'];
        $st = &$state[$vid];
        $st ??= ['anchor' => null, 'litres' => 0.0];
        $qty = (float)$r['quantity'];
        $odo = $r['odometer'] !== null ? (float)$r['odometer'] : null;
        $full = (int)$r['is_full_tank'] === 1;

        if (!$full || $odo === null) {
            if ($st['anchor'] !== null) {
                $st['litres'] += $qty;
            }
            if ($full && $odo === null) {
                $st['anchor'] = null; // a full tank with no odometer breaks the chain
                $st['litres'] = 0.0;
            }
        } else {
            if ($st['anchor'] !== null && $odo > $st['anchor']) {
                $inRange = ($from === null || (string)$r['fuel_date'] >= $from) && ($to === null || (string)$r['fuel_date'] <= $to);
                $litres = $st['litres'] + $qty;
                if ($inRange && $litres > 0) {
                    $out[$vid] ??= ['distance' => 0.0, 'litres' => 0.0, 'kmpl' => null, 'segments' => 0];
                    $out[$vid]['distance'] += $odo - $st['anchor'];
                    $out[$vid]['litres'] += $litres;
                    $out[$vid]['segments']++;
                }
            }
            $st['anchor'] = $odo;
            $st['litres'] = 0.0;
        }
        unset($st);
    }
    foreach ($out as $vid => $v) {
        $out[$vid]['kmpl'] = $v['litres'] > 0 ? round($v['distance'] / $v['litres'], 2) : null;
    }
    return $out;
}
