# Solid Waste Management System — Phase 1

Entrance, installation and authentication foundation for a commercial ERP.
Core PHP, MySQL, no framework. Runs unchanged on XAMPP and on a production
LAMP server.

Phase 1 delivers the installation wizard, the unified login, the security
and permission foundation, the theming system and a protected shell. It
contains no business modules by design.

---

## Requirements

| Item | Minimum |
|------|---------|
| PHP | 8.0 (8.1+ recommended) |
| Extensions | `pdo_mysql`, `mbstring`, image support (`getimagesize`) |
| MySQL / MariaDB | 5.7 / 10.3 |
| Server | Apache with `mod_rewrite`, or Nginx (see below) |

## Installing on XAMPP

1. Copy the folder into `C:\xampp\htdocs\`.
2. Create an empty database in phpMyAdmin, for example `swms`.
3. Open `http://localhost/solid_waste_management_system/`.
4. Follow the wizard: database, company, Super Administrator.
5. Sign in with the email address and password you chose.

## Installing on a production server

1. Upload the folder; point the domain at it.
2. Create a database and a MySQL user with full rights on it.
3. Make `database/`, `uploads/` and `storage/logs/` writable (775).
4. Visit the domain and complete the wizard.
5. Delete or rename `install/` once the wizard finishes.
6. Open `database/config.generated.php` and set
   `define('APP_ENV', 'production');` — it is the default — and, behind a
   proxy or CDN, set `APP_URL` explicitly.

The wizard writes `database/config.generated.php` and
`database/installed.lock`. While both exist the installer refuses to run,
and `install/process.php` rejects every request.

## Moving between servers

Copy the whole folder, export and import the database, then edit the
credentials in `database/config.generated.php`. No other file contains
environment-specific values, and no path or URL is hard-coded.

---

## How signing in works

One field on the login screen accepts either identifier:

| Account | Signs in with | `login_type` |
|---------|---------------|--------------|
| Super Administrator | email + password | `email` |
| ERP user | CNIC + password | `cnic` |

The system decides from what was typed, and the account's own `login_type`
must agree, so an email cannot be used against a CNIC account or the
reverse. CNICs are stored as 13 digits and may be typed with or without
dashes. There is no public registration: accounts are created inside the
ERP, land in `pending`, and the person sets their own password through an
activation link.

Account states are `active`, `inactive`, `pending` and `blocked`. Only
`active` accounts can authenticate. Nothing deletes an account, so history
stays intact when someone leaves.

## Security

- `password_hash()` / `password_verify()`, with automatic rehashing when
  PHP's default algorithm changes. Passwords are never stored or displayed
  in plain text.
- Every query is prepared; the database helper offers no way to build SQL
  from user input.
- CSRF tokens on every state-changing form and AJAX call.
- Session cookies are `HttpOnly` and `SameSite=Lax`, `Secure` over HTTPS;
  the id is regenerated on login; idle and absolute timeouts apply; a
  user-agent fingerprint is checked.
- Failed sign-ins are throttled per IP (6 attempts, 15-minute lockout).
- Remember-me uses a split selector/validator pair, rotated on every use.
- Reset and activation tokens are stored hashed, expire, and are single-use.
- Uploads are checked by real image type, renamed, and stored in a folder
  where script execution is switched off.
- `core/`, `includes/`, `database/` and `storage/` are blocked by
  `.htaccess`, and every PHP file in them also refuses to run directly.

### Nginx

`.htaccess` does nothing on Nginx. Add the equivalent to the server block:

```nginx
location ~ ^/(core|includes|database|storage)/ { deny all; }
location ~ \.(sql|lock|log)$ { deny all; }
location ^~ /uploads/ { location ~ \.php$ { deny all; } }
```

---

## Structure

```
config.php          bootstrap: paths, version, install state, core includes
index.php           the only entry point; routes to installer, login or shell
core/               database, session, auth, permissions, security, helpers
auth/               login, logout, forgot/reset password, activation
auth/process/       form handlers (validate, act, redirect)
install/            the first-run wizard
admin/              the protected shell (dashboard, profile)
includes/           header, footer, sidebar, reusable components
modules/            future modules — see modules/README.md
assets/css|js/      the design system and behaviour, in separate files
uploads/            company logos and profile pictures
storage/logs/       application and error logs
database/           schema, generated config, install lock
```

## Theming

`assets/css/themes.css` holds every colour, shadow, radius and type value
as a CSS variable, in a light and a dark set. Changing the palette for a
reseller means editing that one file. The switcher stores the choice in
`localStorage` and follows the operating system until a choice is made.

## Versioning

`APP_VERSION` in `config.php` is the single source of truth. It appears on
the login card, the installer, the footer and in cache-busting asset URLs.
Releases follow `MAJOR.MINOR.PATCH`.
