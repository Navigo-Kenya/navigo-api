<div align="center">

# Hopln API

**The intelligent transit backend powering Nairobi's matatu navigation.**

Laravel 13 · PHP 8.3 · PostgreSQL / PostGIS · OpenAI · OpenTripPlanner

---

[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![OpenAI](https://img.shields.io/badge/OpenAI-GPT--4o--mini-412991?style=flat-square&logo=openai&logoColor=white)](https://openai.com)
[![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)

</div>

---

## Overview

Hopln API is the server-side backbone of the **Hopln** mobile application — a conversational public-transport assistant for Nairobi's matatu and bus network. It handles three core responsibilities:

- **Transit routing** — proxies OpenTripPlanner (GTFS + OSM) and post-processes itineraries into rich, mobile-ready JSON
- **Intelligent geocoding** — resolves natural-language place names against a local GTFS stop database before falling back to Google Maps
- **Kwame AI assistant** — a multi-turn conversational agent (OpenAI GPT-4o-mini) that understands voice and text, plans routes on demand, and responds with synthesised speech

---

## Table of Contents

1. [Architecture](#architecture)
2. [API Reference](#api-reference)
3. [Services](#services)
4. [Data Models](#data-models)
5. [Getting Started](#getting-started)
6. [Environment Variables](#environment-variables)
7. [Running Tests](#running-tests)
8. [Roadmap](#roadmap)

---

## Architecture

```
Mobile App  (Expo / React Native)
      │
      │  HTTPS  /api/v1/*
      ▼
┌─────────────────────────────────────────────────────────────┐
│                        Hopln API                            │
│                  Laravel 13 · PHP 8.3                       │
│                                                             │
│  Routes ──► Controllers (Api/V1)                            │
│                   │                                         │
│          ┌────────┼──────────────────────┐                  │
│          ▼        ▼                      ▼                  │
│  LocationController  RouteController  AiTransitController   │
│          │            │                  │                  │
│          ▼            ▼                  ▼                  │
│  LocationService  TransitEngineService  AiAssistantService  │
│  GeocodingService       │              /       \            │
│                         │    GeocodingService  OpenAI       │
│                         │    (geocoding)       (GPT / TTS)  │
│                         ▼                                   │
│               OpenTripPlanner  ◄── GTFS + OSM data          │
│               (Docker :8080)                                │
│                                                             │
│  PostgreSQL 16 + PostGIS 3.4   ◄── GTFS-aligned schema      │
│  Laravel Cache (Redis / file)  ◄── OTP results · sessions   │
└─────────────────────────────────────────────────────────────┘
```

### Request lifecycle for an AI trip plan

```
POST /journey/ai-plan
  │
  ├─ 1. [Optional] Whisper STT  — base64 audio → transcript
  │
  ├─ 2. Load session history from Cache (key: kwame:{sessionId})
  │
  ├─ 3. First GPT call  (tool_choice: required)
  │       ├─ calculate_route  →  resolve coords  →  OTP  →  itinerary
  │       │       └─ Second GPT call (forced chat_or_clarify) → spoken response
  │       └─ chat_or_clarify  →  spoken response directly
  │
  ├─ 4. Save trimmed history (last 24 messages, TTL 30 min)
  │
  └─ 5. Parallel TTS generation (holding phrase + final response)
         └─ Returns: route JSON + spoken_response + tts_audio (base64 MP3)
```

---

## API Reference

All endpoints are prefixed with `/api/v1`.

### Stops

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/stops/all` | Full list of GTFS stops |
| `GET` | `/stops/nearby` | Stops within a radius of GPS coordinates |
| `GET` | `/stops/search` | Full-text search across stop names |
| `GET` | `/stops/{id}` | Single stop by ID |

#### `GET /stops/nearby`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `lat` | float | yes | Latitude |
| `lng` | float | yes | Longitude |
| `radius` | int | no | Search radius in metres (default: 800) |

Uses `ST_DWithin` for primary radius lookup; falls back to `ST_DistanceSphere` KNN ordering when radius returns nothing.

---

### Journey

#### `POST /journey/calculate`

Direct route calculation. Bypasses AI.

```jsonc
// Request
{
  "from_lat": -1.2921,
  "from_lng":  36.8219,
  "to_lat":   -1.3001,
  "to_lng":    36.7800,
  "date": "2025-05-17",   // optional
  "time": "08:00",        // optional
  "walk_reluctance": 13.5 // optional
}

// Response — abbreviated
{
  "summary": "CBD → Westlands via Moi Avenue",
  "total_duration": 1620,
  "total_walk_distance": 320,
  "segments": [
    { "mode": "WALK", "distance": 180, "duration": 130 },
    { "mode": "BUS",  "route_name": "23", "headsign": "Westlands", "duration": 1200, ... },
    { "mode": "WALK", "distance": 140, "duration": 100 }
  ]
}
```

> **Nighttime hack**: if the local time is between 20:00–04:00 and no explicit time is given, the OTP query is set to 14:00 to guarantee valid departures (matatus run limited service late at night).

---

#### `POST /journey/ai-plan`

Conversational trip planning through **Kwame**. Supports both text and voice input within a stateful multi-turn session.

```jsonc
// Request
{
  "session_id": "ks_1715942400_ab3f7",
  "text": "Take me from Kencom to Westlands",  // or omit for voice
  "audio": {                                    // optional — mutually exclusive with text
    "base64": "<base64-encoded .m4a>",
    "mime":   "audio/m4a"
  },
  "lat": -1.2864,   // optional — user GPS
  "lng":  36.8172,
  "aliases": {      // optional — personal location keywords
    "home":   { "lat": -1.310, "lng": 36.804 },
    "work":   { "lat": -1.286, "lng": 36.817 },
    "school": { "lat": -1.295, "lng": 36.810 }
  }
}

// Response
{
  "spoken_response": "Got it! The 23 matatu from Kencom takes about 27 minutes.",
  "holding_phrase":  "Let me check routes from Kencom to Westlands real quick...",
  "holding_tts":     "<base64 MP3>",  // played immediately while route is loading
  "tts_audio":       "<base64 MP3>",  // final spoken response
  "transcript":      "Take me from Kencom to Westlands",  // populated for voice input
  "route": { ... }  // same shape as /journey/calculate, or null for chat-only turns
}
```

---

## Services

### `AiAssistantService`

The brain of Kwame. Manages the full conversational pipeline.

**Dual-tool architecture** — `tool_choice: required` forces the model to always call one of two tools, eliminating deferred plain-text responses:

| Tool | When called |
|------|-------------|
| `calculate_route` | Both origin and destination are known |
| `chat_or_clarify` | Everything else — greetings, clarification, chitchat |

`calculate_route` carries a `holding_phrase` argument — a warm sentence the model generates itself, shown to the user while the (synchronous) route calculation runs in the background.

**Session history** is stored in Laravel Cache under `kwame:{sessionId}`, trimmed to the last 24 messages, with a 30-minute TTL.

**Alias resolution** — keywords like `"home"`, `"work"`, `"school"`, `"office"` are resolved via the `aliases` map from the request payload instead of being geocoded. Today that map is populated from the user's GPS; when user authentication ships, it will contain the user's actual saved address coordinates. The geocoding service is never reached for these keywords.

---

### `GeocodingService`

Tiered location resolution:

```
Query: "Kencom"
  │
  ├─ 1. Exact match (ILIKE)        ── local GTFS stop database
  ├─ 2. Starts-with match (ILIKE)  ── local GTFS stop database
  ├─ 3. Contains match (ILIKE)     ── local GTFS stop database
  │
  └─ 4. Google Maps Geocoding API  ── Nairobi region bias (region=ke, components=country:KE)
         └─ Cached 24 hours per query
```

Prioritising the local database ensures stop names like *"Kencom"*, *"Westlands Stage"*, or *"GPO"* resolve instantly and exactly — these are GTFS stop names that Google Maps may not know. Google Maps only handles arbitrary addresses and landmarks that aren't in the local dataset.

---

### `TransitEngineService`

Proxies OpenTripPlanner and enriches the raw OTP response:

- Calls `GET /otp/routers/default/plan` with `mode=TRANSIT,WALK`
- Applies `walkReluctance=13.5` by default (increases to 20–25 when the user mentions heavy bags or rain)
- `maxWalkDistance=1500 m`, `numItineraries=2`
- Results cached for 5 minutes keyed on origin + destination + departure time
- Post-processes raw legs into the mobile-friendly `segments` array, including stop sequences, polylines, and transfer points

**Stop proximity**: `ST_DWithin` on the PostGIS `geography` type for accurate metre-based distance; KNN fallback (`<->`) when no stops are within the radius.

---

### `WalkingService` & `SnapToRoadsService`

Post-processing utilities applied to OTP walking legs:

- **`WalkingService`**: enriches walk segments with turn-by-turn instructions
- **`SnapToRoadsService`**: snaps raw polyline coordinates to the road network for smoother map rendering; results cached in `CachedSnappedPolyline`

---

## Data Models

GTFS-aligned schema on PostgreSQL 16 + PostGIS 3.4.

| Model | Table | Key fields |
|-------|-------|------------|
| `Stop` | `stops` | `id`, `name`, `location` (PostGIS geometry), `stop_code` |
| `Route` | `routes` | `id`, `short_name`, `long_name`, `type` |
| `Trip` | `trips` | `id`, `route_id`, `headsign`, `direction_id` |
| `StopTime` | `stop_times` | `trip_id`, `stop_id`, `arrival_time`, `stop_sequence` |
| `Shape` | `shapes` | `shape_id`, `pt_lat`, `pt_lon`, `pt_sequence` |
| `CachedWalkingRoute` | `cached_walking_routes` | Origin/dest hash, GeoJSON polyline |
| `CachedSnappedPolyline` | `cached_snapped_polylines` | Polyline hash, snapped coordinates |

The `Stop.location` column is a `geometry(Point, 4326)` column. All proximity queries use `ST_DWithin` on the `geography` cast for accurate earth-surface distance calculations.

---

## Getting Started

### Prerequisites

- PHP 8.3 + Composer
- Docker (for PostgreSQL + PostGIS and OpenTripPlanner)
- Node.js (for Vite asset compilation)

### Installation

```bash
# Clone and install
git clone <repo-url> hopln-api
cd hopln-api
composer install

# Environment setup
cp .env.example .env
php artisan key:generate

# Start the database (PostgreSQL 16 + PostGIS 3.4)
docker compose up -d

# Run migrations
php artisan migrate

# Start all services (HTTP server + queue + logs + Vite)
composer dev
```

### OpenTripPlanner

OTP runs as a separate Docker container in the `nairobi-otp/` directory:

```bash
cd ../nairobi-otp
docker compose up -d   # starts OTP on http://localhost:8080
```

Place Nairobi GTFS data (`*.zip`) and the OSM extract (`nairobi.osm.pbf`) in `nairobi-otp/data/`. OTP builds the graph on first startup (~3–5 minutes, 6 GB heap).

---

## Environment Variables

```env
# Application
APP_KEY=
APP_ENV=local
APP_URL=http://localhost:8000

# Database — PostgreSQL + PostGIS
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hopln
DB_USERNAME=hopln
DB_PASSWORD=

# OpenAI — AI assistant, speech-to-text, text-to-speech
OPENAI_API_KEY=

# Google Maps — geocoding fallback
GOOGLE_MAPS_API_KEY=

# Cache / Sessions (default: file — switch to redis in production)
CACHE_STORE=file
SESSION_DRIVER=file

# OpenTripPlanner
OTP_BASE_URL=http://localhost:8080
```

---

## Running Tests

```bash
# All tests
composer test
# or
php artisan test

# Single file
php artisan test --filter=RouteCalculationTest

# Code style
./vendor/bin/pint
```

---

## Roadmap

The items below are actively planned. Architecture decisions made today (session IDs, `UserContext`, alias resolution) are designed to slot these in without breaking changes.

### Authentication & User Profiles

- Laravel Sanctum token authentication
- User registration / login endpoints
- Saved alias addresses — `home`, `work`, `school` stored per user in the database
- Once a user is authenticated, `AiAssistantService` will receive their real address coordinates instead of the current GPS fallback, with zero code changes required in the routing pipeline

### Favourite Stops & Routes

- Endpoints to save, list, and delete favourite stops
- Favourite route suggestions surfaced in AI responses

### Real-Time Transit

- Integration with a real-time vehicle location feed (GTFS-RT or proprietary Nairobi data source)
- Departure boards: live next-departure times per stop
- WebSocket / SSE channel for in-trip position updates

### Push Notifications

- "Your matatu is 2 stops away" alerts
- Service disruption warnings
- Arrival reminders

### Fare Estimation

- Distance-based fare calculation per matatu segment
- M-Pesa deep-link integration (pay within the app)

### Community Contributions

- Crowd-sourced stop corrections (name, location)
- Missing stop reports fed into a moderation queue
- Contribution history and trust scoring per user

### Analytics & Observability

- Per-session AI conversation metrics (turns, tool-call rate, route success rate)
- Grafana dashboards for OTP cache hit rates and geocoding fallback frequency
- Structured logging for all AI pipeline stages

---

<div align="center">

Built with care for Nairobi's commuters.

</div>
