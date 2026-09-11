# Ka-Agapay — Operations Runbook

Deployment, configuration, backup and recovery for the live system serving
**RHU1 & RHU2, Malasiqui, Pangasinan**.

Written for whoever inherits this system, not for whoever built it. If something
here disagrees with the code, **the code wins** — fix this document.

| | |
|---|---|
| **Production host** | DigitalOcean droplet (Ubuntu) |
| **Web stack** | Nginx + PHP-FPM 8.3 |
| **Database** | PostgreSQL |
| **TLS** | Let's Encrypt via Certbot |
| **Backend** | Laravel 10.50.2 |
| **CI/CD** | None — manual deploy |

**Companion documents**
- `docs/HANDOVER-CHECKLIST.md` — the non-technical handover (accounts, costs, contacts)
- `docs/deploy/kaagapay-backup.sh` — the nightly backup script, ready to install
- `.env.example` — authoritative list of every environment variable

---

## 1. What production actually is

A single droplet running **Nginx, PHP-FPM 8.3 and PostgreSQL**, with HTTPS
terminated by a Certbot-issued certificate. The Laravel backend is served from a
directory on that droplet; the React web admin is a static bundle served by
Nginx from a separate web root.

> ### ⛔ Do not follow the Dockerfile
>
> The repository contains a `Dockerfile`, `docker/start.sh` and an Apache vhost.
> These are leftovers from an abandoned Render.com deployment — `start.sh` still
> prints *"Skipping database migrations on Render startup."* They describe a
> PHP-Apache container that production does not use, with no scheduler and no
> queue worker. Treat them as dead files until someone deliberately removes or
> rewrites them.

### The three deployable pieces

| Piece | Source root | How it ships |
|---|---|---|
| **Backend API** | `ka-agapay-backend` (github.com/Orfanel-0205/PROMAIN-BE) | `git pull` on the droplet, then Composer + artisan cache steps |
| **Web admin** | `rhu-admin-main` | Built locally, packed as `.tar.gz`, `scp`'d, extracted into the Nginx static root (§4) |
| **Mobile app** | `KaAgapay` (Expo) | EAS build → store submission |

> ### ⚠ Nested folder
>
> The repository root and the Laravel application root are not the same directory
> on every checkout. Before running any `artisan` command, confirm you are in the
> directory containing `artisan`, `composer.json` and `public/` — not its parent.
> A `git pull` at the wrong level appears to succeed and changes nothing.

---

## 2. Environment variables

**`.env.example` is the authoritative list** and is kept in sync with the code.
It was previously stock Laravel — documenting MySQL, Pusher, AWS and Mailpit,
none of which this system uses, and none of the credentials it needs. A clone
configured from that version could not boot.

The variables that most often cause trouble:

| Variable | Why it bites |
|---|---|
| `APP_URL` | Signed registration invite links are **signed against** this. If it disagrees with the URL staff actually open, every invite fails signature verification. |
| `ADMIN_APP_URL` | Invite links are **built** from this. No sensible default — must be set. |
| `SANCTUM_STATEFUL_DOMAINS` | Must include the web admin host or admin login fails with a 419. |
| `APP_DEBUG` | Must be `false`. Stack traces expose patient-data paths. |
| `APP_KEY` | Never rotate casually — it decrypts existing sessions and encrypted columns. |
| `LOG_LEVEL` | `warning` in production. `debug` fills the disk. |

### Feature credentials

| Variable | Gates | Notes |
|---|---|---|
| `GEMINI_API_KEY` | AI chat assistant | Verify: `php artisan gemini:test-key` |
| `SEMAPHORE_API_KEY` | All SMS | Dominant recurring cost. Balance: `GET /api/v1/admin/sms/account` |
| `SEMAPHORE_SENDERNAME` | All SMS | Must be pre-registered with Semaphore |
| `OCR_SPACE_API_KEY` | PhilHealth / employee ID OCR | Without it, OCR endpoints return a config error |
| `BACKUP_*` | Nightly backups | See §7 |
| `LOG_SLACK_WEBHOOK_URL` | Error alerting | See §8 |

### Video calling — telemedicine **and** Team Chat

Both share one signer (`JitsiTokenService`). Misconfiguration fails by **hanging
at "joining"**, not by erroring, which is why it needs explicit verification.

```
JITSI_PROVIDER=jaas
JITSI_DOMAIN=8x8.vc
JITSI_JWT_ENABLED=true
JITSI_APP_ID=<tenant id>          # JWT 'sub' + room namespace prefix
JITSI_API_KEY=<key id>            # JWT 'kid' header; alias JITSI_API_KEY_ID
JITSI_PRIVATE_KEY=<PEM path or inline PEM>   # alias: path may sit in JITSI_APP_SECRET
```

The PEM should be `0400`/`0600`, owned by the php-fpm user.

```bash
php artisan jitsi:doctor    # presence, path, permissions, validity — never prints contents
```

> **Housekeeping:** `GOOGLE_VISION_API_KEY` appears in `config/services.php` but
> no code reads it — OCR goes to OCR.space. Safe to remove.

---

## 3. Deploying the backend

1. SSH to the droplet, `cd` into the directory containing `artisan`.
2. **Take a database snapshot first** (§7) so a bad migration is recoverable.
3. Pull and install:
   ```bash
   git pull origin main
   composer install --no-dev --optimize-autoloader
   ```
4. Apply any new environment variables. Diff against `.env.example` — features
   have shipped needing a variable nobody set.
5. Migrate. Every migration in this project is additive by policy; if one wants
   to drop a column, stop and investigate.
   ```bash
   php artisan migrate --force
   ```
6. Rebuild caches and reload. **Clear before caching** — a stale config cache
   holding an old API key is a common and confusing failure.
   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   sudo systemctl reload php8.3-fpm
   sudo systemctl reload nginx
   ```
7. Smoke-test before walking away:
   ```bash
   curl -s https://<api-host>/api/v1/health     # {"status":"ok"}
   ```

> **If `config:cache` fails** the site is already serving errors. Run
> `php artisan optimize:clear` to fall back to uncached config — slower, but it
> boots — then fix `.env` and re-cache.

---

## 4. Deploying the web admin

No CI/CD. The API base URL is baked in at build time, so building with the wrong
`VITE_API_URL` produces a bundle that looks fine and talks to the wrong server.
Since 2026-09-10 the build refuses to: `scripts/check-api-url.mjs` runs as
`prebuild` and `postbuild`, and fails unless `VITE_API_URL` is https, points at
the server host rather than localhost or a LAN IP, ends in `/api/v1`, and
actually landed in the bundle. Put the value in `.env.production` (gitignored);
`.env.example` documents it.

**First check whether a deploy is needed at all.** Vite names the entry script
after a hash of its contents, so if the live hash equals the local one, the
server already serves this exact build and redeploying changes nothing:

```bash
curl -s https://<admin-host>/ | grep -oE '/assets/[^"]+\.js' | head -1   # live
grep -oE '/assets/[^"]+\.js' dist/index.html | head -1                     # local
```

```bash
# locally, in rhu-admin-main
npm ci
npm run build            # prebuild check -> tsc && vite build -> postbuild check

# Pack the CONTENTS of dist/, not the dist folder itself. The droplet step
# below extracts into dist.new/ and asserts dist.new/assets/...; an archive with
# a top-level dist/ lands at dist.new/dist/assets/... and fails that assertion.
# Earlier revisions said `zip -r admin-dist.zip dist` here, which was both the
# wrong format for the `tar -xzf` below and nested one level too deep.
tar -czf admin-dist.tar.gz -C dist .
tar -tzf admin-dist.tar.gz | head -3     # expect ./index.html and ./assets/...
scp admin-dist.tar.gz user@<droplet>:/tmp/
```

On Windows, PowerShell's execution policy can block `npm` (it runs as
`npm.ps1`). Use `npm.cmd run build`, or Git Bash. `tar` ships with Windows 10
and later, so the same `tar -czf` line works in PowerShell.

```bash
# on the droplet — keep the previous build; it is the entire rollback plan
#
# THE DOCROOT IS /var/www/ka-agapay-admin/dist, confirmed against
# /etc/nginx/sites-enabled/ka-agapay. Earlier revisions of this runbook said
# /var/www/rhu-admin, which nginx does not serve: following them deployed a
# correct build to a directory nobody reads.
STAMP=$(date +%Y-%m-%d-%H%M)
sudo rm -rf /var/www/ka-agapay-admin/dist.new
sudo mkdir -p /var/www/ka-agapay-admin/dist.new
sudo tar -xzf /tmp/admin-dist.tar.gz -C /var/www/ka-agapay-admin/dist.new

# Assert the STAGED copy before it becomes live, not after.
BREF=$(grep -oE '/assets/[^"]+\.js' /var/www/ka-agapay-admin/dist.new/index.html | head -1)
test -f "/var/www/ka-agapay-admin/dist.new${BREF}"
[ "$(wc -c < "/var/www/ka-agapay-admin/dist.new${BREF}")" -gt 500000 ]

sudo mv /var/www/ka-agapay-admin/dist "/var/www/ka-agapay-admin/dist.prev-${STAMP}"
sudo mv /var/www/ka-agapay-admin/dist.new /var/www/ka-agapay-admin/dist
sudo chown -R www-data:www-data /var/www/ka-agapay-admin/dist
```

Verify in a **hard-refreshed** browser: Vite hashes asset filenames, but
`index.html` is not hashed and is routinely served stale.

**Rollback:** `sudo mv /var/www/ka-agapay-admin/dist /var/www/ka-agapay-admin/dist.bad && sudo mv /var/www/ka-agapay-admin/dist.prev-<STAMP> /var/www/ka-agapay-admin/dist`

> **Housekeeping:** this mv-aside pattern never deletes anything, so `/var/www`
> accumulates. As of 6 September 2026 it holds ~50 `ka-agapay-admin-backup-*`
> directories from June, two stale `admin-dist*.zip` files, and several
> zero-byte junk files (`npm`, `scp`, `ssh`, `tsc`, `cd`, `Compress-Archive`)
> left by a mistyped shell command. Harmless at 8% disk use, but prune old
> `dist.prev-*` and `*-backup-*` directories periodically — keeping the last
> two or three is all the rollback plan actually needs.

> **Known:** the bundle is ~2 MB with no code splitting. First load is slow on
> barangay connections. Deferred, not a new fault.

---

## 5. The scheduler — the thing that silently isn't running

The recurring jobs are declared in `app/Console/Kernel.php`. All depend on one
system cron entry. **If it is missing, nothing errors and nothing logs** — the
features simply never happen, and it surfaces weeks later as "the reminders
stopped working."

| When | Job | If it never runs |
|---|---|---|
| Every 10 min | Check Expo push receipts (`push:check-receipts`) | Undelivered pushes look delivered in every log, and dead device tokens are never retired |
| 07:30 daily | Inventory low-stock / expiry sweep | Staff never learn stock ran out |
| 08:00 daily | Follow-up reminder push | Follow-ups missed |
| 08:15 daily | Event SMS reminders (3 days out) | Medical missions unannounced |
| 00:05 daily | Expire stale prescriptions | Expired prescriptions stay dispensable |
| — | Telemedicine session reminders: **disabled since 2026-09-02** | It called a method that no longer exists and failed on every run; read the comment in `Kernel.php` before re-enabling |

```bash
sudo crontab -l -u www-data | grep schedule:run

# If it prints nothing, add it:
sudo crontab -e -u www-data
* * * * * cd /path/to/ka-agapay-backend && php artisan schedule:run >> /dev/null 2>&1

php artisan schedule:list     # prove the schedule is registered
```

> ### ⛔ Verify this on the live server
> Whether this entry exists cannot be determined from the source tree. It is the
> highest-value thing to check on the droplet: every scheduled patient-facing
> notification depends on it, and its absence is completely silent.

---

## 6. Certificates and renewal

Certificates last 90 days. Certbot normally installs a renewal timer — but
"normally" is not "verified", and an expired certificate takes down the mobile
app and the web admin simultaneously.

```bash
systemctl list-timers | grep certbot
sudo certbot certificates          # expiry dates
sudo certbot renew --dry-run       # proves renewal actually works
```

**The hook people forget:** a renewed certificate does not take effect until
Nginx reloads.

```sh
# /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
#!/bin/sh
systemctl reload nginx
```

---

## 7. Database backup and restore

> ### The Settings panel used to lie about this
>
> Until this hardening pass, the web admin's **Backup & Retention** panel wrote a
> timestamp to the browser's `localStorage` and called no API. "Automatic Daily
> Backup" was a checkbox that controlled nothing, and "Last Backup" showed a
> hardcoded date. Staff could read that screen and believe the database was
> protected when nothing backed up anything.
>
> **That panel is now read-only and server-backed.** It can only show runs that
> actually happened.

This matters more here than in most systems. The project enforces
archive-never-delete everywhere — soft deletes, restore windows, deletion
reasons, full actor accountability. All of that protects records from an errant
click. **None of it protects records from a failed droplet.**

### How it works now

| Piece | What it does |
|---|---|
| `backup_runs` table | One row per run: `started_at`, `finished_at`, `status`, `file_name`, `file_size_bytes`, `offsite_status`, `error_message`, `trigger` |
| `php artisan backup:run` | pg_dump → gzip → record the row → prune old **files** (never rows) |
| `php artisan backup:offsite <uploaded\|failed>` | Records whether the dump actually left the droplet |
| `GET /api/v1/admin/backups/status` | Read-only status for the Settings panel |
| `docs/deploy/kaagapay-backup.sh` | The cron wrapper that chains all three |

There is deliberately **no HTTP endpoint that triggers a backup.** `pg_dump` on a
request thread would block a PHP-FPM worker for the duration and compete with
live traffic for the same database. Cron owns backups.

**Health states shown in the panel:**

| State | Meaning |
|---|---|
| `healthy` | Recent successful dump, confirmed off the droplet |
| `unprotected` | Dump succeeded but never left the droplet — **not green** |
| `stale` | Last good backup older than `BACKUP_STALE_AFTER_HOURS` (default 36) |
| `never` | Nothing has ever run — the cron job is not installed |

`unprotected` is a distinct state on purpose: a dump sitting on the machine it
protects against is not a backup.

### Two layers, both cheap

**Layer 1 — DigitalOcean droplet backups.** Enable weekly backups in the control
panel (~20% of droplet cost). Whole-machine recovery: OS, Nginx config,
certificates, uploaded files. Coarse (up to a week of loss) — the floor, not the
plan.

**Layer 2 — the nightly script.** Install `docs/deploy/kaagapay-backup.sh`; its
footer carries the full procedure. Summary:

```bash
# .env
BACKUP_PATH=/var/backups/kaagapay
BACKUP_KEEP_DAYS=14
BACKUP_OFFSITE_ENABLED=true
php artisan config:cache

sudo mkdir -p /var/backups/kaagapay
sudo chown www-data:www-data /var/backups/kaagapay && sudo chmod 750 /var/backups/kaagapay
sudo cp docs/deploy/kaagapay-backup.sh /usr/local/bin/ && sudo chmod 755 /usr/local/bin/kaagapay-backup.sh

# Prove it works BEFORE trusting the schedule:
sudo -u www-data /usr/local/bin/kaagapay-backup.sh

sudo crontab -e -u www-data
0 2 * * * /usr/local/bin/kaagapay-backup.sh >> /var/log/kaagapay-backup.log 2>&1
```

> **`pg_dump` version matters.** If the client tools drift from the server
> version, the dump aborts with *"server version mismatch"*. The failure is
> recorded and alerted, but keep them in step. Override the binary with
> `BACKUP_PG_DUMP_BIN` if it is not on cron's PATH.

### Restoring

Never restore straight over production.

```bash
createdb -h "$DB_HOST" -U "$DB_USERNAME" ka_agapay_restore_test
gunzip -c ka_agapay_2026-09-01_0200.sql.gz | psql -h "$DB_HOST" -U "$DB_USERNAME" ka_agapay_restore_test

psql -h "$DB_HOST" -U "$DB_USERNAME" ka_agapay_restore_test \
  -c "select count(*) from users;" -c "select count(*) from consultations;"
```

> **A backup you have never restored is a guess.** Do one restore drill per term
> into a scratch database and write the date in `docs/HANDOVER-CHECKLIST.md`.
> Silent `pg_dump` failures are the normal way backup strategies die.

---

## 8. Logging and alerting

`config/logging.php`'s stack was hardcoded to `['single']` — one file, never
rotated, which is the usual way this droplet's disk fills. It is now driven by
`LOG_STACK`.

```
LOG_CHANNEL=stack
LOG_STACK=daily,slack      # daily rotates and keeps 14 files
LOG_LEVEL=warning
LOG_SLACK_WEBHOOK_URL=     # blank until someone with Slack admin creates one
LOG_SLACK_LEVEL=warning
```

**Listing `slack` with no webhook is safe and verified:** the handler is skipped,
nothing throws, and `daily` still receives every record. Setting the webhook
switches alerting on with no code change.

`LOG_SLACK_LEVEL` deliberately does **not** inherit `LOG_LEVEL`. A server left at
`LOG_LEVEL=debug` would otherwise fire the webhook on every debug record and
flood the channel into uselessness. Raise it to `error` if `warning` proves
noisy.

**Backup failures log at `error`**, so they reach the webhook at either setting:

```
[backup] Database backup FAILED.                              (backup:run)
[backup] Off-site copy FAILED — dump exists only on the droplet.  (backup:offsite)
```

This is right-sized for an LGU deployment. It is not observability tooling and
does not need to be — the goal is that a failed SMS batch or JaaS auth failure
reaches a person the same day rather than the same month.

---

## 9. Security posture

### Rate limiting

Auth endpoints previously shared **one `throttle:5,1` bucket keyed by IP alone.**
Every RHU workstation sits behind a single municipal connection, so five combined
attempts per minute were shared by all staff at both facilities — one mistyped
password locked out the building.

Each named limiter now applies a tight **per-account** limit plus a wide
**per-IP** ceiling:

| Limiter | Per account | Per IP | Routes |
|---|---|---|---|
| `auth-login` | 5/min | 30/min | login, admin login, register, verify-otp, biometric |
| `auth-recovery` | 3/min | 15/min | forgot/reset password, resend-otp |
| `auth-invite` | 10/min per token | 20/min | invite verify + accept |

Recovery is tighter because each accepted request can send a **billed SMS**.

These sit **on top of** `BruteForceProtection` (5 failures per mobile number,
15-minute lockout), which is per-account and survives across IPs. Rate limiting
bounds request rate; brute-force protection bounds failure count. Neither
replaces the other.

### Dependency scanning

```bash
cd /path/to/ka-agapay-backend && composer audit
cd /path/to/rhu-admin-main    && npm audit
```

Run monthly (§10). As of this pass the backend is down to **3 advisories in 1
package** and the web admin to **2**, all requiring major version bumps.

### ⚠ Laravel 10 is past end of security support

The 3 remaining backend advisories have **no fix on the 10.x line**:

- **Temporary Signed URL Path Confusion** — fixed only in ≥12.61.1. This lands
  directly on the signed one-time registration invites.
- **CRLF injection in the default email rule** (high) — fixed in ≥12.60.0.

Upgrading is a **scoped project, not a maintenance task.** Blast radius:

| Surface | Size | Why it needs retesting |
|---|---|---|
| Middleware | 12 | Laravel 11 deletes `Http/Kernel.php`; every alias re-registers in `bootstrap/app.php` |
| Rate limiters | 4 | `RouteServiceProvider` largely dissolves in 11 |
| Scheduler | 5 jobs | Moves to `routes/console.php`; silent failure if missed |
| Auth | 268 routes | Sanctum 3 → 4 |
| Date logic | queue, appointments, telemedicine | Carbon 2 → 3 — priority scoring and date-scoping all do date math |
| Controllers / Models / Migrations | 46 / 55 / 130 | Broad but mostly mechanical |

**The constraint is the safety net: 5 test files, 12 tests.** Nothing covers
queue prioritization, SMS, inventory, RHU isolation or the signed-invite flow.
Write characterization tests for **RHU isolation and the signed-invite flow
first**, then upgrade — otherwise the upgrade risks silently breaking the
isolation guarantee this system's data privacy rests on.

---

## 10. When something breaks

```bash
curl -s https://<api-host>/api/v1/health
tail -n 100 storage/logs/laravel-$(date +%F).log
sudo journalctl -u php8.3-fpm -n 50 --no-pager
```

| Symptom | Likely cause |
|---|---|
| **Video calls hang at "joining"** | JaaS credentials. `php artisan jitsi:doctor`. Historically an unset variable name (`JITSI_API_KEY_ID` vs `JITSI_API_KEY`) resolving to null, or PEM permissions the php-fpm user cannot read. |
| **SMS stopped, no error** | Check the Semaphore balance first (`GET /api/v1/admin/sms/account`) — depletion looks identical to an outage. Then `sms_logs`: rows are written *before* the provider is called, so a failed row means it went out and was rejected. |
| **Scheduled reminders never arrive** | The missing `schedule:run` cron entry (§5). |
| **Chatbot returns truncated/empty replies** | It is a thinking model; internal reasoning tokens bill against `maxOutputTokens`. Check for `finishReason=MAX_TOKENS` before assuming an outage. `php artisan gemini:test-key`. |
| **Invite links all report invalid signature** | `APP_URL`/`ADMIN_APP_URL` changed, or a stale config cache. `php artisan optimize:clear`, then re-cache. |
| **500 immediately after deploy** | Cached config referencing a now-missing variable. `php artisan optimize:clear`. |
| **Backup panel says "never" / "stale"** | The cron job is not installed, or stopped. §7. |
| **Backup panel says "unprotected"** | Dumps are succeeding but not leaving the droplet — check the uploader credentials in the wrapper script. |
| **Disk full** | Historically the unrotated `single` log channel. Confirm `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_LEVEL=warning` (§8), and that `/var/log/kaagapay-backup.log` is rotating. |

---

## 11. Mobile app releases

Expo, built through EAS and submitted to the stores.

- **Store review takes days.** A backend change requiring a matching mobile
  change cannot ship the same day. Keep API changes additive and
  backward-compatible, or older installed apps break in the field.
- **Credentials stay out of the repository.** `fcm-service-account.json` and
  `google-services.json` are correctly gitignored. A fresh clone will not build
  until someone supplies them — transfer them out of band, never commit them to
  "fix" the build.
- **How `google-services.json` actually reaches an EAS build, verified
  2026-09-06.** It is *not* referenced by `android.googleServicesFile` in
  `app.json`. It reaches the build because a `.easignore` file exists, and when
  one is present EAS uses it **instead of** `.gitignore` to decide what to
  upload — `.easignore` does not list `google-services.json`, so it ships.
  That works, but it works by omission rather than by intent: adding the file
  to `.easignore`, or deleting `.easignore` so `.gitignore` applies again,
  would silently produce builds with no FCM configuration. Push registration
  would then fail on device while the build itself still succeeds.
  If you harden this by declaring `"googleServicesFile": "./google-services.json"`
  in the `android` block, note that `expo prebuild` then **fails** when the file
  is absent — so CI must be given the file (a GitHub Actions secret written to
  disk before prebuild) in the same change, or the APK boot test breaks.
- **CI-built APKs have no FCM configuration.** GitHub Actions checks out from
  git, where `google-services.json` is correctly ignored, so
  `expo prebuild --clean` generates an `android/` without it. The boot test APK
  therefore cannot receive push notifications. That is fine for what the boot
  test asserts (the app opens), but it means the CI artifact differs from a
  shipped build in a push-relevant way — do not use a CI APK to test
  notifications.
- **A build with no `google-services.json` used to succeed silently.** Measured,
  not assumed. Two cases, and the dangerous one is the common one:

  | Starting state | Missing file causes |
  |---|---|
  | `android/` already generated | Gradle **fails** — the generated `android/app/build.gradle` applies `com.google.gms.google-services` unconditionally |
  | Fresh clone, or `prebuild --clean` | **Build succeeds.** Prebuild omits both the file and the plugin, Gradle has nothing to object to, and the APK installs, opens, requests a push token, and can never receive a notification |

  The second row is what CI does on every run, which is the proof that nothing
  in the toolchain objects to it.

  **Guard:** `scripts/check-push-credentials.mjs` in the mobile repo asserts the
  file exists, is non-empty, is valid JSON, and lists this app's
  `android.package` (a valid file for the *wrong* app is the quiet failure —
  FCM would issue tokens against the package named in the file). It is wired as
  `eas-build-pre-install` in `package.json`, so **every EAS build runs it first
  and fails loudly**. Run it by hand before any local build with
  `npm run check:push-credentials`.

  Verified by removing the file, emptying it, and pointing it at a different
  package — all three exit non-zero with the reason named. `npm install` does
  **not** trigger the hook (confirmed empirically), so GitHub Actions keeps
  building without the credential, which is intended.
- **No offline support.** No local caching or queued writes; every screen needs a
  live connection. For BHWs on barangay visits this is a real limit, and it is
  the thesis's own second recommendation for future work.
- **Dependencies are behind.** `npm audit` reports 34 advisories, all requiring an
  Expo SDK 54 → 57 major jump. The one `critical` (`tar`) is in `@expo/cli` —
  build tooling that never ships in the device bundle, so it affects the build
  machine only, not patients' phones.

---

## 12. Maintenance cadence

None of this is automated. Written down it is a checklist someone can follow;
left implicit it is what stops happening the moment the original team graduates.

| Every | Do | Why |
|---|---|---|
| **Week** | Check the Semaphore balance; glance at the Settings → Backup panel | SMS is the dominant cost and fails quietly when funds run out |
| **Month** | `composer audit` and `npm audit`; confirm the backup panel reads `healthy` | Both take seconds |
| **Quarter** | `sudo certbot renew --dry-run`; restore a dump into a scratch database and record the date; prune old `dist.prev-*` / `*-backup-*` directories under `/var/www` (§4) | Certificates last 90 days; an untested restore is not a restore; the deploy pattern never deletes anything |
| **Year** | Review the framework version against its security-support window | Laravel 10 is already past it (§9) |

---

*Last updated: 1 September 2026, during the pre-handover hardening pass.
Items still requiring a human at the droplet terminal are listed in
`docs/HANDOVER-CHECKLIST.md`.*
