#!/bin/bash

# Exit immediately if any command fails
set -e

echo "Starting full zero-cache refresh of the app container..."

echo "Step 1: Stopping and removing the app container..."
docker compose -f docker-compose.prod.yml rm -fs app

echo "Step 2: Rebuilding the app image with zero cache (This might take a minute)...">
docker compose -f docker-compose.prod.yml build --no-cache app

echo "Step 3: Bringing the app container back online..."
docker compose -f docker-compose.prod.yml up -d app

echo "Waiting a few seconds for the container to fully boot..."
sleep 3

echo "Step 4: Nuking and rebuilding the Laravel cache..."
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize
docker compose -f docker-compose.prod.yml exec app php artisan queue:restart

echo "Refresh complete! The app is now running a pristine build."
