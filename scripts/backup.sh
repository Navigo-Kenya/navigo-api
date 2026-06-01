#!/usr/bin/env bash
# Hopln — backup to Cloudflare R2.
# Backs up: PostgreSQL database + OTP graph.obj
# Cron (as deploy user): 0 2 * * * /opt/hopln/api/scripts/backup.sh >> /var/log/hopln-backup.log 2>&1

set -euo pipefail

APP_DIR="/opt/hopln/api"
COMPOSE="docker compose -f ${APP_DIR}/docker-compose.prod.yml"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "[backup ${TIMESTAMP}] ── Starting ─────────────────────────"

# Load .env to read DB credentials
set -a
# shellcheck source=/opt/hopln/api/.env
source "${APP_DIR}/.env"
set +a

# ── PostgreSQL ────────────────────────────────────────────────────────────────
echo "[backup] Dumping PostgreSQL"
DUMP_FILE="/tmp/hopln_pg_${TIMESTAMP}.sql.gz"

${COMPOSE} exec -T postgres \
  pg_dump -U "${DB_USERNAME}" "${DB_DATABASE}" \
  | gzip > "${DUMP_FILE}"

DUMP_SIZE=$(du -sh "${DUMP_FILE}" | cut -f1)
echo "[backup] Dump size: ${DUMP_SIZE}"

echo "[backup] Uploading to r2:hopln-backups/postgres/"
rclone copy "${DUMP_FILE}" r2:hopln-backups/postgres/ \
  --stats 0 \
  --log-level INFO

rm "${DUMP_FILE}"

echo "[backup] Rotating dumps older than 30 days"
rclone delete r2:hopln-backups/postgres/ --min-age 30d --log-level INFO

# ── OTP graph ─────────────────────────────────────────────────────────────────
GRAPH_SRC="/opt/hopln/otp-data/graph.obj"
if [ -f "${GRAPH_SRC}" ]; then
  GRAPH_SIZE=$(du -sh "${GRAPH_SRC}" | cut -f1)
  echo "[backup] Uploading graph.obj (${GRAPH_SIZE}) to r2:hopln-backups/otp/"
  rclone copy "${GRAPH_SRC}" r2:hopln-backups/otp/ \
    --transfers 1 \
    --stats 0 \
    --log-level INFO
  # Keep only the 5 most recent graph backups
  echo "[backup] Rotating graph backups (keeping 5 most recent)"
  rclone delete r2:hopln-backups/otp/ --min-age 5d --log-level INFO
else
  echo "[backup] graph.obj not found at ${GRAPH_SRC}, skipping"
fi

echo "[backup ${TIMESTAMP}] ── Complete ──────────────────────────"
