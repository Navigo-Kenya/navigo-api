#!/usr/bin/env bash
# Navigo — OTP graph rebuild script.
# Called by the Laravel queue job (OTP_BUILD_CMD in .env) when Export & Sync runs.
# Also safe to run manually: bash /var/www/scripts/otp-rebuild.sh
#
# Sequence:
#   1. Stop the serving OTP container
#   2. Run the builder container (--build --save, up to 10 min)
#   3. Start the serving OTP container (--load, ~90s startup)
#
# Health-check is intentionally omitted: OtpDeliveryService::waitUntilHealthy()
# polls OTP_HEALTH_CHECK_URL after this script exits.

set -euo pipefail

APP_DIR="/opt/hopln/api"
COMPOSE="docker compose -f ${APP_DIR}/docker-compose.prod.yml"

echo "[otp-rebuild] ── Starting graph rebuild ──────────────────"
echo "[otp-rebuild] Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"

OTP_DATA="/var/opentripplanner"
GRAPH="${OTP_DATA}/graph.obj"
GRAPH_BAK="${OTP_DATA}/graph.obj.bak"

# ── Back up current graph ─────────────────────────────────────────────────────
if [ -f "${GRAPH}" ]; then
  echo "[otp-rebuild] Backing up graph.obj → graph.obj.bak"
  cp "${GRAPH}" "${GRAPH_BAK}"
fi

# ── Stop serve container ──────────────────────────────────────────────────────
echo "[otp-rebuild] Stopping OTP serve container"
${COMPOSE} stop otp

# ── Build graph ───────────────────────────────────────────────────────────────
echo "[otp-rebuild] Building graph (this may take 5–10 minutes)"
BUILD_START=$(date +%s)

if ! ${COMPOSE} --profile build run --rm otp-builder; then
  echo "[otp-rebuild] ERROR: Builder failed — restoring backup graph"
  if [ -f "${GRAPH_BAK}" ]; then
    cp "${GRAPH_BAK}" "${GRAPH}"
    ${COMPOSE} up -d otp
    echo "[otp-rebuild] Restored graph.obj.bak and restarted OTP"
  else
    echo "[otp-rebuild] No backup available — OTP will remain stopped"
  fi
  exit 1
fi

BUILD_END=$(date +%s)
BUILD_DURATION=$(( BUILD_END - BUILD_START ))
echo "[otp-rebuild] Build completed in ${BUILD_DURATION}s"

# ── Start serve container ─────────────────────────────────────────────────────
echo "[otp-rebuild] Starting OTP serve container"
${COMPOSE} up -d otp

echo "[otp-rebuild] ── Rebuild complete — OTP starting up ──────"
