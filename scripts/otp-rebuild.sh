#!/usr/bin/env bash
# Runs inside hopln_queue via OtpDeliveryService::rebuild() (OTP_BUILD_CMD).
# Also safe to run manually: bash /var/www/scripts/otp-rebuild.sh
#
# Uses plain `docker` commands instead of `docker compose` because this script
# executes inside the queue container where the compose file is at /var/www,
# but the Docker daemon resolves bind-mount paths on the HOST filesystem.
# docker compose would try to mount /var/www from the host (which doesn't exist);
# using `docker run -v /opt/hopln/otp-data:...` references the correct host path directly.
set -euo pipefail

# Paths inside the queue container (via the otp_data volume).
OTP_DATA="/var/opentripplanner"
GRAPH="${OTP_DATA}/graph.obj"
GRAPH_BAK="${OTP_DATA}/graph.obj.bak"

# HOST path that otp_data volume is bind-mounted from (docker-compose.prod.yml → device).
# The builder container is launched via the HOST daemon, so it needs the host path.
OTP_HOST_DATA="/opt/hopln/otp-data"

OTP_IMAGE="opentripplanner/opentripplanner:2.4.0"
OTP_CONTAINER="hopln_otp"
BUILDER_CONTAINER="hopln_otp_builder"

echo "[otp-rebuild] ── Starting graph rebuild ──────────────────"
echo "[otp-rebuild] Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"

# ── Back up current graph ─────────────────────────────────────────────────────
if [ -f "${GRAPH}" ]; then
  echo "[otp-rebuild] Backing up graph.obj → graph.obj.bak"
  cp "${GRAPH}" "${GRAPH_BAK}"
fi

# ── Stop serve container ──────────────────────────────────────────────────────
echo "[otp-rebuild] Stopping OTP serve container"
docker stop "${OTP_CONTAINER}"

# ── Build graph ───────────────────────────────────────────────────────────────
echo "[otp-rebuild] Building graph (this may take 5–10 minutes)"
BUILD_START=$(date +%s)

# Remove any leftover builder container from a previous failed run.
docker rm -f "${BUILDER_CONTAINER}" 2>/dev/null || true

if ! docker run --rm \
     --name "${BUILDER_CONTAINER}" \
     -e "JAVA_OPTS=-Xmx4G -Xms512m -XX:+UseG1GC" \
     -v "${OTP_HOST_DATA}:/var/opentripplanner" \
     "${OTP_IMAGE}" \
     --build --save; then

  echo "[otp-rebuild] ERROR: Builder failed — restoring backup and restarting OTP"
  if [ -f "${GRAPH_BAK}" ]; then
    cp "${GRAPH_BAK}" "${GRAPH}"
    echo "[otp-rebuild] Restored graph.obj.bak"
  else
    echo "[otp-rebuild] No backup available — OTP will remain stopped"
  fi
  docker start "${OTP_CONTAINER}"
  exit 1
fi

BUILD_END=$(date +%s)
echo "[otp-rebuild] Build completed in $(( BUILD_END - BUILD_START ))s"

# ── Start serve container ─────────────────────────────────────────────────────
echo "[otp-rebuild] Starting OTP serve container"
docker start "${OTP_CONTAINER}"

echo "[otp-rebuild] ── Rebuild complete — OTP starting up ──────"
