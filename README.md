<div align="center">

# Hopln API

**The intelligent transit backend powering Nairobi's matatu navigation.**

Laravel 13 · PHP 8.3 · PostgreSQL / PostGIS · OpenAI · OpenTripPlanner

---

[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io)
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
2. [Transit Data Strategy](#transit-data-strategy)
3. [API Reference](#api-reference)
4. [Services](#services)
5. [Data Models](#data-models)
6. [Getting Started](#getting-started)
7. [Environment Variables](#environment-variables)
8. [Running Tests](#running-tests)
9. [Roadmap](#roadmap)

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
│  Redis 7                       ◄── cache · queues · sessions │
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

## Transit Data Strategy

This section explains the data foundation Hopln is built on, why it is reliable today, where its limits are, and the engineering decisions that close the gap between a static schedule and the unpredictable reality of Nairobi's streets.

---

### The Digital Matatus Foundation

Hopln's routing data originates from the **Digital Matatus project** — a collaboration between the University of Nairobi, Columbia University, and Groupshot that produced the first complete GTFS dataset for Nairobi's informal transit network. The dataset maps every named matatu and bus route in the city: stop locations, route alignments, and transfer points.

This is the backbone of the `nairobi-otp/` OpenTripPlanner instance and the PostGIS stop database. It gives Hopln two things that simply did not exist in any app before:

- A reliable answer to "which routes serve this area?"
- A reliable answer to "how do I transfer between two routes?"

**What GTFS does well in Nairobi:** stop proximity queries, route discovery, multi-leg journey planning, and walking-distance estimates. These are valuable and largely accurate because the physical route alignments change slowly.

**Where static GTFS falls short:** matatus are *fill-and-go*, not scheduled. A GTFS timetable implies a bus departs at 08:14 — a concept that does not exist in Nairobi. Departure frequency varies by time of day, day of week, traffic, weather, and how quickly the previous vehicle filled. A static feed cannot capture any of this, which is why real-time data from the IoT network (described in the Roadmap) is a prerequisite for departure-time accuracy.

---

### From Static to Realtime — The GTFS-RT Loop

The transition from static reliability to realtime reliability requires closing a data loop. Every SACCO management device deployed on a vehicle does not only serve the SACCO — it feeds the algorithm.

```
SACCO dispatches vehicle
        │
        ▼
In-bus GPS device broadcasts position every 5 s
        │
        ▼
Hopln backend ingests position stream
        │
   ┌────┴───────────────────────────────────────┐
   ▼                                            ▼
VehiclePosition table                   GTFS-RT feed generated
(PostGIS point, heading, speed)         (TripUpdate + VehiclePosition)
                                                │
                                                ▼
                                        OpenTripPlanner reloads
                                        (live departure predictions)
                                                │
                                                ▼
                                        Passenger app shows
                                        accurate next-departure times
```

This loop is the core strategic value of building the SACCO management platform. SACCOs adopt it for operational reasons (stage queuing, turnaround metrics, NTSA compliance); Hopln benefits from the GPS stream as a byproduct. Neither side has to do extra work once the devices are installed — the data flows automatically.

---

### The Nairobi Algorithm Factors

Standard GTFS-RT assumes a bus follows a fixed timetable and can be predicted by interpolating between scheduled stops. That model breaks down in Nairobi. The routing algorithm must account for two behaviours that are unique to informal transit networks and absent from any European transit API:

#### Fill-Time Factor

Matatus do not depart on a schedule — they depart when full (or nearly full). At major termini like **Railway Station**, **Khoja**, or **Westlands Stage**, a vehicle can sit idle for anywhere from 2 minutes to 25 minutes waiting to fill, depending on time of day, weather, and competing vehicles on the same route.

The Fill-Time Factor is a per-terminus, per-route, per-time-of-day estimate of this idle wait time, derived from historical GPS departure data. It is added to the algorithm's ETA calculation as a dwell penalty before the first departure event, not after boarding.

Without it, the algorithm would tell a user "the next 23 leaves in 1 minute" when the vehicle is physically present at the stage but still loading — creating false expectations and missed connections.

As GPS data accumulates, the Fill-Time Factor is refined per route and per terminus using a rolling average weighted toward recent observations (traffic and loading patterns shift seasonally).

#### Mshukiwa Factor

*Mshukiwa* (Swahili: the one being dropped off) refers to the common practice of matatu drivers making unscheduled stops along the route to pick up or discharge passengers at locations that are not designated GTFS stops. This is near-universal on high-demand corridors like Ngong Road, Thika Road, and Mombasa Road.

The Mshukiwa Factor adjusts the per-segment travel-time estimate on a given route to account for these micro-stops. A segment that nominally takes 4 minutes based on distance and average speed may routinely take 7–9 minutes during morning peak because of roadside loading activity.

This factor is derived from the difference between GPS-observed segment durations and OTP's theoretical estimates, averaged across all vehicles on the route over a rolling 30-day window. It is applied as a per-segment multiplier during journey planning, improving ETA accuracy without requiring any change to the underlying GTFS data.

---

### SACCO Adoption Strategy — Solving Real Operational Pain

SACCOs will not adopt a platform because it benefits passengers. They will adopt it because it reduces their own friction. Three specific problems in daily SACCO operations are the entry points:

#### 1. The Stage Queue — Digitising the Notebook

At every major terminus, a *stage manager* (sometimes called a *dispatcher* locally) currently keeps a handwritten notebook recording which vehicle arrived first and which is next in line to load. This notebook is the only source of truth for departure order and is prone to disputes, bribes, and lost records.

HoplnOS digitises this queue. When a vehicle arrives at the terminus and its IoT device is detected in the geofenced zone, it is automatically added to the queue with a timestamp. The stage manager sees a live, ordered list on a tablet — no paper, no disputes, verifiable history. This single feature has direct financial implications for every driver: fair queue position means predictable income.

This is the primary pitch to any SACCO: *"We will stop your drivers from jumping the queue and your managers from arguing about it."*

#### 2. Turnaround Time — Making Trips Visible

SACCO owners currently have no reliable way to know how many revenue trips a given vehicle completed in a day. Drivers self-report, conductors occasionally inflate receipts, and the real number is unverifiable.

Once GPS devices are installed, the system can automatically count completed route cycles: terminus departure → terminus arrival = one trip. Daily trip counts per vehicle and per driver are surfaced in the HoplnOS finance module. SACCO owners immediately see which vehicles are doing 6 trips per day and which are doing 3 — and can investigate why.

Faster turnarounds mean more trips. More trips mean more revenue for the SACCO and more data for the Hopln algorithm. Both sides benefit.

#### 3. Geofencing — Route Compliance & NTSA Risk

A frequent source of NTSA (National Transport and Safety Authority) fines is drivers deviating from their licensed route — cutting through residential estates to bypass traffic, serving stops outside their designated alignment, or operating on a different route entirely without authorisation.

HoplnOS monitors each vehicle's GPS trace against the polyline of its assigned GTFS route. A deviation beyond a configurable threshold (e.g., 200 metres for more than 60 seconds) triggers an immediate alert to the dispatcher and logs the event for the SACCO owner. This gives SACCOs a paper trail demonstrating route compliance if challenged by NTSA, and reduces fine exposure.

---

### Engineering Constraints for the Nairobi Context

Two constraints that are often overlooked in transit app development for Nairobi — and that have direct implications for how this backend and the mobile app are built:

#### Low-Data Architecture

Matatu conductors and drivers are acutely sensitive to mobile data costs. An app that burns 50 MB per day in background data will be uninstalled within a week. The in-bus IoT device and the conductor companion app must use the lightest possible data transport.

GPS position updates are sent as **MQTT messages** (sub-1 KB per packet, TCP-based, persistent connection) rather than HTTPS POST requests. At one update per 5 seconds, MQTT costs roughly 1–2 MB per operating day per vehicle. HTTPS with TLS handshake overhead on the same frequency would cost 5–10×.

The passenger app on the user side uses the existing REST API but is designed to minimise unnecessary polling — real-time updates are pushed via WebSocket only when the user has an active trip, not in the background.

#### Offline-First for Passengers

Nairobi's cellular network is excellent in the CBD and along major corridors, but degrades in informal settlements, during heavy rain, and in low-signal pockets across the city. A passenger in Kibera or Mathare should still be able to plan a route.

The passenger app (Hopln mobile) caches the GTFS static dataset locally on device. When offline:

- Route search and stop discovery work against the local cache
- Journey planning uses the static OTP graph (pre-computed itineraries for common origin-destination pairs can be cached at build time)
- Real-time overlays (live vehicle positions, live departure boards) are gracefully hidden with a clear "offline mode" indicator rather than showing stale data

When connectivity is restored, the real-time layer reactivates automatically. The static layer is the floor, not the fallback.

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
- Results cached in Redis for 5 minutes keyed on origin + destination + departure time
- Post-processes raw legs into the mobile-friendly `segments` array, including stop sequences, polylines, and transfer points

**Stop proximity**: `ST_DWithin` on the PostGIS `geography` type for accurate metre-based distance; KNN fallback (`<->`) when no stops are within the radius.

---

### Redis

Redis 7 (Alpine) runs as a Docker service and backs three Laravel subsystems:

| Driver | Key pattern | TTL |
|--------|-------------|-----|
| **Cache** (`CACHE_STORE=redis`) | `hopln:otp:*`, `hopln:geocode:*`, `hopln:kwame:*` | 5 min – 24 h |
| **Queue** (`QUEUE_CONNECTION=redis`) | `queues:default` | Until processed |
| **Sessions** (`SESSION_DRIVER=redis`) | `laravel_session:*` | 120 min |

The queue worker (`php artisan queue:listen`) started by `composer dev` consumes jobs from the Redis queue. OTP route results and Kwame session history are the primary cache consumers; Google Maps geocoding results are cached for 24 hours.

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
- Docker (for PostgreSQL + PostGIS, Redis, and OpenTripPlanner)
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

# Start infrastructure (PostgreSQL 16 + PostGIS 3.4, Redis 7, pgAdmin)
docker compose up -d

# Run migrations
php artisan migrate

# Start all services (HTTP server + queue worker + logs + Vite)
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

# Redis — cache, queue, sessions (Docker service on :6379)
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_STORE=redis
CACHE_PREFIX=hopln
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# OpenAI — AI assistant, speech-to-text, text-to-speech
OPENAI_API_KEY=

# Google Maps — geocoding fallback
GOOGLE_MAPS_API_KEY=

# OpenTripPlanner
OTP_BASE_URL=http://localhost:8080
OTP_CACHE_TTL=300
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
In-bus device  ──► MQTT broker  ──► VehiclePositionIngestionJob (Redis queue)
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

- Crowd-sourced stop corrections (name, location) submitted through the mobile app and fed into a moderation queue
- Missing stop reports with photo evidence
- Contribution history and trust scoring per user — high-trust contributors can have their corrections auto-approved
- SACCO administrators can endorse or reject community-submitted changes for their routes
- This is the mobile-app entry point to the broader HoplnOS community mapping platform described below

---

### Analytics & Observability

- Per-session AI conversation metrics: turns per session, tool-call rate, route success rate, fallback-to-Google-Maps rate
- Grafana dashboards for OTP cache hit rates, geocoding pipeline latency, and AI pipeline stage timings
- Structured logging for every stage of the AI pipeline (Whisper → GPT → geocoding → OTP → TTS)
- Once IoT devices are live: fleet-wide on-time performance, average route durations, stop dwell times, and peak-hour occupancy heatmaps

---

## HoplnOS — Transit Network Operating System

> HoplnOS is a separate web platform built on top of the same Hopln API backend. It serves two distinct audiences through two distinct portals: **SACCO operators** who need professional tools to manage their fleets, routes, and finances; and **the general public** who want to contribute to and improve the accuracy of Nairobi's public transport data — much like OpenStreetMap does for geographic data.
>
> The two portals are unified under one platform because the data they produce flows into the same place: the GTFS dataset and the PostGIS database that power the Hopln mobile app. A SACCO administrator updating a timetable and a community member correcting a stop name are both editing the same underlying network graph, just with different permissions, different interfaces, and different validation workflows.

---

### Portal 1 — SACCO Operations Portal

A private, role-gated web dashboard for SACCO administrators, fleet managers, dispatchers, and finance officers. Access is granted after a SACCO completes the onboarding process and signs the partnership agreement.

#### Roles & Access Levels

| Role | Who | Access |
|------|-----|--------|
| `sacco_owner` | SACCO chairperson / director | Full access to all modules, billing, and user management |
| `fleet_manager` | Operations manager | Fleet, routes, schedules, IoT devices |
| `dispatcher` | Control room operator | Real-time monitoring, incident management |
| `finance_officer` | Accounts team | Revenue reports, disbursements, fare rules |
| `driver` | Bus driver | Personal schedule, route sheet, incident reporting |
| `conductor` | Ticket conductor | Ticket validation app, boarding/alighting events |

#### Module 1 — Fleet Management

Every vehicle operated by the SACCO is registered and tracked here.

- **Vehicle registry**: plate number, fleet number, capacity (seated + standing), vehicle type (14-seater, 25-seater, 33-seater), manufacturing year, PSV licence number, insurance expiry
- **IoT device pairing**: each registered vehicle is paired to its in-bus GPS/validator device via a unique hardware ID; the portal shows device status (online, offline, low battery, firmware update available)
- **Maintenance log**: scheduled service dates, mileage alerts, breakdown history; overdue vehicles automatically flagged and temporarily removed from active route assignments
- **Driver assignment**: link a driver to a vehicle for a given shift; historical assignment records kept for audit purposes
- **Fleet map**: live map view of all active vehicles with colour-coded status (on-route, at terminus, offline, in maintenance)

#### Module 2 — Route & Schedule Management

The canonical source of truth for the SACCO's route definitions, replacing ad-hoc spreadsheets and paper timetables.

- **Route editor**: map-based drag-and-drop interface for defining a route's stop sequence; each waypoint is linked to a verified stop in the shared GTFS stop database; the polyline between stops is auto-snapped to the road network via Mapbox Directions
- **Timetable builder**: define service patterns (weekday, Saturday, Sunday, public holiday), departure frequencies (headway-based or fixed-time), and first/last service times per terminus
- **Seasonal variations**: create one-off schedule overrides for specific dates (e.g., reduced service on Easter Monday, extended service during Nairobi Marathon)
- **Fare matrix**: set fares per boarding–alighting stop pair; distance-based or flat-rate per segment; the matrix is used by the ticketing system and surfaced to passengers in the Hopln app
- **GTFS export**: any saved route/schedule change is immediately compiled into a GTFS feed update and pushed to OpenTripPlanner, making it live in the passenger app within minutes
- **Change audit log**: every edit is timestamped and attributed to the user who made it; previous versions are retained and restorable

#### Module 3 — Real-Time Network Monitoring

The dispatcher's control room view — a live operational picture of the entire fleet.

- **Live fleet map**: all active vehicles plotted on a map with real-time positions updated every 5 seconds from the IoT GPS devices; each vehicle marker shows route, direction, speed, and occupancy
- **Schedule adherence**: each vehicle's actual position is compared to its expected position based on the timetable; early, on-time, and late departures are colour-coded; persistent lateness triggers an automated alert to the dispatcher
- **Headway monitoring**: for high-frequency routes, the system monitors the gap between consecutive vehicles; bunching (two buses too close together) and gapping (interval too large) are flagged in real time with suggested corrective actions
- **Passenger load**: live occupancy percentage per vehicle from the in-bus passenger counter; the dispatcher can see at a glance which vehicles are overcrowded and which are running near-empty
- **Incident board**: real-time feed of events from the field — breakdowns reported by drivers, validator errors, stop device failures; each incident has a status (open, acknowledged, resolved) and can be escalated

#### Module 4 — Financial Operations

Transparent revenue reporting and automated disbursement.

- **Revenue dashboard**: daily, weekly, and monthly fare revenue broken down by route, vehicle, and payment method (single ticket vs. subscription pass); exportable as CSV or PDF
- **Subscription attribution**: when a City Pass holder boards a SACCO's vehicle, Hopln calculates that SACCO's share of the subscription revenue based on actual ridership (passenger-kilometres); this attribution is shown transparently in the finance module
- **Disbursement schedule**: SACCOs are paid out on a configurable cycle (daily, weekly); the disbursement engine calculates the net amount (gross fares minus Hopln's service fee) and initiates an M-Pesa B2B transfer automatically
- **Transaction ledger**: itemised record of every ticket validated, every subscription boarding, every disbursement, and every adjustment, with full traceability back to the originating device event
- **Fare dispute resolution**: passengers can contest a fare charge through the Hopln app; the dispute appears in the SACCO's finance module with the supporting IoT event data (boarding stop, alighting stop, timestamp) for review

#### Module 5 — Driver & Conductor Management

- **Staff registry**: driver and conductor profiles with licence numbers, PSV badge numbers, employment start date, and emergency contacts
- **Shift scheduling**: assign drivers and conductors to specific vehicles and routes for each operating day; shift confirmations sent via SMS
- **Performance metrics**: per-driver statistics including on-time departure rate, incident reports filed, and (once live) passenger satisfaction scores
- **Conductor companion app**: a separate lightweight mobile web app for conductors to validate QR tickets and record cash transactions; works offline and syncs when connectivity is restored

---

### Portal 2 — Community Mapping Platform

A publicly accessible web platform where anyone can view, verify, and contribute improvements to Nairobi's public transport data. Inspired by OpenStreetMap's model of community-maintained geographic data, but focused entirely on transit: stops, routes, and real-world observations that no SACCO would think to document.

The community platform solves a data quality problem that SACCO data alone cannot fix. SACCOs define their official routes and stops, but the reality on the ground diverges constantly: informal stops spring up and disappear, route deviations happen without any official update, stop names are known by five different names depending on who you ask. Community contributors — daily commuters, boda drivers, local residents — are the only people who can capture this ground truth at scale.

#### The Data Model

Every contribution targets one of three entity types:

| Entity | What can be contributed |
|--------|------------------------|
| **Stop** | New stop, name correction, location correction, photo, accessibility info, shelter/seating status |
| **Route** | New route, missing stop on existing route, stop sequence correction, route alignment correction |
| **Observation** | Real-world event: "this stop no longer exists", "matatus on route 23 skip this stop in the evenings", "the stop at Ngong Road / Valley Road junction is unnamed" |

#### Contribution Workflow

```
Contributor submits edit
         │
         ▼
Automated validation
  ├─ Geometry check (stop not in ocean, not 50 km from nearest road)
  ├─ Duplicate detection (stop already exists within 30 m)
  └─ Conflict check (edit doesn't contradict a verified SACCO record)
         │
    ┌────┴────┐
    ▼         ▼
 Auto-      Queued for
approved   human review
(high-     (new contributor,
 trust)     conflicting edits,
            SACCO-owned data)
         │
    ┌────┴────┐
    ▼         ▼
  Approved  Rejected
  → merged  → contributor
  into GTFS   notified with reason
```

Approved contributions are merged into the GTFS dataset on a rolling basis and pushed to OpenTripPlanner, making every accepted community edit live in the passenger app within minutes.

#### Trust & Contribution System

Contributors earn trust through a transparent, merit-based system — not gamification, but quality control.

| Level | How earned | Privileges |
|-------|-----------|------------|
| **New Contributor** | Account created | Submit stop and route edits; all changes go to moderation queue |
| **Verified Contributor** | 10 accepted edits, no reversions | Edits to stop names and minor location corrections auto-approved |
| **Trusted Contributor** | 50 accepted edits, trusted by 3 moderators | Auto-approval for most edit types; can vote on others' pending edits |
| **Moderator** | Nominated by existing moderators, approved by Hopln | Can approve or reject any pending contribution; can revert accepted edits |
| **SACCO Verified** | Linked to a verified SACCO account | Auto-approval for edits to that SACCO's own routes and stops |

Each contributor has a public profile showing their edit history, acceptance rate, and specialisation area (which zones of Nairobi they know best).

#### Map-Based Editing Interface

The editing UI is a full-screen interactive map — no forms, no spreadsheets.

- **Stop placement**: click anywhere on the map to propose a new stop; drag an existing stop marker to correct its position; a 15-metre snap-to-nearest-road is applied automatically so stops land on the kerb, not in the middle of a lane
- **Route tracing**: click stops in sequence to define or correct a route alignment; the system highlights gaps (stops too far from the road network) and overlaps (stop already served by another route in the same direction)
- **Photo evidence**: contributors can attach photos to any stop or observation; photos are stored and displayed in the Hopln passenger app as well as the community platform
- **Street-level view**: integration with Mapillary (open street-level imagery) so contributors can verify stop locations without visiting them in person
- **Conflict highlighting**: when a proposed edit conflicts with existing verified data, both versions are shown side-by-side on the map with a clear explanation of the conflict, so the contributor understands why their edit needs review

#### Public Data API

All verified community data — stops, routes, observations — is accessible via a read-only public API, encouraging third-party transit apps, researchers, and civic technologists to build on top of Hopln's dataset.

```
GET /public/v1/stops                   All verified stops (GeoJSON)
GET /public/v1/stops/{id}              Single stop with contribution history
GET /public/v1/routes                  All verified routes
GET /public/v1/routes/{id}/stops       Ordered stop sequence for a route
GET /public/v1/contributions/recent    Latest accepted contributions
```

Rate-limited per IP, no authentication required. Full GTFS exports available as static file downloads on a daily refresh cycle.

---

### HoplnOS — Backend Architecture Implications

HoplnOS is a new frontend application, but it deepens the same Hopln API backend. No new backend service is introduced; instead, new modules are added to the existing Laravel application.

#### New Data Models

| Model | Purpose |
|-------|---------|
| `SaccoUser` | Platform account linked to a `Sacco`; carries role assignment |
| `FleetVehicle` | Registered bus with plate number, capacity, IoT device pairing |
| `IoTDevice` | Hardware unit (in-bus or at-stop) with serial number, firmware version, last-seen timestamp |
| `ServicePattern` | Timetable definition: headway, first/last service, days of operation |
| `FareMatrix` | Per-SACCO fare rules: boarding stop, alighting stop, fare amount |
| `ShiftAssignment` | Driver + vehicle + route for a given operating day |
| `CommunityContributor` | Public user account for the mapping platform; separate from the passenger `User` model |
| `Contribution` | A single proposed edit (stop, route, observation) with status and diff |
| `ContributionVote` | Trusted contributor vote on a pending contribution |
| `ModerationAction` | Audit record of every approve/reject/revert decision |

#### New Permission Scopes

The existing API will be extended with a Laravel permission layer (Spatie `laravel-permission`) with the following guard structure:

```
Guards:
  api          — passenger app (Sanctum tokens)
  sacco        — SACCO portal (Sanctum tokens, sacco_user table)
  community    — community mapping platform (Sanctum tokens, community_contributor table)

Key permission checks:
  sacco:manage-fleet          sacco:manage-routes
  sacco:view-financials       sacco:manage-staff
  community:submit-edit       community:vote-on-edit
  community:moderate          hopln:admin
```

#### New API Namespaces

```
/api/v1/sacco/*          — SACCO portal endpoints (sacco guard)
/api/v1/community/*      — community mapping endpoints (community guard + public)
/public/v1/*             — unauthenticated public data read endpoints
```

#### Real-Time Features

The SACCO dispatcher view requires a persistent connection for live vehicle position updates. This is implemented via **Laravel Echo** (WebSockets) backed by **Soketi** (self-hosted, compatible with Pusher protocol):

```
IoT device  →  MQTT ingestion  →  VehiclePositionUpdated event
                                         │
                                   Laravel Echo broadcast
                                         │
                              SACCO portal WebSocket client
                              (updates vehicle markers on map)
```

The community platform does not require WebSockets; edit status updates are delivered via polling or Firebase push notification.

---

### Community Contributions

- Crowd-sourced stop corrections (name, location) submitted through the mobile app and fed into a moderation queue
- Missing stop reports with photo evidence
- Contribution history and trust scoring per user — high-trust contributors can have their corrections auto-approved
- SACCO administrators can endorse or reject community-submitted changes for their routes
- This is the mobile-app entry point to the broader HoplnOS community mapping platform described above

---

### Analytics & Observability

- Per-session AI conversation metrics: turns per session, tool-call rate, route success rate, fallback-to-Google-Maps rate
- Grafana dashboards for OTP cache hit rates, geocoding pipeline latency, and AI pipeline stage timings
- Structured logging for every stage of the AI pipeline (Whisper → GPT → geocoding → OTP → TTS)
- Once IoT devices are live: fleet-wide on-time performance, average route durations, stop dwell times, and peak-hour occupancy heatmaps
- HoplnOS analytics layer: community data quality score per zone, SACCO schedule adherence trends, top contributors per month

---

<div align="center">

Built with care for Nairobi's commuters.

</div>
