# Transport module (v1.0.0)

Fleet management for the Solid Waste Management System: vehicles, driver assignment,
deployment, documents, maintenance, fuel, dashboard, reports and an audit trail.
**Trips / route management is out of scope** — the vehicle id is the hook a future
Trips module will use.

## Installing / upgrading
Nothing to run. Copy the files over an installed system and open **Transport** in the
sidebar. On first load the module creates its tables (`CREATE TABLE IF NOT EXISTS`),
registers its permissions and records its schema version, exactly like the HR module.
Nothing existing is altered or dropped.

Then, as Super Administrator:
1. **Roles & permissions** → tick the `Transport` permissions for the roles that need them.
2. **Transport → Configuration → Quick start** (optional) to create starter lists, or add
   your own vehicle types, statuses, ownership types, document types, maintenance and fuel types.
   Every list starts empty — nothing is hard-coded.
3. **Configuration → Settings** → choose which HR staff pool / type counts as *drivers*.

## What is reused
| Need | Reused |
|---|---|
| Drivers | `employees` (HR) filtered by the staff pool / type set in configuration |
| Geography | `regions`, `districts`, `tehsils`, `ucs`, `beats` |
| Area-based access | `hr_accessible_uc_ids()` — applied inside every query |
| Permissions / users | `Permission`, `roles`, `users` |
| Audit | shared `activity_log` (+ `transport_audit_changes` for old/new values and reason) |
| Uploads | HR's content-based type detection and blocked-extension list |
| Exports | existing `.xlsx` writer and TCPDF wrapper |

## Tables
`vehicle_types`, `vehicle_statuses`, `vehicle_ownership_types`, `vehicle_document_types`,
`maintenance_types`, `fuel_types`, `vehicles`, `vehicle_identifier_history`,
`vehicle_documents`, `vehicle_driver_assignments`, `vehicle_deployments`,
`vehicle_maintenance`, `vehicle_fuel_records`, `transport_audit_changes`.

## Key rules (enforced server-side)
- Vehicle number, tracker and RFID are unique; the number ignores case, spaces and dashes.
  Archiving a vehicle frees them for reuse; restoring re-checks.
- One current driver and one current deployment per vehicle — a database unique key,
  not just a check. Periods never overlap. Closing a row keeps it as history.
- A vehicle whose status is not in the *in service* group cannot take a new driver or
  deployment, and cannot be moved to such a status while it still has either.
- Restricted (area-scoped) users see only vehicles currently deployed in their UCs; ids in
  URLs, file downloads, lookups, dashboards and exports are all checked.
- Fuel total = quantity × rate, always recalculated by the server; odometer readings must be
  consistent with neighbouring readings; efficiency uses full-tank stretches only.
- Files live in `storage/transport-files/` (web-denied) and are served by `file.php` after a
  permission and scope check.

## Status behaviour groups
Names are free; each status belongs to one of: *In service*, *Under maintenance*,
*Out of service*, *Inactive*. Dashboard definitions: **Active** = in service;
**Assigned** = has a current driver; **Available** = in service without a driver.

## Customising
- Starter suggestions: `data/starter.json` (offered, never applied automatically).
- Permissions: `lib/schema.php` → `trn_permission_catalogue()`.
- Reports: `lib/reports.php` → `trn_report_catalogue()` / `trn_report_run()`.
