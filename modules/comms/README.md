# Communication & Task Management (0.5.0)

Status: Phases 1-4 complete (foundation, full chat, Settings, Tasks, submission with evidence, independent review, reviewer reassignment, reopening, extensions, overdue, reminders). Notification bell, dashboards, exports, archive and the audit viewer (Phase 5) are NOT implemented yet. See SETUP-GUIDE.md.

- Entry: `modules/comms/index.php` (needs `comms.access`). Menu: Communication.
- Permissions: 28 independent `comms.*` slugs (see `lib/schema.php`). Grant them on Roles & permissions. Nothing is granted automatically; Super Admin holds all.
- Tables: `comms_migrations`, `comms_audit_log`. Add new `migrations/NNN_name.sql` files and bump `COMMS_SCHEMA_VERSION`.
- Settings keys (system_settings, group `comms`) and defaults: `lib/core.php::comms_setting_defaults()`.
- Real-time: AJAX polling only (planned), no extra server process, no internet needed.
- Test: `php tools/comms-selftest.php --confirm-test-database` (throwaway DB whose name contains "test").
