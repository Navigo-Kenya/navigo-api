#!/bin/bash

# Exit immediately if any command fails
set -e

echo "Starting near-zero downtime refresh of the Navigo API container..."

# Note: The 'git pull' command is now executed by GitHub Actions BEFORE this script runs!

# 1. Build the new image BEFORE stopping the old one
echo "Step 1: Rebuilding the app image (The live app is STILL ONLINE)..."
#docker compose -f docker-compose.prod.yml rm -fs app
docker compose -f docker-compose.prod.yml build app

# 2. Docker cleanly swaps the old container for the new one
echo "Step 2: Swapping containers (Expected downtime: ~2 seconds)..."
docker compose -f docker-compose.prod.yml up -d app

echo "Waiting a few seconds for the new container to fully boot..."
sleep 3

# 3. Optimize the fresh container
echo "Step 3: Rebuilding the Laravel cache..."
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize
docker compose -f docker-compose.prod.yml exec app php artisan queue:restart

echo "Refresh complete! The API is now running a pristine build."
