# Attendance module

Version 1.12.0 · timezone **Asia/Karachi** (fixed) · depends on HR, Structure and Users.

## Setup (after copying the files)
1. Open any Attendance page once as an administrator — tables and the 18 `attendance.*`
   permissions are created automatically (or run `php modules/attendance/cron/migrate.php`).
2. **Roles → permissions**: tick the Attendance permissions for each role (nothing is pre-granted).
   Typical sets: Office Staff = access + mark_self + history_view + leave_submit_self;
   Supervisor = access + mark_team + dashboard + reports + export + leave_submit_proxy +
   leave_view + review + history_view.
3. **Attendance → Attendance Setup**: check the staff pools / types. Defaults: Office Staff pool is
   self-marked; Field Staff and Transport Staff pools are Supervisor-marked; the staff type with
   slug `supervisor` (or the Complaints module's Supervisor choice) is the Supervisor.
4. **Attendance → Timing Settings**: first save the **company default** (the fallback for any category
   without its own timing). Then give **each staff category its own timing** — Office Staff, Field Staff,
   Transport Staff are independent: saving one never changes another. Field and Transport can also have a
   per-UC override *for that category*. Resolution, most specific first: category-in-UC → category →
   company default. A save is effective **today** (immediately); pick a later date to schedule a change.
   Each category has its own permission (*Manage Office / Field / Transport Staff attendance timing*,
   registered automatically from the HR staff pools); `timing_manage` manages every category and the
   company default; `timing_view` is read-only.
5. **Scheduler** (required for Pending Review / midnight Absent to be exact): every 5 minutes run
   `php modules/attendance/cron/sweep.php` (Linux cron or Windows Task Scheduler), or call the
   token URL shown on the Setup page. Without it the module still catches up whenever a page is
   opened, but nothing is guaranteed to happen at 12:00 AM on a quiet day.
6. A Supervisor whose user account was created with their CNIC is recognised automatically (the account
   CNIC must equal the employee CNIC). Otherwise link explicitly. Link each Office Staff member and Supervisor to their user account: **HR → Employees → Edit →
   "Login account" card** (needs `employee.update`). One account per employee. They sign out and in again. Assign Field / Transport staff to their UC; assign each UC's Supervisor.

## Rules in one place
| Situation | Result |
|---|---|
| Check-in inside window | Checked in (not yet Present) |
| Check-out inside window after a Check-in | Present |
| Check-in window passes with no Check-in | Pending Review |
| Check-out window passes after a Check-in | Incomplete |
| Reviewer decides before midnight | Present / Absent, with reason and audit |
| Unresolved at 12:00 AM | Absent (automatic) |
| Sunday | Off Day (everyone) |
| Office holiday | Holiday (Office Staff only) |
| Approved leave | Leave |
| After the day is final | Only Attendance Edit (correction) |

A window is open from its start time up to and including its end time. Check-in must close
before Check-out opens (overlap is rejected on save).

## Decisions made where the requirements were silent
* **Supervisors' own attendance** was not defined, so by default Supervisors mark their OWN attendance on
  My Attendance (their role needs `mark_self`); they are not on anyone's marking list. Change this under
  Attendance Setup → "types that mark their own attendance".
* **Administrators / Super Admin** can mark inside the windows if they hold `mark_team` (any UC);
  they cannot record late attendance, mark Office Staff, or mark themselves.
* **Leave** can start today or later; past days change only through Attendance Correction.
  Nobody can approve their own leave. Approval changes only days that have no recorded events.
* **Timing changes** are effective today by default (used at once by the marking screens and the server
  check). Past dates are refused; a later date schedules a future change. Recorded attendance is never
  altered; unmarked cases the old window had closed are re-opened (or, if the window was shortened,
  flagged) straight away.
* **Pending Review** decisions are Present or Absent; they never add a time.
* Reports/scope follow the employee's **current** assignment; each row shows the UC/Supervisor
  that applied on that date.

## Testing
`php tools/attendance-selftest.php --confirm-test-database` (database name must contain "test").
