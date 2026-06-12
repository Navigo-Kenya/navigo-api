#!/bin/bash

# Exit immediately if any command fails
set -e

echo "Starting near-zero downtime refresh of the API container..."

# 1. We MUST pull the new code first!
echo "Step 1: Pulling the latest code from GitHub..."
git pull origin main

# 2. Build the new image BEFORE stopping the old one
echo "Step 2: Rebuilding the app image (The live app is STILL ONLINE)..."
# Pro-tip: Dropping --no-cache reduces build time from minutes to seconds
#docker compose -f docker-compose.prod.yml rm -fs app
docker compose -f docker-compose.prod.yml build app

# 3. Docker cleanly swaps the old container for the new one
echo "Step 3: Swapping containers (Expected downtime: ~2 seconds)..."
docker compose -f docker-compose.prod.yml up -d app

echo "Waiting a few seconds for the new container to fully boot..."
sleep 3

# 4. Optimize the fresh container
echo "Step 4: Rebuilding the Laravel cache..."
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize
docker compose -f docker-compose.prod.yml exec app php artisan queue:restart

echo "Refresh complete! The app is now running a pristine build."
