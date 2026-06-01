#!/usr/bin/env bash
# Hopln — OTP graph rebuild script.
# Called by the Laravel queue job (OTP_BUILD_CMD in .env) when Export & Sync runs.
# Also safe to run manually: bash /opt/hopln/api/scripts/otp-rebuild.sh
#
# Sequence:
#   1. Stop the serving OTP container
#   2. Run the builder container (--build --save, up to 10 min)
#   3. Start the serving OTP container (--load, ~90s startup)

set -euo pipefail

APP_DIR="/opt/hopln/api"
COMPOSE="docker compose -f ${APP_DIR}/docker-compose.prod.yml"

echo "[otp-rebuild] ── Starting graph rebuild ──────────────────"
echo "[otp-rebuild] Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"

# ── Stop serve container ──────────────────────────────────────────────────────
echo "[otp-rebuild] Stopping OTP serve container"
${COMPOSE} stop otp

# ── Build graph ───────────────────────────────────────────────────────────────
echo "[otp-rebuild] Building graph (this may take 5–10 minutes)"
BUILD_START=$(date +%s)

${COMPOSE} --profile build run --rm otp-builder

BUILD_END=$(date +%s)
BUILD_DURATION=$(( BUILD_END - BUILD_START ))
echo "[otp-rebuild] Build completed in ${BUILD_DURATION}s"

# ── Start serve container ─────────────────────────────────────────────────────
echo "[otp-rebuild] Starting OTP serve container"
${COMPOSE} up -d otp

# ── Health check ──────────────────────────────────────────────────────────────
echo "[otp-rebuild] Waiting for OTP to become ready (up to 10 min)"
RETRIES=40
DELAY=15

for i in $(seq 1 ${RETRIES}); do
  if ${COMPOSE} exec -T otp curl -sf http://localhost:8080/otp > /dev/null 2>&1; then
    echo "[otp-rebuild] OTP is ready (attempt ${i})"
    break
  fi
  if [ "${i}" -eq "${RETRIES}" ]; then
    echo "[otp-rebuild] ERROR: OTP did not become ready after $(( RETRIES * DELAY ))s"
    echo "[otp-rebuild] Check logs: docker compose -f ${APP_DIR}/docker-compose.prod.yml logs otp"
    exit 1
  fi
  echo "[otp-rebuild] Not ready yet (attempt ${i}/${RETRIES}), waiting ${DELAY}s..."
  sleep "${DELAY}"
done

echo "[otp-rebuild] ── Rebuild complete ────────────────────────"
