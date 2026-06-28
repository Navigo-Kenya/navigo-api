#!/usr/bin/env bash
# Hopln — production backup to Cloudflare R2
# Backs up: PostgreSQL database · OTP graph.obj · Laravel storage/app · .env
#
# Cron (as deploy user, daily at 02:00):
#   0 2 * * * /opt/hopln/api/scripts/backup.sh >> /var/log/hopln-backup.log 2>&1
#
# First-time setup: run scripts/backup-setup.sh

set -euo pipefail

# ── Config ────────────────────────────────────────────────────────────────────
APP_DIR="/opt/hopln/api"
OTP_GRAPH="/opt/hopln/otp-data/graph.obj"
COMPOSE="docker compose -f ${APP_DIR}/docker-compose.prod.yml"
RCLONE_CONF="${APP_DIR}/.rclone.conf"
BACKUP_BUCKET="r2:navigo-backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

KEEP_DB_DAYS=30       # rolling 30-day window of daily DB dumps
KEEP_GRAPH_DAYS=14    # rolling 14-day window of OTP graph snapshots
KEEP_STORAGE_DAYS=7   # rolling 7-day window of storage archives
KEEP_ENV_DAYS=90      # keep .env backups for 90 days

# ── Bootstrap ─────────────────────────────────────────────────────────────────
log() { echo "[backup ${TIMESTAMP}] $*"; }
die() { log "ERROR: $*"; exit 1; }

log "── Starting ──────────────────────────────────────────────────"

# Load .env so DB credentials and R2 keys are available
set -a
# shellcheck source=/opt/hopln/api/.env
source "${APP_DIR}/.env"
set +a

# Require rclone
command -v rclone &>/dev/null || die "rclone not found — run scripts/backup-setup.sh first"

# Auto-generate rclone config from .env on first run (or if it was deleted)
if [ ! -f "${RCLONE_CONF}" ]; then
  log "Generating rclone config from .env → ${RCLONE_CONF}"
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
fi

RCLONE="rclone --config ${RCLONE_CONF} --stats 0 --log-level INFO"

# Temp file registry — all cleaned up on exit regardless of success/failure
TMP_FILES=()
cleanup() { for f in "${TMP_FILES[@]:-}"; do [ -f "$f" ] && rm -f "$f"; done; }
trap cleanup EXIT

# ── 1. PostgreSQL ─────────────────────────────────────────────────────────────
log "Dumping PostgreSQL (${DB_DATABASE})..."

DUMP_FILE="/tmp/hopln_pg_${TIMESTAMP}.sql.gz"
TMP_FILES+=("${DUMP_FILE}")

${COMPOSE} exec -T -e PGPASSWORD="${DB_PASSWORD}" postgres \
  pg_dump -U "${DB_USERNAME}" --clean --if-exists "${DB_DATABASE}" \
  | gzip > "${DUMP_FILE}"

# Abort if the dump is empty (pg_dump silent failure)
[ -s "${DUMP_FILE}" ] || die "pg_dump produced an empty file — check postgres container logs"

DUMP_SIZE=$(du -sh "${DUMP_FILE}" | cut -f1)
log "Dump complete: ${DUMP_SIZE}"

${RCLONE} copy "${DUMP_FILE}" "${BACKUP_BUCKET}/postgres/"
log "Uploaded → ${BACKUP_BUCKET}/postgres/hopln_pg_${TIMESTAMP}.sql.gz"

${RCLONE} delete "${BACKUP_BUCKET}/postgres/" --min-age ${KEEP_DB_DAYS}d
log "Rotated DB dumps older than ${KEEP_DB_DAYS} days"

# ── 2. OTP graph ──────────────────────────────────────────────────────────────
if [ -f "${OTP_GRAPH}" ]; then
  GRAPH_SIZE=$(du -sh "${OTP_GRAPH}" | cut -f1)
  GRAPH_DEST="graph_${TIMESTAMP}.obj"
  log "Uploading OTP graph (${GRAPH_SIZE}) → ${BACKUP_BUCKET}/otp/${GRAPH_DEST}"

  # copyto (not copy) so the destination filename includes the timestamp
  ${RCLONE} copyto "${OTP_GRAPH}" "${BACKUP_BUCKET}/otp/${GRAPH_DEST}"
  ${RCLONE} delete "${BACKUP_BUCKET}/otp/" --min-age ${KEEP_GRAPH_DAYS}d
  log "Rotated OTP graph backups older than ${KEEP_GRAPH_DAYS} days"
else
  log "WARNING: graph.obj not found at ${OTP_GRAPH} — skipping (OTP may not have built yet)"
fi

# ── 3. Laravel storage/app ────────────────────────────────────────────────────
STORAGE_DIR="${APP_DIR}/storage/app"
if [ -d "${STORAGE_DIR}" ]; then
  STORAGE_FILE="/tmp/hopln_storage_${TIMESTAMP}.tar.gz"
  TMP_FILES+=("${STORAGE_FILE}")

  log "Archiving storage/app..."
  tar -czf "${STORAGE_FILE}" -C "${APP_DIR}" storage/app

  STORAGE_SIZE=$(du -sh "${STORAGE_FILE}" | cut -f1)
  log "Storage archive: ${STORAGE_SIZE}"

  ${RCLONE} copy "${STORAGE_FILE}" "${BACKUP_BUCKET}/storage/"
  log "Uploaded → ${BACKUP_BUCKET}/storage/hopln_storage_${TIMESTAMP}.tar.gz"

  ${RCLONE} delete "${BACKUP_BUCKET}/storage/" --min-age ${KEEP_STORAGE_DAYS}d
  log "Rotated storage archives older than ${KEEP_STORAGE_DAYS} days"
else
  log "WARNING: ${STORAGE_DIR} not found — skipping"
fi

# ── 4. .env ───────────────────────────────────────────────────────────────────
ENV_FILE="${APP_DIR}/.env"
if [ -f "${ENV_FILE}" ]; then
  log "Backing up .env → ${BACKUP_BUCKET}/env/env_${TIMESTAMP}.env"
  ${RCLONE} copyto "${ENV_FILE}" "${BACKUP_BUCKET}/env/env_${TIMESTAMP}.env"
  ${RCLONE} delete "${BACKUP_BUCKET}/env/" --min-age ${KEEP_ENV_DAYS}d
  log "Rotated .env backups older than ${KEEP_ENV_DAYS} days"
fi

# ── Done ──────────────────────────────────────────────────────────────────────
log "── Complete ──────────────────────────────────────────────────"
