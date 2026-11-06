# Communication module — setup, HTTPS for voice, backup and restore

Status in this release (0.5.0): Phase 2 (Chat), Phase 3 (Tasks) and Phase 4 (Review, deadlines, reminders) are complete. Chat: private + group chat, unread counts, Sent/Delivered/Read, online/last seen,
typing, replies, offline retry, files with preview/download, voice messages, reactions, forwarding, search, edit/delete with a time
limit, delete-for-me, pinning, group announcements, group name/picture, reader lists, storage limit, Settings page.
Tasks: two-step creation, assignment, per-employee acceptance and progress, checklists with versions, templates, comments and mentions, links to ERP records, history, cancellation.
Phase 4: submission with evidence, independent review (approve / reject), reviewer unavailability and manual reassignment, reopening, deadline extension requests, overdue handling, advance reminders.
NOT yet built: the notification bell, dashboards, exports, archive/restore and the audit-log viewer (Phase 5); final hardening (Phase 6).
Announcements are highlighted in the group and count as unread; a pop-up/bell notification for them arrives with Phase 5.

## 0. PHP upload limits (XAMPP)
The largest file a person can send is the smaller of the Settings value and PHP's limits. In `C:\xampp\php\php.ini` set
`upload_max_filesize` and `post_max_size` at least as large as your "Maximum file size" (post_max_size a little larger), then restart Apache.
If a file is too big for PHP, the chat says so plainly. Settings shows the current PHP values.
Uploaded files are stored in `storage/comms/` (not reachable by URL) under random names and served only after a permission check.

## 1. First-time setup
1. Copy the project over your existing one. Keep `database/config.generated.php` and `database/installed.lock`.
2. Open any ERP page once; the module creates its tables automatically (`comms_*`).
3. Roles & permissions: tick the **Communication** permissions per role. Nothing is granted automatically.
   - Everyone who chats needs: `access`, `send_message`. To start a private chat: `chat_private`. To create groups: `group_create`;
     to manage a group's members you also need `group_manage` and must be a manager of that group.
   - Super Admin has everything. The Administrator role has nothing until you tick it.
4. Settings: Communication › Settings (needs `comms.settings`).

## 2. Microphone (voice messages) on other PCs — HTTPS is REQUIRED
Browsers allow the microphone only on `https://` pages or on `http://localhost`. A PC that opens the ERP as
`http://192.168.x.x/...` will be refused by the browser; no code can bypass this. Voice recording is built; on a PC without HTTPS the mic button
says so instead of failing silently. (Everything except the microphone works over plain HTTP.)

XAMPP already ships Apache with SSL on port 443. You need a certificate that names your server's LAN IP:
1. Find the server PC's fixed LAN IP (give it a static/reserved IP in your router), e.g. `192.168.1.10`.
2. In `C:\xampp\apache` run (use your IP):
   `bin\openssl req -x509 -nodes -days 825 -newkey rsa:2048 -config conf\openssl.cnf -keyout conf\ssl.key\erp.key -out conf\ssl.crt\erp.crt -subj "/CN=192.168.1.10" -addext "subjectAltName=IP:192.168.1.10"`
3. In `C:\xampp\apache\conf\extra\httpd-ssl.conf` set `SSLCertificateFile` to `conf/ssl.crt/erp.crt` and `SSLCertificateKeyFile` to `conf/ssl.key/erp.key`. Restart Apache.
4. Allow inbound TCP 443 in Windows Firewall on the server.
5. On EVERY client PC/phone, install `erp.crt` as a trusted certificate (Windows: double-click › Install › Local Machine ›
   "Trusted Root Certification Authorities"; Android: Settings › Security › Install a certificate › CA certificate), then open
   `https://192.168.1.10/<your-erp-folder>/`. Without this step browsers show a warning and some block the microphone.
6. Use only the https address from then on.
Not verified: I could not run XAMPP, Windows or Android here, so test one client PC first. For a single test machine only, Chrome's
`chrome://flags/#unsafe-treat-insecure-origin-as-secure` (add your http address) is a temporary workaround; do not rely on it.

## 3. Backup and restore (tested procedure; the ERP has no built-in backup feature)
Everything the module owns is in the database (`comms_*` tables, plus `system_settings` rows with group `comms`).
Uploaded files and voice messages live in `storage/comms/` (back this folder up together with the database).
**Backup** (daily, e.g. Task Scheduler; run from `C:\xampp\mysql\bin`):
`mysqldump -u root -p --single-transaction YOUR_DB > D:\backups\erp_YYYYMMDD.sql`
then copy the project's `storage/comms/` and `uploads/` folders to the same place.
**Restore** (on a spare database first):
`mysql -u root -p -e "CREATE DATABASE erp_restore CHARACTER SET utf8mb4"` then `mysql -u root -p erp_restore < erp_YYYYMMDD.sql`
Point `database/config.generated.php` at it to verify, or restore into the real database only after stopping Apache.
**Verified here (MariaDB 10.11):** after dump, drop and restore, conversation/member/message/audit counts, a checksum of all
message text and all comms settings were identical; no orphaned rows; a member's "only after you joined" window was still enforced.
Also verified with files: after restoring the database and copying `storage/comms/` back, every file matched its stored SHA-256 and access checks still worked.
Not verified: Windows paths, your production data.

## 4. Tests
`php tools/comms-selftest.php`, `comms-chat-selftest.php`, `comms-extras-selftest.php`, `comms-tasks-selftest.php` and `comms-review-selftest.php` (all in `tools/`), each with `--confirm-test-database`
(throwaway database whose name contains "test").

## 5. Behaviour you should know about
- Delete for everyone keeps the text in the database (history/audit) but nobody sees it again; its file is blocked but never auto-deleted. Moderators (`comms.message_moderate`) may edit/delete others' messages; this is audited separately.
- Edit/delete by the author is limited to the "Edit / delete time limit" in Settings; "Delete for me" has no limit.
- A file picked in the browser waits in memory: if the page is closed before it uploads, the upload is lost (typed text is kept). Failed uploads show Retry/Discard.
- Group pins and announcements: managers only. In a private chat either person can pin.
- Forwarding a file shares one stored copy (counted once against the storage limit).
- Search covers message text and file names inside your own conversations and your own history window only.
- Voice format is chosen by the browser (webm, ogg or mp4); the server detects the real type from the bytes.

## 6. Tasks — permissions to grant and rules to know
Grant per role (Roles & permissions › Communication). Nothing is automatic:
- Everyone who takes part: `access`. Employees who can be given work: `task_progress` (needed to be assigned and to update progress/checklists).
- Creating tasks: `task_create`; assigning other people: `task_assign`; assigning oneself: `task_self_assign` (separate on purpose).
- Reviewers (selectable as the task's reviewer): `task_review`. A reviewer can never also be an assignee of the same task.
- Editing a task you assigned: `task_edit`; cancelling: `task_cancel`; templates: `template_manage`.
Who can open a task: ONLY its assigner, its currently assigned employees and its reviewer. Not Super Admin, not other reviewers, not a department head. (A reports/oversight view for authorised managers comes with Phase 5.) Everyone else gets "not found".
Creating a task is two steps; nothing is saved or notified until "Confirm and assign". Each employee has their own status, progress and checklist; one person declining or finishing does not affect the others.
Progress: with a checklist it is the share of REQUIRED steps the employee has completed (all steps if none is required) against the latest checklist version; without one, the employee enters a percentage and a comment. 100% does not complete a task: that needs submission and independent approval (Phase 4).
Changing a checklist creates a new version (old ones kept), recalculates every employee against the latest version (percentages can go down) and notifies them.
Deadlines: the original deadline is always kept. For now the assigner can move a deadline to a future date directly; the request-and-approve extension flow and reminders arrive in Phase 4. Past deadlines are refused when set or changed.
Links to ERP records (complaint, employee, vehicle, union council) are optional and never grant access: a person without access to that module sees only "you do not have access".
Notifications are already being recorded for task events (no message/description text in them); the bell and list screen arrive in Phase 5.
Backup: all task tables are in the database dump (verified: counts and checksums identical after restore).

## 7. Review, deadlines and reminders (Phase 4)
Extra permissions (grant per role; none is automatic):
- `task_submit` — employees who may submit completion. `evidence_view` — lets an assigner or a co-assignee open someone else's evidence (the submitter and the task's reviewer always can).
- `task_review` — the designated reviewer. `reviewer_reassign` — assigner or the current reviewer may pick a new reviewer. `task_reopen` — assigner or reviewer may reopen approved work.
- `deadline_extend` — approve extension requests (as a participant), and set or change an advance reminder (this is new: a creator without it can no longer set reminders).
How it works:
- An employee submits with a comment, every REQUIRED checklist step done, and up to 5 evidence files (required if the task says so). Files are checked by content like chat files, count toward the storage limit, and are stored in `storage/comms/task-evidence/` (not reachable by URL). After submitting, the employee cannot edit or withdraw it or change progress.
- The designated reviewer approves or rejects each employee's submission separately. A reviewer can never be the submitter or anyone assigned to the task. Rejection needs a reason and returns only that employee to In Progress (shown as "Correction requested"); they resubmit as a new round. All rounds, comments and evidence are kept.
- If the reviewer is deactivated or loses `task_review`, the task is flagged "Reviewer unavailable", the assigner (and participants with `reviewer_reassign`) are notified once, and nothing is switched automatically. Someone authorised picks a new eligible reviewer with a reason; waiting submissions move to them and the change is logged.
- Reopening needs a reason and correction details, picks which approved employees go back to In Progress, keeps their progress, checklist state and the original approval, and requires a NEW submission and approval.
- Extension requests: an employee who has accepted the task proposes a later date with a reason. The deadline does not change until the assigner (or a participant with `deadline_extend`, never the requester) approves; the original deadline, old/new values, approver and time are kept, reminders are rescheduled and everyone is notified. A deadline the assigner edits directly is recorded in the same history.
- Overdue: a task whose deadline has passed and is not fully approved is Overdue. It is never locked, work can still be submitted, nothing escalates automatically, and a notice is sent once per deadline. A deadline given as a date only means the END of that day (23:59:59).
- Advance reminders (1 hour to 1 week before) go to employees still working plus the assigner, once, and are cancelled or rescheduled when the deadline changes; none is sent for finished or cancelled tasks.
Scheduler (XAMPP): reminders and overdue/reviewer checks run automatically, at most once a minute, whenever anyone uses the system (chat, task pages). If you want them to fire even when nobody is using the system, add a Windows Task Scheduler job every 5 minutes: `C:\xampp\php\php.exe C:\xampp\htdocs\<project>\tools\comms-cron.php`.
Evidence upload limits follow PHP's `upload_max_filesize` / `post_max_size` (see section 0).
Backup: the new tables and `storage/comms/task-evidence/` must be backed up together (verified: counts, checksums and all evidence files identical after restore).
