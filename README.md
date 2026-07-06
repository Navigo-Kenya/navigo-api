<div align="center">

# Navigo API

**The intelligent transit backend powering Nairobi's matatu navigation.**

---

[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgresql.org)
[![PostGIS](https://img.shields.io/badge/PostGIS-3.4-336791?style=flat-square&logo=postgresql&logoColor=white)](https://postgis.net)
[![Redis](https://img.shields.io/badge/Redis-7-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io)
[![Gemini](https://img.shields.io/badge/Gemini-AI-4285F4?style=flat-square&logo=google&logoColor=white)](https://ai.google.dev)
[![Mapbox](https://img.shields.io/badge/Mapbox-GL-000000?style=flat-square&logo=mapbox&logoColor=white)](https://mapbox.com)
[![GitHub](https://img.shields.io/badge/GitHub-navigo--api-181717?style=flat-square&logo=github)](https://github.com/Navigo-Kenya/navigo-api)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=flat-square)](LICENSE)

</div>

---

## Overview

Navigo API is the server-side backbone of the **Navigo** transit platform, a public-transport assistant for Nairobi's matatu and bus network. It exposes three route surfaces:

| Surface | Path prefix | Audience |
|---------|-------------|----------|
| **Passenger API** | `/api/v1/` | Mobile app (Expo / React Native) |
| **Console API** | `/api/v1/console/` | Back-office admin SPA |
| **Admin routes** | `routes/admin.php` | Console (separate middleware group) |

The platform covers multi-agency transit management, GTFS data pipelines, fleet operations, SACCO membership, a revenue ledger, real-time vehicle tracking, crowdsourced contributions, AI-powered journey planning, and a full RBAC permission system.

---

## Table of Contents

1. [Architecture](#architecture)
2. [Passenger API Reference](#passenger-api-reference)
3. [Console API Reference](#console-api-reference)
4. [Services](#services)
5. [Background Jobs](#background-jobs)
6. [Artisan Commands](#artisan-commands)
7. [Data Models](#data-models)
8. [Getting Started](#getting-started)
9. [Environment Variables](#environment-variables)
10. [Running Tests](#running-tests)
11. [Backup & Restore](#backup--restore)
12. [Transit Data Strategy](#transit-data-strategy)

---

## Architecture

```
Mobile App (Expo)                    Console SPA (React)
      │                                      │
      │  /api/v1/*                           │  /api/v1/console/*
      ▼                                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                          Navigo API                             │
│                     Laravel 13 · PHP 8.3                        │
│                                                                 │
│  Passenger Controllers (Api/V1)                                 │
│    Auth · OAuth · Stops · Journey · Kwame AI · Community        │
│    Contributions · Reports · Alerts · Notifications             │
│                                                                 │
│  Console Controllers (Console)                                  │
│    Dashboard · Users · Contributions · GTFS Data               │
│    Fleet · SACCO · Ledger · Analytics · Real-Time Ops           │
│    Fares · Interop · Access Management · Scenarios              │
│                                                                 │
│  Services Layer                                                 │
│    TransitEngineService  AiAssistantService  LedgerService      │
│    GtfsExportService  StorageService  PushNotificationService   │
│    WalkingService  FareService  NetworkAnalysisService          │
│    Google Cloud (TTS · STT · LLM)  Export (GTFS · Excel · NeTEx)│
│                                                                 │
│  PostgreSQL 16 + PostGIS 3.4 ◄── GTFS-aligned schema (71 models)│
│  Redis 7                     ◄── cache · queues · sessions      │
│  OpenTripPlanner 2.4 (Docker)◄── GTFS + OSM transit routing     │
│  Cloudflare R2               ◄── file storage + backups         │
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
Mobile App
    │
    ├── StopService / RouteService / AiService
    │       └── services/apiClient.ts  (axios, base URL from EXPO_PUBLIC_API_URL)
    │
    ▼
Laravel API  (prefix /api/v1)
    │
    ├── GET  /stops/nearby          → LocationController → PostGIS ST_DWithin
    ├── GET  /stops/search          → LocationController → LocationService (tiered ILIKE + Mapbox)
    ├── POST /journey/calculate     → RouteController → TransitEngineService → OTP HTTP call
    ├── POST /journey/ai-plan       → AiTransitController → AiAssistantService (Gemini) → OTP
    └── POST /kwame/speak           → KwameTtsController → GoogleTtsService (Cloud TTS)
    │
    ▼
OpenTripPlanner  (http://otp:8080/otp/routers/default/plan)
    GTFS schedule data + OSM walking graph
    Results cached in Redis (5 min, keyed by origin/destination/time hash)
```

---

## Passenger API Reference

All endpoints prefixed `/api/v1/`. Public endpoints are throttled at 60 req/min unless noted.

### Auth & Users

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/auth/register` |, | Register with email + password |
| `POST` | `/auth/login` |, | Login, returns Sanctum token |
| `POST` | `/auth/google` |, | Google OAuth sign-in |
| `POST` | `/auth/apple` |, | Apple Sign-In |
| `POST` | `/auth/phone/set` |, | Set phone number (throttled: otp) |
| `POST` | `/auth/phone/send` |, | Send OTP SMS (throttled: otp) |
| `POST` | `/auth/phone/verify` |, | Verify OTP code |
| `POST` | `/auth/password/forgot` |, | Send password-reset email |
| `POST` | `/auth/password/reset` |, | Reset password via token |
| `GET` | `/auth/me` | ✓ | Current user profile |
| `PATCH` | `/auth/profile` | ✓ | Update display name, bio, etc. |
| `POST` | `/auth/avatar` | ✓ | Upload avatar to R2 |
| `GET` | `/auth/settings` | ✓ | Notification + app settings |
| `PATCH` | `/auth/settings` | ✓ | Update settings |
| `POST` | `/auth/logout` | ✓ | Revoke current token |
| `POST` | `/auth/device-tokens` | ✓ | Register Expo push token |
| `DELETE` | `/auth/device-tokens` | ✓ | Remove push token |
| `GET` | `/auth/notifications` | ✓ | In-app notification inbox |
| `PATCH` | `/auth/notifications/{id}/read` | ✓ | Mark one notification read |
| `POST` | `/auth/notifications/mark-all-read` | ✓ | Mark all read |
| `GET` | `/auth/notifications/unread-count` | ✓ | Unread count badge |

### Stops

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/stops/all` | Full GTFS stop list |
| `GET` | `/stops/nearby?lat&lng&radius` | Stops within radius (default 800 m) via `ST_DWithin` |
| `GET` | `/stops/search?q` | Tiered full-text search: ILIKE → Mapbox Geocoding fallback |
| `GET` | `/stops/{id}` | Single stop by ID |
| `GET` | `/stops/{id}/reviews` | Community reviews for a stop |
| `GET` | `/stops/{id}/photos` | Crowd-sourced photos for a stop |

### Journey Planning

#### `POST /journey/calculate`

```jsonc
{ "from_lat": -1.2921, "from_lng": 36.8219,
  "to_lat":   -1.3001, "to_lng":   36.7800,
  "date": "2025-05-17", "time": "08:00" }
```

Returns itinerary segments with `mode`, `route_name`, `headsign`, `duration`, `polyline`.

> **Nighttime hack**: if local time is 20:00–04:00 and no explicit time was given, OTP query time is forced to 14:00 to return valid daytime routes.

#### `POST /journey/ai-plan`

Conversational trip planning via **Kwame** (Gemini AI). Supports text or base64 audio input. Returns `spoken_response`, `tts_audio` (base64 MP3), `route`. Session history stored under `kwame:{sessionId}` (last 24 messages, 30-min TTL).

#### `POST /journey/feedback`

Submit rating + optional comment for a completed journey (throttled: 60/min).

### Kwame AI Services

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/kwame/speak` | Text → speech (Google Cloud TTS, returns base64 MP3) |
| `POST` | `/kwame/transcribe` | Audio → text (Google Cloud STT) |

### Community

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/contributions/nearby?lat&lng` |, | Nearby crowdsourced contributions |
| `GET` | `/community/leaderboard?period=all\|weekly` |, | Top contributors by points; default `all`, `weekly` ranks last 7 days |
| `GET` | `/user/contributions` | ✓ | User's own contributions |
| `POST` | `/user/contributions` | ✓ | Submit a new contribution |
| `PATCH` | `/user/contributions/{id}` | ✓ | Edit own contribution |
| `DELETE` | `/user/contributions/{id}` | ✓ | Delete own contribution |
| `POST` | `/contributions/{id}/vote` | ✓ | Upvote / downvote a contribution |
| `GET` | `/user/community/stats` | ✓ | Personal points, badges, rank, `streak_days`, `contributed_today` |
| `GET` | `/user/badges` | ✓ | Earned badges |

### Saved Data

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/user/saved-places` | ✓ | User's saved places list |
| `POST` | `/user/saved-places` | ✓ | Save a new place |
| `DELETE` | `/user/saved-places/{id}` | ✓ | Remove a saved place |
| `GET` | `/user/saved-journeys` | ✓ | Saved journey routes |
| `POST` | `/user/saved-journeys` | ✓ | Save a journey route |
| `DELETE` | `/user/saved-journeys/{id}` | ✓ | Remove a saved journey |

### Reports & Alerts

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/reports/viewport?bbox=...` | Crowdsourced map reports within viewport |
| `POST` | `/reports` | Submit a transit report (hazard, delay, etc.) |
| `POST` | `/reports/{id}/vote` | Confirm or dismiss a report |
| `GET` | `/alerts` | Active service alerts |
| `GET` | `/gtfs-rt/alerts` | GTFS-RT alerts feed (for OTP integration) |
| `GET` | `/coverage` | GTFS network coverage GeoJSON |
| `GET` | `/otp/warmup` | Warm up OTP connection |

---

## Console API Reference

All endpoints prefixed `/api/v1/console/`. Protected by `auth:sanctum` + `role` middleware + `agency.scope` (multi-tenancy injection). Auth header: `Authorization: Bearer <token>`.

Permission format: `role:<permission>`, a user must hold the listed permission to access the route. Superadmin and `hopln_admin` bypass all permission checks.

---

### Dashboard

20+ analytics endpoints for the overview SPA panel.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/dashboard` | Core KPIs: DAU, MAU, journeys, pending contributions |
| `GET` | `/dashboard/agency-stats` | Per-agency summary |
| `GET` | `/dashboard/otp-trend` | OTP build success rate over time |
| `GET` | `/dashboard/network-stats` | Stops, routes, trips counts |
| `GET` | `/dashboard/user-growth` | User registration trend |
| `GET` | `/dashboard/journey-heatmap` | Journey origin/destination heatmap |
| `GET` | `/dashboard/top-routes` | Highest-traffic routes |
| `GET` | `/dashboard/platform-revenue` | Revenue aggregated across agencies |
| `GET` | `/dashboard/fleet-pulse` | Live fleet activity snapshot |
| `GET` | `/dashboard/compliance-overview` | Document expiry and compliance status |
| `GET` | `/dashboard/contributor-leaderboard` | Top contributors |
| `GET` | `/dashboard/onboarding-funnel` | Agency onboarding completion rates |
| `GET` | `/dashboard/retention-cohort` | User retention cohort analysis |
| `GET` | `/activity` | Global activity event feed |
| `GET` | `/system-health` | OTP + queue worker status |

---

### Users

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/users` |, | Paginated user list |
| `GET` | `/users/{id}` |, | User detail |
| `GET` | `/users/export` |, | Export users as CSV |
| `PATCH` | `/users/{id}` | `users.edit` | Update name, role, points |
| `POST` | `/users/{id}/ban` | `users.ban` | Ban user account |
| `POST` | `/users/{id}/unban` | `users.ban` | Unban user account |
| `PATCH` | `/users/{id}/points` | `users.edit` | Adjust community points |
| `POST` | `/users/{id}/badges` | `users.edit` | Award a badge |
| `DELETE` | `/users/{userId}/badges/{badgeId}` | `users.edit` | Revoke a badge |

### Badges

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/badges` |, | All badge definitions |
| `POST` | `/badges` | `users.edit` | Create badge |
| `PATCH` | `/badges/{id}` | `users.edit` | Update badge |
| `DELETE` | `/badges/{id}` | `users.edit` | Delete badge |

---

### Contributions (Moderation)

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/contributions` |, | Paginated contributions queue |
| `GET` | `/contributions/{id}` |, | Contribution detail |
| `PATCH` | `/contributions/{id}` | `contributions.moderate` | Edit contribution data |
| `POST` | `/contributions/{id}/approve` | `contributions.moderate` | Approve + award points |
| `POST` | `/contributions/{id}/decline` | `contributions.moderate` | Decline with reason |
| `POST` | `/contributions/bulk-approve` | `contributions.moderate` | Bulk approve |
| `POST` | `/contributions/bulk-decline` | `contributions.moderate` | Bulk decline |
| `POST` | `/contributions/{id}/assign` | `contributions.moderate` | Assign to moderator |

---

### GTFS Data

#### Stops

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/stops` |, | Paginated stop list |
| `GET` | `/stops/{id}` |, | Stop detail |
| `POST` | `/stops` | `stops.create` | Create stop |
| `PATCH` | `/stops/{id}` | `stops.edit` | Update stop |
| `DELETE` | `/stops/{id}` | `stops.delete` | Delete stop |
| `POST` | `/stops/{id}/stop-times` | `stops.edit` | Add stop time to stop |
| `GET` | `/stops/claimed` |, | Agency-claimed stops |
| `POST` | `/stops/{id}/claim` | `agencies.edit` | Claim stop for agency |
| `DELETE` | `/stops/{id}/claim` | `agencies.edit` | Release stop claim |

#### Routes

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/routes` |, | Paginated route list |
| `GET` | `/routes/{id}` |, | Route detail |
| `POST` | `/routes` | `routes.create` | Create route |
| `PATCH` | `/routes/{id}` | `routes.edit` | Update route metadata |
| `PATCH` | `/routes/{id}/stop-sequence` | `routes.edit` | Reorder stops on a route |
| `PUT` | `/routes/{id}/shape` | `routes.edit` | Save route shape (GTFS LineString) |
| `PUT` | `/routes/{id}/trip-stops` | `routes.edit` | Save canonical trip stop sequence |
| `DELETE` | `/routes/{id}` | `routes.delete` | Delete route |
| `POST` | `/routes/stops-near-line` |, | Find stops near a drawn polyline |

#### Trips

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/trips` |, | Paginated trip list |
| `GET` | `/trips/{id}` |, | Trip detail |
| `GET` | `/trips/pending-review` |, | Trips awaiting approval |
| `POST` | `/trips` | `trips.create` | Create trip |
| `PATCH` | `/trips/{id}` | `trips.edit` | Update trip metadata |
| `PUT` | `/trips/{id}/shape` | `trips.edit` | Save trip shape |
| `PUT` | `/trips/{id}/stop-times` | `trips.edit` | Save stop times |
| `POST` | `/trips/{id}/propagate-shape` | `trips.edit` | Copy shape to all sibling trips in direction |
| `POST` | `/trips/{id}/submit-for-review` | `trips.edit` | Submit draft for review |
| `POST` | `/trips/{id}/approve` | `scheduling.edit` | Approve draft trip |
| `POST` | `/trips/{id}/reject` | `scheduling.edit` | Reject draft trip |
| `DELETE` | `/trips/{id}` | `trips.delete` | Delete trip |
| `POST` | `/trips/stops-near-line` |, | Find stops near a drawn polyline |

#### Trip Frequencies

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/trips/{tripId}/frequencies` |, | List frequency bands |
| `POST` | `/trips/{tripId}/frequencies` | `trips.edit` | Add frequency band |
| `PATCH` | `/trips/{tripId}/frequencies/{id}` | `trips.edit` | Update frequency band |
| `DELETE` | `/trips/{tripId}/frequencies/{id}` | `trips.edit` | Delete frequency band |

#### Service Calendars

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/service-calendars` |, | All service calendars |
| `GET` | `/service-calendars/{id}` |, | Calendar detail |
| `POST` | `/service-calendars` | `calendars.create` | Create calendar |
| `PATCH` | `/service-calendars/{id}` | `calendars.edit` | Update calendar |
| `DELETE` | `/service-calendars/{id}` | `calendars.delete` | Delete calendar |
| `POST` | `/service-calendars/{id}/exceptions` | `calendars.edit` | Add service exception date |
| `DELETE` | `/service-calendars/{id}/exceptions/{eid}` | `calendars.edit` | Remove exception |
| `POST` | `/service-calendars/{id}/exceptions/bulk` | `calendars.edit` | Bulk add exceptions |

#### Route Patterns

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/route-patterns` |, | All route patterns |
| `POST` | `/route-patterns` | `network.edit` | Create pattern |
| `PATCH` | `/route-patterns/{id}` | `network.edit` | Update pattern |
| `PUT` | `/route-patterns/{id}/stops` | `network.edit` | Save pattern stop sequence |
| `DELETE` | `/route-patterns/{id}` | `network.edit` | Delete pattern |

---

### GTFS Export & Validation

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/gtfs/status` |, | GTFS build status + last sync time |
| `POST` | `/gtfs/validate` | `gtfs.view` | Run internal GTFS validator (9 checks) |
| `GET` | `/gtfs/official-validate` | `gtfs.view` | Run Google GTFS Validator JAR (cached 30 min) |
| `POST` | `/gtfs/export` | `gtfs.sync` | Export GTFS + trigger OTP rebuild |
| `POST` | `/gtfs/export-as` | `gtfs.export` | Download as `gtfs`, `gtfs-flex`, `excel`, or `netex` |
| `GET` | `/otp/status` |, | OTP health + last build log |
| `GET` | `/otp/log` |, | Full OTP build log |
| `POST` | `/otp/sync` | `gtfs.sync` | Manually trigger OTP graph rebuild |
| `POST` | `/otp/cancel` | `gtfs.sync` | Cancel running OTP sync |

---

### Data Quality

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/quality/score` |, | Overall score (0–100) + 6 metrics (cached 1 h) |
| `GET` | `/quality/drill-down?metric=X` |, | Entity list for one metric |
| `GET` | `/quality/shape-inspector?trip_id=X` |, | Stop gaps, teleports, reversals for a trip |
| `GET` | `/quality/duplicate-stops?radius=N` |, | Stop pairs within N metres (default 50) |
| `POST` | `/quality/merge-stops` | `quality.fix` | Merge duplicate into canonical |
| `POST` | `/stops/{id}/snap` | `quality.fix` | Snap stop to nearest road |

---

### Network Analysis

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/network/graph` |, | Route-stop graph (nodes + edges) |
| `GET` | `/network/coverage` |, | Stop coverage GeoJSON |
| `POST` | `/network/isochrone` |, | Walk-shed isochrone from a point |
| `GET` | `/network/desire-lines` |, | Origin–destination flow lines |
| `GET` | `/network/transfer-graph` |, | Transfer connection graph |
| `GET` | `/network/cross-agency-transfers` |, | Inter-agency transfer points |
| `GET` | `/network/agencies` |, | Agency layer for the map |
| `GET` | `/network/modal-layers` |, | OSM multi-modal layers |
| `POST` | `/network/modal-layers/refresh-osm` | `network.edit` | Refresh OSM layer cache |
| `GET` | `/network/snapshots` |, | Saved network snapshots |
| `POST` | `/network/snapshots` | `network.edit` | Create network snapshot |
| `GET` | `/network/snapshots/{id}` |, | Snapshot detail |

#### Corridors

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/corridors` |, | Corridor list |
| `GET` | `/corridors/{id}` |, | Corridor detail + shape |
| `POST` | `/corridors` | `network.edit` | Create corridor |
| `PATCH` | `/corridors/{id}` | `network.edit` | Update corridor |
| `PUT` | `/corridors/{id}/shape` | `network.edit` | Save corridor shape |
| `DELETE` | `/corridors/{id}` | `network.edit` | Delete corridor |
| `GET` | `/corridors/{id}/routes` |, | Routes running through corridor |
| `POST` | `/corridors/{id}/routes` | `network.edit` | Attach route to corridor |
| `DELETE` | `/corridors/{id}/routes/{rid}` | `network.edit` | Detach route |

#### Scenarios

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/scenarios` |, | Planning scenarios list |
| `GET` | `/scenarios/{id}` |, | Scenario detail |
| `POST` | `/scenarios` | `network.edit` | Create scenario |
| `PATCH` | `/scenarios/{id}` | `network.edit` | Update scenario |
| `DELETE` | `/scenarios/{id}` | `network.edit` | Delete scenario |
| `POST` | `/scenarios/{id}/overrides` | `network.edit` | Add route/stop override |
| `DELETE` | `/scenarios/{id}/overrides/{oid}` | `network.edit` | Remove override |
| `GET` | `/scenarios/{id}/compare` |, | Compare scenario vs baseline |
| `POST` | `/scenarios/{id}/publish` | `network.publish_scenario` | Publish scenario to live network |

---

### Timetable & Scheduling

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/routes/{id}/timetable` |, | Timetable data for a route |
| `PUT` | `/routes/{id}/timetable` | `scheduling.edit` | Save timetable |
| `POST` | `/scheduling/optimize-headway` | `scheduling.edit` | Generate optimised headway suggestions |
| `GET` | `/scheduling/layover-analysis` |, | Layover times per terminal |
| `GET` | `/scheduling/blocks` |, | Vehicle block assignments |

---

### Fares

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST` | `/fares/zones` | `fares.edit` | Fare zones CRUD |
| `GET/POST/DELETE` | `/fares/attributes` | `fares.edit` | Fare attributes CRUD |
| `GET/POST/DELETE` | `/fares/rules` | `fares.edit` | Fare rules CRUD |
| `GET/POST/DELETE` | `/fares/route-fares` | `fares.edit` | Route-based fares CRUD |
| `GET/POST/PATCH/DELETE` | `/fares/modifiers` | `fares.edit` | Fare modifiers (concession, peak, etc.) |
| `POST` | `/fares/modifiers/{id}/toggle` | `fares.edit` | Enable / disable a modifier |
| `GET` | `/fares/preview` |, | Simulate fare for an itinerary |
| `GET` | `/fares/export` | `fares.edit` | Export GTFS fare files |

---

### Accessibility (Interop)

GTFS-flex pathways and station levels for accessibility mapping.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH/DELETE` | `/network/interop` | `interop.edit` | Interop entries CRUD |
| `GET/POST/PATCH/DELETE` | `/network/levels` | `interop.edit` | Station levels CRUD |
| `GET/POST/PATCH/DELETE` | `/network/pathways` | `interop.edit` | Pathways CRUD |
| `GET` | `/network/pathways/export` | `interop.edit` | Export pathway GTFS files |

---

### Fleet Management

#### Vehicles

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/vehicles` |, | Paginated vehicle list |
| `GET` | `/vehicles/{id}` |, | Vehicle detail |
| `POST` | `/vehicles` | `fleet.edit` | Register vehicle |
| `PATCH` | `/vehicles/{id}` | `fleet.edit` | Update vehicle |
| `DELETE` | `/vehicles/{id}` | `fleet.edit` | Remove vehicle |
| `GET` | `/vehicles/import/sample` | `fleet.edit` | Download import CSV template |
| `POST` | `/vehicles/import/preview` | `fleet.edit` | Preview CSV import |
| `POST` | `/vehicles/import/confirm` | `fleet.edit` | Confirm CSV import |
| `GET` | `/compliance/expiry-summary` |, | Document expiry summary |

#### Vehicle Documents & Devices

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH/DELETE` | `/vehicles/{id}/documents` | `fleet.edit` | Vehicle document CRUD |
| `GET/POST/PATCH/DELETE` | `/vehicles/{id}/devices` | `fleet.edit` | IoT device CRUD |
| `POST` | `/vehicles/{id}/devices/{did}/rotate-token` | `fleet.edit` | Rotate device API token |

#### Drivers & Conductors

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH/DELETE` | `/drivers` | `fleet.edit` | Driver CRUD |
| `GET/POST/PATCH/DELETE` | `/conductors` | `fleet.edit` | Conductor CRUD |

#### Shifts

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/shifts` |, | Shift list |
| `GET` | `/shifts/uncovered` |, | Trips with no assigned shift |
| `POST/PATCH/DELETE` | `/shifts` | `fleet.edit` | Shift CRUD |
| `POST` | `/shifts/{id}/start` | `fleet.edit` | Start a shift |
| `POST` | `/shifts/{id}/end` | `fleet.edit` | End a shift |

#### Vehicle Owners

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH/DELETE` | `/fleet/owners` | `fleet.edit` | Owner CRUD |
| `POST` | `/fleet/owners/{id}/photo` | `fleet.edit` | Upload owner photo |
| `GET` | `/fleet/owners/{id}/summary` |, | Owner revenue + vehicle summary |
| `GET/POST/DELETE` | `/fleet/owners/{id}/documents` | `fleet.edit` | Owner documents CRUD |

#### Expenses & Maintenance

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/DELETE` | `/fleet/expenses` | `fleet.edit` | Vehicle expense CRUD |
| `GET` | `/fleet/expenses/summary` |, | Expense summary by category |
| `GET/POST/PATCH/DELETE` | `/fleet/maintenance` | `fleet.edit` | Maintenance window CRUD |

---

### Real-Time Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/ops/live/positions` | Live vehicle positions |
| `GET` | `/ops/live/ghost-trips` | Scheduled trips with no vehicle |
| `GET` | `/ops/live/stats` | Live ops dashboard stats |
| `GET` | `/ops/performance` | Delay dashboard |
| `GET` | `/ops/performance/heatmap` | Delay heatmap |
| `GET` | `/ops/performance/worst-routes` | Worst on-time-performance routes |
| `GET` | `/ops/positions/history` | Historical vehicle positions |
| `GET` | `/ops/positions/dates/{vehicleId}` | Days for which position data is available |

#### Stage Queues

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/stage-queues` |, | Active queue at a terminus |
| `POST` | `/stage-queues` | `ops.view` | Add vehicle to queue |
| `POST` | `/stage-queues/reorder` | `ops.view` | Reorder queue |
| `POST` | `/stage-queues/{id}/depart` | `ops.view` | Mark vehicle as departed |
| `POST` | `/stage-queues/{id}/skip` | `ops.view` | Skip a vehicle in queue |

#### Service Alerts

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH/DELETE` | `/ops/alerts` | `ops.manage_alerts` | Alert CRUD |
| `POST` | `/ops/alerts/{id}/activate` | `ops.manage_alerts` | Activate alert |
| `POST` | `/ops/alerts/{id}/expire` | `ops.manage_alerts` | Expire alert |

#### Incidents

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH` | `/ops/incidents` | `ops.manage_incidents` | Incident CRUD |
| `GET` | `/ops/incidents/stats` |, | Incident statistics |
| `POST` | `/ops/incidents/{id}/resolve` | `ops.manage_incidents` | Resolve incident |
| `POST` | `/ops/incidents/{id}/assign` | `ops.manage_incidents` | Assign incident |

#### SLA Rules

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST/PATCH/DELETE` | `/ops/sla` | `ops.view` | Route SLA rules CRUD |

---

### Ledger & Revenue

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST` | `/ledger/split-configs` | `ledger.configure` | Revenue split configuration per agency |
| `POST` | `/ledger/daily-levy` | `ledger.configure` | Apply daily SACCO levy across agency vehicles |
| `GET` | `/ledger/wallets` |, | Agency wallet list |
| `GET` | `/ledger/wallets/{id}/transactions` |, | Transaction history for a wallet |
| `GET` | `/ledger/fleet-revenue` |, | Fleet-wide revenue summary |
| `GET` | `/ledger/revenue-trend` |, | Revenue trend over time |
| `GET` | `/ledger/vehicles/{id}/revenue` |, | Per-vehicle revenue breakdown |
| `GET` | `/ledger/route-revenue` |, | Revenue per route |
| `POST` | `/ledger/test-split` | `ledger.configure` | Simulate a revenue split calculation |
| `GET/POST/PATCH` | `/banking` | `ledger.configure` | Daily banking records CRUD |
| `GET` | `/banking/summary` |, | Banking summary by period |
| `GET` | `/banking/trends` |, | Banking trend chart |
| `GET/POST` | `/vehicle-targets` | `fleet.edit` | Daily revenue targets per vehicle |
| `POST` | `/payroll/generate` | `ledger.view` | Generate payroll for a period |

---

### SACCO Members

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/members/me` | `profile.view` | Own member profile |
| `GET` | `/members/me/fees` | `profile.view` | Own fee history |
| `GET` | `/members` | `members.view` | Paginated member list |
| `GET` | `/members/export` | `members.view` | Export members CSV |
| `GET/POST` | `/members/import/*` | `members.manage` | CSV import flow (sample → preview → confirm) |
| `POST` | `/members` | `members.manage` | Register member |
| `GET/PATCH` | `/members/{id}` | `members.view` | Member detail + update |
| `POST` | `/members/{id}/vet` | `members.manage` | Submit vetting decision |
| `POST` | `/members/{id}/activate` | `members.manage` | Activate membership |
| `POST` | `/members/{id}/suspend` | `members.manage` | Suspend membership |
| `POST` | `/members/{id}/reinstate` | `members.manage` | Reinstate membership |
| `POST` | `/members/{id}/account` | `members.manage` | Create user account for member |
| `GET/POST` | `/members/{id}/fees` | `members.manage` | Fee records CRUD |
| `POST/DELETE` | `/members/{id}/documents` | `members.manage` | Member document upload/delete |

---

### Agencies

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET/POST` | `/agencies` | `agencies.create` | Agency list + create |
| `PATCH` | `/agencies/{id}` | `agencies.edit` | Update agency |
| `POST` | `/agencies/{id}/logo` | `agencies.edit` | Upload agency logo |
| `DELETE` | `/agencies/{id}` | `agencies.delete` | Delete agency |
| `GET` | `/agencies/{id}/onboarding-status` |, | Onboarding checklist |
| `POST` | `/agencies/{id}/complete-onboarding` | `agencies.edit` | Mark onboarding complete |
| `GET` | `/agencies/{id}/operated-routes` |, | Routes operated by agency |
| `GET` | `/agencies/{id}/available-routes` |, | Unclaimed routes available to claim |
| `POST` | `/agencies/{id}/operated-routes` | `agencies.edit` | Claim routes for agency |
| `DELETE` | `/agencies/{id}/operated-routes/{route}` | `agencies.edit` | Release route claim |
| `POST` | `/agencies/{id}/impersonate` | `access.impersonate` | Impersonate agency context |
| `GET/POST/DELETE` | `/invitations` | `agencies.edit` | Staff invitation CRUD |
| `GET` | `/audit-logs` | `settings.view` | Audit event log |
| `GET` | `/route-licenses` |, | Route operating licenses |
| `POST/PATCH/DELETE` | `/route-licenses` | `agencies.edit` | License CRUD |

---

### Access Management (RBAC)

Built on Spatie Laravel Permission. 10 roles, 54 permissions.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/access/roles` | `access.view` | All roles + permissions |
| `GET` | `/access/roles/{role}` | `access.view` | Role detail |
| `PUT` | `/access/roles/{role}/permissions` | `access.manage` | Update role permissions |
| `GET` | `/access/users` | `access.view` | Users with their roles |
| `POST` | `/access/users` | `access.manage` | Create console user |
| `GET` | `/access/users/{user}` | `access.view` | User role + permissions |
| `PUT` | `/access/users/{user}/role` | `access.manage` | Assign role |
| `PUT` | `/access/users/{user}/permissions` | `access.manage` | Override permissions |
| `PUT` | `/access/users/{user}/agencies` | `access.manage` | Set agency scope |
| `DELETE` | `/access/users/{user}` | `access.manage` | Revoke console access |

---

### Analytics

Throttled at 30 req/min. Results cached 5 min.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/analytics/overview` | Platform-wide KPI summary |
| `GET` | `/analytics/journeys` | Journey volume + success rate trend |
| `GET` | `/analytics/searches` | Search query analytics |
| `GET` | `/analytics/contributions` | Contribution submission + approval rates |
| `GET` | `/analytics/user-growth` | User registration trend |
| `GET` | `/analytics/trip-variance` | Trip schedule variance analysis |

### Notifications

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/notifications` |, | Sent notification log |
| `POST` | `/notifications/broadcast` | `notifications.send` | Broadcast push notification to all users |
| `POST` | `/broadcasts/fleet` | `notifications.send` | Broadcast to fleet personnel |

---

## Services

### Core Services

| Service | Purpose |
|---------|---------|
| `TransitEngineService` | Central routing proxy. Calls OTP with `mode=TRANSIT,WALK`, `maxWalkDistance=1500`, `walkReluctance=13.5`, `numItineraries=config('transit.otp.num_itineraries', 5)`. Redis-cached 5 min. Nighttime mode: forces 14:00 when local time is 20:00–04:00. Stop proximity fallback via KNN. |
| `AiAssistantService` | Manages the Kwame conversational pipeline. Dual-tool Gemini architecture forces `calculate_route` or `chat_or_clarify` on every turn. |
| `LedgerService` | Revenue split processing, percentage mode and Lengo (fixed target) mode. Daily SACCO levy application. UUID wallet creation and management. |
| `WalkingService` | Walking leg routing via Google Directions API. Results cached per origin/destination pair in `cached_walking_routes`. |
| `LocationService` | Tiered stop lookup: exact → starts-with → contains (ILIKE on local DB). Falls back to Mapbox Geocoding when fewer than 3 local results. `pg_trgm` similarity for fuzzy duplicate detection. |
| `GeocodingService` | Server-side geocoding via Mapbox. Results cached 24 h. |
| `StorageService` | Single entry point for all R2 file I/O. Handles upload, delete, and URL resolution across three storage generations (R2, legacy public, legacy uploads). |
| `GtfsExportService` | Generates standards-compliant GTFS zip from PostGIS. Called by OTP sync and multi-format exporter. |
| `GtfsValidatorService` | Runs 9 internal data-quality checks (orphan stops, missing shapes, out-of-bounds, duplicates, etc.). |
| `GtfsOfficialValidatorService` | Wraps the [Google GTFS Validator](https://github.com/MobilityData/gtfs-validator) Java CLI JAR. Results cached 30 min. Returns `{ available: false }` if JAR not configured. |
| `DataQualityService` | Computes 6 metrics (see table below). Results cached 1 h under `quality:score`. |
| `OtpDeliveryService` | Delivers generated GTFS zip to the OTP container and triggers a graph rebuild. Polls health until OTP is live. |
| `FareService` | Resolves applicable fares for a planned itinerary (zones + attributes + rules + modifiers). |
| `NetworkAnalysisService` | Stop coverage GeoJSON and transfer graph computation. |
| `NetworkSnapshotService` | Records immutable network snapshots on every stop/route/trip change. |
| `IsochroneService` | Walk-shed reachability maps from a given origin point. |
| `ContributionService` | Creates contributions, awards community points + streak bonus on approval, checks badge thresholds. `decline()` sets status to `rejected` and dispatches a rejection push to the author. |
| `ReportService` | Viewport-scoped transit reports with vote tallying. Dispatches a +5 pts push when a report's upvote count first crosses 5. |
| `PushNotificationService` | Sends push notifications via Expo Push API. Dispatches `SendPushNotificationJob`. |
| `AuditService` | Logs actor + action + target to `audit_logs`. |
| `ImportService` | Generic CSV import pipeline: temp-file staging → preview → confirm (used by vehicles, drivers, conductors, members). |
| `CoverageService` | Network coverage GeoJSON for the public map layer. |
| `DesireLineService` | Origin–destination flow line computation from journey logs. |
| `RoadSnapperService` | Pluggable road-snapping (driver: `mapbox` · `google` · `none`). Used for stop snap and quality fixes. |
| `OtpService` | SMS OTP generation, hashing, and verification for phone auth. |
| `SmsService` | Africa's Talking SMS dispatch. |

#### DataQualityService Metrics

| Metric | Type | Description |
|--------|------|-------------|
| `routes_with_shapes` | Positive | % routes where ≥ 1 trip has a shape |
| `trips_with_full_stop_times` | Positive | % scheduled trips with ≥ 2 stop_times |
| `stops_within_bounds` | Positive | % stops inside Kenya bbox (`ST_Within`) |
| `valid_service_refs` | Positive | % trips whose `service_id` exists in `service_calendars` |
| `orphan_shapes` | Inverse | Count of shapes with no referencing trip |
| `duplicate_stop_pairs` | Inverse | Count of stop pairs within 50 m (`ST_DWithin` on `geography`) |

**Overall score**: weighted avg of the 4 positive metrics − (orphans × 0.5) − (duplicates × 1.0), clamped to `[0, 100]`.

### AI Services (`Services/AI/`)

| Service | Purpose |
|---------|---------|
| `GoogleLlmService` | Gemini text generation and streaming. Used by `AiAssistantService`. |
| `GoogleTtsService` | Google Cloud Text-to-Speech → base64 MP3. |
| `GoogleSttService` | Google Cloud Speech-to-Text → transcript. |
| `GoogleCloudAuth` | Authenticates as a GCP service account from `GCP_KEY_PATH`. |

### Export Services (`Services/Export/`)

```
ExporterContract (interface)
  export() → string   ← absolute path to generated file
  getMimeType() → string
  getFilename() → string

GtfsExporter        ← wraps GtfsExportService (standard GTFS zip)
GtfsFlexExporter    ← GTFS + locations.geojson for demand-responsive extensions
ExcelExporter       ← OpenSpout XLSX; sheets: agencies, routes, trips, stops, stop_times
NeTExExporter       ← SimpleXMLElement; ResourceFrame + SiteFrame + ServiceFrame stub
ExporterFactory     ← make(string $format): ExporterContract
```

### StorageService, R2 Setup

R2 is an S3-compatible object store with zero egress fees. The Laravel filesystem disk `r2` is configured in `config/filesystems.php`:

```php
'r2' => [
    'driver'   => 's3',
    'key'      => env('CLOUDFLARE_R2_ACCESS_KEY_ID'),
    'secret'   => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY'),
    'region'   => 'auto',
    'bucket'   => env('CLOUDFLARE_R2_BUCKET'),       // navigo-files
    'endpoint' => env('CLOUDFLARE_R2_ENDPOINT'),
    'url'      => env('CLOUDFLARE_R2_URL'),           // https://files.navigo.co.ke
    'use_path_style_endpoint' => true,
]
```

All uploaded files are served through the `files.navigo.co.ke` custom domain. No file bytes transit through the Laravel process after upload.

#### StorageService API

| Method | Signature | Description |
|--------|-----------|-------------|
| `upload` | `upload(UploadedFile $file, string $folder): string` | Store on R2, return public HTTPS URL |
| `delete` | `delete(?string $url): void` | Safe delete across R2 + both legacy disk generations |
| `url` | `url(string $path): string` | Bucket-relative path → public URL |
| `relativePath` | `relativePath(string $url): string` | Public URL → bucket-relative path |

#### R2 Folder Conventions

| Entity | Prefix |
|--------|--------|
| User avatars | `avatars/{user_id}/` |
| Agency logos | `agency-logos/{agency_id}/` |
| Owner photos | `owner-photos/{owner_id}/` |
| Vehicle documents | `vehicle-docs/{vehicle_id}/` |
| Owner documents | `owner-documents/{owner_id}/` |
| Member documents | `member-docs/{member_id}/` |

---

## Background Jobs

| Job | Queue | Timeout | Purpose |
|-----|-------|---------|---------|
| `OtpSyncJob` | `otp` | 20 min | Full GTFS pipeline: export → validate → deliver → rebuild graph → health-poll. Flushes Redis journey cache on success. |
| `LogJourneyJob` | `default` |, | Persist journey log for analytics and desire-line computation |
| `SendPushNotificationJob` | `default` |, | Dispatch Expo push notification |
| `AggregateDelayReportsJob` | `default` |, | Aggregate delay reports into on-time performance records |
| `ComputeOnTimePerformanceJob` | `default` |, | Compute OTP metrics per trip |
| `CheckHeadwaySlaJob` | `default` |, | Evaluate headway SLA violations |
| `CheckIncidentEscalationsJob` | `default` |, | Auto-escalate unresolved incidents past thresholds |
| `PurgeOldPositionsJob` | `default` |, | Delete stale vehicle position records |
| `PurgeOldTripUpdatesJob` | `default` |, | Delete stale GTFS-RT trip updates |

Production queue worker (from `docker-compose.prod.yml`):
```bash
php artisan queue:work --queue=otp,default --tries=1 --timeout=0
```

---

## Artisan Commands

### `patterns:generate`

Auto-derives canonical route patterns from existing trip `stop_times` data.

```bash
php artisan patterns:generate           # generate missing patterns
php artisan patterns:generate --force   # clear all and regenerate
```

**Algorithm**: for each `(route_id, direction_id)` pair, groups trips by their stop-ID sequence fingerprint. The most-common sequence becomes the canonical `RoutePattern`; minority variants are stored as non-canonical. Uses chunked inserts (500 rows/chunk).

### `compliance:send-alerts`

Sends compliance expiry alerts to agency owners and fleet managers.

```bash
php artisan compliance:send-alerts
php artisan compliance:send-alerts --dry-run   # preview without sending
```

Warns at 30-day, 7-day, and 1-day thresholds for vehicle documents.

### `db:seed --class=GtfsExcelSeeder`

Streams GTFS data from `storage/app/gtfs/*.xlsx` into the database using OpenSpout generators (near-zero memory). Seeds: routes → shapes (PostGIS aggregation) → trips → stops → stop_times → trip_frequencies.

---

## Data Models

GTFS-aligned schema on PostgreSQL 16 + PostGIS 3.4. **71 models** across transit, fleet, community, finance, and operations.

### Transit (GTFS)

| Model | Table | Key Fields |
|-------|-------|------------|
| `Stop` | `stops` | `id` (string), `name`, `location` (PostGIS Point 4326), `route_ids`, `trip_ids` |
| `Route` | `routes` | `route_id`, `short_name`, `long_name`, `type`, `color`, `agency_id` |
| `Trip` | `trips` | `trip_id`, `route_id`, `service_id`, `headsign`, `direction_id`, `shape_id`, `scheduling_type` |
| `StopTime` | `stop_times` | `trip_id`, `stop_id`, `arrival_time`, `departure_time`, `stop_sequence` |
| `Shape` | `shapes` | `shape_id`, `path` (PostGIS LineString 4326) |
| `TripFrequency` | `trip_frequencies` | `trip_id`, `start_time`, `end_time`, `headway_secs` |
| `ServiceCalendar` | `service_calendars` | `service_id`, `monday`..`sunday`, `start_date`, `end_date` |
| `ServiceException` | `service_exceptions` | `service_id`, `date`, `exception_type` |
| `RoutePattern` | `route_patterns` | `route_id`, `name`, `direction_id`, `is_canonical` |
| `RoutePatternStop` | `route_pattern_stops` | `route_pattern_id`, `stop_id`, `stop_sequence`, `timepoint` |

### Agency & Network

| Model | Table | Notes |
|-------|-------|-------|
| `Agency` | `agencies` | `agency_id` (string PK), `name`, `logo_url` |
| `Corridor` | `corridors` | `corridor_id` (string PK), PostGIS LineString path |
| `NetworkSnapshot` | `network_snapshots` | Immutable JSON snapshot of network state |
| `NetworkScenario` | `network_scenarios` | Planning scenario with override set |
| `FareZone` / `FareAttribute` / `FareRule` / `FareModifier` | fares_* | GTFS fare schema + modifier extensions |

### Fleet

| Model | Table | Notes |
|-------|-------|-------|
| `Vehicle` | `vehicles` | Plate, type, capacity, compliance expiry fields |
| `VehicleOwner` | `vehicle_owners` | Owner profile; HasMany Vehicles |
| `Driver` / `Conductor` | `drivers` / `conductors` | Staff roster |
| `Shift` | `shifts` | Driver ↔ Vehicle ↔ Route assignment |
| `VehicleDocument` | `vehicle_documents` | NTSA, insurance, etc. |
| `FleetDevice` | `fleet_devices` | IoT GPS device, rotatable API token |
| `VehiclePosition` | `vehicle_positions` | Real-time GPS records |
| `VehicleExpense` | `vehicle_expenses` | Fuel, maintenance costs |
| `MaintenanceWindow` | `maintenance_windows` | Scheduled maintenance |
| `DailyBanking` | `daily_banking` | Daily revenue banking records |
| `VehicleTarget` | `vehicle_targets` | Daily revenue targets |

### Finance

| Model | Table | Notes |
|-------|-------|-------|
| `Wallet` | `wallets` | UUID primary key (`HasUuids`), agency-scoped |
| `WalletTransaction` | `wallet_transactions` | UUID PK, credit/debit entries |
| `SplitConfig` | `split_configs` | Revenue split configuration per agency |
| `PaymentSplit` | `payment_splits` | Individual split disbursement records |

### Community

| Model | Table | Notes |
|-------|-------|-------|
| `Contribution` | `contributions` | Crowdsourced data from mobile users |
| `ContributionVote` | `contribution_votes` | Up/downvotes on contributions |
| `TransitReport` | `transit_reports` | Hazard, delay, incident reports |
| `ReportVote` | `report_votes` | Confirmation votes on reports |
| `Badge` / `UserBadge` | `badges` / `user_badges` | Gamification |
| `JourneyFeedback` | `journey_feedbacks` | Post-journey ratings |
| `JourneyLog` | `journey_logs` | Analytics + desire-line source |

### Auth & Users

| Model | Table | Notes |
|-------|-------|-------|
| `User` | `users` | `HasApiTokens`, `HasRoles` (Spatie), `Notifiable` |
| `UserAgencyScope` | `user_agency_scopes` | Restricts operator users to their agencies |
| `SaccoMember` | `sacco_members` | SACCO membership profile, linked to User |
| `MemberFee` / `MemberDocument` / `MemberVetting` | member_* | SACCO member lifecycle |
| `StaffInvitation` | `staff_invitations` | Token-based console invitations |
| `OtpCode` | `otp_codes` | Phone verification OTP |
| `DeviceToken` | `device_tokens` | Expo push tokens |
| `SavedPlace` / `SavedJourney` | saved_* | User favourites |

### Operations & Caching

| Model | Table | Notes |
|-------|-------|-------|
| `ServiceAlert` | `service_alerts` | GTFS-RT-compatible service alerts |
| `Incident` | `incidents` | Operational incidents with escalation |
| `StageQueue` | `stage_queues` | Vehicle queuing at termini |
| `RouteLicense` | `route_licenses` | Regulatory route licenses |
| `OtpLog` | `otp_logs` | OTP build status, duration, errors |
| `CachedWalkingRoute` | `cached_walking_routes` | Google Directions walking legs |
| `CachedSnappedPolyline` | `cached_snapped_polylines` | Snapped road polylines |

The `Stop.location` and `Shape.path` columns are PostGIS geometry columns. All proximity queries use `ST_DWithin` on the `::geography` cast for accurate metre-based distances.

### Observers

| Observer | Model | Triggers |
|----------|-------|---------|
| `StopObserver` | Stop | Records network snapshot on create/update/delete |
| `RouteObserver` | Route | Records network snapshot |
| `TripObserver` | Trip | Records network snapshot |
| `ShapeObserver` | Shape | Records network snapshot |
| `VehicleObserver` | Vehicle | Creates agency wallet on `created`; logs compliance alerts |
| `AgencyObserver` | Agency | Creates agency wallet on `created` |

---

## Getting Started

### Prerequisites

- PHP 8.3 + Composer
- Docker (PostgreSQL + PostGIS, Redis, OTP)
- Java 11+ (optional, only for Google GTFS Validator JAR)

### Installation

```bash
git clone https://github.com/Navigo-Kenya/navigo-api
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

# Start all services (HTTP + queue + logs + Vite)
composer dev
```

### OpenTripPlanner

```bash
cd ../nairobi-otp
docker compose up -d   # OTP on http://localhost:8080
```

Place `*.zip` (GTFS) and `nairobi.osm.pbf` in `nairobi-otp/data/`. Graph builds on first startup (~3–5 min, 6 GB heap).

### Scripts

| Script | Purpose |
|--------|---------|
| `scripts/setup-server.sh` | Initial server provisioning (Docker, Caddy, firewall) |
| `scripts/deploy.sh` | Git pull, rebuild images, run migrations, warm caches |
| `scripts/otp-rebuild.sh` | Build OTP graph, backup current `graph.obj`, restart serve container |
| `scripts/backup-setup.sh` | One-time backup configuration (rclone + cron) |
| `scripts/backup.sh` | Run a full backup to Cloudflare R2 |
| `scripts/restore.sh` | Restore from R2 backup |
| `scripts/refresh.sh` | Quick dev environment refresh |

### Production Docker Stack (`docker-compose.prod.yml`)

| Service | Image | Role |
|---------|-------|------|
| `caddy` | caddy:2-alpine | Reverse proxy + auto TLS (ports 80, 443, HTTP/3) |
| `app` | Dockerfile | Laravel PHP-FPM |
| `queue` | Dockerfile | Queue worker (`otp,default` queues) |
| `postgres` | postgres:16 + PostGIS | Primary database (internal only) |
| `redis` | redis:7-alpine | Cache, queues, sessions (256 MB LRU, AOF) |
| `otp` | opentripplanner:2.4.0 | Transit routing server (`--load`) |
| `otp-builder` | opentripplanner:2.4.0 | Graph builder, profile `build` only |
| `pgadmin` | pgadmin4 | Database admin UI (internal only) |

### Production vs Local Differences

| Variable | Local | Production |
|----------|-------|------------|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `DB_HOST` | `127.0.0.1` | `postgres` (Docker service name) |
| `REDIS_CLIENT` | `predis` | `phpredis` (C extension) |
| `REDIS_HOST` | `127.0.0.1` | `redis` (Docker service name) |
| `CACHE_STORE` | `database` | `redis` |
| `QUEUE_CONNECTION` | `database` | `redis` |
| `OTP_BASE_URL` | `http://127.0.0.1:8080` | `http://otp:8080` |
| `OTP_DATA_PATH` | `D:\React\nairobi-otp\data` | `/opt/hopln/otp-data` |

---

## Environment Variables

```env
# ── Application ────────────────────────────────────────────────────
APP_KEY=
APP_ENV=local
APP_URL=http://localhost:8000
APP_DEBUG=true

# ── Database (PostgreSQL + PostGIS) ────────────────────────────────
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hopln
DB_USERNAME=hopln
DB_PASSWORD=

# ── Redis (cache, queue, sessions) ─────────────────────────────────
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_STORE=redis
CACHE_PREFIX=hopln
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# ── Cloudflare R2 (object storage) ─────────────────────────────────
CLOUDFLARE_R2_ACCESS_KEY_ID=
CLOUDFLARE_R2_SECRET_ACCESS_KEY=
CLOUDFLARE_R2_BUCKET=navigo-files
CLOUDFLARE_R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
CLOUDFLARE_R2_URL=https://files.navigo.co.ke

# ── OpenTripPlanner ─────────────────────────────────────────────────
OTP_BASE_URL=http://localhost:8080
OTP_CACHE_TTL=300
OTP_DATA_PATH=../nairobi-otp/data
OTP_SYNC_TIMEOUT=120

# ── AI / Gemini ─────────────────────────────────────────────────────
GEMINI_API_KEY=

# ── Google Cloud (TTS · STT · LLM) ──────────────────────────────────
GCP_PROJECT_ID=
GCP_KEY_PATH=storage/app/gcp-key.json

# ── Google OAuth & Maps ─────────────────────────────────────────────
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_ID_IOS=
GOOGLE_CLIENT_ID_ANDROID=
GOOGLE_MAPS_API_KEY=
GOOGLE_ROADS_API_KEY=      # only if ROAD_SNAPPER_DRIVER=google
GOOGLE_PLACES_API_KEY=

# ── Apple Sign-In ───────────────────────────────────────────────────
APPLE_CLIENT_ID=com.navigo.ke
APPLE_TEAM_ID=
APPLE_KEY_ID=

# ── Mapbox (server-side geocoding + road snapping) ──────────────────
MAPBOX_API_KEY=
GEOCODER_DRIVER=mapbox
ROAD_SNAPPER_DRIVER=none   # mapbox | google | none

# ── Africa's Talking (SMS) ──────────────────────────────────────────
AT_API_KEY=sandbox
AT_USERNAME=sandbox
AT_SENDER_ID=HOPLN

# ── Push Notifications (Expo) ───────────────────────────────────────
EXPO_PUSH_URL=https://exp.host/--/api/v2/push/send
FIREBASE_CREDENTIALS=

# ── Sanctum ─────────────────────────────────────────────────────────
SANCTUM_EXPIRATION=43200   # 12 hours

# ── Google GTFS Validator (optional) ────────────────────────────────
GTFS_VALIDATOR_JAR_PATH=   # absolute path to gtfs-validator-*.jar
GTFS_VALIDATOR_JAVA_BIN=java

# ── Mail ─────────────────────────────────────────────────────────────
MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@navigo.co.ke
MAIL_FROM_NAME="Navigo"

# ── Sentry (optional, error tracking) ──────────────────────────────
# Install: run `composer require sentry/sentry-laravel` locally,
# commit composer.json + composer.lock, then `composer install` on server.
# The app boots without the package, bootstrap/app.php uses a class_exists
# guard so PHP never resolves the class unless it is installed.
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1

# ── Feature flags ────────────────────────────────────────────────────
KWAME_VISION_ENABLED=false   # enables image param in /journey/ai-plan
BRIEFING_LLM=false           # enables Gemini polish for morning briefing
```

### Cache Keys

| Key | TTL | Populated by |
|-----|-----|-------------|
| `hopln:otp:*` | 5 min | `TransitEngineService` |
| `hopln:geocode:*` | 24 h | `GeocodingService` |
| `quality:score` | 1 h | `DataQualityService::compute()` |
| `quality:official_validation` | 30 min | `GtfsOfficialValidatorService::validate()` |
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

## Backup & Restore

Three scripts in `scripts/` handle the full backup lifecycle. All backups go to **`navigo-backups`**, a dedicated private Cloudflare R2 bucket with no public access and no custom domain, separate from the `navigo-files` public bucket used by `StorageService`. Same R2 credentials, different bucket.

### What gets backed up

| Target | Destination in R2 | Retention |
|--------|-------------------|-----------|
| PostgreSQL database (`pg_dump`) | `postgres/hopln_pg_<timestamp>.sql.gz` | 30 days |
| OTP graph (`graph.obj`) | `otp/graph_<timestamp>.obj` | 14 days |
| Laravel `storage/app/` | `storage/hopln_storage_<timestamp>.tar.gz` | 7 days |
| `.env` file | `env/env_<timestamp>.env` | 90 days |

### First-time setup (run once on the server)

**Prerequisites:** Create a private `navigo-backups` bucket in the Cloudflare dashboard (no public access, no custom domain). R2 credentials must already be in `.env` (`CLOUDFLARE_R2_ACCESS_KEY_ID`, `CLOUDFLARE_R2_SECRET_ACCESS_KEY`, `CLOUDFLARE_R2_ENDPOINT`).

```bash
chmod +x scripts/backup-setup.sh
./scripts/backup-setup.sh
```

The setup script does the following automatically:
- Installs `rclone` via `apt`
- Writes `/opt/hopln/api/.rclone.conf` from `.env` credentials (mode `600`)
- Creates `/var/log/hopln-backup.log` with correct ownership
- Registers the daily cron job (`0 2 * * *`)
- Runs one test backup to verify everything works

### Manual backup

```bash
./scripts/backup.sh
```

Logs to stdout. In production it appends to `/var/log/hopln-backup.log`.

### Cron schedule

```cron
0 2 * * * /opt/hopln/api/scripts/backup.sh >> /var/log/hopln-backup.log 2>&1
```

Registered automatically by `backup-setup.sh`. Verify with `crontab -l`.

### Browse backups

```bash
rclone --config /opt/hopln/api/.rclone.conf ls r2:navigo-backups/
```

Or use the restore script's list mode:

```bash
./scripts/restore.sh list
```

### Restore

```bash
./scripts/restore.sh list                    # list all available backups
./scripts/restore.sh db                      # interactive DB restore
./scripts/restore.sh db 20240615_020000      # restore specific snapshot
./scripts/restore.sh storage                 # restore latest storage archive
./scripts/restore.sh env                     # restore latest .env to .env.restored
```

> **Warning:** `restore.sh db` drops and recreates all tables from the dump. Always run during a maintenance window. Redis journey cache is flushed automatically after restore.

### rclone config reference

Written to `/opt/hopln/api/.rclone.conf` (mode `600`) by `backup-setup.sh` and auto-regenerated by `backup.sh` if deleted:

```ini
[r2]
type = s3
provider = Cloudflare
access_key_id = <CLOUDFLARE_R2_ACCESS_KEY_ID from .env>
secret_access_key = <CLOUDFLARE_R2_SECRET_ACCESS_KEY from .env>
endpoint = <CLOUDFLARE_R2_ENDPOINT from .env>
acl = private
no_check_bucket = true
```

---

## Transit Data Strategy

Navigo's routing data originates from the **Digital Matatus project**, the first complete GTFS dataset for Nairobi's informal transit network, produced by the University of Nairobi and Columbia University. It maps every named matatu route: stop locations, route alignments, and transfer points.

**What GTFS does well in Nairobi**: stop proximity, route discovery, multi-leg journey planning. Physical route alignments change slowly, so this data is reliably accurate.

**Where static GTFS falls short**: matatus are *fill-and-go*, not scheduled. A static timetable implies a fixed departure time, a concept that does not exist in Nairobi. Two factors the Navigo algorithm accounts for that standard GTFS routing ignores:

- **Fill-Time Factor**, per-terminus dwell time before a vehicle departs (varies 2–25 min depending on time of day, route, and demand). Stored as a rolling average from GPS data once the IoT layer is live.
- **Mshukiwa Factor**, per-segment travel-time multiplier for roadside loading and alighting at non-official stops on high-demand corridors (Ngong Road, Thika Road, Mombasa Road).

The long-term roadmap includes a full GTFS-RT loop: IoT GPS devices → MQTT ingestion → live vehicle positions → OTP real-time feed → accurate departure predictions in the passenger app.

---

<div align="center">

Built with care for Nairobi's commuters.

</div>
