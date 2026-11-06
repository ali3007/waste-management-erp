# Changelog

## 1.13.0 — Communication & Task Management, Phases 1-4 (chat, tasks, review)
- New module `modules/comms/`: bootstrap, versioned additive migration runner, 28 independent `comms.*` permissions, live eligibility helpers (`comms_eligible_users`), append-only audit table `comms_audit_log`, settings defaults (stored in `system_settings`, group `comms`), workspace shell with Chat / Tasks / Notifications tabs (placeholders only).
- Additive edits: `includes/sidebar.php` (Communication menu entry), `modules/users/bootstrap.php` (module listed so its permissions appear on the Roles screen), `config.php` (version), `assets/css/comms.css` (new, module-scoped).
- New table: `comms_audit_log`, `comms_migrations`. No existing table or data changed.
- Phase 2a: private chats and groups (text), unread counts, Sent/Delivered/Read, online/last seen, typing, replies, offline retry without duplicates, group membership windows, Settings page. New tables `comms_conversations`, `comms_members`, `comms_messages`, `comms_presence`.
- Phase 2b: file attachments (content-checked, private storage, expiring signed links, preview/download), voice messages, reactions, forwarding, message search, edit/delete with time limit and moderation, delete-for-me, pinning, group announcements, group name/picture, reader lists, storage limit with warning. New tables `comms_attachments`, `comms_reactions`, `comms_pins`, `comms_message_hidden`, `comms_message_edits`; new columns on `comms_messages` / `comms_conversations`.
- Phase 3 (Tasks): two-step creation with confirmation, assignment, per-employee acceptance/decline/progress, checklists with versions, templates, comments/replies/mentions, optional links to ERP records, per-task history, cancellation, task list with filters and pagination, in-app notification records. New tables `comms_tasks`, `comms_task_assignees`, `comms_task_progress`, `comms_task_checklists`, `comms_task_checklist_items`, `comms_task_step_log`, `comms_task_comments`, `comms_task_mentions`, `comms_task_edits`, `comms_task_events`, `comms_task_templates`, `comms_notifications`.
- Phase 4: completion submission with evidence, independent review (approve/reject with reasons, rounds kept), self-approval blocked, reviewer unavailability flag and manual reassignment, reopening for correction, deadline extension requests, deadline history, overdue handling, advance reminders and review-overdue notices, opportunistic scheduler plus `tools/comms-cron.php`. New tables `comms_task_submissions`, `comms_task_evidence`, `comms_task_reviewer_changes`, `comms_task_reopens`, `comms_task_extensions`, `comms_task_deadline_history`, `comms_task_reminders`. Changed: setting an advance reminder now needs `comms.deadline_extend`.
- Tests: `tools/comms-selftest.php` (76), `tools/comms-review-selftest.php` (179), `tools/comms-tasks-selftest.php` (176), `tools/comms-extras-selftest.php` (126), `tools/comms-chat-selftest.php` (66), plus browser-simulated runs (34 + 58 chat, 58 tasks, 51 review).

## 1.0.0 — Phase 1

Entrance, installation and authentication foundation.

**Installation**
- Five-step wizard: requirements, database, company, Super Administrator, done
- Live connection test before anything is written; refuses a database that
  already holds an installation
- Generates `database/config.generated.php` and locks itself afterwards

**Authentication**
- Unified Email / CNIC sign-in with automatic account-type detection
- Password hashing, CSRF protection, prepared statements, session hardening
- Per-IP throttling, remember-me with rotating tokens
- Password reset and account activation foundations (hashed, expiring,
  single-use tokens)
- Account states: active, inactive, pending, blocked

**Foundation**
- Role and permission tables with a centralised `Permission::can()` check
- Module registry table for later phases
- Company branding stored in settings, surfaced on the login card
- Neomorphic design system with full light and dark themes
- Reusable component kit: buttons, inputs, alerts, modal, cards, uploader,
  theme switcher, loading states
- Protected application shell with sidebar navigation

**Not in this release** — HR, sites, attendance, transport, complaints,
payroll, inventory, operations, reports, maps and the mobile app.

## 1.1.0 — Phase 2: User Management

Built entirely inside `modules/users/`. The only Phase 1 file touched is
`includes/sidebar.php`, where two navigation links were added — exactly
the additive change `modules/README.md` describes. No Phase 1 table, page
or permission slug was altered; the permissions this module uses
(`users.view/create/update/delete`, `roles.view/update`) were already
seeded by the Phase 1 installer.

**Users**
- List with search (name, email, CNIC) and role/status filters, paginated
- Create: choose CNIC or email as the sign-in identifier, assign a role,
  optional avatar — produces a one-time activation link, shown once, never
  a password (nobody but the new user ever sets it)
- Edit: name, phone, avatar, role — the sign-in identifier itself is fixed
  after creation
- View: full profile, recent activity, and account actions
- Resend activation link for accounts still pending
- Status transitions: deactivate, block, reactivate — signs out every
  remembered device on change
- Guards: the Super Administrator account cannot be edited or have its
  status changed from this screen; nobody can change their own role or
  status here

**Roles & permissions**
- Create custom roles (Supervisor, HR Manager, Field Staff, etc.) with a
  name, seniority level and description
- Permission matrix grouped by module, with a "select all" per group —
  built from whatever is in the `permissions` table, so a later phase's
  permissions appear here automatically
- Delete a custom role once no user holds it; system roles (Super Admin,
  Administrator) can't be deleted, and Super Admin's own permissions can't
  be edited, since it always holds everything

**Removed** — `tools/create-user.php` is superseded by this module's
create screen and is no longer the recommended path, though it still
works unchanged for scripted/bulk setups.

**Not in this release** — bulk import, CSV export, per-field audit diffs,
and email delivery of the activation link (still shown on screen, per
Phase 1's design).

## 1.8.0 — HR module completed

Finishes everything the HR specification asked for beyond Stage 1 (HR types,
employee master, assignments). **No reinstall needed** — see "Upgrading" in
`modules/hr/README.md`; the first HR page you open applies the small additive
database update itself.

**Employee profile**
- Corporate profile page: company branding, photo, ID, status badges,
  personal / employment information, registered area, current assignment(s)
  with history, documents
- Download **Employee Profile PDF** (A4, letterhead, photo, footer with
  page numbers and generation date) and **Employment Letter PDF** (active
  employees only, always from the latest database values)
- PDFs use the company name, logo, address and contact details configured
  at installation; the PDF library (TCPDF) is bundled in `libs/tcpdf`

**Documents**
- Employee Picture, CNIC Picture and any number of other documents, from
  the Add/Edit form or the profile page
- Stored privately in `storage/employee-docs/` and served only through
  `modules/hr/file.php` after a permission and area-scope check
- File type is detected from the file's content, not its name; scriptable
  and executable types (including `shell.php.jpg`) are refused; size limits
  (2 MB pictures, 5 MB documents) and decompression-bomb dimension limits
  are enforced server-side

**Dashboard & reports**
- HR Dashboard: live summary cards, type-wise counts per pool, and five
  charts (staff pool, HR type, UC-wise, Beat-wise, active vs inactive) —
  dependency-free SVG, theme-aware, no hard-coded numbers
- HR Reports: eight filters that combine, count breakdowns by pool, type,
  region, district, tehsil, UC, beat and status, and the matching list
- CSV export of the filtered employee list and of the summary counts
  (formula-injection safe, Excel-friendly UTF-8)

**Bulk import**
- Template → upload → validate → preview → confirm → import
- Row-numbered errors (invalid CNIC, missing name, invalid region/district/
  tehsil/pool/type, bad dates), duplicate CNICs skipped (against the
  database and within the file), nothing imported silently
- Confirm runs in one transaction and re-checks duplicates at insert time
- Imported employees start in Unassigned Employees

**Lists, filters, access control**
- All Employees / Unassigned / Assigned rebuilt as tables with search and
  cascading filters (pool, type, region, district, tehsil, UC, beat, status)
- Area-based access applied to every list, report, export, PDF, document
  and edit/assign endpoint — typing another employee's id returns 403
- New permissions: employee documents (view/upload/download/delete),
  profile PDF, employment letter, HR dashboard, HR reports, report export,
  CSV import. Existing roles keep exactly what they had; tick the new boxes
  under Users → Roles to grant them (Super Administrator has all).
- Editing an employee to a staff type with a different assignment rule
  closes the old assignment (history kept) and returns them to Unassigned
- Inactive employees can no longer be assigned

## 1.9.0 — Complaints Management module

New module (**Complaints**), built on the existing users/roles/permissions, Region→Beat
structure and HR employees/assignments. No existing table is changed; no reinstall needed.
See `modules/complaints/README.md` for setup, access rules and limits.

- Complaint create / edit / detail with generated unique numbers (configurable prefix),
  duplicate warning, optional GPS, attachments and a full append-only timeline
- Assignment by UC: the Supervisor and Monitoring Officer(s) HR says cover that UC are shown,
  notified (bell + list) and are the only field users who can see the complaint
- Workflow New → Assigned → In Progress → Ready for Resolution → Resolved, Reopened, Rejected,
  with mandatory reasons, soft delete/restore, reassignment history and resolution history
- Configurable sources, types, priorities, SLA rules (within / nearing / breached),
  status labels and numbering — nothing pre-filled
- Before / After evidence galleries: folder upload in batches, drag-and-drop order,
  thumbnails + preview + original, modal viewer with zoom, swipe and keyboard
- Dashboard: summary cards, six live charts (Chart.js, bundled locally), listing,
  quick date ranges and click-to-filter, shareable URLs
- Reports: all filters, CSV / Excel / PDF export, per-complaint PDF
- PowerPoint generator that preserves an uploaded template design (details slide + one
  Before/After pair per slide) with a preview step and template management
- Server-side UC scope on every list, chart, export, file and action

## 1.10.0 — Transport module

Fleet management: vehicles, drivers, deployment, documents, maintenance and fuel.
Trips / route management is intentionally **not** included.

**Vehicles**
- Add / edit / archive (soft delete) / restore; picture upload (re-encoded, size-bounded)
- Unique vehicle number (spacing and dashes ignored), tracker and RFID, enforced by the database
- Registration, tracker and RFID change history; chassis / engine duplicate protection
- Status behaviour groups (in service, maintenance, out of service, inactive) drive dashboard
  counts and block invalid actions (no driver or deployment for a vehicle that is not in service)

**Drivers and deployment** (history is never overwritten)
- Drivers are existing HR employees; the eligible staff pool / type is chosen in configuration
- One current driver per vehicle (database-enforced), optional one-vehicle-per-driver rule
- Region → District → Tehsil → UC → Beat deployment with validated hierarchy and full history
- Area-based access: restricted users only see and change vehicles deployed inside their UCs

**Documents, maintenance, fuel**
- Document types are configurable (required, expiry, warning window); replace keeps old copies
- Valid / Expiring soon / Expired computed live; missing-required-document report
- Maintenance records with invoice upload; fuel records with server-side cost and odometer checks
- Fuel efficiency measured only from complete full-tank stretches

**Dashboard, reports, audit**
- Live dashboard with interactive charts and region→UC / type / status / driver / date filters
- 22 reports with Excel, CSV and PDF export (same filters and scope as the screen)
- Audit trail with old / new values and reasons, on the shared activity log
- 26 new permissions; sidebar entry; nothing is pre-filled (optional Quick start in configuration)

## 1.11.0 — Attendance

Attendance Management for Office Staff (self-attendance) and Field / Transport Staff
(marked by their Supervisor). Everything reuses existing data: users, roles and
permissions, HR employees, staff pools / types and UC assignments, the Structure
geography, `system_settings` and the shared `activity_log`. Only `includes/sidebar.php`
was touched outside the new `modules/attendance` folder (menu entry; "Attendance" removed
from the coming-soon list).

**Marking**
- Office Staff record their own Check-in / Check-out; Supervisors record it, one employee at a
  time, for active staff currently assigned to their UC. No bulk entry, no date or time input.
- Date and time are the server's, in Asia/Karachi. Windows are enforced on the server for every
  user, Super Admin included; a closed window is never reopened. Check-out needs a Check-in; no
  duplicates (database row lock + guarded updates — verified with simultaneous requests).
- Present only when both events are recorded inside their windows.

**Rules engine (scheduler)**
- Missed Check-in → Pending Review; Check-in with no Check-out → Incomplete; unresolved at
  12:00 AM Asia/Karachi → Absent. Leave, Off Day, Holiday and valid Present are never overwritten.
- Idempotent, locked against overlapping runs, recovers any missed days after downtime, records
  every run (`attendance_job_runs`). `cron/sweep.php` (CLI or token URL) plus a throttled
  catch-up when attendance pages open.
- Sunday = Off Day for everyone; yearly holidays apply to Office Staff only; approved leave only.

**Configuration (all data, nothing hard-coded)**
- Versioned timing: company default + optional per-UC override; changes apply from tomorrow.
- Which HR pools / types are self-marked, Supervisor-marked, or are Supervisors.
- Office Staff holiday calendar; leave workflow (Pending / Approved / Rejected, with history).

**Review, correction, reporting, audit**
- Pending Review decisions (who / when / what / why) — cannot enter a late time.
- Attendance Correction under a separate "Attendance Edit" permission, original kept.
- Dashboard (live), Daily / Weekly / Monthly / range reports with region → UC / supervisor /
  category / status filters, Excel and PDF export using the same query and scope as the screen.
- Records keep the category, UC and Supervisor as they were on the day, so transfers never
  rewrite history. Every action, refusal and automatic transition is audited.
- 18 permissions, versioned SQL migration (`modules/attendance/migrations`), CLI self-test
  `tools/attendance-selftest.php` (refuses to run on a non-test database).

**HR addition (1.11.0)** — Edit Employee now has a "Login account" card to link / unlink an employee's
user account (`employees.user_id`) through a separate form (`modules/hr/process/employee-link-user.php`):
CSRF-protected, same permission and area scope as editing the employee, one account per employee,
only Active/Pending accounts, audited in the activity log.

**1.11.0 note** — Supervisor staff types now mark their own attendance by default (My Attendance), until categories are saved explicitly in Attendance Setup.

## 1.11.1 — Attendance fixes

- **Supervisor saw the wrong UC / no workers.** The Users module creates a CNIC account but never sets
  `employees.user_id`, so attendance did not recognise the signed-in Supervisor as an employee, treated
  the account as unrestricted and showed the first UC alphabetically. A CNIC-login account is now matched
  to the employee with the same CNIC (read-only; an employee linked to another account is never taken
  over). A non-Super-Admin account that matches no employee can no longer mark attendance or submit
  leave for others (fail closed) and sees an explanatory message. Supervisor staff types are also found
  by name containing "supervisor" when none is configured.
- **Timing changes applied only from tomorrow.** Edits were forced to a tomorrow-or-later effective date.
  Edits are now effective today by default; a later date still schedules a future change; past dates are
  refused. After an immediate change, unmarked cases the old window had closed are re-opened (or flagged
  if the window was shortened); recorded Check-in/Check-out times and past days are never altered.
- No database changes.

## 1.12.0 — Attendance: independent timing per staff category

- Office, Field and Transport Staff now each have their **own** check-in / check-out windows
  (extends `attendance_timing_configs`; no new table). Scopes: `company` (fallback, unchanged),
  `pool-<id>` (category), `uc-<id>-pool-<id>` (one category inside one UC); legacy `uc-<id>` rows
  keep working. Resolution: category-in-UC → category → company. Saving one category never
  touches another — verified for every pair.
- One resolver (`att_config_for`) now serves the marking screens, the server-side window check and
  the scheduler, so they cannot disagree. The Supervisor screen shows the selected category's windows.
- Permissions: a "Manage <category> attendance timing" permission is registered per HR staff pool
  (e.g. `attendance.timing_manage_office_staff`); `timing_manage` still manages everything, including
  the company default; refused attempts are audited (`timing_blocked`).
- Changes remain effective today (immediate) with optional future-dated scheduling; recorded
  attendance and past days are never altered.
- Database: migration `002_timing_category_scope.php` (idempotent; widens `scope_key` to VARCHAR(40),
  adds nullable `staff_pool_id` + index + FK). Existing rows are untouched.

## 1.12.1 — Audit and bug-fix release

Full audit of the existing ERP (see AUDIT-REPORT.md). No database schema changes.

- **Fixed — every user showed the same profile picture on Edit/View.** `includes/header.php` assigned a variable
  named `$avatar` (the signed-in user's chip) into page scope, overwriting the page's own `$avatar`.
  Header now uses header-local names; the Users pages use `$userAvatar`; `avatar_url()` refuses path-like
  values; replacing a picture no longer deletes a file another account still references.
- **Fixed — Supervisor saw an employee but marking was refused.** Employees with several open UC assignments
  were authorised against their lowest UC only; authorisation now checks all of the employee's open UCs
  against the Supervisor's UCs. (Earlier in 1.11.1: CNIC-login accounts are matched to their employee.)
- **Fixed (security) — privilege escalation in User Management.** A user who could edit roles or users could
  grant themselves/others any permission. A non-Super-Admin may now only grant or assign permissions they hold,
  cannot edit their own role, and cannot modify, block, demote or re-activate accounts/roles above their access.
- **Fixed — Roles screen could not list or grant a module's permissions (e.g. Transport) until that module had
  been opened once.** The Roles screen now registers every installed module's permissions first.
- Attendance: accurate message when a Supervisor's UC is inactive; `.htaccess` for internal folders.
- Tests: `tools/attendance-selftest.php` (186 checks) and new `tools/erp-regression.php` (23 checks).
