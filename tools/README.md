# tools/

Command-line utilities for an installed system. These are administrator
tools, not part of the application: they refuse to run over HTTP, and the
User Management module in a later phase replaces `create-user.php` with a
proper screen. Anything created here uses the same tables and the same
activation flow, so nothing needs migrating when that screen arrives.

## create-user.php

```bash
# staff — signs in with CNIC
php tools/create-user.php --name="Ali Raza" --cnic=36302-1234567-1 --role=admin

# administrator — signs in with email
php tools/create-user.php --name="Sara Khan" --email=sara@company.com --role=admin

# what roles exist
php tools/create-user.php --list-roles
```

| Option | |
|--------|--|
| `--name` | required |
| `--cnic` or `--email` | exactly one; decides how the person signs in |
| `--role` | role slug, default `admin` |
| `--phone` | optional |
| `--days` | activation link lifetime, 1–30, default 7 |
| `--url` | site URL for the printed link, if `APP_URL` is not set |

The account is created as `pending` with no usable password, and the script
prints a one-time activation link. The person opens it and chooses their own
password. You never see or set it.
