# ERP Audit and Bug-Fix Report — release 1.12.1

Environment used: PHP 8.3 CLI + built-in web server, MariaDB 10.11, a real installer run, node/jsdom for the page JavaScript.
**Not used:** Apache, a real browser, your production database, a mail server. Statuses below reflect what was actually executed.

## 1. Project inspected
231 PHP files (0 syntax errors), 72 form/AJAX handlers, 7 modules: Users & Roles, HR, Structure (Region→District→Tehsil→UC→Beat),
Complaints, Transport, Attendance, plus Auth/Session/Installer. 61 tables, 109 foreign keys.

## 2. Reported bugs
| # | Bug | Root cause | Status |
|---|-----|-----------|--------|
| 1a | Supervisor sees employees, marking fails | Employees with **several open UC assignments** (HR "multi" types) were authorised against their *lowest* UC only; the roster uses all UCs. Reproduced with a differential test (roster vs authority): 1 mismatch → 0 after fix. | Fixed and Tested |
| 1b | Supervisor not recognised after account creation (fixed in 1.11.1) | Users module creates the CNIC account but never sets `employees.user_id`; attendance now matches CNIC → employee. Reproduced: Supervisor was shown another UC. | Fixed and Tested |
| 1c | Happy path | A properly configured Supervisor could already check in/out through the real page JavaScript (request → JSON → success message → DB row; duplicate refused; persists on refresh; forged ids refused). | Verified (no defect) |
| 2 | Every user shows the same profile picture on Edit/View | `includes/header.php` assigned `$avatar` (signed-in user's chip) into page scope, **overwriting** the page's own `$avatar` set before the header include. Reproduced through the real UI (even a user with his own picture showed the admin's). List page was never affected. | Fixed and Tested |

**If marking still fails on your data:** open *Attendance → Attendance Audit*, filter Outcome = Blocked. Each refusal records its reason code
(`not_authorized`, `window_closed`, `no_timing`, `non_working`…). I could not see your database, so I cannot rule out a data-specific cause beyond the two I reproduced.

## 3. Additional defects found
| Priority | Defect | Status |
|---|---|---|
| **High (security)** | **Privilege escalation in User Management.** A user with only `roles.update` could edit *their own role* and grant themselves all 98 permissions; a user with `users.create/update/delete` could create, demote, block or re-activate accounts holding more power than themselves. Proven on the original code, refused after the fix. | Fixed and Tested |
| High | **Roles screen could not list/grant a module's permissions until that module had been opened once** (installer seeds only 7 core permissions; each module registers its own lazily). This is why Transport permissions were "missing". Simulated fresh install: 7 → 98 permissions, 26 Transport. | Fixed and Tested |
| Low | Replacing a user's picture deleted the old file without checking another account referenced it. | Fixed and Tested |
| Low | `avatar_url()` followed any stored value; now only bare file names. | Fixed and Tested |
| Low | Supervisor page said "not assigned to a UC" when the UC was merely deactivated. | Fixed and Tested |
| Low | Attendance internal folders lacked `.htaccess`. | Fixed (Apache rule not exercised) |
| Info | Role "level" is displayed but never used for authorisation (replaced by the subset rule above). | Documented |
| Info | Deactivating a UC with employees still assigned is allowed without warning. | Not changed (business decision) |
| Info | Logout works via GET (logout-CSRF nuisance). | Not changed |
| Info | HR export is CSV-only (xlsx/pdf parameters return CSV). | Not changed (by design) |
| Info | **No database backup/restore feature exists** (only soft-delete restore of records). | Not applicable |

## 4. Verified sound (no defect found)
Auth (throttling, session rotation, fingerprinting, blocked accounts denied immediately, no account enumeration on reset, installer locked after install, `APP_ENV=production` by installer);
all 72 handlers refused anonymous and zero-permission users; no SQL injection found (dynamic SQL uses whitelists/int casts); output escaping clean
(a stored `<script>` name renders escaped); uploads validated by content (PHP, polyglot GIF, HTML, SVG, fake PNG, double extension all rejected),
random stored names, downloads gated and `nosniff`; DB: all tables InnoDB/utf8mb4 with primary keys, every FK indexed, integrity queries clean.

## 5. Files changed
`includes/header.php` (root cause of bug 2) · `modules/users/{edit,view}.php` · `modules/users/process/{update,create,status,resend-activation,role-save,role-delete}.php` ·
`modules/users/{bootstrap,roles}.php` · `core/helpers.php` · `modules/attendance/lib/{access,marking,leave}.php` · `modules/attendance/{supervisor.php,process/mark.php}` ·
`modules/attendance/{lib,migrations,partials}/.htaccess` · `tools/attendance-selftest.php` · `tools/erp-regression.php` (new) · `CHANGELOG.md` · `config.php` (version) · this report.
`audit-changes.patch` contains the exact diff against the file you uploaded (`git apply -R audit-changes.patch` rolls everything back).

## 6. Database changes
None. No migrations, no data modified.

## 7. Tests executed (actual results)
* `php -l` on 231 files — 0 errors.
* `tools/attendance-selftest.php` — **186/186 passed** (includes 28 new checks for the bugs above; real MariaDB, simulated PKT clock, 3-process concurrency).
* `tools/erp-regression.php` — **23/23 passed** (header collision, avatar handling, delegation rule, permission registration).
* HTTP against a real install: profile pictures on list/view/edit for users with/without pictures, picture independence, shared-file protection, missing/traversal values;
  escalation attacks before (succeeded) and after (refused); Roles page on a simulated fresh install; installer re-run; tools/ and lib/ exposure; 72 handlers × 4 request types; every page as admin and as a zero-permission user;
  HR search/filter/pagination/export/bad ids; Transport (22 reports, exports, vehicle/driver/fuel/maintenance creation, duplicate vehicle); Complaints (create, workflow incl. invalid transitions, exports, attachments up/down);
  Structure validation and cascades.
* Real page JavaScript executed in jsdom against the live server: Supervisor check-in and check-out, success/error messages, no console errors.

## 8. Not executed / limitations
Apache and `.htaccess` enforcement; a real browser (layout, responsive rendering, console); HR bulk import and HR employee document uploads; Transport document uploads;
Complaints resolve-with-evidence and PowerPoint generation; visual inspection of PDF/Excel files; email delivery; load/performance; production data.
The deployed version of your own data was not available, so data-specific problems cannot be excluded.

## 9. Preserved
No data, schema, existing images or unrelated module behaviour was changed. Super Admin workflows are unchanged. Existing roles and users keep working;
the only behavioural change is that non-Super-Admins can no longer grant or manage access above their own.

## 10. Deployment
1. Back up the database (`mysqldump`) and the project folder (including `uploads/` and `storage/`).
2. Copy the new files over the installation (do not overwrite `database/config.generated.php`, `database/installed.lock`, `uploads/`, `storage/`).
3. Sign out and in. Open **Users → Roles** once (this registers every module's permissions), then tick permissions for each role as needed.
4. Optional: run the two test suites on a *test* database copy (they refuse to run on any database whose name lacks "test").

## 11. Recommended next steps
Add a periodic database backup/restore procedure (none exists); decide whether a UC with assigned staff may be deactivated; run the module checks above that could not be executed in a staging copy with real data and Apache.
