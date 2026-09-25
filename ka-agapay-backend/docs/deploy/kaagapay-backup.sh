#!/usr/bin/env bash
#
# kaagapay-backup.sh — nightly database backup for the Ka-Agapay droplet.
#
# INSTALLED ON PRODUCTION as /usr/local/bin/kaagapay-backup.sh, run from the
# www-data crontab at 02:00 UTC (10:00 Manila). This file is the source of
# truth; if you change it, copy it to /usr/local/bin/ on the droplet. See
# "Installing" at the bottom.
#
# Note the cron entry appends to /var/log/kaagapay-backup.log. That file must
# exist and be writable BY www-data before the job can run at all: when a shell
# cannot open a redirect target it aborts before executing the command, so a
# missing or root-owned log file means the backup silently never runs and
# nothing anywhere records that it didn't.
#
# What it does, in order:
#   1. php artisan backup:run    — pg_dump + gzip, and writes a backup_runs row
#   2. tars the uploaded FILES that no dump contains
#   3. copies both off the droplet
#   4. php artisan backup:offsite — records whether step 3 actually worked
#
# WHY THE FILES (added 2026-09-22)
# -------------------------------
# Until today this backed up the database alone. Resident ID photographs,
# PhilHealth IDs, prescription PDFs and Team Chat attachments live on disk,
# not in PostgreSQL, so losing the droplet would have restored a database
# full of rows pointing at files that no longer existed — the worst shape of
# failure, because it looks like a successful recovery until somebody opens
# a record.
#
# Both archives carry the SAME timestamp, taken from the dump filename. A
# database from 02:00 and files from 04:00 describe different moments, and a
# real recovery is the wrong time to find that out.
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

# The doubled path segment is NOT a typo: the git repository root is
# /var/www/ka-agapay-backend and the Laravel application sits in a
# ka-agapay-backend/ subdirectory inside it, so artisan is one level deeper
# than the repo. This file previously shipped the shorter path, which pointed
# at a directory containing no artisan.
APP_DIR="/var/www/ka-agapay-backend/ka-agapay-backend"   # directory containing `artisan`
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

# --- Step 2: archive the files the dump does not contain -------------------

# The dump this run just produced. Guarded so an empty directory cannot let
# the upload silently succeed with no argument.
LATEST="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -n 1 || true)"

if [ -z "$LATEST" ]; then
    log "ERROR: backup:run reported success but no dump file was found in $BACKUP_DIR"
    "$PHP_BIN" artisan backup:offsite failed $RUN_ARG --message="No dump file found on disk after a successful run."
    exit 1
fi

# Share the dump's own timestamp rather than taking a new one, so the pair
# can never describe two different moments.
STAMP="$(basename "$LATEST" | sed -E 's/^kaagapay_db_(.*)\.sql\.gz$/\1/')"
FILES_ARCHIVE="$BACKUP_DIR/kaagapay_files_${STAMP}.tar.gz"

# Only what cannot be regenerated. Caches, compiled views, sessions and logs
# are all rebuilt on a fresh deploy and would triple the archive for nothing.
log "Archiving uploaded files..."

tar -czf "$FILES_ARCHIVE" \
    -C "$APP_DIR/storage" \
    app/private \
    app/public \
    2>/tmp/kaagapay-files-tar.err
TAR_RC=$?

if [ $TAR_RC -ne 0 ]; then
    log "ERROR: could not archive files (rc=$TAR_RC): $(head -c 300 /tmp/kaagapay-files-tar.err)"
    "$PHP_BIN" artisan backup:offsite failed $RUN_ARG --message="File archive failed (rc=$TAR_RC)."
    exit 1
fi

# Read the archive back before trusting it. A corrupt tar that uploads
# successfully is worse than no tar at all, because it is believed until the
# day it is needed.
ARCHIVED_COUNT="$(tar -tzf "$FILES_ARCHIVE" 2>/dev/null | grep -vc '/$' || true)"
ON_DISK_COUNT="$(find "$APP_DIR/storage/app/private" "$APP_DIR/storage/app/public" -type f 2>/dev/null | wc -l)"

if [ "${ARCHIVED_COUNT:-0}" -lt 1 ]; then
    log "ERROR: the file archive is unreadable or empty."
    rm -f "$FILES_ARCHIVE"
    "$PHP_BIN" artisan backup:offsite failed $RUN_ARG --message="File archive could not be read back."
    exit 1
fi

# A small drift is normal: a file can be written while tar is running. A
# large one means something was skipped, and that is worth seeing in the log
# rather than discovering during a recovery.
log "Archived $ARCHIVED_COUNT file(s); $ON_DISK_COUNT on disk ($(du -h "$FILES_ARCHIVE" | cut -f1))."

# --- Step 3: get both off the droplet -------------------------------------

log "Uploading $(basename "$LATEST") and $(basename "$FILES_ARCHIVE") to $REMOTE_DEST ..."

# Swap this one line for whatever uploader the droplet has installed:
#   s3cmd --config=/etc/kaagapay/s3cfg put "$LATEST" "$REMOTE_DEST/"
#   aws s3 cp "$LATEST" "$REMOTE_DEST/" --endpoint-url https://sgp1.digitaloceanspaces.com
#   rclone copy "$LATEST" "spaces:kaagapay-backups"
#   scp "$LATEST" backup-user@another-host:/srv/kaagapay-backups/
#
# --config is explicit because this runs from cron as www-data, whose home is
# /var/www -- a directory it cannot even write to, and which holds no .s3cfg.
# s3cmd's default ~/.s3cfg lookup therefore finds nothing and the upload fails
# while the local dump still succeeds, which is exactly the split-failure this
# script separates steps 1 and 3 to catch. Credentials live in
# /etc/kaagapay/s3cfg, mode 0600 and owned by www-data.
UPLOAD_ERR="$(s3cmd --config=/etc/kaagapay/s3cfg put "$LATEST" "$REMOTE_DEST/" 2>&1)"
UPLOAD_RC=$?

# The files matter as much as the rows. Either one missing makes the other
# an incomplete recovery, so a failure on either is a failed backup.
if [ $UPLOAD_RC -eq 0 ]; then
    UPLOAD_ERR="$(s3cmd --config=/etc/kaagapay/s3cfg put "$FILES_ARCHIVE" "$REMOTE_DEST/" 2>&1)"
    UPLOAD_RC=$?
fi

# Prune old file archives here. backup:run prunes the dumps itself, but it
# knows nothing about these.
KEEP_DAYS="$(grep -E '^BACKUP_KEEP_DAYS=' "$APP_DIR/.env" 2>/dev/null | cut -d= -f2 | tr -dc '0-9')"
[ -z "$KEEP_DAYS" ] && KEEP_DAYS=14
find "$BACKUP_DIR" -name 'kaagapay_files_*.tar.gz' -type f -mtime "+$KEEP_DAYS" -delete 2>/dev/null

# --- Step 4: record the truth ---------------------------------------------

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
# A backup you have never restored is a guess. That drill is now a script:
#
#   kaagapay-restore-drill.sh
#
# It restores the newest dump into a scratch database, compares tables,
# indexes and row counts against production, and drops the scratch copy.
# It never touches the live database, and it runs monthly from the root
# crontab. First run 26 September 2026: PASS.
#
# To put a backup BACK into the live database, use kaagapay-restore.sh --
# that one is destructive and asks before it does anything.
