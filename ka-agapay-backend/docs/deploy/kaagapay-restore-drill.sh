#!/usr/bin/env bash
#
# kaagapay-restore-drill.sh — prove the backups can actually be restored.
#
# INSTALLED ON PRODUCTION as /usr/local/bin/kaagapay-restore-drill.sh. This
# file is the source of truth; if you change it, copy it to /usr/local/bin/ on
# the droplet. See "Installing" at the bottom.
#
#   kaagapay-restore-drill.sh                 # newest dump
#   kaagapay-restore-drill.sh /path/to.sql.gz # a specific one
#
# WHY THIS EXISTS
# ---------------
# A backup nobody has restored is a hope, not a backup. The failure everyone
# fears is losing the server; the failure that actually happens is discovering,
# on the day you need it, that the dumps have been silently truncated for weeks
# because a disk filled or a password changed. Nothing about a backup job
# failing that way looks wrong — the cron still runs, the file still appears,
# and the log still says OK. Only a restore tells you.
#
# So this restores the dump into a SCRATCH database and compares it against
# production. It never writes to the live database. It is safe to run any time,
# including from cron, and is scheduled monthly so a corrupting backup is
# caught within weeks rather than at the worst possible moment.
#
# WHAT A PASS LOOKS LIKE
# ----------------------
# Table and index counts must match production exactly. Row counts must match
# too — EXCEPT for rows written after the dump was taken, which is normal and
# expected, not a fault. The script works out that cutoff from the dump's own
# filename and treats only those rows as explainable. A row missing from before
# the cutoff is a real failure and exits non-zero.
#
# FIRST RUN: 26 September 2026 — 0 errors, 79/79 tables, 276/276 indexes, all
# row counts matched except 1 heatmap_alert and 18 notifications created after
# the 02:00 dump.

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/kaagapay}"
LIVE_DB="${LIVE_DB:-kaagapay_db}"
SCRATCH_DB="kaagapay_restore_drill_$$"

# Tables worth comparing. Not every table — these are the ones whose loss would
# actually matter, which keeps the output readable enough to be read.
TABLES="users consultations appointments prescriptions queue_tickets \
telemedicine_sessions barangays rhus heatmap_alerts notifications \
resident_profiles inventory_items"

psql_live() { sudo -u postgres psql -tA -d "$LIVE_DB" -c "$1" 2>/dev/null || echo "n/a"; }
psql_scratch() { sudo -u postgres psql -tA -d "$SCRATCH_DB" -c "$1" 2>/dev/null || echo "n/a"; }

# The scratch database goes away whatever happens — including on failure, so a
# broken run does not leave a half-restored copy of patient data lying around.
cleanup() {
    sudo -u postgres psql -q -c "DROP DATABASE IF EXISTS $SCRATCH_DB;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

DUMP="${1:-}"

if [ -z "$DUMP" ]; then
    DUMP="$(ls -t "$BACKUP_DIR"/kaagapay_db_*.sql.gz 2>/dev/null | head -1 || true)"
fi

[ -n "$DUMP" ] && [ -f "$DUMP" ] || {
    echo "FAIL: no dump found. Looked in $BACKUP_DIR"
    exit 1
}

echo "=== Ka-Agapay restore drill ==="
echo "dump : $(basename "$DUMP") ($(du -h "$DUMP" | cut -f1))"
echo "taken: $(date -r "$DUMP" -u '+%Y-%m-%d %H:%M UTC')"
echo

# The dump's own mtime is the cutoff: rows created after it cannot be in the
# file and must not be counted against the restore.
CUTOFF="$(date -r "$DUMP" -u '+%Y-%m-%d %H:%M:%S')"

# --- 1. the archive itself -------------------------------------------------
# Cheap, and catches a truncated or partially-written file before we spend time
# building a database out of it.
echo "--> archive integrity"
gzip -t "$DUMP" || { echo "FAIL: $DUMP is not a valid gzip archive"; exit 1; }
echo "    ok"

# --- 2. restore into scratch ----------------------------------------------
echo "--> restoring into $SCRATCH_DB"
sudo -u postgres psql -q -c "DROP DATABASE IF EXISTS $SCRATCH_DB;" >/dev/null 2>&1 || true
sudo -u postgres psql -q -c "CREATE DATABASE $SCRATCH_DB;" >/dev/null

RESTORE_LOG="$(mktemp)"
gunzip -c "$DUMP" | sudo -u postgres psql -q -d "$SCRATCH_DB" >"$RESTORE_LOG" 2>&1 || true

ERRORS="$(grep -ciE '^ERROR' "$RESTORE_LOG" || true)"
echo "    restore errors: ${ERRORS:-0}"

if [ "${ERRORS:-0}" -gt 0 ]; then
    echo
    echo "FAIL: the dump did not restore cleanly. First few errors:"
    grep -iE '^ERROR' "$RESTORE_LOG" | head -5
    rm -f "$RESTORE_LOG"
    exit 1
fi
rm -f "$RESTORE_LOG"

# --- 3. schema completeness ------------------------------------------------
# A dump can restore without error and still be missing objects, so count them.
echo "--> schema"
LT="$(psql_live "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';")"
ST="$(psql_scratch "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';")"
LI="$(psql_live "SELECT count(*) FROM pg_indexes WHERE schemaname='public';")"
SI="$(psql_scratch "SELECT count(*) FROM pg_indexes WHERE schemaname='public';")"

printf "    tables  production %-6s restored %-6s %s\n" "$LT" "$ST" "$([ "$LT" = "$ST" ] && echo ok || echo MISMATCH)"
printf "    indexes production %-6s restored %-6s %s\n" "$LI" "$SI" "$([ "$LI" = "$SI" ] && echo ok || echo MISMATCH)"

FAILED=0
[ "$LT" = "$ST" ] || FAILED=1
[ "$LI" = "$SI" ] || FAILED=1

# --- 4. row counts ---------------------------------------------------------
echo "--> row counts (differences after $CUTOFF UTC are expected)"
printf "    %-24s %10s %10s %s\n" TABLE PRODUCTION RESTORED RESULT

for t in $TABLES; do
    P="$(psql_live "SELECT count(*) FROM $t;")"
    R="$(psql_scratch "SELECT count(*) FROM $t;")"

    [ "$P" = "n/a" ] && continue

    if [ "$P" = "$R" ]; then
        printf "    %-24s %10s %10s ok\n" "$t" "$P" "$R"
        continue
    fi

    # Different. Only acceptable if every extra production row was created
    # after the dump was taken.
    AFTER="$(psql_live "SELECT count(*) FROM $t WHERE created_at > '$CUTOFF';")"
    DELTA=$((P - R))

    if [ "$AFTER" != "n/a" ] && [ "$AFTER" = "$DELTA" ]; then
        printf "    %-24s %10s %10s ok (+%s written after the dump)\n" "$t" "$P" "$R" "$DELTA"
    else
        printf "    %-24s %10s %10s DATA LOSS (%s rows unaccounted for)\n" "$t" "$P" "$R" "$DELTA"
        FAILED=1
    fi
done

echo
if [ "$FAILED" -eq 0 ]; then
    echo "PASS — this backup is restorable."
    exit 0
fi

echo "FAIL — this backup did NOT restore faithfully. Do not rely on it."
echo "Check the backup job (/var/log/kaagapay-backup.log) before anything else."
exit 1

# ---------------------------------------------------------------------------
# Installing
# ---------------------------------------------------------------------------
#   scp docs/deploy/kaagapay-restore-drill.sh root@<droplet>:/usr/local/bin/
#   ssh root@<droplet> 'chmod +x /usr/local/bin/kaagapay-restore-drill.sh'
#
# Scheduled monthly from the www-data crontab (run as root via sudo is NOT
# needed; the script calls sudo -u postgres itself):
#
#   30 3 1 * * /usr/local/bin/kaagapay-restore-drill.sh >> /var/log/kaagapay-restore-drill.log 2>&1
#
# The log file must exist and be writable by www-data BEFORE the job can run:
# a shell that cannot open its redirect target aborts before running the
# command, so a missing log means the drill silently never happens — the same
# trap documented in kaagapay-backup.sh.
#
#   touch /var/log/kaagapay-restore-drill.log
#   chown www-data:www-data /var/log/kaagapay-restore-drill.log
