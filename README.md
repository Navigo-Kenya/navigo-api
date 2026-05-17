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

The items below are actively planned. Architecture decisions made today — session IDs, `UserContext`, alias resolution, the `aliases` payload shape — are deliberately designed to slot each of these phases in without breaking changes to the existing pipeline.

---

### Authentication & User Profiles

- Laravel Sanctum token authentication
- User registration / login endpoints
- Saved alias addresses — `home`, `work`, `school` stored per user in the database; coordinates sent to `AiAssistantService` via the existing `aliases` payload with zero changes to the routing pipeline
- Profile photo, preferred language, accessibility preferences

---

### Favourite Stops & Routes

- Endpoints to save, list, and delete favourite stops and routes
- Favourite stops surfaced as quick-pick suggestions in Kwame
- Commute pattern detection — if a user travels the same route every weekday morning, Kwame proactively surfaces departure times before being asked

---

### Real-Time Transit

- Integration with a GTFS-RT feed (or a proprietary Nairobi vehicle-location data source once the IoT network below is operational)
- Departure boards: live next-departure times per stop, accessible via `GET /stops/{id}/departures`
- WebSocket / SSE channel for in-trip position updates pushed to the mobile client
- Kwame aware of real-time delays — can say "the 23 is running 8 minutes late, want me to find an alternative?"

---

### Push Notifications

- "Your matatu is 2 stops away" alerts triggered by real-time vehicle position
- Service disruption warnings per subscribed route
- Arrival reminders set by the user ("remind me 10 minutes before I need to leave for work")
- Implemented via Firebase Cloud Messaging; device tokens stored per authenticated user

---

### Fare Estimation

- Distance-based and route-based fare matrix per SACCO (once SACCO partnership data is available)
- Fare breakdown surfaced in the route card and in Kwame's spoken response
- Historical fare data used to flag price gouging during peak hours

---

### Smart Transit Network — Integrated Ticketing & SACCO Partnerships

> This is the long-term flagship initiative for Hopln — a Navigo-inspired digital transit network built for Nairobi. Navigo (Paris) turned the entire RATP network into a single, seamless subscription card. Hopln's ambition is to do the same for Nairobi's fragmented, SACCO-operated matatu ecosystem: one app, one pass, every route.
>
> Unlike Paris where a single public authority (RATP) controls all transit, Nairobi's network is owned and operated by dozens of independent SACCOs (Savings and Credit Co-operative Organisations). Each SACCO controls one or more matatu routes, sets its own fares, and manages its own fleet. **The entire ticketing initiative is therefore contingent on establishing commercial and technical partnerships with these SACCOs first.** What follows is the phased plan once that foundation is in place.

#### Phase 1 — SACCO Onboarding & Partnership Infrastructure

Before any physical device can go on a bus, Hopln must become a trusted partner to the SACCOs that run those buses.

- **SACCO registration portal**: a web dashboard where a SACCO administrator can register their organisation, verify their routes against the existing GTFS data, and accept the revenue-sharing terms
- **Route ownership model**: each GTFS route is linked to a verified SACCO entity in the database; fare rules, operating hours, and fleet size are managed per SACCO
- **Revenue distribution engine**: when a user buys a ticket or subscription that covers multiple SACCOs, the collected fare is automatically split and disbursed to each relevant SACCO's account (M-Pesa Paybill integration)
- **SACCO dashboard API**: endpoints exposing per-route ridership, revenue, and on-time performance data back to SACCO administrators, giving them a concrete incentive to participate
- **New models**: `Sacco`, `SaccoRoute` (join), `FareRule`, `RevenueTransaction`

#### Phase 2 — IoT Infrastructure: Onboarding Devices

The physical layer that turns a matatu fleet into a connected network. Two classes of device are deployed:

**In-bus devices (one per vehicle):**

| Capability | Purpose |
|-----------|---------|
| GPS tracker (4G) | Vehicle position broadcast every 5 seconds to the Hopln backend |
| Passenger validator | NFC reader + QR scanner for ticket validation at boarding |
| Passenger counter | Infrared or ultrasonic sensor counting boardings and alightings |
| On-board display | Small screen showing route name, next stop, and seat availability |
| Unique bus ID | Each device is permanently linked to a fleet number and SACCO |

**At-stop devices (one per major stop):**

| Capability | Purpose |
|-----------|---------|
| NFC pad | Tap-to-pay for single-journey tickets |
| QR scanner | Scan a ticket QR code for validation |
| Arrival display | Live departure board showing next 3 matatus per route |
| Unique stop ID | Links physical hardware to the GTFS `stop_id` |

The backend receives a continuous stream from these devices via a lightweight MQTT broker (or a dedicated ingestion endpoint). A new `VehiclePosition` model and `StopEvent` model persist this data; GTFS-RT feeds are generated from it for consumption by OpenTripPlanner, closing the loop between live data and route planning.

```
In-bus device  ──► MQTT broker  ──► VehiclePositionIngestionJob (queued)
                                          │
                               ┌──────────┼──────────────┐
                               ▼          ▼              ▼
                     VehiclePosition   StopEvent    GTFS-RT feed
                     (PostGIS point)   (boarding)   (→ OTP)
```

#### Phase 3 — Digital Ticketing (Single Journey)

With devices deployed, single-journey cashless tickets become possible.

- **Ticket purchase**: user selects a route and boarding stop in the app → Hopln generates a signed, time-limited QR code (valid for 15 minutes) → user pays via M-Pesa STK Push
- **Ticket validation**: conductor's device or stop device scans the QR code → backend verifies signature and marks the ticket as used → confirmation sent to user and conductor
- **NFC boarding**: for users with NFC-enabled phones, tap replaces QR scan
- **Conductor app**: a lightweight companion app (separate from the passenger app) for conductors to manually validate tickets offline if the device is temporarily disconnected
- **Fare calculation**: automatically derived from the boarding stop and alighting stop registered by the in-bus counter, eliminating arguments over fare amounts
- **New models**: `Ticket`, `TicketValidation`, `Payment`
- **New endpoints**: `POST /tickets/purchase`, `POST /tickets/validate`, `GET /tickets/{id}`

#### Phase 4 — Subscription Passes (Navigo-inspired)

Once single-journey ticketing is operational and SACCO coverage is broad enough, subscription passes become viable.

**Pass types:**

| Pass | Coverage | Billing |
|------|----------|---------|
| Single Route Pass | One specific route, unlimited trips | Monthly |
| Zone Pass | All routes within a defined geographic zone | Monthly |
| City Pass | All Hopln-partnered routes across Nairobi | Monthly or Annual |
| Corporate Pass | City Pass purchased in bulk by an employer for employees | Monthly per seat |

- Passes are stored as a `Subscription` model linked to the user; the validation endpoint checks for an active subscription before requiring a single-journey ticket
- M-Pesa recurring payment via Daraja API (automatic monthly debit with user consent)
- Passes are transferable to NFC (if the user's phone supports HCE) — tap in, tap out, exactly like Navigo
- Partial-month proration for new subscribers
- Grace period (48 hours) before a lapsed subscription is deactivated to handle payment delays
- Kwame is aware of the user's active subscription and factors it into trip advice ("you're covered on the 23 — just tap in as usual")

#### Phase 5 — Smart In-App Experience

With real-time vehicle data flowing from the IoT network, the mobile app gains a new class of features that are impossible without it.

**Knowing which bus you're on:**

When a user boards a matatu and taps in (QR or NFC), the backend associates their session with the specific vehicle's `bus_id`. From that moment:

- The map shows the user's own bus as a highlighted marker, distinct from other vehicles on the same route
- The app displays the live position of the bus relative to the user's destination, with a dynamically recalculated ETA
- "You are on bus KCB 734Y, route 23 — next stop: Yaya Centre (2 min)"
- If the bus deviates from the expected route (e.g., a traffic diversion), the app detects it and Kwame proactively suggests alternatives

**Occupancy & comfort:**

- The in-bus passenger counter exposes live seat availability per vehicle
- Users can see before boarding whether a matatu is full, half-full, or nearly empty
- Kwame can factor occupancy into route recommendations: "The next 23 in 3 minutes is already full — the one after in 8 minutes has plenty of room"

**End-to-end journey tracking:**

- When a user starts a trip from the app, the backend monitors their progress through the boarding events from the IoT network — no GPS polling from the phone required
- Automatic trip completion detection: when the user alights, the trip is marked done and a receipt is generated
- Journey history stored per user: route taken, fare paid, duration, on-time performance

**New API surface introduced by this phase:**

| Endpoint | Description |
|----------|-------------|
| `GET /vehicles/{id}/position` | Live GPS position of a specific bus |
| `GET /routes/{id}/vehicles` | All active buses on a route with positions and occupancy |
| `GET /stops/{id}/departures` | Live next-departure board for a stop |
| `POST /trips/{id}/board` | Associate user session with a vehicle (tap-in event) |
| `POST /trips/{id}/alight` | Record alighting (tap-out event), finalise fare |
| `GET /users/me/trips` | Paginated journey history for the authenticated user |

---

### Community Contributions

- Crowd-sourced stop corrections (name, location) submitted through the app and fed into a moderation queue
- Missing stop reports with photo evidence
- Contribution history and trust scoring per user — high-trust contributors can have their corrections auto-approved
- SACCO administrators can endorse or reject community-submitted changes for their routes

---

### Analytics & Observability

- Per-session AI conversation metrics: turns per session, tool-call rate, route success rate, fallback-to-Google-Maps rate
- Grafana dashboards for OTP cache hit rates, geocoding pipeline latency, and AI pipeline stage timings
- Structured logging for every stage of the AI pipeline (Whisper → GPT → geocoding → OTP → TTS)
- Once IoT devices are live: fleet-wide on-time performance, average route durations, stop dwell times, and peak-hour occupancy heatmaps

---

<div align="center">

Built with care for Nairobi's commuters.

</div>
