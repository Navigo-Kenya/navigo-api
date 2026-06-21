<div align="center">

# Hopln API

**The intelligent transit backend powering Nairobi's matatu navigation.**

---

[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![PostGIS](https://img.shields.io/badge/PostGIS-3.4-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgis.net)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io)
[![Gemini](https://img.shields.io/badge/Gemini-AI-4285F4?style=flat-square&logo=google&logoColor=white)](https://ai.google.dev)
[![Mapbox](https://img.shields.io/badge/Mapbox-GL-000000?style=flat-square&logo=mapbox&logoColor=white)](https://mapbox.com)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=flat-square)](LICENSE)

</div>

---

## Overview

Hopln API is the server-side backbone of the **Hopln** transit platform, a public-transport assistant for Nairobi's matatu and bus network. It exposes three surfaces:

| Surface | Path prefix | Audience |
|---------|-------------|----------|
| **Passenger API** | `/api/v1/` | Mobile app (Expo / React Native) |
| **Console API** | `/api/v1/console/` | Back-office admin SPA |
| **Admin routes** | `routes/admin.php` | Console (separate middleware group) |

---

## Table of Contents

1. [Architecture](#architecture)
2. [Passenger API Reference](#passenger-api-reference)
3. [Console API Reference](#console-api-reference)
4. [Services](#services)
5. [Artisan Commands](#artisan-commands)
6. [Data Models](#data-models)
7. [Getting Started](#getting-started)
8. [Environment Variables](#environment-variables)
9. [Running Tests](#running-tests)
10. [Transit Data Strategy](#transit-data-strategy)

---

## Architecture

```
Mobile App (Expo)          Console SPA (React)
      │                          │
      │  /api/v1/*               │  /api/v1/console/*
      ▼                          ▼
┌──────────────────────────────────────────────────────────────┐
│                          Hopln API                           │
│                    Laravel 13 · PHP 8.3                      │
│                                                              │
│  Passenger Controllers (Api/V1)                              │
│    LocationController, RouteController, AiTransitController  │
│                                                              │
│  Console Controllers (Console)                               │
│    ConsoleDashboardController  ConsoleUserController         │
│    ConsoleContributionController  ConsoleStopController      │
│    ConsoleRouteController  ConsoleTripController             │
│    ConsoleCalendarController  ConsoleGtfsController          │
│    ConsoleNetworkController  ConsoleTimetableController      │
│    ConsoleSchedulingController  ConsoleDataQualityController │
│                                                              │
│  Services Layer                                              │
│    TransitEngineService  AiAssistantService                  │
│    LocationService  GeocodingService  StorageService         │
│    GtfsExportService  GtfsValidatorService                   │
│    GtfsOfficialValidatorService                              │
│    DataQualityService  RoadSnapperService                    │
│    Export/ExporterFactory (GTFS, GTFS-Flex, Excel, NeTEx)   │
│                                                              │
│  PostgreSQL 16 + PostGIS 3.4 ◄── GTFS-aligned schema        │
│  Redis 7                     ◄── cache · queues · sessions   │
│  OpenTripPlanner (Docker)    ◄── GTFS + OSM routing          │
└──────────────────────────────────────────────────────────────┘
```

---

## Passenger API Reference

All endpoints: `GET|POST /api/v1/...`

### Stops

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/stops/all` | Full GTFS stop list |
| `GET` | `/stops/nearby?lat&lng&radius` | Stops within radius (default 800 m) via `ST_DWithin` |
| `GET` | `/stops/search?q` | Full-text search across stop names |
| `GET` | `/stops/{id}` | Single stop by ID |

### Journey

#### `POST /journey/calculate`

```jsonc
{ "from_lat": -1.2921, "from_lng": 36.8219,
  "to_lat": -1.3001,  "to_lng": 36.7800,
  "date": "2025-05-17", "time": "08:00" }
```

Returns segments with `mode`, `route_name`, `headsign`, `duration`, `polyline`.

> **Nighttime hack**: if local time is 20:00–04:00 and no explicit time was given, OTP query time is forced to 14:00.

#### `POST /journey/ai-plan`

Conversational trip planning via Kwame (Gemini AI). Supports text or base64 audio input. Returns `spoken_response`, `tts_audio` (base64 MP3), `route`.

---

## Console API Reference

All endpoints: `GET|POST|PATCH|DELETE /api/v1/console/...`

Auth: Bearer token (`Authorization: Bearer <token>`). Role middleware enforces minimum role per group.

### Dashboard

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/console/dashboard` | KPIs: DAU, MAU, journeys today, pending contributions |
| `GET` | `/console/activity` | Recent event feed |
| `GET` | `/console/system-health` | OTP + queue status |

### GTFS Data

| Method | Endpoint | Min role | Description |
|--------|----------|----------|-------------|
| `GET` | `/console/stops` | moderator | Paginated stop list |
| `POST` | `/console/stops` | admin | Create stop |
| `PATCH` | `/console/stops/:id` | admin | Update stop |
| `DELETE` | `/console/stops/:id` | admin | Delete stop |
| `POST` | `/console/stops/:id/snap` | admin | Snap stop to nearest road |
| `GET` | `/console/routes` | moderator | Paginated route list |
| `POST` | `/console/routes` | admin | Create route |
| `GET` | `/console/trips` | moderator | Paginated trip list |
| `POST` | `/console/trips` | admin | Create trip |
| `GET` | `/console/calendars` | moderator | Service calendars |
| `POST` | `/console/calendars` | admin | Create calendar |

### GTFS Export & Validation

| Method | Endpoint | Min role | Description |
|--------|----------|----------|-------------|
| `GET` | `/console/otp/status` | moderator | OTP health + last sync |
| `POST` | `/console/otp/sync` | admin | Trigger GTFS re-import into OTP |
| `POST` | `/console/gtfs/export-as` | admin | Download as `gtfs`, `gtfs-flex`, `excel`, or `netex` |
| `GET` | `/console/gtfs/validate` | moderator | Run internal GTFS validator (9 checks) |
| `GET` | `/console/gtfs/official-validate` | admin | Run Google GTFS Validator JAR (cached 30 min) |

### Data Quality

| Method | Endpoint | Min role | Description |
|--------|----------|----------|-------------|
| `GET` | `/console/quality/score` | moderator | Overall score (0–100) + 6 metrics (cached 1 h) |
| `GET` | `/console/quality/drill-down?metric=X` | moderator | Entity list for one metric |
| `GET` | `/console/quality/shape-inspector?trip_id=X` | moderator | Stop gaps, teleports, reversals for a trip |
| `GET` | `/console/quality/duplicate-stops?radius=N` | moderator | Stop pairs within N metres (default 50) |
| `POST` | `/console/quality/merge-stops` | admin | Merge duplicate into canonical, redirect all stop_times |

### Timetable & Scheduling

| Method | Endpoint | Min role | Description |
|--------|----------|----------|-------------|
| `GET` | `/console/timetable` | moderator | Timetable data by route + pattern |
| `GET` | `/console/timetable/headway-report` | moderator | Headway analysis per route |
| `POST` | `/console/timetable/optimize-headways` | admin | Generate optimised headway suggestions |
| `GET` | `/console/scheduling/blocks` | moderator | Vehicle block list |
| `POST` | `/console/scheduling/blocks` | admin | Save a block assignment |
| `DELETE` | `/console/scheduling/blocks/:id` | admin | Delete a block |
| `GET` | `/console/scheduling/layover-report` | moderator | Layover times per terminal |
| `GET` | `/console/scheduling/time-space-diagram` | moderator | Time-space diagram data |

### Network Analysis

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/console/network/graph` | Route-stop graph (nodes + edges) |
| `GET` | `/console/network/coverage` | Stop coverage GeoJSON |
| `GET` | `/console/network/desire-lines` | Origin–destination flow lines |
| `GET` | `/console/network/corridors` | High-frequency corridor detection |
| `GET` | `/console/network/transfer-graph` | Transfer connection graph |
| `GET` | `/console/network/snapshots` | Saved network snapshots |
| `POST` | `/console/network/snapshots` | Create a snapshot |
| `GET` | `/console/network/scenarios` | Planning scenarios |
| `GET` | `/console/network/variants` | Route variants |

---

## Services

### `TransitEngineService`

Central routing proxy. Calls OTP `GET /otp/routers/default/plan` with `mode=TRANSIT,WALK`, `maxWalkDistance=1500`, `walkReluctance=13.5`, `numItineraries=2`. Results cached in Redis 5 min (key: origin+destination+time hash). Includes nighttime mode and stop proximity fallback.

---

### `AiAssistantService`

Manages the Kwame conversational pipeline. Dual-tool architecture forces `calculate_route` or `chat_or_clarify` on every turn. Session history stored under `kwame:{sessionId}` (last 24 messages, 30-min TTL).

---

### `GtfsExportService`

Generates a standards-compliant GTFS zip from the PostGIS database. Called by OTP sync and by the multi-format exporter. Returns the path to the generated zip file.

---

### `GtfsValidatorService`

Runs 9 internal data-quality checks (stop orphans, missing shapes, out-of-bounds stops, duplicate stops, etc.). Called by `GET /console/gtfs/validate`.

---

### `GtfsOfficialValidatorService`

Wraps the [Google GTFS Validator](https://github.com/MobilityData/gtfs-validator) Java CLI JAR.

```
isAvailable()  → checks GTFS_VALIDATOR_JAR_PATH exists + java is in PATH
validate(zip)  → exec("java -jar {jar} --input {zip} --output_base {dir}")
                  → parse report.json → group by ERROR / WARNING / INFO
```

Returns `{ available: false }` if JAR is not configured. Results cached 30 min.

---

### `DataQualityService`

Computes 6 metrics cached 1 hour under key `quality:score`.

| Metric | Type | Description |
|--------|------|-------------|
| `routes_with_shapes` | Positive | % routes where ≥ 1 trip has a shape |
| `trips_with_full_stop_times` | Positive | % scheduled trips with ≥ 2 stop_times |
| `stops_within_bounds` | Positive | % stops inside Kenya bbox (PostGIS `ST_Within`) |
| `valid_service_refs` | Positive | % trips whose `service_id` exists in `service_calendars` |
| `orphan_shapes` | Inverse | Count of shapes with no referencing trip |
| `duplicate_stop_pairs` | Inverse | Count of stop pairs within 50 m (`ST_DWithin` on `geography`) |

**Overall score**: weighted avg of the 4 positive metrics − (orphans × 0.5) − (duplicates × 1.0), clamped to `[0, 100]`.

`drillDown(metric)` returns the actual entity list for any metric (lazy, only runs on demand).

---

### `RoadSnapperService`

Pluggable road-snapping service. Driver is selected by `ROAD_SNAPPER_DRIVER` env var.

| Driver | Implementation |
|--------|---------------|
| `mapbox` | Mapbox Map Matching API (`/matching/v5/mapbox/driving/{lng},{lat}`) |
| `google` | Google Roads `nearestRoads` API |
| `none` (default) | Returns original coords unchanged, `snapped: false` |

Returns `{ snapped, original_lat, original_lng, snapped_lat, snapped_lng, road_name, distance_m }`.

---

### Export Architecture, `Services/Export/`

```
ExporterContract (interface)
  export() → string   ← path to generated file
  getMimeType() → string
  getFilename() → string

GtfsExporter        ← wraps GtfsExportService
GtfsFlexExporter    ← GTFS + locations.geojson for demand-responsive extensions
ExcelExporter       ← openspout XLSX; sheets: agencies, routes, trips, stops, stop_times
NeTExExporter       ← SimpleXMLElement; ResourceFrame + SiteFrame + ServiceFrame stub
ExporterFactory     ← make(string $format): ExporterContract
```

---

### `LocationService` / `GeocodingService`

Tiered stop lookup: exact → starts-with → contains (all ILIKE on local GTFS DB), then Google Maps Geocoding API (region `ke`, cached 24 h). `pg_trgm` `similarity()` used for fuzzy name matching in duplicate detection.

---

### `StorageService`

Single entry point for all file I/O in the application. Backed by **Cloudflare R2** as the primary disk. Every controller that handles file uploads or deletions injects this service via constructor — no raw `Storage` facade calls exist outside it.

#### Cloudflare R2 setup

R2 is an S3-compatible object store with zero egress fees. The Laravel filesystem driver is `s3` pointed at the R2 endpoint:

```php
// config/filesystems.php  (disk: 'r2')
's3' driver with:
  'key'      => env('R2_ACCESS_KEY_ID')
  'secret'   => env('R2_SECRET_ACCESS_KEY')
  'region'   => 'auto'
  'bucket'   => env('R2_BUCKET')
  'endpoint' => env('R2_ENDPOINT')          // https://<account>.r2.cloudflarestorage.com
  'url'      => env('R2_PUBLIC_URL')        // https://files.navigo.co.ke  (custom domain)
  'visibility' => 'public'
```

All uploaded files are served through the `files.navigo.co.ke` custom domain (a Cloudflare-managed domain pointing at the R2 public bucket). No file bytes ever transit through the Laravel process after the initial upload — clients fetch directly from R2/CDN.

#### Public API

| Method | Signature | Description |
|--------|-----------|-------------|
| `upload` | `upload(UploadedFile $file, string $folder): string` | Store on R2, return public HTTPS URL. Throws `RuntimeException` on write failure. |
| `delete` | `delete(?string $url): void` | Safe delete: R2 → legacy public disk → legacy uploads dir → skip external. Null-safe. |
| `url` | `url(string $path): string` | Bucket-relative path → fully-qualified public URL. |
| `relativePath` | `relativePath(string $url): string` | Public URL → bucket-relative path (needed for low-level R2 operations). |

#### Legacy disk handling

Before the R2 migration, files lived on two local disks. `delete()` handles all three storage generations transparently:

| URL pattern | Disk | Action |
|-------------|------|--------|
| `files.navigo.co.ke/…` or `r2.cloudflarestorage.com/…` | R2 | `Storage::disk('r2')->delete()` |
| `/storage/…` | Laravel public disk | `Storage::disk('public')->delete()` |
| `/uploads/…` | Legacy raw public dir | `unlink(public_path(...))` |
| Any other URL (Google, Apple OAuth) | External | Log + skip silently |

#### Controllers that inject StorageService

`AuthController` · `ConsoleAgencyController` · `VehicleOwnerController` · `VehicleDocumentController` · `OwnerDocumentController` · `SaccoMemberController`

#### Folder naming convention

| Entity | R2 prefix |
|--------|-----------|
| User avatars | `avatars/{user_id}/` |
| Agency logos | `agency-logos/{agency_id}/` |
| Owner photos | `owner-photos/{owner_id}/` |
| Vehicle documents | `vehicle-docs/{vehicle_id}/` |
| Owner documents | `owner-documents/{owner_id}/` |
| Member documents | `member-docs/{member_id}/` |

---

## Artisan Commands

### `patterns:generate`

Auto-derives canonical route patterns from existing trip `stop_times` data.

```bash
php artisan patterns:generate           # Generate missing patterns
php artisan patterns:generate --force   # Clear all and regenerate
```

**Algorithm**: for each `(route_id, direction_id)` combination, groups trips by their stop-ID sequence fingerprint. The most-used sequence becomes the canonical `RoutePattern`; minority variants are stored as non-canonical. Creates `route_patterns` and `route_pattern_stops` records. Uses chunked inserts (500 rows/chunk).

Run this once after seeding `stop_times` from `GtfsExcelSeeder`.

### `db:seed --class=GtfsExcelSeeder`

Streams GTFS data from `storage/app/gtfs/*.xlsx` into the database using OpenSpout generators (near-zero memory). Seeds routes → shapes (PostGIS aggregation) → trips → stops → stop_times → trip_frequencies.

---

## Data Models

GTFS-aligned schema on PostgreSQL 16 + PostGIS 3.4.

| Model | Table | Key fields |
|-------|-------|------------|
| `Stop` | `stops` | `id` (string), `name`, `location` (PostGIS Point 4326), `route_ids`, `trip_ids` |
| `Route` | `routes` | `route_id`, `short_name`, `long_name`, `type`, `color`, `text_color` |
| `Trip` | `trips` | `trip_id`, `route_id`, `service_id`, `headsign`, `direction_id`, `shape_id`, `scheduling_type` |
| `StopTime` | `stop_times` | `trip_id`, `stop_id`, `arrival_time`, `departure_time`, `stop_sequence` |
| `Shape` | `shapes` | `shape_id`, `path` (PostGIS LineString 4326) |
| `TripFrequency` | `trip_frequencies` | `trip_id`, `start_time`, `end_time`, `headway_secs` |
| `ServiceCalendar` | `service_calendars` | `service_id`, `monday`..`sunday`, `start_date`, `end_date` |
| `ServiceException` | `service_exceptions` | `service_id`, `date`, `exception_type` |
| `RoutePattern` | `route_patterns` | `id`, `route_id`, `name`, `direction_id`, `is_canonical` |
| `RoutePatternStop` | `route_pattern_stops` | `route_pattern_id`, `stop_id`, `stop_sequence`, `timepoint` |
| `NetworkSnapshot` | `network_snapshots` | `name`, `description`, `snapshot_data` (JSON) |
| `NetworkScenario` | `network_scenarios` | `name`, `base_snapshot_id`, `overrides` (JSON) |
| `Corridor` | `corridors` | `name`, `route_ids`, `path` (PostGIS LineString) |
| `CachedWalkingRoute` | `cached_walking_routes` | Origin/dest hash, GeoJSON polyline |
| `CachedSnappedPolyline` | `cached_snapped_polylines` | Polyline hash, snapped coordinates |
| `OtpLog` | `otp_logs` | `status`, `duration_seconds`, `error` |

The `Stop.location` and `Shape.path` columns are PostGIS geometry columns. All proximity queries use `ST_DWithin` on the `::geography` cast for accurate metre-based distances.

---

## Production Deployment

The full deployment guide — server setup, Docker Compose stack, Cloudflare Pages, R2 backups, and CI/CD — lives in **[DEPLOYMENT.md](../DEPLOYMENT.md)** at the repository root.

### Production vs local differences

| Variable | Local | Production |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `DB_HOST` | `127.0.0.1` | `postgres` (Docker service name) |
| `REDIS_CLIENT` | `predis` | `phpredis` (C extension) |
| `REDIS_HOST` | `127.0.0.1` | `redis` (Docker service name) |
| `CACHE_STORE` | `database` | `redis` |
| `QUEUE_CONNECTION` | `database` | `redis` |
| `OTP_BASE_URL` | `http://127.0.0.1:8080` | `http://otp:8080` (Docker service name) |
| `OTP_DEPLOY_DRIVER` | `local` | `local` |
| `OTP_DATA_PATH` | `D:\React\nairobi-otp\data` | `/opt/hopln/otp-data` |
| `OTP_BUILD_CMD` | Windows cmd string | `/opt/hopln/api/scripts/otp-rebuild.sh` |

Copy `.env.production` to `.env` on the server and fill in the secrets. pgAdmin is not included in the production compose file.

---

## Getting Started

### Prerequisites

- PHP 8.3 + Composer
- Docker (PostgreSQL + PostGIS, Redis, OTP)
- Java 11+ (optional, only for Google GTFS Validator)

### Installation

```bash
git clone <repo-url> hopln-api
cd hopln-api
composer install

cp .env.example .env
php artisan key:generate

# Start infrastructure (PostgreSQL + PostGIS :5432, Redis :6379, pgAdmin :5050)
docker compose up -d

php artisan migrate

# Seed GTFS data (place .xlsx files in storage/app/gtfs/ first)
php artisan db:seed --class=GtfsExcelSeeder
php artisan patterns:generate

# Start all services (HTTP + queue + logs)
composer dev
```

### OpenTripPlanner

```bash
cd ../nairobi-otp
docker compose up -d   # OTP on http://localhost:8080
```

Place `*.zip` (GTFS) and `nairobi.osm.pbf` in `nairobi-otp/data/`. Graph builds on first startup (~3–5 min, 6 GB heap).

---

## Environment Variables

```env
# Application
APP_KEY=
APP_ENV=local
APP_URL=http://localhost:8000

# Database, PostgreSQL + PostGIS
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hopln
DB_USERNAME=hopln
DB_PASSWORD=

# Redis, cache, queue, sessions
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_STORE=redis
CACHE_PREFIX=hopln
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# AI assistant
GEMINI_API_KEY=

# Geocoding fallback
GOOGLE_MAPS_API_KEY=

# Cloudflare R2 object storage
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
R2_PUBLIC_URL=https://files.navigo.co.ke   # custom domain pointed at the R2 bucket

# OpenTripPlanner
OTP_BASE_URL=http://localhost:8080
OTP_CACHE_TTL=300

# Mapbox, server-side geocoding + road snapping
MAPBOX_API_KEY=

# Road snapper, driver: mapbox | google | none (default: none)
ROAD_SNAPPER_DRIVER=none
GOOGLE_ROADS_API_KEY=   # only needed if ROAD_SNAPPER_DRIVER=google

# Google GTFS Validator (optional, Feature 24)
GTFS_VALIDATOR_JAR_PATH=   # absolute path to gtfs-validator-*.jar
GTFS_VALIDATOR_JAVA_BIN=java

# Push notifications
FIREBASE_CREDENTIALS=
```

### Cache Keys

| Key | TTL | Populated by |
|-----|-----|-------------|
| `quality:score` | 1 hour | `DataQualityService::compute()` |
| `quality:official_validation` | 30 min | `GtfsOfficialValidatorService::validate()` |
| `hopln:otp:*` | 5 min | `TransitEngineService` |
| `hopln:geocode:*` | 24 h | `GeocodingService` |
| `kwame:{sessionId}` | 30 min | `AiAssistantService` |

---

## Running Tests

```bash
composer test
# or
php artisan test

php artisan test --filter=RouteCalculationTest

./vendor/bin/pint   # code style
```

---

## Transit Data Strategy

Hopln's routing data originates from the **Digital Matatus project**, the first complete GTFS dataset for Nairobi's informal transit network, produced by the University of Nairobi and Columbia University. It maps every named matatu route: stop locations, route alignments, and transfer points.

**What GTFS does well in Nairobi**: stop proximity, route discovery, multi-leg journey planning. The physical route alignments change slowly, so this data is reliably accurate.

**Where static GTFS falls short**: matatus are *fill-and-go*, not scheduled. A static timetable implies a fixed departure time, a concept that does not exist in Nairobi. Two factors the Hopln algorithm accounts for that standard GTFS routing ignores:

- **Fill-Time Factor**, per-terminus dwell time before a vehicle departs (varies 2–25 min depending on time of day, route, and demand). Stored as a rolling average from GPS data once the IoT layer is live.
- **Mshukiwa Factor**, per-segment travel-time multiplier that accounts for roadside loading and alighting at non-official stops on high-demand corridors (Ngong Road, Thika Road, Mombasa Road).

The long-term roadmap includes a full GTFS-RT loop: IoT GPS devices on vehicles → MQTT ingestion → live vehicle positions → OTP real-time feed → accurate departure predictions in the passenger app.

---

<div align="center">

Built with care for Nairobi's commuters.

</div>
