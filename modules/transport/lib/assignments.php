<?php
/**
 * Driver assignment and vehicle deployment — the business rules.
 *
 * Both work the same way and both keep full history:
 *   - A row is "current" while open_flag = 1 (end_date NULL). Closing a row
 *     sets end_date and clears open_flag; rows are never overwritten.
 *   - UNIQUE (vehicle_id, open_flag) means the database itself refuses a
 *     second current row for one vehicle, whatever the application does.
 *   - Periods are half-open [start, end): a vehicle may change driver on the
 *     same day the old one ends, but periods never overlap.
 *   - Every change runs in a transaction that also writes its audit row.
 *
 * Each function returns ['ok' => bool, 'message' => string].
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Why this vehicle cannot take a new driver / deployment, or null if it can. */
function trn_vehicle_blocked_reason(array $vehicle): ?string
{
    if ($vehicle['deleted_at'] !== null) {
        return 'This vehicle is archived. Restore it first.';
    }
    if ((string)$vehicle['status_category'] !== 'active') {
        return 'Vehicle ' . $vehicle['registration_no'] . ' is "' . $vehicle['status_name']
            . '", so it cannot take a new driver or deployment. Change its status first.';
    }
    return null;
}

/** Lock the vehicle row for the rest of the transaction (serialises concurrent changes). */
function trn_lock_vehicle(int $vehicleId): void
{
    Database::fetch('SELECT id FROM vehicles WHERE id = :id FOR UPDATE', ['id' => $vehicleId]);
}

function trn_is_duplicate_key(Throwable $e): bool
{
    return $e instanceof PDOException && (string)$e->getCode() === '23000';
}

/** Names for a geography chain — used in audit text and messages. */
function trn_geo_text(array $geo): string
{
    $row = Database::fetch(
        'SELECT u.name AS uc, t.name AS tehsil, d.name AS district, r.name AS region
           FROM ucs u JOIN tehsils t ON t.id = u.tehsil_id JOIN districts d ON d.id = t.district_id JOIN regions r ON r.id = d.region_id
          WHERE u.id = :id',
        ['id' => $geo['uc_id']]
    );
    if ($row === null) {
        return '#' . $geo['uc_id'];
    }
    $beat = !empty($geo['beat_id']) ? Database::fetchValue('SELECT name FROM beats WHERE id = :id', ['id' => $geo['beat_id']]) : null;
    return $row['uc'] . ($beat ? ' / ' . $beat : '') . ' (' . $row['tehsil'] . ', ' . $row['district'] . ', ' . $row['region'] . ')';
}

/* ------------------------------------------------------------------ *
 | Drivers                                                             |
 * ------------------------------------------------------------------ */

/**
 * Assign a driver ($mode 'assign': vehicle has none) or change the driver
 * ($mode 'change': closes the current one first).
 */
function trn_assign_driver(array $vehicle, int $employeeId, string $date, string $remarks, string $mode): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'message' => $m];
    $vehicleId = (int)$vehicle['id'];

    if ($blocked = trn_vehicle_blocked_reason($vehicle)) {
        return $fail($blocked);
    }
    $driver = trn_find_eligible_driver($employeeId);
    if ($driver === null) {
        return $fail(trn_driver_rules_configured()
            ? 'That person is not an active, eligible driver. Drivers come from the HR staff pool/type chosen in Transport Configuration.'
            : 'No driver eligibility rule is set yet. Choose the HR staff pool or type that counts as drivers in Transport Configuration.');
    }
    $start = trn_valid_date($date);
    if ($start === null) {
        return $fail('Enter a valid assignment date.');
    }
    if ($start > trn_today()) {
        return $fail('The assignment date cannot be in the future.');
    }

    try {
        Database::beginTransaction();
        trn_lock_vehicle($vehicleId);

        $open = Database::fetch(
            'SELECT a.*, e.name AS driver_name FROM vehicle_driver_assignments a JOIN employees e ON e.id = a.employee_id
              WHERE a.vehicle_id = :v AND a.open_flag = 1',
            ['v' => $vehicleId]
        );

        if ($open !== null) {
            if ((int)$open['employee_id'] === $employeeId) {
                Database::rollBack();
                return $fail($driver['name'] . ' is already the current driver of this vehicle.');
            }
            if ($mode !== 'change') {
                Database::rollBack();
                return $fail('This vehicle already has a current driver (' . $open['driver_name'] . '). Use "Change driver" instead.');
            }
            if ($start < (string)$open['assigned_date']) {
                Database::rollBack();
                return $fail('The new driver cannot start before the current driver\'s start date (' . trn_date((string)$open['assigned_date']) . ').');
            }
        } elseif ($mode === 'change') {
            Database::rollBack();
            return $fail('This vehicle has no current driver to change. Use "Assign driver".');
        }

        /* No overlap with earlier, already-closed periods. */
        $latestEnd = Database::fetchValue(
            'SELECT MAX(end_date) FROM vehicle_driver_assignments WHERE vehicle_id = :v AND open_flag IS NULL',
            ['v' => $vehicleId]
        );
        if ($latestEnd !== null && $start < (string)$latestEnd) {
            Database::rollBack();
            return $fail('That date overlaps an earlier assignment that ended on ' . trn_date((string)$latestEnd) . '.');
        }

        /* One vehicle per driver, unless Configuration allows several. */
        if (trn_setting('transport_driver_multi') !== '1') {
            $other = Database::fetch(
                'SELECT v.registration_no FROM vehicle_driver_assignments a JOIN vehicles v ON v.id = a.vehicle_id
                  WHERE a.employee_id = :e AND a.open_flag = 1 AND a.vehicle_id <> :v LIMIT 1',
                ['e' => $employeeId, 'v' => $vehicleId]
            );
            if ($other !== null) {
                Database::rollBack();
                return $fail($driver['name'] . ' is already the current driver of vehicle ' . $other['registration_no'] . '. Remove them from it first.');
            }
        }

        if ($open !== null) {
            Database::execute(
                'UPDATE vehicle_driver_assignments SET end_date = :d, open_flag = NULL, ended_by = :by, end_reason = :r WHERE id = :id',
                ['d' => $start, 'by' => Auth::id(), 'r' => 'Replaced by ' . $driver['name'], 'id' => (int)$open['id']]
            );
        }

        $newId = Database::insert('vehicle_driver_assignments', [
            'vehicle_id' => $vehicleId, 'employee_id' => $employeeId, 'assigned_date' => $start, 'open_flag' => 1,
            'remarks' => $remarks !== '' ? $remarks : null, 'assigned_by' => Auth::id(),
        ]);

        if ($open !== null) {
            trn_audit('driver.changed', 'vehicle', $vehicleId,
                'Driver of ' . $vehicle['registration_no'] . ' changed from ' . $open['driver_name'] . ' to ' . $driver['name'],
                ['Driver' => $open['driver_name']], ['Driver' => $driver['name'], 'From' => $start], $remarks, $vehicleId);
        } else {
            trn_audit('driver.assigned', 'vehicle', $vehicleId,
                $driver['name'] . ' assigned as driver of ' . $vehicle['registration_no'],
                null, ['Driver' => $driver['name'], 'From' => $start], $remarks, $vehicleId);
        }

        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        if (trn_is_duplicate_key($e)) {
            return $fail('This vehicle was just given a driver by someone else. Reload and check the current driver.');
        }
        return $fail(Database::friendlyError($e));
    }

    return ['ok' => true, 'message' => $open !== null
        ? 'Driver changed to ' . $driver['name'] . '. The previous assignment is kept in history.'
        : $driver['name'] . ' is now the driver of ' . $vehicle['registration_no'] . '.'];
}

/** Remove (end) the current driver. The row is closed, never deleted. */
function trn_end_driver(array $vehicle, string $date, string $reason): array
{
    $vehicleId = (int)$vehicle['id'];
    $end = trn_valid_date($date);
    if ($end === null) {
        return ['ok' => false, 'message' => 'Enter a valid end date.'];
    }
    if ($end > trn_today()) {
        return ['ok' => false, 'message' => 'The end date cannot be in the future.'];
    }

    try {
        Database::beginTransaction();
        trn_lock_vehicle($vehicleId);
        $open = Database::fetch(
            'SELECT a.*, e.name AS driver_name FROM vehicle_driver_assignments a JOIN employees e ON e.id = a.employee_id
              WHERE a.vehicle_id = :v AND a.open_flag = 1',
            ['v' => $vehicleId]
        );
        if ($open === null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'This vehicle has no current driver.'];
        }
        if ($end < (string)$open['assigned_date']) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'The end date cannot be before the start date (' . trn_date((string)$open['assigned_date']) . ').'];
        }
        Database::execute(
            'UPDATE vehicle_driver_assignments SET end_date = :d, open_flag = NULL, ended_by = :by, end_reason = :r WHERE id = :id',
            ['d' => $end, 'by' => Auth::id(), 'r' => $reason !== '' ? $reason : null, 'id' => (int)$open['id']]
        );
        trn_audit('driver.removed', 'vehicle', $vehicleId,
            $open['driver_name'] . ' removed as driver of ' . $vehicle['registration_no'],
            ['Driver' => $open['driver_name'], 'From' => $open['assigned_date']], ['Ended' => $end], $reason, $vehicleId);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return ['ok' => false, 'message' => Database::friendlyError($e)];
    }
    return ['ok' => true, 'message' => $open['driver_name'] . ' was removed from ' . $vehicle['registration_no'] . '. The assignment is kept in history.'];
}

/* ------------------------------------------------------------------ *
 | Deployments                                                         |
 * ------------------------------------------------------------------ */

/**
 * Deploy a vehicle to Region → District → Tehsil → UC (→ Beat), or change its
 * deployment ($mode 'change' closes the current one). $inTransaction lets the
 * Add Vehicle handler create the first deployment inside its own transaction.
 *
 * @param array{region_id:int,district_id:int,tehsil_id:int,uc_id:int,beat_id:?int} $geo already validated
 */
function trn_deploy_vehicle(array $vehicle, array $geo, string $date, string $remarks, string $mode, bool $inTransaction = false): array
{
    $fail = static function (string $m) use ($inTransaction): array {
        if (!$inTransaction) {
            Database::rollBack();
        }
        return ['ok' => false, 'message' => $m];
    };
    $vehicleId = (int)$vehicle['id'];

    if ($blocked = trn_vehicle_blocked_reason($vehicle)) {
        return ['ok' => false, 'message' => $blocked];
    }
    $start = trn_valid_date($date);
    if ($start === null) {
        return ['ok' => false, 'message' => 'Enter a valid start date.'];
    }
    if ($start > trn_today()) {
        return ['ok' => false, 'message' => 'The start date cannot be in the future.'];
    }
    if (!hr_can_access_uc($geo['uc_id'])) {
        return ['ok' => false, 'message' => 'Unauthorized action — that UC is outside your area.'];
    }

    try {
        if (!$inTransaction) {
            Database::beginTransaction();
        }
        trn_lock_vehicle($vehicleId);

        $open = Database::fetch(
            'SELECT d.*, u.name AS uc_name FROM vehicle_deployments d JOIN ucs u ON u.id = d.uc_id WHERE d.vehicle_id = :v AND d.open_flag = 1',
            ['v' => $vehicleId]
        );
        if ($open !== null) {
            if ($mode !== 'change') {
                return $fail('This vehicle is already deployed (' . $open['uc_name'] . '). Use "Change deployment" instead.');
            }
            if ((int)$open['uc_id'] === $geo['uc_id'] && (int)($open['beat_id'] ?? 0) === (int)($geo['beat_id'] ?? 0)) {
                return $fail('The vehicle is already deployed to that location.');
            }
            if ($start < (string)$open['start_date']) {
                return $fail('The new deployment cannot start before the current one (' . trn_date((string)$open['start_date']) . ').');
            }
        } elseif ($mode === 'change') {
            return $fail('This vehicle has no current deployment to change. Use "Deploy vehicle".');
        }

        $latestEnd = Database::fetchValue(
            'SELECT MAX(end_date) FROM vehicle_deployments WHERE vehicle_id = :v AND open_flag IS NULL',
            ['v' => $vehicleId]
        );
        if ($latestEnd !== null && $start < (string)$latestEnd) {
            return $fail('That date overlaps an earlier deployment that ended on ' . trn_date((string)$latestEnd) . '.');
        }

        $newText = trn_geo_text($geo);
        if ($open !== null) {
            $oldText = trn_geo_text(['uc_id' => (int)$open['uc_id'], 'beat_id' => $open['beat_id'] !== null ? (int)$open['beat_id'] : null]);
            Database::execute(
                'UPDATE vehicle_deployments SET end_date = :d, open_flag = NULL, ended_by = :by, end_reason = :r WHERE id = :id',
                ['d' => $start, 'by' => Auth::id(), 'r' => 'Redeployed', 'id' => (int)$open['id']]
            );
        }

        Database::insert('vehicle_deployments', [
            'vehicle_id' => $vehicleId, 'region_id' => $geo['region_id'], 'district_id' => $geo['district_id'],
            'tehsil_id' => $geo['tehsil_id'], 'uc_id' => $geo['uc_id'], 'beat_id' => $geo['beat_id'],
            'start_date' => $start, 'open_flag' => 1, 'remarks' => $remarks !== '' ? $remarks : null, 'assigned_by' => Auth::id(),
        ]);

        if ($open !== null) {
            trn_audit('deployment.changed', 'vehicle', $vehicleId,
                $vehicle['registration_no'] . ' redeployed to ' . $newText,
                ['Deployment' => $oldText], ['Deployment' => $newText, 'From' => $start], $remarks, $vehicleId);
        } else {
            trn_audit('deployment.created', 'vehicle', $vehicleId,
                $vehicle['registration_no'] . ' deployed to ' . $newText,
                null, ['Deployment' => $newText, 'From' => $start], $remarks, $vehicleId);
        }

        if (!$inTransaction) {
            Database::commit();
        }
    } catch (Throwable $e) {
        if (!$inTransaction) {
            Database::rollBack();
        }
        if (trn_is_duplicate_key($e)) {
            return ['ok' => false, 'message' => 'This vehicle was just deployed by someone else. Reload and check its current deployment.'];
        }
        if ($inTransaction) {
            throw $e;
        }
        return ['ok' => false, 'message' => Database::friendlyError($e)];
    }

    return ['ok' => true, 'message' => $open !== null
        ? 'Deployment changed. The previous deployment is kept in history.'
        : $vehicle['registration_no'] . ' is now deployed to ' . $newText . '.'];
}

/** End the current deployment (vehicle becomes un-deployed). The row is closed, never deleted. */
function trn_end_deployment(array $vehicle, string $date, string $reason): array
{
    $vehicleId = (int)$vehicle['id'];
    $end = trn_valid_date($date);
    if ($end === null) {
        return ['ok' => false, 'message' => 'Enter a valid end date.'];
    }
    if ($end > trn_today()) {
        return ['ok' => false, 'message' => 'The end date cannot be in the future.'];
    }

    try {
        Database::beginTransaction();
        trn_lock_vehicle($vehicleId);
        $open = Database::fetch(
            'SELECT d.*, u.name AS uc_name FROM vehicle_deployments d JOIN ucs u ON u.id = d.uc_id WHERE d.vehicle_id = :v AND d.open_flag = 1',
            ['v' => $vehicleId]
        );
        if ($open === null) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'This vehicle has no current deployment.'];
        }
        if (!hr_can_access_uc((int)$open['uc_id'])) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'Unauthorized action — that UC is outside your area.'];
        }
        if ($end < (string)$open['start_date']) {
            Database::rollBack();
            return ['ok' => false, 'message' => 'The end date cannot be before the start date (' . trn_date((string)$open['start_date']) . ').'];
        }
        Database::execute(
            'UPDATE vehicle_deployments SET end_date = :d, open_flag = NULL, ended_by = :by, end_reason = :r WHERE id = :id',
            ['d' => $end, 'by' => Auth::id(), 'r' => $reason !== '' ? $reason : null, 'id' => (int)$open['id']]
        );
        trn_audit('deployment.ended', 'vehicle', $vehicleId,
            $vehicle['registration_no'] . ' withdrawn from ' . $open['uc_name'],
            ['Deployment' => $open['uc_name'], 'From' => $open['start_date']], ['Ended' => $end], $reason, $vehicleId);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return ['ok' => false, 'message' => Database::friendlyError($e)];
    }
    return ['ok' => true, 'message' => $vehicle['registration_no'] . ' was withdrawn from ' . $open['uc_name'] . '. The deployment is kept in history.'];
}
