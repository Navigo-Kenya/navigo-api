#!/usr/bin/env bash
# Hopln API — deploy script.
# Called by GitHub Actions over SSH as the 'deploy' user.
# Rebuilds app + queue images only — postgres/redis/otp/caddy are NOT touched.

set -euo pipefail

APP_DIR="/opt/hopln/api"
COMPOSE="docker compose -f ${APP_DIR}/docker-compose.prod.yml"

echo "[deploy] ── Starting deployment ─────────────────────────"
echo "[deploy] Host:   $(hostname)"
echo "[deploy] Date:   $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "[deploy] Branch: $(git -C ${APP_DIR} rev-parse --abbrev-ref HEAD)"

# ── Pull latest code ──────────────────────────────────────────────────────────
echo "[deploy] Pulling latest code"
git -C "${APP_DIR}" pull origin main
echo "[deploy] HEAD: $(git -C ${APP_DIR} log -1 --oneline)"

# ── Build images ──────────────────────────────────────────────────────────────
echo "[deploy] Building app and queue images"
${COMPOSE} build --pull app queue

# ── Restart application containers ───────────────────────────────────────────
echo "[deploy] Restarting app and queue containers"
${COMPOSE} up -d --no-deps app queue

# ── Database migrations ───────────────────────────────────────────────────────
echo "[deploy] Running migrations"
${COMPOSE} exec -T app php artisan migrate --force

# ── Warm caches ───────────────────────────────────────────────────────────────
echo "[deploy] Warming config, route and view caches"
${COMPOSE} exec -T app php artisan config:cache
${COMPOSE} exec -T app php artisan route:cache
${COMPOSE} exec -T app php artisan view:cache

# ── Verify ────────────────────────────────────────────────────────────────────
echo "[deploy] Container status:"
${COMPOSE} ps app queue

echo "[deploy] ── Deployment complete ────────────────────────"
