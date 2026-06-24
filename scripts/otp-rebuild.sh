#!/usr/bin/env bash
# Runs inside hopln_queue via OtpDeliveryService::rebuild() (OTP_BUILD_CMD).
# Also safe to run manually: bash /var/www/scripts/otp-rebuild.sh
#
# The compose file is referenced by its container-side path (/var/www/...).
# --project-name must match the host deployment (basename of /opt/hopln/api → "api")
# so that docker compose reuses the existing api_otp_data volume and api_hopln_net
# network instead of creating new www_* ones.
set -euo pipefail

COMPOSE="docker compose -f /var/www/docker-compose.prod.yml --project-name api"

OTP_DATA="/var/opentripplanner"
GRAPH="${OTP_DATA}/graph.obj"
GRAPH_BAK="${OTP_DATA}/graph.obj.bak"

echo "[otp-rebuild] ── Starting graph rebuild ──────────────────"
echo "[otp-rebuild] Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"

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
echo "[otp-rebuild] Build completed in $(( BUILD_END - BUILD_START ))s"

# ── Start serve container ─────────────────────────────────────────────────────
echo "[otp-rebuild] Starting OTP serve container"
${COMPOSE} up -d otp

echo "[otp-rebuild] ── Rebuild complete — OTP starting up ──────"
