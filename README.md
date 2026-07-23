# TRSys

TRSys (Tool Reservation System) is a lightweight instrument-booking plugin for
DokuWiki. It is intended for a small internal laboratory where users already
have DokuWiki accounts and need a shared weekly calendar for reserving tools.

TRSys runs inside DokuWiki. It reuses DokuWiki authentication, serves a
FullCalendar-based interface without a CDN or frontend build step, and stores
reservations in a local SQLite database outside the web root. No separate
Node.js, Python, or application server is required.

## Features

- Multiple laboratory tools in one weekly calendar.
- 30-minute booking resolution and a rolling seven-day booking window.
- Per-tool maximum booking duration and per-user weekly quota.
- Transactional conflict detection, including simultaneous requests.
- Full reservation details visible to every authenticated user.
- Booking owners can edit or cancel their own future reservations.
- TRSys administrators can manage tools and create outage periods.
- TRSys administrator privileges are independent of DokuWiki superuser and
  group settings.
- Locally bundled FullCalendar assets; no production CDN dependency.
- SQLite schema installer, cleanup command, automated PHP tests, and JavaScript
  logic tests.

## Repository Layout

The repository root is the DokuWiki plugin source root. There is intentionally
no additional `instrumentbooking/` wrapper directory.

```text
.
├── action.php                         DokuWiki AJAX endpoint
├── helper.php                         booking, quota, permission, and DB logic
├── syntax.php                         ~~INSTRUMENTBOOKING~~ renderer
├── script.js                          calendar and settings interface
├── style.css                          TRSys visual theme
├── plugin.info.txt                    DokuWiki plugin metadata
├── instrumentbooking.local.php.example
├── bin/
│   ├── install.php                    initialize or migrate the database
│   ├── admin.php                      initialize/revoke TRSys administrators
│   └── cleanup.php                    retention cleanup
├── conf/                              DokuWiki plugin configuration metadata
├── db/schema.sql                      SQLite schema
├── lang/                              DokuWiki messages and setting labels
├── tests/                             PHP and JavaScript tests
└── vendor/fullcalendar/               locally bundled FullCalendar Standard
```

The installed directory must still be named `instrumentbooking`, because that
is the DokuWiki plugin identifier:

```text
<DOKUWIKI_ROOT>/lib/plugins/instrumentbooking/
```

## Linux Server Deployment

The following procedure targets a typical Ubuntu/Debian server with DokuWiki
under `/var/www/dokuwiki` and the web server running as `www-data`. Adjust both
values for your server.

### 1. Install requirements

TRSys requires:

- A working DokuWiki installation.
- PHP 8.2 or newer.
- PHP CLI and the PDO SQLite extension.
- SQLite stored on a local filesystem, not NFS, SMB/CIFS, or another network
  filesystem.
- `git` and `rsync` for the deployment commands below.

On Ubuntu/Debian:

```sh
sudo apt update
sudo apt install git rsync sqlite3 php-cli php-sqlite3
php -v
php -m | grep -i pdo_sqlite
```

If PHP SQLite was installed after the web server started, restart the relevant
Apache or PHP-FPM service before testing the plugin.

### 2. Set deployment paths and obtain the source

The DokuWiki root is the directory containing `doku.php`.

```sh
export DOKUWIKI_ROOT=/var/www/dokuwiki
export TRSYS_SOURCE=/opt/TRSys
test -f "$DOKUWIKI_ROOT/doku.php"
git clone https://github.com/yuansui1023/TRSys.git "$TRSYS_SOURCE"
```

For an upgrade, use the existing source checkout and run `git pull` there
instead of cloning again.

### 3. Copy the plugin into DokuWiki

Create the plugin directory, then copy the contents of the repository root into
it:

```sh
sudo install -d -o root -g root -m 0755 \
  "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking"
sudo rsync -a \
  --exclude='.git*' \
  --exclude='.cursor/' \
  --exclude='.DS_Store' \
  --exclude='README.md' \
  --exclude='SECURITY.md' \
  --exclude='tests/' \
  "$TRSYS_SOURCE/" \
  "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/"
```

Do not copy the repository as
`lib/plugins/instrumentbooking/instrumentbooking/`. Files such as
`action.php`, `helper.php`, and `plugin.info.txt` must be directly inside
`lib/plugins/instrumentbooking/`.

Do not modify DokuWiki core files.

### 4. Create the local configuration

The live configuration belongs in DokuWiki's `conf/` directory so source
updates do not overwrite it:

```sh
sudo cp \
  "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/instrumentbooking.local.php.example" \
  "$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
sudo editor "$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

At minimum, set:

```php
<?php

return [
    'database_path' => '/var/lib/dokuwiki/instrumentbooking/bookings.sqlite3',
    'timezone' => 'America/Los_Angeles',
    'cancelled_retention_days' => 180,
    'history_retention_days' => 730,
];
```

`database_path` should be an absolute path outside the DokuWiki web root.
`timezone` must be a valid PHP/IANA timezone name. Tools and their booking
limits are created later from the TRSys Settings panel; they do not need to be
hard-coded in this file.

Protect the configuration while keeping it readable by the web server:

```sh
sudo chown root:www-data \
  "$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
sudo chmod 0640 \
  "$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

### 5. Create and verify local SQLite storage

```sh
sudo install -d -o www-data -g www-data -m 0770 \
  /var/lib/dokuwiki/instrumentbooking
findmnt -T /var/lib/dokuwiki/instrumentbooking \
  -o TARGET,FSTYPE,SOURCE
```

Common local filesystem types include `ext4` and `xfs`. Stop if the result is
`nfs`, `nfs4`, `cifs`, `smbfs`, or another shared/network filesystem. SQLite
locking for this plugin is supported only on local storage.

### 6. Initialize or migrate the database

Run the installer as the same operating-system user that serves DokuWiki:

```sh
sudo -u www-data env DOKUWIKI_ROOT="$DOKUWIKI_ROOT" \
  php "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/bin/install.php" \
  --config="$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

The command creates the database when needed and migrates an existing database
to the current schema. It is safe and expected to run this command again after
every plugin update. A successful installation prints the database path and
schema version.

### 7. Initialize the first TRSys administrator

This is a required installation step. The username must already exist in
DokuWiki, and it must be the account's login name rather than its display name.

```sh
sudo -u www-data env DOKUWIKI_ROOT="$DOKUWIKI_ROOT" \
  php "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/bin/admin.php" \
  bootstrap YOUR_DOKUWIKI_USERNAME \
  --config="$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

`bootstrap` works only while the TRSys administrator table is empty. It does
not grant DokuWiki superuser privileges and does not alter the DokuWiki user
account.

After logging in with this account, open **Settings** to create tools and add
additional TRSys administrators from the existing DokuWiki user list.

### 8. Create the DokuWiki booking page

Create a page such as `booking:calendar` with this content:

```text
~~INSTRUMENTBOOKING~~
```

Use DokuWiki ACL rules to restrict that page to the intended signed-in lab
users. TRSys requires a DokuWiki login for every API operation, but page ACL is
still the correct outer access boundary.

Open the page and confirm:

1. The calendar loads without an invalid-response message.
2. The first administrator sees **Settings**.
3. A normal user does not see **Settings** or the outage option.
4. A normal booking can be created and an overlapping booking is rejected.
5. Tool quotas and maximum durations are enforced.

If old JavaScript or CSS is still shown after an update, use DokuWiki's purge
URL once:

```text
https://YOUR-WIKI/doku.php?id=booking:calendar&purge=true
```

Then perform a browser hard refresh.

### 9. Deny direct web access to maintenance files

For Apache:

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

Reload the web server after changing its configuration.

## Upgrade Procedure

Back up the database before deploying an update:

```sh
sudo install -d -o www-data -g www-data -m 0770 \
  /var/backups/instrumentbooking
sudo -u www-data sqlite3 \
  /var/lib/dokuwiki/instrumentbooking/bookings.sqlite3 \
  ".backup '/var/backups/instrumentbooking/bookings-before-upgrade.sqlite3'"
```

Then update and redeploy:

```sh
git -C "$TRSYS_SOURCE" pull
sudo rsync -a --delete --delete-excluded \
  --exclude='.git*' \
  --exclude='.cursor/' \
  --exclude='.DS_Store' \
  --exclude='README.md' \
  --exclude='SECURITY.md' \
  --exclude='tests/' \
  "$TRSYS_SOURCE/" \
  "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/"
sudo -u www-data env DOKUWIKI_ROOT="$DOKUWIKI_ROOT" \
  php "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/bin/install.php" \
  --config="$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

The live configuration and SQLite database are outside the deployed plugin
directory, so the cleanup flags above do not overwrite them. They do remove
stale repository-only files from the plugin directory; verify
`DOKUWIKI_ROOT` before running the command.

## Backups and Cleanup

Use SQLite's online backup command rather than copying a live database file:

```sh
sudo -u www-data sqlite3 \
  /var/lib/dokuwiki/instrumentbooking/bookings.sqlite3 \
  ".backup '/var/backups/instrumentbooking/bookings.sqlite3'"
```

Test retention cleanup without deleting data:

```sh
sudo -u www-data php \
  "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/bin/cleanup.php" \
  --config="$DOKUWIKI_ROOT/conf/instrumentbooking.local.php" \
  --dry-run --verbose
```

Example monthly cron entry:

```cron
15 2 1 * * www-data php /var/www/dokuwiki/lib/plugins/instrumentbooking/bin/cleanup.php --config=/var/www/dokuwiki/conf/instrumentbooking.local.php --verbose >> /var/log/instrumentbooking-cleanup.log 2>&1
```

Take a database backup before cleanup and retain multiple generations of
backups outside the DokuWiki web root.

## Development and Tests

Run these commands from the repository root:

```sh
php tests/run.php
node --check script.js
node tests/js_logic_test.js
```

The production plugin does not require Node.js; Node is used only for
development checks.

## Administrator List CLI Reference

TRSys administrators are stored in SQLite's `plugin_admins` table and are
independent of DokuWiki groups and DokuWiki superuser settings.

Initialize the first administrator during installation:

```sh
sudo -u www-data env DOKUWIKI_ROOT=/var/www/dokuwiki \
  php /var/www/dokuwiki/lib/plugins/instrumentbooking/bin/admin.php \
  bootstrap EXISTING_DOKUWIKI_USERNAME \
  --config=/var/www/dokuwiki/conf/instrumentbooking.local.php
```

Add subsequent administrators from **TRSys → Settings → Administrators**.
Only existing DokuWiki users can be selected, and administrators are added one
at a time.

List the current TRSys administrators:

```sh
sudo -u www-data env DOKUWIKI_ROOT=/var/www/dokuwiki \
  php /var/www/dokuwiki/lib/plugins/instrumentbooking/bin/admin.php \
  list \
  --config=/var/www/dokuwiki/conf/instrumentbooking.local.php
```

Revoke a non-final administrator from the server CLI:

```sh
sudo -u www-data php \
  /var/www/dokuwiki/lib/plugins/instrumentbooking/bin/admin.php \
  revoke EXISTING_ADMIN_USERNAME \
  --config=/var/www/dokuwiki/conf/instrumentbooking.local.php
```

The CLI refuses to revoke the last administrator. To replace the only
administrator, first use the Settings panel to add the successor, then run
`revoke` for the old username. These commands change only TRSys permissions;
they never create, modify, or delete DokuWiki accounts.
