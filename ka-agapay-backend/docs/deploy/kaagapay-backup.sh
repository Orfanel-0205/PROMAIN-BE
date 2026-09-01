#!/usr/bin/env bash
#
# kaagapay-backup.sh — nightly database backup for the Ka-Agapay droplet.
#
# NOT YET INSTALLED ON PRODUCTION. This file is a deliverable for whoever has
# SSH access; see "Installing" at the bottom. Nothing in the application runs
# it automatically.
#
# What it does, in order:
#   1. php artisan backup:run    — pg_dump + gzip, and writes a backup_runs row
#   2. copies the newest dump off the droplet
#   3. php artisan backup:offsite — records whether step 2 actually worked
#
# Steps 1 and 3 are separate because they fail separately. pg_dump succeeds
# while object-storage credentials have quietly expired, leaving the only copy
# of the database on the exact machine whose failure it was meant to survive.
# The Settings page shows that state as "Backup has not left the server" rather
# than green.
#
# No credential value appears in this file. The database password comes from the
# application's .env via `backup:run`; the object-storage credentials come from
# the uploader's own config (~/.s3cfg or ~/.aws/credentials), which must be
# readable by the user in the crontab and by nobody else.

set -uo pipefail

# ---------------------------------------------------------------------------
# Configure these three lines for the droplet, then leave the rest alone.
# ---------------------------------------------------------------------------

APP_DIR="/var/www/ka-agapay-backend"      # directory containing `artisan`
BACKUP_DIR="/var/backups/kaagapay"        # must match BACKUP_PATH in .env
REMOTE_DEST="s3://kaagapay-backups"       # DigitalOcean Spaces / S3 bucket

PHP_BIN="$(command -v php || echo /usr/bin/php)"

# ---------------------------------------------------------------------------

log() { echo "[$(date -Is)] $*"; }

cd "$APP_DIR" || { log "FATAL: cannot cd to $APP_DIR"; exit 1; }

# --- Step 1: dump ----------------------------------------------------------

log "Starting database dump..."

# Capture output so the run id can be pinned below, but still show it in the log.
RUN_OUT="$("$PHP_BIN" artisan backup:run --trigger=cron 2>&1)"
RUN_RC=$?
echo "$RUN_OUT"

if [ $RUN_RC -ne 0 ]; then
    # backup:run has already written a failed backup_runs row and logged at
    # error level, so the Settings panel and the log stack both know. Exiting
    # non-zero additionally trips cron's own MAILTO if one is configured.
    log "FATAL: backup:run failed. Not attempting off-site copy."
    exit 1
fi

# Pin the exact run this script created. Without --run, `backup:offsite` would
# annotate "the newest successful run", which is the wrong row if anything else
# started a backup in between.
RUN_ID="$(echo "$RUN_OUT" | grep -o 'BACKUP_RUN_ID=[0-9]*' | head -n1 | cut -d= -f2)"
RUN_ARG=""
[ -n "$RUN_ID" ] && RUN_ARG="--run=$RUN_ID"

# --- Step 2: get it off the droplet ---------------------------------------

# Newest dump by modification time. Guarded so an empty directory cannot make
# the upload silently succeed with no argument.
LATEST="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -n 1 || true)"

if [ -z "$LATEST" ]; then
    log "ERROR: backup:run reported success but no dump file was found in $BACKUP_DIR"
    "$PHP_BIN" artisan backup:offsite failed $RUN_ARG --message="No dump file found on disk after a successful run."
    exit 1
fi

log "Uploading $(basename "$LATEST") to $REMOTE_DEST ..."

# Swap this one line for whatever uploader the droplet has installed:
#   s3cmd put "$LATEST" "$REMOTE_DEST/"
#   aws s3 cp "$LATEST" "$REMOTE_DEST/" --endpoint-url https://sgp1.digitaloceanspaces.com
#   rclone copy "$LATEST" "spaces:kaagapay-backups"
#   scp "$LATEST" backup-user@another-host:/srv/kaagapay-backups/
UPLOAD_ERR="$(s3cmd put "$LATEST" "$REMOTE_DEST/" 2>&1)"
UPLOAD_RC=$?

# --- Step 3: record the truth ---------------------------------------------

if [ $UPLOAD_RC -eq 0 ]; then
    log "Upload OK."
    "$PHP_BIN" artisan backup:offsite uploaded $RUN_ARG
    exit 0
fi

log "ERROR: upload failed (rc=$UPLOAD_RC)."

# Trim and pass along the uploader's own message. Keep it short: it is stored in
# backup_runs.error_message and shown to RHU staff. Never echo credentials here
# -- if your uploader prints them on error, drop --message entirely.
"$PHP_BIN" artisan backup:offsite failed $RUN_ARG \
    --message="Off-site upload failed (rc=$UPLOAD_RC): $(echo "$UPLOAD_ERR" | head -c 400)"

exit 1

# ===========================================================================
# INSTALLING (requires droplet access — has NOT been done from this repo)
# ===========================================================================
#
#   1. Add to the backend .env, then re-cache config:
#
#        BACKUP_PATH=/var/backups/kaagapay
#        BACKUP_KEEP_DAYS=14
#        BACKUP_OFFSITE_ENABLED=true
#        # BACKUP_PG_DUMP_BIN=/usr/bin/pg_dump   # only if not on cron's PATH
#
#        php artisan config:cache
#
#   2. Create the directory, owned by the user cron will run as:
#
#        sudo mkdir -p /var/backups/kaagapay
#        sudo chown www-data:www-data /var/backups/kaagapay
#        sudo chmod 750 /var/backups/kaagapay
#
#   3. Install the script:
#
#        sudo cp docs/deploy/kaagapay-backup.sh /usr/local/bin/
#        sudo chmod 755 /usr/local/bin/kaagapay-backup.sh
#
#   4. Prove it works BEFORE trusting the schedule. This is the step people
#      skip, and it is the one that catches a pg_dump missing from cron's PATH:
#
#        sudo -u www-data /usr/local/bin/kaagapay-backup.sh
#
#      Then confirm the row is real:
#        GET /api/v1/admin/backups/status   (or the Settings > Backup panel)
#
#   5. Schedule it for 02:00 daily, as the same user:
#
#        sudo crontab -e -u www-data
#        0 2 * * * /usr/local/bin/kaagapay-backup.sh >> /var/log/kaagapay-backup.log 2>&1
#
#   6. Rotate that log so it cannot fill the disk:
#
#        printf '/var/log/kaagapay-backup.log {\n  weekly\n  rotate 8\n  compress\n  missingok\n  notifempty\n}\n' \
#          | sudo tee /etc/logrotate.d/kaagapay-backup
#
# A backup you have never restored is a guess. Do one restore drill into a
# scratch database (docs/OPERATIONS.md, Section 07) and write down the date.
