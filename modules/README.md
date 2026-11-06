# Adding a module

Phase 1 is frozen. A new module is added here without editing anything
inside `core/`, `auth/` or `install/`.

## Layout

```
modules/
└── hr/
    ├── index.php          entry point
    ├── bootstrap.php      permissions the module registers
    ├── install.sql        the module's own tables
    ├── views/
    ├── process/           form handlers
    └── assets/
        ├── hr.css
        └── hr.js
```

## Entry point

Every module page starts the same way. `Permission::require()` handles the
sign-in check, the permission check and the 403 page.

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';

Permission::require('hr.view');

$pageTitle = 'Employees';
$layout = 'app';
$extraStyles = [];                 // files in assets/css/
$extraScripts = [];                // files in assets/js/

require INCLUDES_PATH . '/header.php';
// module markup, built from the components in includes/components/
require INCLUDES_PATH . '/footer.php';
```

## Tables

Put them in `modules/<slug>/install.sql`. Reference `users(id)` and
`roles(id)` with foreign keys; never alter a Phase 1 table's columns.
Record the module in the `modules` table so the version is visible:

```sql
INSERT INTO modules (slug, name, version) VALUES ('hr', 'Human Resources', '1.0.0')
ON DUPLICATE KEY UPDATE version = VALUES(version);
```

## Permissions

Register them once, at install time:

```php
Permission::register('hr', 'view', 'View employees');
Permission::register('hr', 'create', 'Add employees');
Permission::grant($hrManagerRoleId, 'hr.view');
```

Super Admin holds every permission automatically, including ones a module
adds later, so nothing needs to be granted to that role.

## Navigation

Add the item to the `$navigation` array in `includes/sidebar.php`, with the
permission slug that guards it. That array is the only Phase 1 file a module
touches, and it is additive.

## Styling

Reuse the tokens in `assets/css/themes.css` — never hard-code a colour or a
shadow. Both themes then work for free. Module-specific CSS and JS go in the
module's own `assets/` folder or as new files under `assets/css/` and
`assets/js/`; nothing belongs inline in a PHP file.
