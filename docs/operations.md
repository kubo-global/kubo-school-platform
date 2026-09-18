# KUBO operations guide

KUBO runs on a **local server inside the school** (e.g. a Raspberry Pi acting as a Wi-Fi
hotspot), offline. This guide covers provisioning a device, backups and restore, and routine
maintenance. For day-to-day app usage see the in-app help; for development see the
[README](../README.md).

## Provisioning a fresh install

A new device needs the database tables and the role/permission definitions, but **not** the
demo seed data, the setup wizard creates the real school.

```bash
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder   # roles/permissions only, no school
```

Set these in the production `.env`:

```
APP_ENV=production
APP_DEBUG=false          # also force-disabled in code as a safety net
APP_KEY=base64:...       # php artisan key:generate
DB_DATABASE=...          # plus DB_USERNAME / DB_PASSWORD / DB_HOST or DB_SOCKET
BACKUP_REPORT_TOKEN=...  # only if off-device backup tooling reports status (see below)
```

Then open the app: with no school yet, every URL redirects to **`/install`**, the wizard takes
the school through setup and signs the headmaster in. An instance counts as "set up" once a
`School` exists.

## Backups

A headmaster downloads a full database backup (a gzipped MySQL dump,
`kubo-YYYY-MM-DD-His.sql.gz`) from **Backups** in the admin UI.

- **Keep copies off the device.** Schedule a cron job to copy the latest dump to a USB drive or
  another machine. A backup that only lives on the device it's protecting isn't a backup.
- **Report status to the dashboard.** Point the backup cron at `POST /api/backup/report` so the
  dashboard shows backup health. The endpoint is guarded: the on-device cron uses **loopback**
  (no token needed); off-device tooling must send the `BACKUP_REPORT_TOKEN`
  (`Authorization: Bearer <token>`).

  ```json
  { "method": "scheduled", "destination": "usb", "status": "success", "size_bytes": 12345 }
  ```

## Restore

**Destructive — replaces the current data.** Run on the device:

```bash
php artisan kubo:restore /media/usb/kubo-2026-06-25-120000.sql.gz
```

It writes a **safety snapshot** of the current database to `storage/app/pre-restore-*.sql.gz`
first (so the restore is itself reversible), asks for confirmation, then imports the backup.

| Flag | Effect |
|---|---|
| `--dry-run` | Print the plan, change nothing |
| `--force` | Skip the confirmation prompt (for scripts) |
| `--no-snapshot` | Skip the pre-restore safety snapshot |
| `--connection=` | Target a specific DB connection |

## Servicing access

When you need to sign in to service a school's server, run this **on the machine**
(physically or over SSH):

```bash
php artisan kubo:support               # link valid 15 min
php artisan kubo:support --minutes=60  # longer window
```

It prints a signed sign-in link for a hidden **KUBO Support** account. Open the link in
a browser on the machine or the school's LAN and you are signed in.

- **No standing password.** Nothing sits on the school's login screen; the only way in is
  to run this command on the box. The link is signed with the install's own `APP_KEY` (so
  it works for that deployment only) and it expires.
- **On demand.** The KUBO Support account is created the first time you run the command,
  gets the `system_admin` and `admin` roles, and appears in **Users** so the school can see
  it. Remove it there when you are done, or leave it (there is no way in without a fresh
  signed link).
- **Offline-friendly.** Access is gated by having the machine, so it works on a fully
  offline server with no internet or external service involved.

## Routine maintenance

- **Logs & disk.** Laravel writes to `storage/logs`; configure `logrotate` so an offline device
  doesn't fill its disk. Old backups also accumulate under `storage/app/`, prune them.
- **Disk space.** Monitor free space, a full disk takes down MySQL and the backup job.
- **Time.** Keep the device clock correct (term/NAT logic is date-driven); use an RTC module or
  NTP when the device occasionally sees the internet.

## End-of-term analysis for every class

When the head teacher asks for "the results analysed by class", one command prints the
full-term result analysis and histogram of every class as PDFs (the same figures as
Results / By term, on the combined term marks: tests 25% + exam 75%):

```bash
php artisan results:analysis --term="Term 3"                 # current school year, every class
php artisan results:analysis --year="2025 - 2026" --term="Term 3" --class="Grade 4" --class="Grade 5"
php artisan results:analysis --term="Term 3" --out=/media/usb/analysis --no-histogram
```

Without `--term` it takes the term we are in today (else the year's last); without `--out`
the PDFs land in `storage/app/analysis/<year>/<term>/`, two per class. A class with no
marks in that term is listed and skipped, not printed empty.

## Updating a deployed server

```bash
php artisan kubo:restore --dry-run …   # (optional) confirm you have a good recent backup first
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Always take a fresh backup **before** updating.
