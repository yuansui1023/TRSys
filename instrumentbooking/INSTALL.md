# Installation Guide

## Requirements

- DokuWiki with normal plugin support.
- PHP 8.2 or newer with `pdo_sqlite`.
- SQLite database stored on local disk, such as `ext4`, `xfs`, or local block storage.
- No public CDN or production Node.js runtime is required.

## 1. Find the DokuWiki Root

The DokuWiki root is the directory containing `doku.php`. Example:

```sh
cd /var/www/dokuwiki
test -f doku.php && pwd
```

Use this value as `DOKUWIKI_ROOT`.

## 2. Check DokuWiki and PHP

Check the DokuWiki version from the admin page, or inspect `VERSION` in the DokuWiki root if available.

```sh
php -v
php -m | grep -i pdo_sqlite
```

If `pdo_sqlite` is missing, install or enable the PHP SQLite extension before continuing.

## 3. Check the Database Filesystem

The SQLite database must be on local disk. Do not place it on NFS, SMB/CIFS, or a shared distributed filesystem.

```sh
df -PT /var/lib/dokuwiki
```

Good examples include `ext4` and `xfs`. If the result is `nfs`, `nfs4`, `cifs`, `smbfs`, or another network filesystem, stop the SQLite deployment and use PostgreSQL instead. SQLite file locking is not safe for this plugin on network filesystems.

DokuWiki itself may live on a network mount if the SQLite database is placed separately on local `/var/lib/` storage.

## 4. Copy the Plugin

```sh
cp -a instrumentbooking "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking"
```

Do not modify DokuWiki core files.

## 5. Create Local Configuration

```sh
cp "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/instrumentbooking.local.php.example" \
   "$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

Edit:

- `database_path`
- `timezone`
- `manager_groups`
- `instruments`
- each instrument's `allowed_groups`, duration limits, buffers, color, and enabled flag

Usernames and groups are read from the current DokuWiki session. Do not add passwords to this file.

## 6. Create the Database Directory

Example:

```sh
sudo mkdir -p /var/lib/dokuwiki/instrumentbooking
sudo chown www-data:www-data /var/lib/dokuwiki/instrumentbooking
sudo chmod 0770 /var/lib/dokuwiki/instrumentbooking
```

Use the web server user and group for your platform.

## 7. Initialize SQLite

From the DokuWiki root:

```sh
php lib/plugins/instrumentbooking/bin/install.php
```

Or with an explicit config path:

```sh
php lib/plugins/instrumentbooking/bin/install.php --config="$DOKUWIKI_ROOT/conf/instrumentbooking.local.php"
```

The installer checks the config, filesystem type, database directory, schema, and current schema version. It is safe to run again and does not destroy existing data.

## 8. Set File Permissions

After initialization:

```sh
sudo chown www-data:www-data /var/lib/dokuwiki/instrumentbooking/bookings.sqlite3*
sudo chmod 0660 /var/lib/dokuwiki/instrumentbooking/bookings.sqlite3*
```

Keep the database outside the DokuWiki web root.

## 9. Create the Wiki Page

Create a DokuWiki page such as `lab:instrument_booking` containing:

```text
~~INSTRUMENTBOOKING~~
```

Configure the page ACL so only intended internal users can read it. The plugin still checks DokuWiki login and groups for every API request.

## 10. Configure DokuWiki Groups

Create or assign DokuWiki groups matching the config, for example:

- `sem-users`
- `tem-users`
- `instrument-admin`

DokuWiki superusers and members of `manager_groups` can manage bookings and create maintenance/block events.

## 11. Test Booking

1. Log in as a normal user in an allowed group.
2. Open the booking page.
3. Verify only allowed instruments are visible.
4. Create a future booking.
5. Try an overlapping booking and confirm it is rejected.
6. Log in as a manager and create a maintenance/block event.

## 12. Monthly Cleanup

Recommended monthly cron:

```cron
15 2 1 * * www-data php /var/www/dokuwiki/lib/plugins/instrumentbooking/bin/cleanup.php --verbose >> /var/log/instrumentbooking-cleanup.log 2>&1
```

Test first with:

```sh
php "$DOKUWIKI_ROOT/lib/plugins/instrumentbooking/bin/cleanup.php" --dry-run --verbose
```

## 13. Backups

Use SQLite's backup command instead of copying the database during active writes.

Manual backup:

```sh
sqlite3 /var/lib/dokuwiki/instrumentbooking/bookings.sqlite3 \
  ".backup '/var/backups/instrumentbooking/bookings-$(date +%F).sqlite3'"
```

Daily cron:

```cron
30 1 * * * www-data mkdir -p /var/backups/instrumentbooking && sqlite3 /var/lib/dokuwiki/instrumentbooking/bookings.sqlite3 ".backup '/var/backups/instrumentbooking/bookings-$(date +\%F).sqlite3'" && find /var/backups/instrumentbooking -name 'bookings-*.sqlite3' -mtime +14 -delete
```

Keep at least 14 daily backups. Take an extra backup before monthly cleanup or plugin upgrades.

Restore:

1. Stop web writes or put DokuWiki in maintenance mode.
2. Back up the current database file.
3. Copy the chosen backup to the configured `database_path`.
4. Restore ownership and mode.
5. Start DokuWiki again and test the booking page.

## 14. Upgrade

1. Back up the SQLite database.
2. Back up `conf/instrumentbooking.local.php`.
3. Replace the plugin directory.
4. Review `instrumentbooking.local.php.example` for new options.
5. Run `bin/install.php` to verify schema version.
6. Test booking and conflict behavior.

## 15. Uninstall While Keeping Data

1. Remove the wiki page tag or restrict page ACL.
2. Remove `lib/plugins/instrumentbooking`.
3. Keep `/var/lib/dokuwiki/instrumentbooking/bookings.sqlite3` and backups.
4. Keep or archive `conf/instrumentbooking.local.php`.

## 16. Docker Notes

Persist all of these paths:

- DokuWiki `conf/`
- DokuWiki `data/`
- `lib/plugins/instrumentbooking/`
- SQLite database directory, for example `/var/lib/dokuwiki/instrumentbooking/`
- backup directory

The SQLite database volume must be backed by local storage, not NFS/SMB.

## 17. If NFS or SMB Is Detected

Do not bypass SQLite locking limitations. Stop the database deployment, report the filesystem type, and replace SQLite with PostgreSQL for the booking storage layer.
