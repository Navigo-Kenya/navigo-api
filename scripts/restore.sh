#!/usr/bin/env bash
# Hopln — restore from Cloudflare R2
#
# Usage:
#   ./scripts/restore.sh db                  # list DB dumps, prompt to restore one
#   ./scripts/restore.sh db 20240615_020000  # restore a specific timestamp
#   ./scripts/restore.sh storage             # restore latest storage archive
#   ./scripts/restore.sh env                 # restore latest .env backup
#
# WARNING: restoring the database drops and recreates all tables.
#          Run on a maintenance window.

set -euo pipefail

APP_DIR="/opt/hopln/api"
RCLONE_CONF="${APP_DIR}/.rclone.conf"
COMPOSE="docker compose -f ${APP_DIR}/docker-compose.prod.yml"
BACKUP_BUCKET="r2:hopln-backups"

log()  { echo "[restore] $*"; }
die()  { echo "[restore] ERROR: $*"; exit 1; }
warn() { echo "[restore] WARNING: $*"; }

# Load .env
set -a
source "${APP_DIR}/.env"
set +a

command -v rclone &>/dev/null || die "rclone not found — run scripts/backup-setup.sh first"
[ -f "${RCLONE_CONF}" ]       || die ".rclone.conf not found — run scripts/backup-setup.sh first"

RCLONE="rclone --config ${RCLONE_CONF} --stats 0 --log-level INFO"
TARGET="${1:-}"

# ── Helpers ───────────────────────────────────────────────────────────────────

list_remote() {
  # List files in a remote folder, sorted newest-first
  ${RCLONE} ls "${BACKUP_BUCKET}/${1}/" 2>/dev/null | sort -k2 -r | head -20
}

pick_file() {
  # Ask user to pick a file from a remote folder
  local folder="$1"
  echo ""
  echo "Available backups in ${BACKUP_BUCKET}/${folder}/:"
  echo "──────────────────────────────────────────────────"
  list_remote "${folder}"
  echo ""
  read -rp "Enter exact filename to restore: " CHOSEN
  echo "${CHOSEN}"
}

# ── DB restore ────────────────────────────────────────────────────────────────
restore_db() {
  local filename="${1:-}"

  if [ -z "${filename}" ]; then
    filename=$(pick_file "postgres")
  fi

  [[ "${filename}" == *.sql.gz ]] || filename="${filename}.sql.gz"
  [[ "${filename}" == hopln_pg_* ]] || filename="hopln_pg_${filename}.sql.gz"

  local tmp="/tmp/${filename}"

  echo ""
  warn "This will restore ${filename} into ${DB_DATABASE}."
  warn "All current data will be replaced."
  read -rp "Type 'yes' to continue: " CONFIRM
  [ "${CONFIRM}" = "yes" ] || { log "Aborted."; exit 0; }

  log "Downloading ${filename}..."
  ${RCLONE} copy "${BACKUP_BUCKET}/postgres/${filename}" /tmp/
  [ -s "${tmp}" ] || die "Download failed or file is empty"

  log "Restoring to PostgreSQL (${DB_DATABASE})..."
  gunzip -c "${tmp}" | \
    ${COMPOSE} exec -T -e PGPASSWORD="${DB_PASSWORD}" postgres \
      psql -U "${DB_USERNAME}" "${DB_DATABASE}"

  rm -f "${tmp}"
  log "Database restored successfully from ${filename}"

  log "Flushing Redis journey cache..."
  ${COMPOSE} exec -T redis redis-cli --scan --pattern "*otp:journey:v2:*" \
    | xargs -r ${COMPOSE} exec -T redis redis-cli DEL || true
  log "Redis cache flushed"
}

# ── Storage restore ────────────────────────────────────────────────────────────
restore_storage() {
  local filename="${1:-}"

  if [ -z "${filename}" ]; then
    # Default to the newest archive
    filename=$(${RCLONE} ls "${BACKUP_BUCKET}/storage/" | sort -k2 -r | head -1 | awk '{print $2}')
    [ -n "${filename}" ] || die "No storage archives found in ${BACKUP_BUCKET}/storage/"
    log "Using latest archive: ${filename}"
  fi

  local tmp="/tmp/${filename}"

  log "Downloading ${filename}..."
  ${RCLONE} copy "${BACKUP_BUCKET}/storage/${filename}" /tmp/
  [ -s "${tmp}" ] || die "Download failed or file is empty"

  log "Extracting to ${APP_DIR}..."
  tar -xzf "${tmp}" -C "${APP_DIR}"
  rm -f "${tmp}"
  log "Storage restored from ${filename}"
}

# ── .env restore ──────────────────────────────────────────────────────────────
restore_env() {
  local filename="${1:-}"

  if [ -z "${filename}" ]; then
    filename=$(${RCLONE} ls "${BACKUP_BUCKET}/env/" | sort -k2 -r | head -1 | awk '{print $2}')
    [ -n "${filename}" ] || die "No .env backups found in ${BACKUP_BUCKET}/env/"
    log "Using latest .env backup: ${filename}"
  fi

  log "Downloading ${filename}..."
  ${RCLONE} copyto "${BACKUP_BUCKET}/env/${filename}" "${APP_DIR}/.env.restored"
  log ".env restored to ${APP_DIR}/.env.restored"
  log "Review it and rename to .env when ready:"
  log "  mv ${APP_DIR}/.env.restored ${APP_DIR}/.env"
}

# ── Dispatch ──────────────────────────────────────────────────────────────────
case "${TARGET}" in
  db|database)
    restore_db "${2:-}"
    ;;
  storage)
    restore_storage "${2:-}"
    ;;
  env)
    restore_env "${2:-}"
    ;;
  list)
    echo "DB dumps:"; list_remote "postgres"; echo ""
    echo "OTP graphs:"; list_remote "otp"; echo ""
    echo "Storage archives:"; list_remote "storage"; echo ""
    echo ".env backups:"; list_remote "env"
    ;;
  *)
    echo "Usage:"
    echo "  $0 list                          # list all available backups"
    echo "  $0 db                            # interactive DB restore (prompts for file)"
    echo "  $0 db 20240615_020000            # restore specific DB snapshot"
    echo "  $0 storage                       # restore latest storage archive"
    echo "  $0 storage hopln_storage_X.tar.gz"
    echo "  $0 env                           # restore latest .env to .env.restored"
    exit 1
    ;;
esac
