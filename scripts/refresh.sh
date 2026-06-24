#!/bin/bash

# Exit immediately if any command fails
set -e

echo "Starting near-zero downtime refresh of the Navigo API stack..."

# Note: The 'git pull' command is now executed by GitHub Actions BEFORE this script runs!

# 1. Build the new images BEFORE stopping the old ones
echo "Step 1: Rebuilding the app and queue images (The live app is STILL ONLINE)..."
docker compose -f docker-compose.prod.yml build app queue

# 2. Docker cleanly swaps the old containers for the new ones
echo "Step 2: Swapping containers (Expected downtime: ~2 seconds)..."
docker compose -f docker-compose.prod.yml up -d app queue

echo "Waiting a few seconds for the new containers to fully boot..."
sleep 3

# 3. Optimize the fresh container and reload workers
echo "Step 3: Rebuilding the Laravel cache..."
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize

echo "Step 4: Gracefully resetting the queue worker daemon state..."
# This must target the 'queue' container where the artisan queue process actually lives
docker compose -f docker-compose.prod.yml exec queue php artisan queue:restart

echo "Refresh complete! The API and Queue Worker are now running a pristine build."
