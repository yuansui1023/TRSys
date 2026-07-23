# Security Notes

The plugin is designed for a small internal DokuWiki installation and relies
on DokuWiki authentication plus the ACL of the page containing
`~~INSTRUMENTBOOKING~~`.

## Preserved Controls

- DokuWiki login is required for all AJAX operations.
- The authenticated username is read from the DokuWiki session, never from the request body.
- TRSys administrator rights come only from the SQLite `plugin_admins` table.
- Candidate administrators are enumerated with the current auth backend `retrieveUsers()` API when `canDo('getUsers')` is true; passwords, emails, and groups are never returned.
- Adding an administrator re-validates the username with `getUserData()` before insert and rejects username arrays.
- Write operations require DokuWiki CSRF validation.
- SQL uses PDO prepared statements.
- SQLite writes use `BEGIN IMMEDIATE` transactions for conflict checks and updates.
- SQLite busy/locked errors are mapped to `DATABASE_BUSY`.
- Notes have length limits and are stripped of HTML.
- Frontend rendering uses text nodes for user-controlled fields.
- Every authenticated user with access to the booking page can see complete
  reservation details, including username and note. This is an intentional
  collaboration policy, not a privacy boundary.
- A user can modify or cancel only their own eligible booking.
- TRSys administrators can create outage events, but administrator status does
  not allow editing another user's booking.
- The SQLite database is intended to live outside the DokuWiki web root.
- `bin/` and `db/` are not public API paths and should not be web-accessible directly.
- Production JSON errors do not include SQL, stack traces, database paths, server paths, PHP details, or session data.

## Server Hardening

Configure the web server to deny direct access to plugin internals:

```apache
<Directory "/var/www/dokuwiki/lib/plugins/instrumentbooking/bin">
    Require all denied
</Directory>
<Directory "/var/www/dokuwiki/lib/plugins/instrumentbooking/db">
    Require all denied
</Directory>
```

For nginx:

```nginx
location ~ ^/lib/plugins/instrumentbooking/(bin|db)/ {
    deny all;
}
```

Keep `conf/instrumentbooking.local.php` protected by normal DokuWiki `conf/`
access rules. Keep the SQLite database outside the web root and make it
writable only by the DokuWiki web-server account.

## SQLite Storage

Use local disk only. If the database target is NFS, SMB/CIFS, or another distributed filesystem, do not use SQLite for this plugin. Move the database to local `/var/lib/` storage or replace the persistence layer with PostgreSQL.

## Reporting Issues

Report security issues to the local DokuWiki administrator responsible for this installation. Include the plugin version, DokuWiki version, PHP version, and a minimal reproduction without private booking data.
