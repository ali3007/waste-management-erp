# User Management module (Phase 2)

Screens for everything Phase 1's `tools/create-user.php` did from the
command line, plus role and permission management.

```
modules/users/
├── bootstrap.php          shared helpers, included by every page here
├── index.php               list · search · filter · paginate
├── create.php               add a user (CNIC or email)
├── edit.php                  name, phone, avatar, role
├── view.php                   profile, activity, status actions
├── roles.php                   roles list + create/edit permissions
└── process/
    ├── create.php
    ├── update.php
    ├── status.php             deactivate / block / reactivate
    ├── resend-activation.php
    ├── role-save.php           create or update a role
    └── role-delete.php
```

No new database tables. Phase 1's installer already seeded every
permission slug this module checks (`users.view`, `users.create`,
`users.update`, `users.delete`, `roles.view`, `roles.update`) and granted
them all to Super Admin. A site that has run the Phase 1 installer needs
nothing extra to use this module.

## Guardrails worth knowing about

- The Super Administrator account can't be edited, deactivated or have
  its role changed from here — by anyone, including itself.
- Nobody can change their own role or status through this module. Use
  `admin/profile.php` to manage your own account.
- A role can be deleted only once no user holds it; the two system roles
  (Super Admin, Administrator) can never be deleted.
- Creating a user never sets a password — it issues a one-time activation
  link and the person chooses their own, exactly as Phase 1's
  `Auth::completeActivation()` was built to do.

## For Phase 3+

The permission matrix on `roles.php` is built from `SELECT DISTINCT
module FROM permissions`, so calling `Permission::register()` from a new
module is enough for its permissions to appear here, gain a "select all"
group automatically, and become assignable to any role — no change to
this module required.
