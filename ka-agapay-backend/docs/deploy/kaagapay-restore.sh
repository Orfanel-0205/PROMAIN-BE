#!/usr/bin/env bash
#
# kaagapay-restore.sh — put a backup back into the LIVE database.
#
# INSTALLED ON PRODUCTION as /usr/local/bin/kaagapay-restore.sh. This file is
# the source of truth; if you change it, copy it to /usr/local/bin/.
#
#   kaagapay-restore.sh                 # newest dump
#   kaagapay-restore.sh /path/to.sql.gz # a specific one
#
# ============================ READ THIS FIRST ============================
# This OVERWRITES the live database. Every consultation, prescription and
# appointment recorded since the chosen dump was taken will be gone.
#
# Run it only when the live data is already lost or corrupt. If you are not
# certain, run kaagapay-restore-drill.sh instead — that one proves a backup is
# good without touching anything.
# =========================================================================
#
# WHY THIS SCRIPT EXISTS RATHER THAN A PAGE OF INSTRUCTIONS
# ---------------------------------------------------------
# A restore is done by a frightened person at an unreasonable hour, usually
# someone who has never done one before. That is the worst possible moment to
# be transcribing commands out of a document. On 22 September 2026 this system
# lost its admin site to exactly that: a runbook line containing a placeholder
# in angle brackets, which bash read as a redirect. The restore failed and the
# step before it had already moved the live directory away.
#
# So the values here are computed, not typed. What the operator provides is a
# decision, not a command.
#
# WHAT IT DOES BEFORE IT DESTROYS ANYTHING
# ----------------------------------------
#   1. Verifies the archive is a valid gzip file.
#   2. Test-restores it into a scratch database and checks it is complete.
#      A corrupt backup is found BEFORE the live database is dropped, not
#      after — otherwise a bad restore leaves you with nothing at all.
#   3. Dumps the CURRENT live database to /var/backups/kaagapay/pre-restore-*
#      so that even this operation is reversible.
#   4. Only then replaces the live database.

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/kaagapay}"
LIVE_DB="${LIVE_DB:-kaagapay_db}"
APP_DIR="${APP_DIR:-/var/www/ka-agapay-backend/ka-agapay-backend}"
SCRATCH_DB="kaagapay_restore_check_$$"

cleanup() {
    sudo -u postgres psql -q -c "DROP DATABASE IF EXISTS $SCRATCH_DB;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

DUMP="${1:-}"
[ -n "$DUMP" ] || DUMP="$(ls -t "$BACKUP_DIR"/kaagapay_db_*.sql.gz 2>/dev/null | head -1 || true)"

[ -n "$DUMP" ] && [ -f "$DUMP" ] || { echo "FAIL: no dump found in $BACKUP_DIR"; exit 1; }

TAKEN="$(date -r "$DUMP" -u '+%Y-%m-%d %H:%M UTC')"

echo "============================================================"
echo "  RESTORE TO LIVE DATABASE"
echo "============================================================"
echo "  dump    : $(basename "$DUMP")"
echo "  taken   : $TAKEN"
echo "  target  : $LIVE_DB  (THIS WILL BE REPLACED)"
echo

# Tell the operator exactly what they are about to lose, in records rather
# than in abstractions. "You will lose 14 consultations" is a decision someone
# can actually make; "data since the last backup" is not.
CUTOFF="$(date -r "$DUMP" -u '+%Y-%m-%d %H:%M:%S')"
echo "  Records created since that dump, which WILL BE LOST:"
for t in consultations appointments prescriptions queue_tickets telemedicine_sessions; do
    N="$(sudo -u postgres psql -tA -d "$LIVE_DB" -c "SELECT count(*) FROM $t WHERE created_at > '$CUTOFF';" 2>/dev/null || echo '?')"
    printf "    %-24s %s\n" "$t" "$N"
done
echo

read -r -p "  Type RESTORE in capitals to continue: " CONFIRM
[ "$CONFIRM" = "RESTORE" ] || { echo "  Cancelled. Nothing was changed."; exit 1; }
echo

# --- 1. verify the archive -------------------------------------------------
echo "--> checking the archive"
gzip -t "$DUMP" || { echo "FAIL: not a valid gzip archive. Nothing was changed."; exit 1; }
echo "    ok"

# --- 2. test-restore before touching production ----------------------------
# The whole point: find out the backup is bad while the live database is still
# there to fall back on.
echo "--> test-restoring into a scratch database first"
sudo -u postgres psql -q -c "CREATE DATABASE $SCRATCH_DB;" >/dev/null
TMPLOG="$(mktemp)"
gunzip -c "$DUMP" | sudo -u postgres psql -q -d "$SCRATCH_DB" >"$TMPLOG" 2>&1 || true

if grep -qiE '^ERROR' "$TMPLOG"; then
    echo "FAIL: this backup does not restore cleanly. Live database untouched."
    grep -iE '^ERROR' "$TMPLOG" | head -5
    rm -f "$TMPLOG"
    exit 1
fi
rm -f "$TMPLOG"

TABLES="$(sudo -u postgres psql -tA -d "$SCRATCH_DB" -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';")"
[ "$TABLES" -gt 50 ] || { echo "FAIL: restored copy has only $TABLES tables. Live database untouched."; exit 1; }
echo "    ok — $TABLES tables restored cleanly"

# --- 3. snapshot what is about to be replaced ------------------------------
# Even a deliberate restore should be reversible. This is what makes it so.
SAFETY="$BACKUP_DIR/pre-restore-$(date -u +%Y-%m-%d_%H%M%S).sql.gz"
echo "--> snapshotting the current live database"
sudo -u postgres pg_dump "$LIVE_DB" | gzip > "$SAFETY"
echo "    saved to $SAFETY"

# --- 4. replace the live database -----------------------------------------
echo "--> stopping the web server so nothing writes mid-restore"
systemctl stop php8.3-fpm

echo "--> replacing $LIVE_DB"
sudo -u postgres psql -q -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$LIVE_DB' AND pid <> pg_backend_pid();" >/dev/null
sudo -u postgres psql -q -c "DROP DATABASE $LIVE_DB;"
sudo -u postgres psql -q -c "CREATE DATABASE $LIVE_DB;"
gunzip -c "$DUMP" | sudo -u postgres psql -q -d "$LIVE_DB" >/dev/null

echo "--> starting the web server"
systemctl start php8.3-fpm

# Config and route caches can hold values from before the restore.
echo "--> clearing application caches"
cd "$APP_DIR"
sudo -u www-data php artisan config:clear >/dev/null 2>&1 || true
sudo -u www-data php artisan cache:clear >/dev/null 2>&1 || true

# artisan run as root leaves root-owned cache files that www-data cannot write,
# which returns 500 on every authenticated request. Learned the hard way.
chown -R www-data:www-data storage bootstrap/cache

echo
echo "============================================================"
echo "  RESTORE COMPLETE"
echo "============================================================"
echo "  Restored from : $(basename "$DUMP") ($TAKEN)"
echo "  Previous data : $SAFETY"
echo
echo "  Now check, in this order:"
echo "    1. The admin site loads and you can log in."
echo "    2. A recent consultation and appointment are present."
echo "    3. Uploaded files are still on disk — they are NOT in this dump."
echo "       They live in the kaagapay_files_*.tar.gz archives."
echo
echo "  If this restore was a mistake, put the old data back with:"
echo "    kaagapay-restore.sh $SAFETY"

# ---------------------------------------------------------------------------
# Installing
# ---------------------------------------------------------------------------
#   scp docs/deploy/kaagapay-restore.sh root@<droplet>:/usr/local/bin/
#   ssh root@<droplet> 'chmod +x /usr/local/bin/kaagapay-restore.sh'
#
# Never scheduled. This one is only ever run by a person who has decided to
# run it.
