# Complaints Management module (ERP 1.9.0)

Internal complaint handling with UC-based visibility, evidence galleries,
SLA tracking, notifications, dashboards, reports and PowerPoint generation.
There is **no public portal, WhatsApp/SMS/email, or mobile app** in this release.

## Installing / upgrading (no reinstall)

1. Back up the database and project folder, then copy this package over the project.
   (Your `database/config.generated.php` and `database/installed.lock` are not in the zip.)
2. Sign in as Super Administrator and open **Complaints**. The first load creates the
   module's tables (`complaints`, `complaint_*`, `ppt_templates`) and registers its
   18 permissions. Nothing existing is changed or dropped.
3. **Complaints → Configuration** — nothing is pre-filled with business data:
   - *Sources, Types, Priorities* (add the ones your organisation uses)
   - *SLA Rules* (hours per priority; where the clock starts)
   - *Settings*: complaint-number prefix/digits, duplicate look-back days, and which HR
     staff types count as **Supervisor** / **Monitoring Officer**
     (until you save, types with the slug `supervisor` / `monitoring-officer` are used)
4. Under **Users → Roles** tick the new permissions (suggested sets below).
5. **Complaints → PowerPoint templates**: upload your designed `.pptx`.

### Suggested role permissions

| Role | Permissions |
|---|---|
| Administrator | all `complaint.*` |
| Complaint Officer | view, create, update, assign, remark, resolve, reopen, reject, attach, evidence_before, evidence_after, reports, ppt (optionally delete, restore) |
| Monitoring Officer / Supervisor | view, remark, workflow, attach, evidence_before, evidence_after |

Roles are ordinary roles — nothing is hard-coded to a role name.

## How access works (enforced on the server)

Two checks on every page and endpoint: **permission** (does the role allow the action?)
and **scope** (may this user see *this* complaint?).

- A user linked to a **field employee** (an HR staff type that is assigned to UCs — e.g.
  Supervisor, Monitoring Officer) sees only complaints whose UC is one of the UCs that
  employee is **currently assigned to** in HR. This follows HR assignments live: assign
  someone to a UC and they gain access immediately; remove or reassign them and they lose it.
  It applies to lists, dashboard numbers and charts, search, exports, PDFs, pictures,
  downloads and every action — typing another id returns *Access denied*.
- Everyone else (administrators, complaint officers, office staff) is limited only by permissions.
- Buttons shown on a page come from the same function the server enforces (`cmp_actions()`),
  so a button never appears for something the server would refuse.

## Workflow

`New → Assigned → In Progress → Ready for Resolution → Resolved`, plus `Reopened`
(→ In Progress again) and `Rejected`.

- Creating a complaint with a UC **assigns** it: it becomes *Assigned* when the HR data has a
  Supervisor/Monitoring Officer for that UC (they are notified); otherwise it stays *New*.
- Field staff **Start work** and **Mark Ready for Resolution** (needs ≥ 1 After picture).
  They cannot resolve. The resolver (permission `complaint.resolve`) is notified, then
  **Resolves** (remarks required) or **sends it back** (reason required).
- **Reopen**, **Reject**, **Reassign** and **Delete** require a reason, kept in history.
  Reopening keeps earlier resolutions and restarts the SLA clock.
- Delete is a **soft delete** (restorable by `complaint.restore`). Configuration items that
  are in use cannot be deleted — deactivate them.
- The **timeline is append-only**: no screen or endpoint edits or deletes timeline rows.

## SLA

One rule per priority: hours allowed, "nearing" threshold (% of time left), and whether the
clock starts at **creation** or **assignment**. Due/nearing instants are stored on the
complaint, so counts are plain indexed comparisons. Resolved complaints are judged by their
resolution time; rejected ones have no SLA; a priority without a rule has none.
Saving a rule recalculates still-open complaints of that priority.

## Evidence pictures

- Before and After are **independent sequences** with an explicit order (drag to reorder).
- Folder or multi-file upload; sent in small batches with progress, so 100+ pictures are not
  limited by `max_file_uploads`. Pictures are checked by content (JPG/PNG/WEBP only),
  stored privately with generated names, with a thumbnail (grid) and preview (viewer);
  phone-camera rotation is applied. Original is available via "open full size".
- Files live in `storage/complaints/<id>/` (denied to the web server) and are served only by
  `modules/complaints/file.php` after the permission + scope checks.

## PowerPoint generation

Built by editing the PPTX package itself (not a library that redraws the deck), so your
design survives. Algorithm, limits and tested coverage:

- The template file is **copied**; the original is never modified.
- Masters, layouts, theme, fonts and backgrounds are kept.
- **Slide 1 (details)** starts from the template's own first slide (its design shapes are
  kept); if that slide is unsuitable (e.g. a centred title slide) a "title only" layout of
  the template is used. **Slides 2…** (one Before + one After each) start from the template's
  second slide, or the same layout as slide 1.
- Title is filled; empty content placeholders are removed; a native, editable table, pictures,
  labels and captions are added in the free area under the title using the theme's colours
  and fonts. Company logo is added on slide 1 when configured.
- Slides use the saved picture order; unmatched pictures appear opposite a dashed placeholder.
- Limits: shapes that exist only on the template's slides **3 and later** are not reused;
  animations on the cloned sample slides are dropped; the details table shrinks its font and
  shortens very long text to fit.
- Verified with python-pptx (opens, slide/picture counts) and LibreOffice (rendered).
  **It has not been opened in Microsoft PowerPoint itself** — please open one generated
  deck from your own template there before relying on it.

## Exports and PDFs

CSV (formula-injection safe, UTF-8 BOM), Excel `.xlsx` (written without Composer packages;
text is stored as text), list PDF (first 1,500 rows) and a per-complaint PDF with both galleries.
All use the same filters and scope as the screen.

## PHP settings

| Setting | Why | Suggested |
|---|---|---|
| `upload_max_filesize` | pictures up to 25 MB, attachments 10 MB, templates 60 MB | 64M |
| `post_max_size` | one upload batch | 64M |
| `memory_limit` | image processing (large phone photos) | 512M (the module requests it) |
| `extension=gd`, `exif`, `mbstring`, `zip` | pictures, rotation, text, PPTX/XLSX | enabled (default in XAMPP) |

If a batch exceeds `post_max_size` the screen says so and names the setting.

## Notes and limits

- Pictures are limited to 40 megapixels each (protects server memory).
- The complaint number is generated inside the creating transaction from a locked counter —
  unique under simultaneous submissions and never reused. Changing the format affects new
  complaints only.
- Duplicate detection warns (same phone, or same UC + type + similar address, within the
  look-back window) and never blocks; the user chooses.
- The PDF font (DejaVu Sans) does not shape Urdu/Arabic script; on-screen text is unaffected.
- Notifications are in-system only (bell + list). Staff without a login account are shown on
  the complaint but cannot be notified.
- Dashboard UC/type charts show the largest 25/30 entries; the list and exports are complete.
