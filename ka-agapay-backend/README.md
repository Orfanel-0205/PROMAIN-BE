# Ka-Agapay — backend (API)

The server behind the Ka-Agapay system of the Malasiqui Rural Health Units:
the **web admin** used by RHU staff (`Rhu-admin-main-1`) and the **mobile app**
used by residents (`KaAgapay-mobile`) both talk to this API at `/api/v1`.

It handles accounts and sign-in, patient records, the queue, appointments,
SOAP consultations, e-prescriptions and lab requests, telemedicine, follow-ups,
inventory, events and announcements, SMS and push notifications, analytics and
the audit trail.

> **Running production?** Read [`docs/OPERATIONS.md`](docs/OPERATIONS.md) (deploys,
> backups, certificates, the scheduler, what to do when something breaks) and
> [`docs/HANDOVER-CHECKLIST.md`](docs/HANDOVER-CHECKLIST.md) (accounts, costs,
> known limitations). This README is for setting the project up and working on it.

## Stack

| | |
|---|---|
| Framework | Laravel 12 (PHP 8.3) |
| Database | PostgreSQL (the migrations use PostgreSQL features; SQLite will not work) |
| Cache / locks | Redis on the server, file or array locally |
| Sign-in | Laravel Sanctum tokens (7 days), roles via `RoleMiddleware`, policies in `app/Policies` |
| PDFs | dompdf (`resources/views/pdf`) |
| Outside services | Semaphore (SMS), Expo push, OCR.space (ID and paper-SOAP scans), Google Gemini (assistant), Jitsi / JaaS (video), SMTP (email) |

Keys for the outside services are set by a super admin under **Settings → API keys**
in the web admin (stored encrypted), with `.env` as the fallback. They are never
committed.

## Repository layout

The Git repository root is `PROMAIN-BE`; the application is in
`ka-agapay-backend/`. On the server that is `/var/www/ka-agapay-backend/ka-agapay-backend`.

```
app/Http/Controllers/Api   one controller per area (Queue/, Telemedicine/, Ai/, …)
app/Policies               who may see or change what (telemedicine, queue tickets)
app/Services               the work itself (Notification/, Queue/, Events/, Inventory/, …)
app/Support                small shared rules (Rhu, LocalTime, QueuePressure, EventFacility, …)
app/Console/Commands       scheduled jobs (reminders, alerts, event reports, …)
routes/api.php             every endpoint, with the roles allowed on each group
database/migrations        additive by policy — never drop a column
tests/Feature, tests/Unit  PHPUnit
docs/                      OPERATIONS.md, HANDOVER-CHECKLIST.md, deploy scripts
```

## Setting it up on your computer

Requirements: PHP 8.3 with `pdo_pgsql`, Composer, PostgreSQL 14+.

```bash
cd ka-agapay-backend
composer install
cp .env.example .env          # then set DB_* to your local PostgreSQL
php artisan key:generate
php artisan migrate
php artisan db:seed           # roles, the 73 Malasiqui barangays, demo users
php artisan db:seed --class=BarangayCoordinatesSeeder   # map points for the heatmap
php artisan serve             # http://127.0.0.1:8000/api/v1/health → {"status":"ok"}
```

The web admin in development mode calls `http://127.0.0.1:8000/api/v1` by default,
so the two run together without extra configuration.

Every environment variable is listed and explained in `.env.example` and in
[`docs/OPERATIONS.md` §2](docs/OPERATIONS.md). Production must keep `APP_DEBUG=false`.

## Tests

The tests rebuild their database from the migrations on every run, so give them
**their own database** — never point them at your working one:

```bash
createdb kaagapay_test                     # once
DB_DATABASE=kaagapay_test php vendor/bin/phpunit
```

If your PHP has `pdo_pgsql` installed but not enabled (as on a default Laragon),
add `-d extension=pdo_pgsql` after `php`.

As of October 2026: **307 tests, 0 failures, 1 skipped** (the "home RHU" default,
an open decision recorded in the handover checklist). The security tests in
`tests/Feature/Security` each prove a specific hole stays closed; run them after
any change to routes, policies or controllers.

## Scheduled jobs

The server's cron runs `php artisan schedule:run` every minute as `www-data`
(see [`docs/OPERATIONS.md` §5](docs/OPERATIONS.md) — if it stops, nothing below happens):

| Job | When (Philippine time) | What it does |
|---|---|---|
| `appointments:send-reminders` | day before and on the day | SMS and app reminders |
| `followups:send-reminders` | 8:00 AM | 3 days before, day before, on the day |
| `events:send-reminders` | 8:15 AM | text 3 days before to the target barangays |
| `queue:pressure-alerts` | every minute | alert an RHU's staff when its queue is heavy (26+) or over capacity (51+), or an event is nearly full |
| `events:close-ended` | every 15 minutes | when an event ends, send its staff the event report |
| `queue:close-stale`, prescription expiry, inventory alerts, `outbreak:detect`, `push:check-receipts`, `sanctum:prune-expired` | daily / hourly | housekeeping and alerts |

`php artisan schedule:list` shows the full list with next run times. Each job
reports failures to the Slack webhook (`LOG_SLACK_WEBHOOK_URL`).

## Deploying

Short version (full steps and the reasons in [`docs/OPERATIONS.md` §3](docs/OPERATIONS.md)):

```bash
ssh <server>
/usr/local/bin/kaagapay-backup.sh               # snapshot first if there is a migration
cd /var/www/ka-agapay-backend && git pull origin main
cd ka-agapay-backend
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache
systemctl reload php8.3-fpm
curl -s https://<domain>/api/v1/health
```

Run artisan as `www-data`, never as root: files root creates in `storage/` break
the app for the web server.

## Conventions worth knowing

- **Roles** are checked twice: on the route group (`role:...` in `routes/api.php`)
  and in the controller or policy for anything tied to a person or an RHU.
  Residents (`resident`, `patient`) are everyone else's opposite — `User::isStaffAccount()`.
- **RHU scoping**: staff see their own RHU; the MHO and super admin see every RHU
  (`App\Support\Rhu`). Every RHU serves the whole of Malasiqui; residents pick the
  facility when they book.
- **Time**: stored in UTC; anything a person sees or a reminder is scheduled by uses
  Philippine time (`App\Support\LocalTime`).
- **Audit**: `AuditService::info($module, $action, …)` — module first. Use named
  arguments (`$audit->log(request:, action:, module:, …)`) where you can.
- **Comments explain why.** Most non-obvious code says what went wrong before it
  existed. Keep that up.
