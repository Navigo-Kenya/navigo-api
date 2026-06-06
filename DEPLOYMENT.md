# Hopln Deployment Guide

This document is the single source of truth for deploying and operating the Hopln platform.

---

## Table of Contents

1. [Architecture overview](#1-architecture-overview)
2. [Infrastructure](#2-infrastructure)
3. [Cloudflare setup](#3-cloudflare-setup)
4. [DNS records](#4-dns-records)
5. [First-time server setup](#5-first-time-server-setup)
6. [OTP graph management](#6-otp-graph-management)
7. [CI/CD](#7-cicd)
8. [Backup & recovery](#8-backup--recovery)
9. [Runbook](#9-runbook)

---

## 1. Architecture overview

```
                        ┌─────────────────────────────────┐
                        │         Cloudflare CDN          │
                        │                                 │
                        │  navigo.co.ke   → website/ (SSG)  │
                        │  console.navigo.co.ke → console/  │
                        │  (Cloudflare Pages)             │
                        └──────────────┬──────────────────┘
                                       │ HTTPS
                                       ▼
                        ┌─────────────────────────────────┐
                        │      Hetzner CPX32              │
                        │      api.navigo.co.ke              │
                        │                                 │
                        │  ┌─────────────────────────┐   │
                        │  │ caddy  :80 :443          │   │
                        │  └────────────┬────────────┘   │
                        │               │ php_fastcgi     │
                        │  ┌────────────▼────────────┐   │
                        │  │ app   PHP 8.3-FPM       │   │
                        │  │ queue Laravel workers   │   │
                        │  └────────────┬────────────┘   │
                        │               │                 │
                        │  ┌────────────▼────────────┐   │
                        │  │ postgres  PostGIS 16    │   │
                        │  │ redis     7-alpine      │   │
                        │  │ otp       --load  3G    │   │
                        │  └─────────────────────────┘   │
                        └─────────────────────────────────┘
                                       │
                        ┌──────────────▼──────────────────┐
                        │      Cloudflare R2              │
                        │      hopln-backups bucket       │
                        │  postgres/  · otp/              │
                        └─────────────────────────────────┘
```

### Component summary

| Component | Technology | Host | Repo |
|---|---|---|---|
| Passenger & Console API | Laravel 13 / PHP 8.3-FPM | Hetzner CPX32 | `hopln-api/` |
| Reverse proxy + TLS | Caddy 2 | Same box | `hopln-api/Caddyfile` |
| Database | PostgreSQL 16 + PostGIS 3.4 | Same box | — |
| Cache / Queue / Session | Redis 7 | Same box | — |
| Transit routing engine | OpenTripPlanner 2.4 | Same box | `nairobi-otp/` |
| Marketing website | Next.js 15 SSG | Cloudflare Pages | `website/` |
| Operator console | Vite React SPA | Cloudflare Pages | `console/` |
| Backups | Cloudflare R2 | — | — |

---

## 2. Infrastructure

### Hetzner CPX32 (production server)

| Spec | Value |
|---|---|
| vCPU | 4 (AMD EPYC) |
| RAM | 8 GB |
| Disk | 160 GB NVMe SSD |
| Traffic | 20 TB / month |
| OS | Ubuntu 24.04 LTS |
| Swap | 8 GB (on NVMe) |

### Memory budget

| Service | RAM at rest |
|---|---|
| OS + systemd | ~400 MB |
| Caddy | ~30 MB |
| PHP-FPM (app) | ~150 MB |
| Queue worker | ~100 MB |
| PostgreSQL + PostGIS | ~250 MB |
| Redis | ~60 MB |
| **OTP (--load, Nairobi)** | **~2.5 GB** |
| **Total** | **~3.5 GB** |

The remaining 4.5 GB + 8 GB swap absorbs the OTP build spike (4 GB heap, ~10–15 min) triggered when operators run Export & Sync.

### Port exposure

| Port | Service | Public |
|---|---|---|
| 22 | SSH | Yes (restricted by key) |
| 80 | Caddy HTTP → HTTPS redirect | Yes |
| 443 | Caddy HTTPS | Yes |
| 5432 | PostgreSQL | **No** — Docker internal |
| 6379 | Redis | **No** — Docker internal |
| 8080 | OTP | **No** — Docker internal |

---

## 3. Cloudflare setup

### R2 bucket (backups)

1. Cloudflare Dashboard → R2 → **Create bucket** → Name: `hopln-backups`
2. **Manage R2 API tokens** → Create token → Permissions: Object Read & Write on `hopln-backups`
3. Note your: **Account ID**, **Access Key ID**, **Secret Access Key**
4. These go into `.env` on the server as `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_ENDPOINT`

### Pages — `navigo.co.ke` (website)

1. Cloudflare Dashboard → Pages → **Create a project** → Connect Git
2. Select the `website/` repository
3. Build command: `npm run build`
4. Output directory: `out`
5. Environment variables:
   - `NEXT_PUBLIC_API_URL` = `https://api.navigo.co.ke`
   - `NEXT_PUBLIC_MAPBOX_TOKEN` = your Mapbox public token

### Pages — `console.navigo.co.ke` (operator console)

1. Pages → **Create a project** → Connect Git
2. Select the `console/` repository
3. Build command: `npm run build`
4. Output directory: `dist`
5. Environment variables:
   - `VITE_API_URL` = `https://api.navigo.co.ke/api`
   - `VITE_MAPBOX_TOKEN` = your Mapbox public token

**Restrict access with Cloudflare Access (recommended):**
1. Zero Trust → Access → **Applications** → Add an application → Self-hosted
2. Application domain: `console.navigo.co.ke`
3. Policy: Allow emails in `@navigo.co.ke` domain, or add individual email addresses
4. This adds a login gate before the console SPA even loads — free for up to 50 users

---

## 4. DNS records

Add these in Cloudflare DNS for `navigo.co.ke`:

| Name | Type | Value | Proxy |
|---|---|---|---|
| `api` | A | `<Hetzner server IP>` | Yes (orange cloud) |
| `@` | CNAME | `<Pages domain for website>` | Yes |
| `console` | CNAME | `<Pages domain for console>` | Yes |

> **Note**: With Cloudflare proxy enabled on `api.navigo.co.ke`, the server's real IP is hidden. Caddy still handles TLS termination (end-to-end encryption mode in Cloudflare: Full (strict)).

---

## 5. First-time server setup

### Step 1 — Provision

On Hetzner Cloud Console:
- Create server → CPX32 → Ubuntu 24.04 LTS
- Add your local SSH public key during creation
- Note the server's public IP

### Step 2 — Initialize

```bash
ssh root@<server-ip>
curl -fsSL https://raw.githubusercontent.com/<org>/hopln-api/main/scripts/setup-server.sh | bash
```

Or copy and run `hopln-api/scripts/setup-server.sh` manually. This script:
- Updates packages, installs Docker + rclone
- Configures UFW (22, 80, 443, 443/udp only)
- Creates 8 GB swap at `/swapfile`
- Creates `deploy` user (Docker group)
- Creates `/opt/hopln/otp-data/` directory

### Step 3 — Add GitHub Actions SSH key

On your local machine, generate a dedicated deploy key:

```bash
ssh-keygen -t ed25519 -C "github-actions@hopln" -f hopln_deploy_key -N ""
```

On the server:
```bash
echo "$(cat hopln_deploy_key.pub)" >> /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
```

Add `hopln_deploy_key` (private key contents) as `HETZNER_SSH_KEY` secret in GitHub (see §7).

### Step 4 — Clone the API repo

```bash
su - deploy
git clone https://github.com/<org>/hopln-api.git /opt/hopln/api
chmod +x /opt/hopln/api/scripts/*.sh
```

### Step 5 — Seed OTP data

The OTP data directory must contain three files before the first start.

**If `graph.obj` already exists locally:**
```bash
# From your local machine:
scp nairobi-otp/data/graph.obj \
    nairobi-otp/data/gtfs.zip \
    nairobi-otp/data/kenya-latest.osm.pbf \
    deploy@<server-ip>:/opt/hopln/otp-data/
```

**If building graph.obj for the first time:**
```bash
# On your local machine (needs 4–6 GB RAM and ~10 min):
cd nairobi-otp
docker compose --profile build run --rm otp-build
# Then SCP the resulting data/graph.obj to the server as above
```

### Step 6 — Configure environment

```bash
cp /opt/hopln/api/.env.production /opt/hopln/api/.env
chmod 640 /opt/hopln/api/.env
nano /opt/hopln/api/.env
```

Required values to fill in:

| Variable | How to get it |
|---|---|
| `APP_KEY` | Run `php artisan key:generate --show` locally in `hopln-api/` |
| `DB_PASSWORD` | Choose a strong random password |
| `REDIS_PASSWORD` | Optional; set if you add Redis `requirepass` |
| `GEMINI_API_KEY` | Google AI Studio |
| `MAPBOX_API_KEY` | Mapbox dashboard (server-side token) |
| `AWS_ACCESS_KEY_ID` | R2 API token (§3) |
| `AWS_SECRET_ACCESS_KEY` | R2 API token (§3) |
| `AWS_ENDPOINT` | `https://<account-id>.r2.cloudflarestorage.com` |

### Step 7 — Configure rclone for R2

```bash
rclone config
```

Interactive prompts:
```
Name: r2
Type: s3
Provider: Cloudflare
Access key ID: <R2 Access Key ID>
Secret access key: <R2 Secret>
Endpoint: https://<account-id>.r2.cloudflarestorage.com
Location constraint: (leave blank)
ACL: (leave blank)
```

Verify: `rclone ls r2:hopln-backups` (bucket must exist first, create it in §3).

### Step 8 — Start all services

```bash
cd /opt/hopln/api
docker compose -f docker-compose.prod.yml up -d
```

OTP takes 60–120 seconds to load `graph.obj`. Monitor progress:
```bash
docker compose -f docker-compose.prod.yml logs -f otp
# Wait for: "Listening for connections on port 8080"
```

### Step 9 — Database setup

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
```

### Step 10 — Add backup cron

```bash
crontab -e
# Add this line:
0 2 * * * /opt/hopln/api/scripts/backup.sh >> /var/log/hopln-backup.log 2>&1
```

### Step 11 — Verify

```bash
# All containers running
docker compose -f /opt/hopln/api/docker-compose.prod.yml ps

# API responding
curl -s https://api.navigo.co.ke/api/v1/stops/nearby?lat=-1.2921&lng=36.8219 | head -c 200

# OTP health
docker compose -f /opt/hopln/api/docker-compose.prod.yml exec otp \
  curl -s http://localhost:8080/otp | grep version
```

---

## 6. OTP graph management

### Build vs Serve

OpenTripPlanner operates in two distinct modes:

| Mode | Container | Heap | Duration | Purpose |
|---|---|---|---|---|
| `--build --save` | `otp-builder` | `-Xmx4G` | 5–10 min | Compile OSM + GTFS → `graph.obj` |
| `--load` | `otp` | `-Xmx3G` | 60–120 s startup | Serve route queries from `graph.obj` |

Building is CPU and memory intensive. Serving is lightweight — itinerary queries are CPU-bound graph traversals taking milliseconds on an already-loaded graph.

### When to rebuild

| Trigger | Who handles it |
|---|---|
| Route/stop/schedule change via console | Automatic — "Export & Sync" in GtfsPage calls `otp-rebuild.sh` |
| New OSM extract (`kenya-latest.osm.pbf`) | Manual rebuild — needed only when major road infrastructure changes |
| Fresh server deployment | Manual rebuild (or restore `graph.obj` from R2) |

### Automatic rebuild pipeline (console → OTP)

```
Operator clicks "Export & Sync" in GtfsPage
  → Laravel queue job
  → Step 1: Validate GTFS integrity
  → Step 2: Export GTFS zip from PostgreSQL
  → Step 3: Copy gtfs.zip to /opt/hopln/otp-data/
  → Step 4: Execute OTP_BUILD_CMD (scripts/otp-rebuild.sh)
             ↳ Stop otp container
             ↳ Run otp-builder (--build --save, ~10 min)
             ↳ Start otp container (--load, ~90s)
  → Step 5: Poll OTP health until ready (up to 10 min)
  → Step 6: Log result to otp_logs table
```

### Manual rebuild

```bash
bash /opt/hopln/api/scripts/otp-rebuild.sh
```

### Replacing the OSM extract

```bash
# Download new extract (Nairobi bbox)
docker run --rm -v /opt/hopln/otp-data:/data ubuntu bash -c \
  "apt-get install -y osmium-tool && \
   curl -L https://download.geofabrik.de/africa/kenya-latest.osm.pbf -o /data/kenya-latest.osm.pbf"

# Rebuild graph
bash /opt/hopln/api/scripts/otp-rebuild.sh
```

### OTP memory reference

| Region scope | Serve (`--load`) | Build (`--build --save`) |
|---|---|---|
| Nairobi city | `-Xmx3G` | `-Xmx4G` |
| Kenya national | `-Xmx5G` | `-Xmx6G` |
| East Africa | `-Xmx8G` | `-Xmx10G` |

---

## 7. CI/CD

### GitHub Secrets (hopln-api repo)

Settings → Secrets and variables → Actions → New repository secret:

| Secret name | Value |
|---|---|
| `HETZNER_HOST` | Server IP address |
| `HETZNER_SSH_KEY` | Contents of `hopln_deploy_key` (private key from §5) |

### Trigger a deploy

GitHub → Actions → **Deploy API** → **Run workflow** → Branch: `main` → **Run workflow**.

### What the deploy does

1. SSH into server as `deploy` user
2. `git pull origin main` — pull latest code
3. `docker compose build app queue` — rebuild PHP image (bakes in new code + vendor)
4. `docker compose up -d --no-deps app queue` — replace app containers only (postgres/redis/otp/caddy untouched — zero infrastructure downtime)
5. `artisan migrate --force` — run any new migrations
6. Warm config / route / view caches

**Deploy time**: ~2–4 minutes (dominated by Docker build).

### Cloudflare Pages (frontends)

Both `website/` and `console/` deploy automatically via Cloudflare Pages' native GitHub integration — no Actions required. Every push to `main` triggers a production deploy. Every pull request gets a preview URL.

---

## 8. Backup & recovery

### What is backed up

| Artifact | Destination | Frequency | Retention |
|---|---|---|---|
| PostgreSQL dump | `r2:hopln-backups/postgres/` | Daily 02:00 UTC | 30 days |
| OTP `graph.obj` | `r2:hopln-backups/otp/` | After every successful rebuild | 5 copies |

### Restore PostgreSQL

```bash
# Download latest backup
rclone copy r2:hopln-backups/postgres/ /tmp/restore/ \
  --include "hopln_pg_*.sql.gz" --transfers 1
LATEST=$(ls -t /tmp/restore/hopln_pg_*.sql.gz | head -1)

# Stop app to prevent writes during restore
cd /opt/hopln/api
docker compose -f docker-compose.prod.yml stop app queue

# Restore
gunzip -c "${LATEST}" | \
  docker compose -f docker-compose.prod.yml exec -T postgres \
    psql -U "${DB_USERNAME}" "${DB_DATABASE}"

# Restart
docker compose -f docker-compose.prod.yml start app queue
```

### Restore OTP graph

```bash
# Download latest graph
rclone copy r2:hopln-backups/otp/ /tmp/ --include "graph_*.obj"
LATEST=$(ls -t /tmp/graph_*.obj | head -1)
cp "${LATEST}" /opt/hopln/otp-data/graph.obj

# Reload OTP
docker compose -f /opt/hopln/api/docker-compose.prod.yml restart otp
```

### Test backup integrity

```bash
# Verify the latest dump is restorable (read-only check)
rclone copy r2:hopln-backups/postgres/ /tmp/verify/ \
  --include "hopln_pg_*.sql.gz" --transfers 1
LATEST=$(ls -t /tmp/verify/hopln_pg_*.sql.gz | head -1)
gunzip -c "${LATEST}" | head -20   # Should print SQL header lines
```

---

## 9. Runbook

### Deploy a new API version

```bash
# Preferred (auditable, triggers via UI):
# GitHub Actions → Deploy API → Run workflow

# Manual fallback:
ssh deploy@<server-ip> "bash /opt/hopln/api/scripts/deploy.sh"
```

### Rebuild OTP after GTFS changes

Normally triggered automatically by console Export & Sync.
Manual override:
```bash
ssh deploy@<server-ip> "bash /opt/hopln/api/scripts/otp-rebuild.sh"
```

### View logs

```bash
ssh deploy@<server-ip>
cd /opt/hopln/api

docker compose -f docker-compose.prod.yml logs -f app        # Laravel errors
docker compose -f docker-compose.prod.yml logs -f queue      # Queue job output
docker compose -f docker-compose.prod.yml logs -f otp        # OTP routing
docker compose -f docker-compose.prod.yml logs --tail=100 caddy  # HTTP access
```

### Restart a specific service

```bash
docker compose -f /opt/hopln/api/docker-compose.prod.yml restart app
docker compose -f /opt/hopln/api/docker-compose.prod.yml restart queue
docker compose -f /opt/hopln/api/docker-compose.prod.yml restart otp
```

### Rollback a deploy

```bash
ssh deploy@<server-ip>
cd /opt/hopln/api
git log --oneline -10                              # Find the previous commit hash
git checkout <previous-commit-hash>
docker compose -f docker-compose.prod.yml build app queue
docker compose -f docker-compose.prod.yml up -d --no-deps app queue
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
```

### Run a one-off Artisan command

```bash
docker compose -f /opt/hopln/api/docker-compose.prod.yml \
  exec app php artisan <command>
```

### Connect to PostgreSQL

```bash
docker compose -f /opt/hopln/api/docker-compose.prod.yml \
  exec postgres psql -U hopln -d hopln
```

### Check disk usage

```bash
df -h /                                              # NVMe usage
docker system df                                     # Docker volumes + images
du -sh /opt/hopln/otp-data/                         # OTP data files
```

### Scale up checklist

When the CPX32 becomes insufficient:

- **API/PHP slow under load?** → Upgrade to CPX41 (8 vCPU, 16 GB). Same compose, zero config changes.
- **OTP needs more memory** (expanding to Kenya-wide routing)? → Move OTP to a dedicated CX32. Update `OTP_BASE_URL` in `.env`. Update `OTP_BUILD_CMD` to SSH to the OTP box.
- **Database growing large?** → Attach a Hetzner Volume. Stop postgres, move `postgres_data` to the volume, update the bind mount path.
- **High API traffic?** → Enable Cloudflare proxy caching on `api.navigo.co.ke` for read-heavy endpoints.
