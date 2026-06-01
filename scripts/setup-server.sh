#!/usr/bin/env bash
# Hopln — Hetzner CPX32 initial server setup
# OS: Ubuntu 24.04 LTS
# Run once as root immediately after provisioning.
# Usage: bash setup-server.sh

set -euo pipefail

echo ""
echo "╔══════════════════════════════════════╗"
echo "║  Hopln server setup — Ubuntu 24.04  ║"
echo "╚══════════════════════════════════════╝"
echo ""

# ── 1. System update ──────────────────────────────────────────────────────────
echo "==> [1/7] Updating system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

# ── 2. Base tools ─────────────────────────────────────────────────────────────
echo "==> [2/7] Installing base tools"
apt-get install -y -qq curl git ufw jq htop

# ── 3. Docker Engine ──────────────────────────────────────────────────────────
echo "==> [3/7] Installing Docker Engine"
curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
echo "Docker $(docker --version)"

# ── 4. rclone ─────────────────────────────────────────────────────────────────
echo "==> [4/7] Installing rclone"
curl -fsSL https://rclone.org/install.sh | bash
echo "rclone $(rclone --version | head -1)"

# ── 5. UFW firewall ───────────────────────────────────────────────────────────
echo "==> [5/7] Configuring UFW firewall"
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp   comment 'SSH'
ufw allow 80/tcp   comment 'HTTP'
ufw allow 443/tcp  comment 'HTTPS'
ufw allow 443/udp  comment 'HTTP/3 (QUIC)'
ufw --force enable
echo "UFW status:"
ufw status verbose

# ── 6. Swap (8 GB on NVMe) ───────────────────────────────────────────────────
echo "==> [6/7] Creating 8 GB swap file"
if [ ! -f /swapfile ]; then
  fallocate -l 8G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo "Swap created."
else
  echo "Swap file already exists, skipping."
fi
# Reduce swap aggressiveness (RAM is preferred, swap is for burst headroom)
if ! grep -q 'vm.swappiness' /etc/sysctl.conf; then
  echo 'vm.swappiness=10' >> /etc/sysctl.conf
fi
sysctl -p /etc/sysctl.conf > /dev/null
echo "Current memory:"
free -h

# ── 7. Users and directories ──────────────────────────────────────────────────
echo "==> [7/7] Creating deploy user and directories"

if ! id deploy &>/dev/null; then
  useradd -m -s /bin/bash deploy
  echo "User 'deploy' created."
else
  echo "User 'deploy' already exists."
fi

usermod -aG docker deploy
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chown -R deploy:deploy /home/deploy/.ssh

mkdir -p /opt/hopln/otp-data
chown -R deploy:deploy /opt/hopln

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║  Setup complete. Required next steps:               ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""
echo "  1. Add GitHub Actions SSH public key to deploy user:"
echo ""
echo "       echo '<paste-public-key>' >> /home/deploy/.ssh/authorized_keys"
echo "       chmod 600 /home/deploy/.ssh/authorized_keys"
echo "       chown deploy:deploy /home/deploy/.ssh/authorized_keys"
echo ""
echo "  2. Clone the API repo (as deploy user):"
echo ""
echo "       su - deploy"
echo "       git clone https://github.com/<org>/hopln-api.git /opt/hopln/api"
echo "       chmod +x /opt/hopln/api/scripts/*.sh"
echo ""
echo "  3. Copy OTP data from your local machine:"
echo ""
echo "       scp nairobi-otp/data/{graph.obj,gtfs.zip,kenya-latest.osm.pbf} \\"
echo "           deploy@\$(hostname -I | awk '{print \$1}'):/opt/hopln/otp-data/"
echo ""
echo "  4. Configure environment:"
echo ""
echo "       cp /opt/hopln/api/.env.production /opt/hopln/api/.env"
echo "       chmod 640 /opt/hopln/api/.env"
echo "       nano /opt/hopln/api/.env   # fill in APP_KEY, DB_PASSWORD, API keys"
echo ""
echo "  5. Configure rclone for Cloudflare R2 backups:"
echo ""
echo "       rclone config   # add remote named 'r2'"
echo ""
echo "  6. Start services:"
echo ""
echo "       cd /opt/hopln/api"
echo "       docker compose -f docker-compose.prod.yml up -d"
echo ""
echo "  See DEPLOYMENT.md §5 for the full step-by-step guide."
echo ""
