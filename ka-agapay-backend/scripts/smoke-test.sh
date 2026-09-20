#!/usr/bin/env bash
# scripts/smoke-test.sh
#
# The check to run after every deploy: is Ka-Agapay actually working for
# residents and staff right now?
#
# Two modes, because half the answers live outside the server and half inside:
#
#   ./scripts/smoke-test.sh                 from anywhere, over the internet
#   ./scripts/smoke-test.sh --server        on the droplet, as root
#
# With staff credentials it also signs in and exercises the API as a real
# account. Without them it stops before that and says so, rather than
# reporting a pass it did not earn:
#
#   SMOKE_MOBILE=09XXXXXXXXX SMOKE_PASSWORD='...' ./scripts/smoke-test.sh
#
# Use a dedicated test account, never a real staff member's, and never put the
# password in a file that is committed.
#
# Exit code 0 = everything checked passed. Non-zero = something needs a look.

set -uo pipefail

BASE_URL="${BASE_URL:-https://rhu-kaagapay.129-212-236-47.sslip.io}"
ADMIN_URL="${ADMIN_URL:-$BASE_URL}"
API="$BASE_URL/api/v1"
APP_DIR="${APP_DIR:-/var/www/ka-agapay-backend/ka-agapay-backend}"

passed=0
failed=0
skipped=0

pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; passed=$((passed + 1)); }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; failed=$((failed + 1)); }
skip() { printf '  \033[33mSKIP\033[0m  %s\n' "$1"; skipped=$((skipped + 1)); }
head() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# HTTP status of a URL, with a short timeout so a hung server fails fast.
status_of() {
  curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$@"
}

expect_status() {
  local label="$1" expected="$2"; shift 2
  local actual
  actual="$(status_of "$@")"

  if [ "$actual" = "$expected" ]; then
    pass "$label ($actual)"
  else
    fail "$label — expected $expected, got $actual"
  fi
}

# ─────────────────────────────────────────────────────────────────────────────
# Mode 1: from outside. What a resident's phone and a staff browser can reach.
# ─────────────────────────────────────────────────────────────────────────────
remote_checks() {
  head "Reachability"

  local health
  health="$(curl -s --max-time 15 "$API/health")"

  if printf '%s' "$health" | grep -q '"status":"ok"'; then
    pass "API health says ok"
  else
    fail "API health — got: ${health:-<no response>}"
  fi

  expect_status "Staff admin page loads" 200 "$ADMIN_URL/"

  local redirect
  redirect="$(status_of -I "http://${BASE_URL#https://}")"

  if [ "$redirect" = "301" ] || [ "$redirect" = "302" ] || [ "$redirect" = "308" ]; then
    pass "Plain HTTP redirects to HTTPS ($redirect)"
  else
    fail "Plain HTTP should redirect to HTTPS — got $redirect"
  fi

  head "Doors that must stay shut"

  # Patient data behind a login. 401 is the only right answer here.
  expect_status "Prescriptions need a login" 401 -H 'Accept: application/json' "$API/prescriptions"
  expect_status "Patient registry needs a login" 401 -H 'Accept: application/json' "$API/patients/registry"
  expect_status "RHU list needs a login" 401 -H 'Accept: application/json' "$API/rhus"

  # Private files and secrets must never be served.
  local storage env_status
  storage="$(status_of "$BASE_URL/storage/")"
  [ "$storage" = "403" ] || [ "$storage" = "404" ] \
    && pass "Storage folder is not browsable ($storage)" \
    || fail "Storage folder answered $storage — expected 403 or 404"

  env_status="$(status_of "$BASE_URL/.env")"
  [ "$env_status" = "403" ] || [ "$env_status" = "404" ] \
    && pass "Settings file is not downloadable ($env_status)" \
    || fail ".env answered $env_status — expected 403 or 404"

  head "Certificate"

  local expiry
  expiry="$(echo | openssl s_client -connect "${BASE_URL#https://}:443" -servername "${BASE_URL#https://}" 2>/dev/null \
    | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"

  if [ -n "$expiry" ]; then
    local days
    days="$(( ( $(date -d "$expiry" +%s) - $(date +%s) ) / 86400 ))"

    if [ "$days" -gt 14 ]; then
      pass "Certificate valid for $days more days"
    else
      fail "Certificate expires in $days days — renewal should have run"
    fi
  else
    skip "Certificate expiry (openssl not available)"
  fi

  head "Signed in as staff"

  if [ -z "${SMOKE_MOBILE:-}" ] || [ -z "${SMOKE_PASSWORD:-}" ]; then
    skip "Sign-in checks — set SMOKE_MOBILE and SMOKE_PASSWORD to include them"
    return
  fi

  local login token
  login="$(curl -s --max-time 20 -X POST "$API/login" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"mobile_number\":\"$SMOKE_MOBILE\",\"password\":\"$SMOKE_PASSWORD\"}")"

  token="$(printf '%s' "$login" | grep -oE '"token":"[^"]+"' | head -1 | cut -d'"' -f4)"

  if [ -z "$token" ]; then
    fail "Staff sign-in failed — check the test account is active and not locked"
    return
  fi

  pass "Staff sign-in works"

  local auth=(-H "Authorization: Bearer $token" -H 'Accept: application/json')

  expect_status "RHU facility list loads" 200 "${auth[@]}" "$API/rhus"
  expect_status "Queue loads" 200 "${auth[@]}" "$API/queue"
  expect_status "Prescription list loads" 200 "${auth[@]}" "$API/prescriptions"
  expect_status "Notifications load" 200 "${auth[@]}" "$API/notifications"

  # The assistant answers, and answers with an action rather than a lecture.
  local chat
  chat="$(curl -s --max-time 30 -X POST "$API/chat/message" \
    "${auth[@]}" -H 'Content-Type: application/json' \
    -d '{"message":"look for Clifford","audience":"staff","source":"smoke-test"}')"

  if printf '%s' "$chat" | grep -q '"suggested_action":"open_patient_registry"'; then
    pass "Assistant answers and knows what to open"
  else
    fail "Assistant reply was not the expected action — got: $(printf '%s' "$chat" | head -c 200)"
  fi

  curl -s --max-time 15 -X POST "$API/logout" "${auth[@]}" >/dev/null && pass "Sign-out works"
}

# ─────────────────────────────────────────────────────────────────────────────
# Mode 2: on the droplet. The things only the server can answer.
# ─────────────────────────────────────────────────────────────────────────────
server_checks() {
  head "Services"

  for unit in nginx php8.3-fpm postgresql cron; do
    if systemctl is-active --quiet "$unit"; then
      pass "$unit is running"
    else
      fail "$unit is NOT running"
    fi
  done

  head "Database and scheduler"

  if [ -d "$APP_DIR" ]; then
    cd "$APP_DIR" || return

    if sudo -u www-data php artisan migrate:status 2>/dev/null | grep -q "Pending"; then
      fail "Migrations are pending — run php artisan migrate --force"
    else
      pass "No pending migrations"
    fi

    if sudo crontab -l -u www-data 2>/dev/null | grep -q "schedule:run"; then
      pass "Scheduler is in cron"
    else
      fail "Scheduler is NOT in cron — reminders and receipt checks will not run"
    fi
  else
    skip "Application checks — $APP_DIR not found (set APP_DIR)"
  fi

  head "Backups"

  local last
  last="$(ls -t /var/backups/kaagapay/*.gz 2>/dev/null | head -1)"

  if [ -n "$last" ]; then
    local age_hours
    age_hours="$(( ( $(date +%s) - $(stat -c %Y "$last") ) / 3600 ))"

    if [ "$age_hours" -lt 48 ]; then
      pass "Newest backup is ${age_hours}h old"
    else
      fail "Newest backup is ${age_hours}h old — the nightly job may have stopped"
    fi
  else
    skip "Backups (no files in /var/backups/kaagapay)"
  fi

  head "Disk and storage"

  local used
  used="$(df --output=pcent / | tail -1 | tr -dc '0-9')"

  if [ "$used" -lt 85 ]; then
    pass "Disk ${used}% used"
  else
    fail "Disk ${used}% used — clean old builds and backups"
  fi

  if [ -d "$APP_DIR/storage/app/public/ocr" ] || [ -d "$APP_DIR/storage/app/public/prescriptions" ]; then
    fail "ID photos or prescriptions are in public storage — run storage:privatize-sensitive"
  else
    pass "No sensitive files in public storage"
  fi
}

# ─────────────────────────────────────────────────────────────────────────────

printf '\033[1mKa-Agapay smoke test\033[0m  %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')"
printf 'Target: %s\n' "$BASE_URL"

if [ "${1:-}" = "--server" ]; then
  server_checks
else
  remote_checks
fi

head "Result"
printf '  %d passed, %d failed, %d skipped\n\n' "$passed" "$failed" "$skipped"

[ "$failed" -eq 0 ] || exit 1
