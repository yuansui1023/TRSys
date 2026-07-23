# DokuWiki Instrument Booking Plugin

`instrumentbooking` is a lightweight DokuWiki plugin for internal laboratory instrument reservations. It reuses the current DokuWiki login session and user groups, stores only booking events in SQLite, and renders a FullCalendar Standard calendar with local plugin assets.

## Features

- DokuWiki syntax tag: `~~INSTRUMENTBOOKING~~`
- Single AJAX endpoint: `lib/exe/ajax.php?call=instrumentbooking`
- Multiple instruments configured in `conf/instrumentbooking.local.php` and managed in Settings
- TRSys administrators stored in SQLite `plugin_admins` (not DokuWiki admin/manager groups)
- Settings tools table plus DokuWiki user picker for adding a single administrator
- CLI bootstrap/revoke for the first and subsequent TRSys administrators
- Booking and outage (block) events
- UTC Unix timestamps in SQLite, ISO 8601 with offsets over the API
- Conflict prevention with `BEGIN IMMEDIATE` and half-open occupied ranges
- Weekly quotas and per-booking duration limits in hours (stored as minutes)
- Idempotent create requests through UUID `requestId`
- CLI database initialization and cleanup

## Not Included

This first version intentionally does not include approval workflows, email, recurring bookings, quotas, billing, training validation, reports, an instrument admin UI, drag/drop editing, resource timelines, or a separate account system.

## Deployment Summary

1. Copy this directory to `<DOKUWIKI_ROOT>/lib/plugins/instrumentbooking`.
2. Copy `instrumentbooking.local.php.example` to `<DOKUWIKI_ROOT>/conf/instrumentbooking.local.php` and edit it.
3. Place the SQLite database on local server disk outside the web root, for example `/var/lib/dokuwiki/instrumentbooking/bookings.sqlite3`.
4. Run `php lib/plugins/instrumentbooking/bin/install.php`.
5. Create a DokuWiki page containing `~~INSTRUMENTBOOKING~~`.

See `INSTALL.md` for detailed installation, backup, cron, upgrade, and uninstall instructions.
