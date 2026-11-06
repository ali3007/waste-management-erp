# HR module (v2.0.0 of the module, shipped with ERP 1.8.0)

## Upgrading an existing installation (no reinstall)

1. Back up the database and the project folder.
2. Copy the files from this package over your existing project folder
   (the zip contains only the project, not your `database/config.generated.php`
   or `database/installed.lock`, so your installation settings are untouched).
3. Sign in as Super Administrator and open **Employees**. The first HR page load
   runs a small, additive database update:
   - adds `title`, `mime_type`, `file_size` to `employee_documents`
   - adds two indexes
   - registers the new permissions
   - records `hr_schema_version = 2.0.0` in `system_settings`, so later page
     loads skip the check
   Nothing is dropped, renamed or emptied; existing employees, assignments and
   documents are kept.
4. Make sure the web server can write to `storage/` (it creates
   `storage/employee-docs`, `storage/imports` and `storage/cache` itself).
5. Under **Users → Roles**, tick the new permissions for the roles that need them
   (Super Administrator already has everything):

| Permission | Allows |
|---|---|
| HR — View the HR dashboard | Dashboard |
| HR — View HR reports / Export HR reports and employee lists | Reports page / CSV downloads |
| HR — Use the employee CSV bulk import | Bulk import |
| Employee — View / Upload / Download / Delete employee documents | Documents on the profile and file downloads |
| Employee — Generate profile PDF / employment letter | The two PDFs |

Roles that existed before keep exactly the access they had.

## PHP settings worth checking

| Setting | Why | Suggested |
|---|---|---|
| `upload_max_filesize` | documents up to 5 MB, CSV up to 5 MB | `8M` or more |
| `post_max_size` | a form can carry several documents at once | `32M` |
| `extension=gd` | PDF pictures and image checks | enabled (default in XAMPP) |
| `extension=mbstring` | PDF text | enabled (default) |
| `extension=zip` | verifies `.docx` uploads | enabled (default in XAMPP) |

If `zip` is unavailable, `.docx` uploads are refused (PDF and images still work).

## How access control works

- Users **not linked to an employee** (administrators, HR managers) see everything their permissions allow.
- A user **linked to an employee** (`employees.user_id`) sees only employees currently
  assigned to the UC(s) that user is assigned to. This is enforced in the SQL of every
  list, report, export and the dashboard, and re-checked on every employee-specific
  page and endpoint (view, edit, assign, documents, file download, PDF, letter).
  Typing another employee's id returns *Access denied*.
- Because unassigned employees have no UC, a scoped user cannot see or assign them;
  that is done by an unrestricted HR user.

## Files

```
modules/hr/
  dashboard.php  reports.php  report-export.php
  employees.php  unassigned.php  assigned.php
  employee-create|edit|view|assign.php
  employee-pdf.php  employee-letter.php
  file.php                    ← the only way to reach a private file
  import.php                  ← bulk import (upload / preview / done)
  lib/   uploads.php filters.php stats.php reports.php import.php pdf.php HrPdf.php
  partials/  filter-form.php  employee-uploads.php
  process/   *-save / *-status / document-* / import-* endpoints
libs/tcpdf/                   ← bundled PDF library (LGPL), pruned to what is used
assets/css/hr.css  assets/js/hr-charts.js  assets/js/hr.js
```

## Notes and known limits

- **Scripts such as Urdu/Arabic** are not shaped correctly by the bundled PDF font
  (DejaVu Sans). Latin text, digits and common symbols print correctly. Names are
  stored and shown correctly on screen in any script.
- **Employment letter wording** uses only what the HR record holds. It does not state
  a joining date or salary because the employee record does not store them.
- **"Delete employee"**: the existing `employee.delete` permission means
  activate/deactivate. Employees are never hard-deleted, so their history is never lost.
- **Reassignment** keeps history: the old row gets an `unassigned_at` date and a new one is added.
- Changing an employee to a staff type with a **different assignment rule** closes their
  current assignment (history kept) and returns them to Unassigned.
- Dashboard UC/Beat charts count **active** employees only, one count per place they are
  currently assigned; Reports count all matching employees.
