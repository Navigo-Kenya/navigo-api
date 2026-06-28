#!/usr/bin/env bash
# Hopln — one-time backup setup
# Run this once on the server to install rclone, configure R2, create the
# backup bucket, make the log file, and register the daily cron job.
#
# Usage:
#   chmod +x scripts/backup-setup.sh
#   ./scripts/backup-setup.sh

set -euo pipefail

APP_DIR="/opt/hopln/api"
RCLONE_CONF="${APP_DIR}/.rclone.conf"
LOG_FILE="/var/log/hopln-backup.log"
CRON_JOB="0 2 * * * ${APP_DIR}/scripts/backup.sh >> ${LOG_FILE} 2>&1"

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║   Navigo Backup Setup                    ║"
echo "╚══════════════════════════════════════════╝"
echo ""

# ── Step 1: Load .env ─────────────────────────────────────────────────────────
ENV_FILE="${APP_DIR}/.env"
[ -f "${ENV_FILE}" ] || { echo "ERROR: ${ENV_FILE} not found"; exit 1; }

set -a
# shellcheck source=/opt/hopln/api/.env
source "${ENV_FILE}"
set +a

# Verify required R2 variables are set
for var in CLOUDFLARE_R2_ACCESS_KEY_ID CLOUDFLARE_R2_SECRET_ACCESS_KEY CLOUDFLARE_R2_ENDPOINT DB_USERNAME DB_PASSWORD DB_DATABASE; do
  [ -n "${!var:-}" ] || { echo "ERROR: ${var} is not set in .env"; exit 1; }
done
echo "✓ .env loaded — R2 and DB credentials found"

# ── Step 2: Install rclone ────────────────────────────────────────────────────
echo ""
echo "── Installing rclone ────────────────────────────────────────"
if command -v rclone &>/dev/null; then
  echo "✓ rclone already installed: $(rclone version | head -1)"
else
  sudo apt-get update -qq
  sudo apt-get install -y rclone
  echo "✓ rclone installed: $(rclone version | head -1)"
fi

# ── Step 3: Write rclone config ───────────────────────────────────────────────
echo ""
echo "── Configuring rclone for Cloudflare R2 ─────────────────────"
cat > "${RCLONE_CONF}" <<EOF
[r2]
type = s3
provider = Cloudflare
access_key_id = ${CLOUDFLARE_R2_ACCESS_KEY_ID}
secret_access_key = ${CLOUDFLARE_R2_SECRET_ACCESS_KEY}
endpoint = ${CLOUDFLARE_R2_ENDPOINT}
acl = private
no_check_bucket = true
EOF
chmod 600 "${RCLONE_CONF}"
echo "✓ Config written to ${RCLONE_CONF} (mode 600)"

# ── Step 4: Verify bucket is reachable ───────────────────────────────────────
echo ""
echo "── Verifying bucket access ──────────────────────────────────"
if rclone --config "${RCLONE_CONF}" lsd r2:navigo-backups 2>/dev/null; then
  echo "✓ navigo-backups bucket is accessible"
else
  echo "  WARNING: Could not access r2:navigo-backups."
  echo "  Create it in the Cloudflare dashboard (private, no custom domain):"
  echo "    https://dash.cloudflare.com → R2 Object Storage → Create bucket → navigo-backups"
fi

# ── Step 5: Create log file with correct owner ────────────────────────────────
echo ""
echo "── Setting up log file ──────────────────────────────────────"
sudo touch "${LOG_FILE}"
sudo chown "$(whoami):$(whoami)" "${LOG_FILE}"
echo "✓ Log file: ${LOG_FILE}"

# ── Step 6: Make scripts executable ──────────────────────────────────────────
echo ""
echo "── Setting file permissions ─────────────────────────────────"
chmod +x "${APP_DIR}/scripts/backup.sh"
chmod +x "${APP_DIR}/scripts/restore.sh"
echo "✓ backup.sh and restore.sh are executable"

# ── Step 7: Register cron job ─────────────────────────────────────────────────
echo ""
echo "── Registering cron job ─────────────────────────────────────"
if crontab -l 2>/dev/null | grep -qF "backup.sh"; then
  echo "✓ Cron job already registered"
else
  (crontab -l 2>/dev/null; echo "${CRON_JOB}") | crontab -
  echo "✓ Cron job registered: ${CRON_JOB}"
fi

# ── Step 8: Test run ──────────────────────────────────────────────────────────
echo ""
echo "── Running test backup ──────────────────────────────────────"
echo "  (this may take a minute for pg_dump)"
echo ""
"${APP_DIR}/scripts/backup.sh"

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║   Setup complete                         ║"
echo "╚══════════════════════════════════════════╝"
echo ""
echo "  Schedule : daily at 02:00 server time"
echo "  Logs     : tail -f ${LOG_FILE}"
echo "  Browse   : rclone --config ${RCLONE_CONF} ls r2:navigo-backups/"
echo ""
