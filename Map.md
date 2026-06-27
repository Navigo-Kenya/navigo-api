# Map Screen — Full Technical Reference

> **Describes the current production code as of 2026-06.  
> Primary file:** `hopln/app/(tabs)/map.tsx`  
> **Map SDK:** `@rnmapbox/maps` (Mapbox GL Native)

---

## 1. Architecture Overview

The map screen is the heart of the app. It composes three subsystems:

```
map.tsx (root orchestrator)
 │
 ├── GPS & Navigation
 │    ├── useNavigation.ts          ← GPS watch, EMA smoothing, session
 │    ├── services/navigationEngine.ts  ← projection math, step/ETA engine
 │    ├── useHeadingTracker.ts      ← compass sensor → headingStore (no re-renders)
 │    └── store/headingStore.ts     ← live bearing (Zustand, imperative-only)
 │
 ├── Route Rendering
 │    ├── useRouteOverlay.ts        ← active journey → renderable data
 │    ├── components/map/RouteOverlay.tsx   ← polylines + markers
 │    ├── components/map/RouteMarkers.tsx   ← PointAnnotation components
 │    └── components/app/StopsLayer.tsx     ← Mapbox ShapeSource for stops
 │
 └── UI Panels & Overlays
      ├── components/app/MapFloatingUI.tsx        ← search bar, nav banners, FABs
      ├── components/app/JourneyDetailsSheet.tsx  ← trip details bottom sheet
      ├── components/app/RouteStepsList.tsx       ← turn-by-turn step list
      ├── components/map/ReportLayer.tsx          ← incident pins (RN view overlay)
      ├── components/app/StopQuickCard.tsx        ← compact stop card
      ├── components/app/StopDetailsSheet.tsx     ← full stop info sheet
      ├── components/app/NearestStopsSheet.tsx    ← nearby stops list
      ├── components/map/IntermStopInfoCard.tsx   ← intermediate stop info card
      ├── components/map/MapLayersSheet.tsx       ← layer toggle sheet
      ├── components/map/OfflineNotice.tsx        ← offline banner/notice
      └── components/map/SaveWall.tsx             ← auth gate for saving
```

---

## 2. Mapbox Layer Stack (bottom to top, inside MapboxMapView)

### Layer 1 — Base Map

```tsx
<MapboxMapView
  styleURL={dark ? "mapbox://styles/mapbox/dark-v11"
                 : (EXPO_PUBLIC_MAPBOX_STYLE_URL ?? "mapbox://styles/mapbox/streets-v12")}
  logoEnabled={false}
  compassEnabled={false}
  attributionEnabled={false}
  scaleBarEnabled={false}
/>
```

Light style is a custom Mapbox Studio style URL (from env). Dark style is the standard Mapbox dark-v11.
No POI suppression needed — Mapbox styles handle this via the Studio config.

---

### Layer 2 — Offline Raster Tiles (conditional)

```tsx
{!isOnline && offlinePack && (
  <RasterSource
    id="offline-tiles"
    tileUrlTemplates={[`file://${dark ? TILE_PATH_TEMPLATE_DARK : TILE_PATH_TEMPLATE_LIGHT}`]}
    tileSize={256}
  >
    <RasterLayer id="offline-raster" style={{}} />
  </RasterSource>
)}
```

Only rendered when the device is offline AND a tile pack exists. Tiles are served from local
filesystem via `file://` URL. Templates are defined in `services/offlineTiles.ts`.

---

### Layer 3 — User Location Dot

```tsx
<UserLocation visible renderMode="normal" minDisplacement={2}>
  <CircleLayer id="user-location-pulse"
    style={{ circleRadius: 18, circleColor: "#FF6F00", circleOpacity: 0.15 }} />
  <CircleLayer id="user-location-dot"
    style={{ circleRadius: 9, circleColor: "#FF6F00",
             circleStrokeColor: "#FFFFFF", circleStrokeWidth: 3 }} />
</UserLocation>
```

Mapbox's native `UserLocation` component with `renderMode="normal"` so child layers replace
the default blue dot. Uses two `CircleLayer` children:
- **Inner dot:** 9 dp radius, Hopln orange (`#FF6F00`), 3 dp white border
- **Pulse ring:** 18 dp radius, same orange at 15% opacity

`minDisplacement={2}` — dot only re-renders on native side after ≥2 m movement. No custom
async projection needed; Mapbox handles synchronous dot positioning natively, with zero JS lag.

---

### Layer 4 — RouteOverlay

Rendered by `components/map/RouteOverlay.tsx`. Receives pre-computed data from `useRouteOverlay`.

| Sub-element | Component | Style |
|---|---|---|
| Walk legs | `LineLayer` (ShapeSource) | Dashed, grey `#8E8E93`, 3 dp |
| Active walk leg | `LineLayer` | Same, full opacity |
| Past walk legs | `LineLayer` | 30% opacity |
| Transit legs | `LineLayer` | Route color, solid, 5 dp |
| Active transit leg | `LineLayer` | Same, full opacity |
| Past transit legs | `LineLayer` | 35% opacity |
| Intermediate stop dots | `CircleLayer` inside `ShapeSource` | 8 dp, route color |
| Board/alight nodes | `PointAnnotation` + `StopNodeMarker` | 30 dp circle |
| Boarding node (pulsing) | `PointAnnotation` + `TrackedNodeMarker` | Animated pulse |
| Origin pin | `PointAnnotation` + `SquarePin` | Orange square |
| Destination pin | `PointAnnotation` + `DestinationPin` | Black square + label |

**Active transit leg trimming:** During navigation, the active walk leg polyline is trimmed
behind the user (coords up to the nearest projected point are removed), so the dashed grey line
only extends forward — no visual "trail."

**`TrackedNodeMarker` pulse:** Uses `Animated.loop` with a scale 1→2.2→1 + opacity 1→0 cycle
at 850 ms for the boarding stop. `tracksViewChanges` is `true` during animation (forces Mapbox
to re-read the view each frame), set to `false` once boarding is complete.

---

### Layer 5 — StopsLayer (conditional: only when no activeJourney)

```tsx
{!activeJourney && (
  <StopsLayer allStops={allStops} viewCenter={viewCenter} viewZoom={viewZoom}
              selected={selected} onPress={handleSelectStop} />
)}
```

`StopsLayer` uses a single Mapbox `ShapeSource` + `SymbolLayer` for all stops, leveraging
Mapbox's native clustering. Zoom-adaptive filtering:

| Zoom | Viewport radius | Cluster cell |
|---|---|---|
| ≥ 16 | 550 m | none (show all) |
| ≥ 15 | 900 m | 0.002° (~220 m) |
| ≥ 14 | 1400 m | 0.004° (~440 m) |
| < 14 | 2000 m | 0.008° (~880 m) |

Stop pins are hidden below zoom 13. Selected stop is always rendered with an orange highlight
circle regardless of zoom or filter. All stops are fetched once via `StopService.getAllStops()`
with 24h AsyncStorage cache (stale-while-revalidate: cached paint first, then background refresh).

---

### Layer 6 — Dropped Pin (conditional)

```tsx
{longPressCoord && !activeJourney && (
  <PointAnnotation id="dropped-pin"
    coordinate={[longPressCoord.longitude, longPressCoord.latitude]}
    anchor={{ x: 0.5, y: 1.0 }}>
    <DestinationPin name={longPressName} />
  </PointAnnotation>
)}
```

Set on map long-press. Immediately shows "Dropped pin"; background async reverse-geocode via
`MapService.reverseGeocode()` replaces the name when it resolves. Disappears if a journey is set.

---

## 3. React Native Overlay (above MapboxMapView)

### ReportLayer

```tsx
{layers.reports && (
  <ReportLayer ref={reportLayerRef} reports={activeReports}
               mapRef={mapRef} onPress={handleReportPress} />
)}
```

Incident report pins rendered as React Native `<Pressable>` views positioned via
`mapRef.current.getPointInView(coordinate)` — NOT as Mapbox markers. This avoids
Mapbox's PointAnnotation performance issues with many dynamically-clustered pins.

**Projection trigger:** `map.tsx` calls `reportLayerRef.current?.project()` inside:
- `handleRegionChange` (throttled to once per 50 ms during pan)
- `onRegionChangeComplete` (always, when pan/zoom settles)
- When the Reports layer is toggled on (to paint immediately without waiting for a pan)

**Clustering:** O(n²) within 30 m radius. Newest report per cluster is the primary pin.
Cluster badge shows count (capped at "9+").

**Report type → icon/color:**

| Type | Icon (Ionicons) | Color |
|---|---|---|
| traffic_jam | car-outline | `#FF6F00` |
| accident | alert-circle | `#FF3B30` |
| road_blocked | close-circle | `#FF2D55` |
| stage_queue | people | `#FF9500` |
| police_check | shield | `#007AFF` |
| flooded_route | water | `#5856D6` |
| breakdown | build | `#AF52DE` |
| security | alert | `#D32F2F` |
| fare_hike | trending-up | `#30B050` |

**Viewport report fetching:** Debounced 400 ms after `onRegionChangeComplete`. Latest-wins
pattern via `reportReqId` ref (not state) — stale responses are discarded on arrival.
Results are persisted to AsyncStorage (10 min TTL) so last-seen pins are painted instantly
on the next app open.

---

## 4. Camera System

### Camera Ref

```tsx
const cameraRef = useRef<MapboxCamera>(null);
const camera = useMapCamera(mapRef, cameraRef);
```

`useMapCamera` provides two stable methods (memoized):
- `camera.animateTo({ center, zoom, heading, pitch, duration? })` → `cameraRef.current.setCamera()`
- `camera.fitCoordinates(coords, padding?)` → used on route selection to frame the full route

### Navigation Camera Interval (130 ms)

Runs only when `navigating && followMe`. Skips when `isUserGesturingRef.current` is true
(user is mid-pan/pinch — prevents camera fighting the gesture).

**Full pipeline per tick:**

```
1. Read pos from meRef.current (EMA-smoothed, written by useNavigation)
2. Read compass from useHeadingStore.getState().heading (imperative, no re-render)
3. Read gpsHeading from pos.heading

── Sensor fusion ──
4. Re-anchor every 2.5s: anchorGps = gpsHeading; anchorCmp = compass
5. compassDelta = (compass - anchorCmp + 540) % 360 - 180
6. fused = (anchorGps + compassDelta + 360) % 360
7. rawHeading = fused * gpsWeight + compass * (1 - gpsWeight)
     where gpsWeight = clamp((speed - 0.5) / 2.5, 0, 1)

── EMA smoothing ──
8. diff = ((rawHeading - smooth + 540) % 360) - 180
9. smooth = (smooth + α * diff + 360) % 360
     α = 0.75 (vehicle) or 0.88 (walk)
10. Commit smooth to 'committed' only if |Δ| ≥ 1°

── Speed-adaptive zoom ──
11. speedKph = speed * 3.6
    zoom = 19.0 (< 5 kph)
         → 19.0 - ramp to 18.0 (5–30 kph)
         → 18.0 - ramp to 16.5 (30–80 kph)
         → 16.5 - ramp to 15.5 (80–120 kph)
    clamped: [15.0, 19.0]

── Sheet-aware forward offset ──
12. mpp = cos(lat) * 156543 / 2^zoom  [metres per pixel]
13. sheetCompM = min(100, 155 * mpp)  [155px ≈ half collapsed sheet height]
14. netOffsetM = vehicle ? SH * 0.20 * mpp   [70% down screen]
              : walk    ? 50 - sheetCompM     [50m ahead, minus sheet]

── Camera target ──
15. camHRad = headingUp ? committed*π/180 : 0   [north-up vs heading-up]
16. centerLat = pos.lat + (netOffsetM/111320) * cos(camHRad)
17. centerLng = pos.lng + (netOffsetM/111320) * sin(camHRad) / cos(lat*π/180)
18. pitch = navView === 'tilted' ? (vehicle ? 60° : 45°) : 0°

── Heading dead-zone ──
19. hdgDelta = |committed - lastSentHdg| (circular)
    threshold = headingUp ? 1° : (vehicle ? 2° : 5°)
    sendHdg = hdgDelta ≥ threshold && (headingUp || speed ≥ 0.5)

── Animate ──
20. camera.animateTo({ center, zoom, pitch, ...(sendHdg ? {heading} : {}), duration: 80 })
```

**Two heading modes (`headingUp` state):**
- `false` (default) — north-up: camera heading always 0, map never rotates
- `true` — heading-up: camera rotates to face direction of travel

**Compass button cycles:**
1. `!followMe` → `setFollowMe(true)` + north-up (re-lock)
2. `followMe && !headingUp` → `setHeadingUp(true)` (engage heading-up)
3. `followMe && headingUp` → `setHeadingUp(false)` + `animateTo({heading:0})` (back to north-up)

**Dead-zone thresholds (why they differ):**
- Heading-up 1°: user wants smooth map rotation, tight threshold feels responsive
- Vehicle north-up 2°: GPS heading reliable at speed; tight is fine, prevents micro-churn
- Walk north-up 5°: GPS heading noisy at low speed; wide gate prevents map shimmy

---

## 5. GPS & Navigation (useNavigation.ts)

### Location Subscription

```ts
Location.watchPositionAsync({
  accuracy: Location.Accuracy.BestForNavigation,
  timeInterval: 1000,
  distanceInterval: 2,
}, handleLocationUpdate)
```

During navigation, background tracking is also started via `Location.startLocationUpdatesAsync`
with a foreground service notification (Android). Updates arrive via `DeviceEventEmitter`.

### EMA Smoothing (in useNavigation)

All raw GPS values pass through Exponential Moving Average filters before use:

| Value | α (walk) | α (transit/vehicle) | Notes |
|---|---|---|---|
| `latitude` | 0.25 | 0.40 | Higher α = more weight to new value |
| `longitude` | 0.25 | 0.40 | |
| `speed` | 0.35 | 0.35 | |
| `heading` | 0.30 | 0.30 | Circular EMA (handles 0/360 wrap) |

Circular EMA formula (used for heading):
```
delta = ((raw - prev + 540) % 360) - 180
smooth = (prev + α * delta + 360) % 360
```

### NavigationEngine Integration

On every GPS update, the smoothed position is passed to `NavigationEngine.update()`:

```ts
const result = engineRef.current.update(
  smoothLng, smoothLat, smoothSpeed, currentStepIdx
);
// result: { status, stepIndex, distToRoute, distRemM, etaSecs,
//           approachPhase, stopsRemaining, lastPassedStopName, ... }
```

Derived state updates from result:
- `navState.stepIndex` → step advancement
- `navState.status === 'off_route'` → triggers reroute
- `navState.status === 'arrived'` → `setTripStatus('ARRIVED')`, stops navigation
- `navState.approachPhase` → haptic feedback on phase transition
- `navState.stopsRemaining` → displayed in nav banner and step list
- `navState.stepETAs` → per-step ETA shown in RouteStepsList
- `navState.distanceToNextStepM` → distance displayed in nav banner

### Off-Route Rerouting

```ts
if (result.status === 'off_route' && !reroutingRef.current) {
  reroutingRef.current = true;
  const routes = await RouteService.calculateJourney(fromLoc, toLoc);
  if (routes.length > 0) useJourneyStore.getState().updateRoute(routes[0]);
  reroutingRef.current = false;
}
```

`updateRoute()` in journeyStore does a cheap identity check (same `summary` + `total_distance`)
before setting state — suppresses full re-renders when OTP returns the same route.

### Auto-start for AI-derived journeys

```tsx
useEffect(() => {
  if (!activeJourney) { prevJourneyRouteRef.current = null; return; }
  if (activeJourney.route === prevJourneyRouteRef.current) return;
  prevJourneyRouteRef.current = activeJourney.route;
  if (activeJourney.route.is_ai_derived) {
    setFollowMe(true);
    setNavStarted(true);
    setTimeout(() => startNavigation(), 300);
  }
}, [activeJourney, startNavigation]);
```

When Kwame (AI assistant) dispatches a journey, navigation starts automatically after 300 ms
(enough time for the map to frame the route).

---

## 6. NavigationEngine — Algorithm

### Constructor pre-computation

```
1. Deduplicate consecutive identical coordinates
2. Compute segment lengths: segLen[i] = haversine(coords[i], coords[i+1])
3. Compute cumulative distances: cumDist[i] = Σ segLen[0..i-1]
4. totalDistM = Σ all segLen
5. For each step: find closest polyline offset (step.offset)
6. For each stop in each transit step: find closest polyline offset
```

### update(lng, lat, speedMps, currentStepIdx) → EngineResult

```
1. Local search window: scan ±150 m back, ±500 m ahead of last known position
   If all projections > 100 m from user: fall back to full polyline scan
2. Advance high-water mark: hwm = max(hwm, projectedOffset − 2 m)
   (monotonic: engine never moves backward along the route)
3. Off-route detection:
     dist_to_route > 45 m → strike++
     dist_to_route ≤ 45 m → strike = 0
     strikes ≥ 3           → status = 'off_route'
4. Step advancement: scan remaining steps from currentStepIdx
     hwm ≥ step.offset − STEP_REACH_M (18 m) → advance step
5. ETA: sum remaining steps
     transit legs: scheduled duration from route data
     walk legs: distance / max(speed, MIN_WALK_SPEED = 1.4 m/s)
6. Approach phases:
     distToNextStep < 100 m → 'imminent'
     distToNextStep < 300 m → 'near'
     otherwise              → 'far'
7. Return EngineResult
```

### Stop tracking

For each transit step, the engine tracks which intermediate stops have been passed
(comparing hwm to each stop's precomputed polyline offset). `stopsRemaining` counts
how many stops remain before the alighting point. `lastPassedStopName` is the most
recently passed stop name — shown in the nav banner as "Passed: Nation Centre."

---

## 7. useRouteOverlay — Journey → Renderable Data

Called every render of map.tsx. Translates `activeJourney.route` into:

| Output | Type | Used by |
|---|---|---|
| `walkLegs` | `WalkLeg[]` | RouteOverlay (grey dashed polylines) |
| `transitLegs` | `TransitLeg[]` | RouteOverlay (colored solid polylines) |
| `nodeMarkers` | `NodeMarker[]` | RouteOverlay (board/alight circles) |
| `locMarkers` | `LocMarker[]` | RouteOverlay (origin/destination pins) |
| `intermediateStops` | `IntermediateStop[]` | RouteOverlay (small dots, tappable) |
| `steps` | `Step[]` | RouteStepsList + nav engine step index |
| `routeInfo` | `RouteInfo` | JourneyDetailsSheet header |
| `routeLoading` | `boolean` | StopQuickCard loading state |

**Build process:**
1. Walk segments → `WalkLeg` (coords from `segment.coordinates`)
2. Transit segments → `TransitLeg` (coords + route color from `segment.route_color`)
3. Board/alight stops → `NodeMarker` (from `segment.from` / `segment.to`)
4. Intermediate transit stops → `IntermediateStop` (from `segment.stops[]`, projected onto polyline via `ST_LineLocatePoint` equivalent in JS)
5. Steps for walk legs: "Walk X m to [stop name]" + sub-steps from `walk_steps`
6. Steps for transit legs: "Board Line X at [stop]" + intermediate stops + "Alight at [stop]"
7. Steps for arrival: "You have arrived"
8. `camera.fitCoordinates()` called once on first build with all route coords + 80 dp padding

---

## 8. UI Panels — Full Reference

### MapFloatingUI

The main floating UI layer. Absolutely positioned above everything. Contains:

**Search bar (top):** Always visible when not navigating. Tapping opens `/search` screen.
"Ask Kwame" chip routes to `/kwame` (AI assistant).

**Navigation banner (active nav):** Shows current step instruction + type icon, distance to next
step, "then…" chip for next step preview, step ETA. Background color is dark/charcoal.
Props consumed: `nextPreview`, `nextStep`, `distanceToNextStepM`, `stepEta`, `nextNextPreview`,
`showNavSub` (from `prefs.navHints === 'detailed'`).

**Walking sub-instruction:** Below the main banner when `navHints === 'detailed'` — shows the
`walkInstruction` (nearest upcoming turn within the current walk leg's sub-steps) and
`walkDestination` (name of the boarding stop being walked to).

**Wrong direction banner:** Red banner when `wrongDirection && navigating`. Appears above the
main banner.

**GPS lost pill:** Red pill "GPS signal lost" when `gpsLost`.

**Waiting for bus banner:** Shown when `tripStatus === 'WAITING_FOR_BUS' && navStarted`.
Pulsing orange background with the boarding stop name.

**Arrived banner:** Green success card on `navStatus === 'arrived'`.

**Speed pill:** Bottom-right, shows current speed in km/h when navigating (hidden when 0).

**Start/Stop navigation button:** Shown when a journey is active. Transitions
`WAITING_FOR_BUS ↔ IN_TRANSIT`.

**Floating action buttons (right side):**
- Recenter (when `!followMe`)
- Compass / heading toggle (always in nav mode)
- Layers toggle
- Report an incident

**Bottom offset:** Adjusted based on whether `StopQuickCard` or `StopDetailsSheet` is open,
so FABs don't overlap the card.

---

### JourneyDetailsSheet

Draggable bottom sheet with two snap points: **peek** (collapsed) and **full** (expanded).

**Collapsed view:**
- Route summary chips (Line 58, Line 23, etc.) in their route colors
- Total duration (`sToMin(total_duration)`)
- Walk distance (`mToNice(total_walk_distance)`)
- Arrival time (computed from `eta`)
- Start Navigation / Stop Navigation toggle button
- Save / Unsave button (heart icon)
- Share, Open in Maps actions

**Expanded view (scrollable):**
- Same header
- `RouteStepsList` (full turn-by-turn)

**Live updates during navigation:**
- `eta` prop drives a live countdown updating every second
- `remainingDistanceM` shown as formatted distance

---

### RouteStepsList

Timeline-style step list. Each step type renders differently:

| Step type | Visual |
|---|---|
| `origin` | Orange square dot, "From [name]" |
| `depart` | Colored circle + route badge + "Board Line X at [stop]" + stop count |
| `transit-stop` | Small dot (indented), stop name, collapsible list |
| `arrive-transfer` | Station icon, "Alight at [stop]" |
| `walk` | Dashed line + walking icon + "Walk X m to [stop]" |
| `walk-substep` | Small grey dot (indented), turn instruction, collapsible |
| `arrive` | Black square dot, destination name |

Active step (matching `nextStepIdx`) is highlighted with a subtle orange left border.
Transit steps expand to show all intermediate stops on tap.
Walk steps expand to show sub-steps (turn-by-turn directions from Google Directions API).

---

### StopQuickCard

Spring-in animated compact card shown when a stop pin is tapped. Contains:
- Stop name + coordinate badge
- Up to 3 route short-name chips in their route colors (from `stop.route_nams`)
- "Go to Stop" button → `handleGoToStop()` (calculates journey from current position)
- "View Details" button → opens `StopDetailsSheet`
- Close (X) → deselects stop, re-enables followMe

---

### StopDetailsSheet

Full-featured stop detail panel. Fetches `StopService.getStopDetails(stop.id)` on open.
Contains tabs:
- **Overview:** Route list with colors, next expected departure times (when available)
- **Photos:** Community-uploaded stop photos
- **Reviews:** Aggregate star rating + individual reviews from users
- **Report:** Quick-report flow for stop issues (missing sign, unsafe, damaged shelter)
- **Contribute:** Submit stop name correction, location correction, photo

Includes `LocationPickerModal` (mini Mapbox map for coordinate corrections) and three
`MiniSheet` modals for different contribution flows.

---

### NearestStopsSheet

Slide-up sheet listing 5 nearest stops within 2000 m. Fetched from
`StopService.getNearbyStops(lat, lng, 2000, 5)` when opened. Each row shows stop name,
distance in metres, and a "Navigate" button that triggers `handleGoToStop`.

---

### IntermStopInfoCard

Absolutely positioned card (bottom: 260) shown when an intermediate stop dot on the route
is tapped. Shows:
- Route chip (route short name + route color)
- Stop name
- Close button

---

### MapLayersSheet

Slide-up sheet with layer toggles. Current layers:

| Layer key | Label | Status |
|---|---|---|
| `reports` | Live reports | Active |
| `coolSpots` | Cool spots | "Coming Soon" |
| `heatmap` | Stop density | "Coming Soon" |
| `saved` | Saved places | "Coming Soon" |

Persisted via `useMapLayersStore` (Zustand persist middleware, AsyncStorage).

---

### OfflineNotice

Three variants, shown when `!isOnline`:

| Variant | Shown when | Content |
|---|---|---|
| `active` | Offline pack exists | Compact pill: "Offline map active" |
| `download` | Authenticated, no pack | Banner: "Download offline map" → `/(account)/offline-maps` |
| `login` | Not authenticated | Banner: "Sign in to use offline maps" → `/(auth)/login` |

---

### SaveWall

Bottom sheet shown when an unauthenticated user tries to save a journey. Prompts to sign in.
`visible` controlled by `showSaveWall` state. On dismiss → `setShowSaveWall(false)`.

---

## 9. Zustand Stores — Map Screen Reads

| Store | What map.tsx reads | Re-render impact |
|---|---|---|
| `journeyStore` | `activeJourney`, `tripStatus`, `setJourney`, `clearJourney`, `updateRoute` | Re-renders on journey change |
| `headingStore` | `heading` via `getState()` (imperative only) | **Zero re-renders** — read only inside intervals |
| `mapLayersStore` | `layers.reports` | Re-renders on layer toggle |
| `networkStore` | `isOnline` | Re-renders on connectivity change |
| `offlineMapStore` | `pack` | Re-renders on pack change |
| `savedStore` | `journeys`, `addJourney`, `removeJourney` | Re-renders on save/unsave |
| `prefsStore` | `prefs` (navView, navHints, maxWalkMeters) | Re-renders on pref change |
| `authStore` | `isAuthenticated` | Re-renders on auth state change |

**Key design:** `headingStore` is read imperatively (`getState()`) inside the 130 ms camera
interval. The compass updates 12 times per second — if it were a React selector, it would
cause 12 re-renders/second of the entire map screen. The imperative read gives zero overhead.

---

## 10. Services Used by Map Screen

### StopService (`services/stop.ts`)

| Method | Description |
|---|---|
| `getAllStops()` | 3-tier cache: in-memory → AsyncStorage (24h) → network. Stale-while-revalidate. |
| `getNearbyStops(lat, lng, radius, limit)` | PostGIS radius query via `/stops/nearby` |
| `getStopDetails(id)` | Full stop info including routes, reviews, photos |
| `searchStops(query)` | Backend full-text search + Mapbox geocoding fallback |

### RouteService (`services/route.ts`)

| Method | Description |
|---|---|
| `calculateJourney(fromLoc, toLoc, maxWalk?)` | POST `/journey/calculate` with current date+time in Nairobi TZ |

No client-side caching. Every call goes to the server (server has 5-min Redis cache).
Sends `date` and `time` in OTP's expected format (`hh:mmam`/`hh:mmpm`).

### ReportService (`services/report.ts`)

| Method | Description |
|---|---|
| `getReportsInViewport(n, s, e, w)` | Fetch incidents in bounding box |
| `createReport(...)` | Submit a new report |
| `voteReport(id, type)` | Thumbs up/down on a report |

### MapService (`services/map.ts`)

| Method | Description |
|---|---|
| `reverseGeocode(lat, lng)` | Mapbox reverse geocoding → place name string |
| `searchPlaces(query)` | Google Places Autocomplete |
| `getPlaceDetails(placeId)` | Google Place Details |

### offlineTiles (`services/offlineTiles.ts`)

Downloads Mapbox raster tiles to local filesystem:
- `estimatePack()` → tile count estimate for a given bbox + zoom range
- `downloadPack(bbox, onProgress)` → concurrent 6-worker download to `{documentDirectory}/offline_tiles/{light|dark}/{z}/{x}/{y}.png`
- `deletePack()` → removes downloaded tiles

---

## 11. State Machine: TripStatus

```
IDLE
  │  setJourney() called (from /search or Kwame)
  ▼
WAITING_FOR_BUS    ← User has a route, not yet moving
  │  startNavigation() called + speed > 1.5 m/s detected
  ▼
IN_TRANSIT         ← User is moving along the route
  │  NavigationEngine returns status === 'arrived'
  ▼
ARRIVED            ← Destination reached
  │  clearJourney() or route re-selected
  ▼
IDLE
```

`navStarted` local state (distinct from `tripStatus`): `true` when the user has explicitly
pressed "Start Navigation." This is separate from `IN_TRANSIT` because the user may be
`WAITING_FOR_BUS` with `navStarted=true` (navigation started, but still at the stage waiting).

---

## 12. Complete Journey Flow (User Perspective)

### Step 1 — Discover & Search
- User opens the app → map screen loads, centered on Nairobi CBD (`DEFAULT_REGION`)
- Stops layer paints from AsyncStorage cache instantly; background network refresh
- User taps Search bar → `/search` screen opens
- User types destination → `StopService.searchStops()` then Mapbox geocoding fallback
- User selects from+to → `RouteService.calculateJourney()` called
- Results returned → user selects a route → `useJourneyStore.setJourney(fromLoc, toLoc, route)` dispatched

### Step 2 — Route Preview
- `activeJourney` becomes non-null
- `useRouteOverlay` builds legs, markers, steps
- `camera.fitCoordinates()` frames the full route with 80 dp padding
- `JourneyDetailsSheet` slides up in peek mode
- Walk legs rendered (dashed grey), transit legs rendered (colored), origin/destination pins placed
- Intermediate stop dots placed along transit legs
- Boarding node pulses orange at first transit stop
- `tripStatus` = `WAITING_FOR_BUS`

### Step 3 — Walk to Stage
- User presses "Start Navigation" → `tripStatus` stays `WAITING_FOR_BUS`, `navStarted = true`
- Camera locks to user, north-up by default, zoom 19
- "Waiting for bus" banner shows boarding stop name
- Navigation engine active: step index points to walk step "Walk to [stage]"
- Sub-steps from Google Directions show turn-by-turn within the walk leg
- Voice guide announces instructions (respects `navHints` pref: off / concise / detailed)
- Walk leg in RouteOverlay trims behind user as they walk

### Step 4 — Board the Matatu
- User arrives at stage within 18 m of the boarding node
- Engine advances step → "Board Line X" step becomes active
- Boarding node pulse stops; `stopsRemaining` starts counting down
- Camera zooms out slightly as speed increases after boarding
- `tripStatus` transitions to `IN_TRANSIT` when speed > 1.5 m/s
- Banner shows: "Line 58 · 6 stops remaining · Next: Nation Centre"
- `lastPassedStopName` updates as each intermediate stop is passed

### Step 5 — Ride
- Speed-adaptive zoom follows vehicle speed (18.0 at 30 kph, down to 16.5 at 80 kph)
- `headingUp` state controls whether map rotates to face direction of travel
- Report pins visible if `layers.reports` is on; hazard pins auto-cluster at density
- Intermediate stop dots fade as user passes them (past leg opacity 35%)
- User can pinch-zoom without losing camera follow; pan disables follow

### Step 6 — Alight
- Engine counts down `stopsRemaining` on each passed stop
- At 2 stops remaining: alight warning fires ("Prepare to alight in 2 stops")
- Step advances to "Alight at [stop]" → banner updates
- User alights; if multi-leg route, engine transitions to next walk leg

### Step 7 — Arrival
- Engine returns `status === 'arrived'` when user is within 18 m of destination step
- `tripStatus` = `ARRIVED`
- Navigation stops, camera unlocks
- Arrival banner shown ("You have arrived at [destination]")
- Voice: "You have arrived at [destination name]"
- JourneyDetailsSheet shows post-trip actions: rate, save, share

### Step 8 — Clear Journey
- User presses X or "Done" → `handleClearJourney()`:
  - `stopNavigation()`
  - `clearJourney()` → `activeJourney = null`, `tripStatus = IDLE`
  - `navStarted = false`
  - `selectedIntermStop = null`
  - Camera unlocks, `followMe = true`, map re-centers on user

---

## 13. Offline Behavior

| Feature | Offline behavior |
|---|---|
| Map tiles | Served from local filesystem if offline pack downloaded |
| Stop pins | Painted from AsyncStorage cache (24h TTL) |
| Report pins | Painted from AsyncStorage cache (10 min TTL, last-seen) |
| Route calculation | Fails with error (requires OTP on server) |
| Navigation (ongoing) | Works fully — GPS, engine, and voice are all device-local |
| Save/unsave journey | Fails silently if not authenticated; auth requires network |

`OfflineNotice` is shown when `!isOnline` with appropriate CTA based on auth state.

---

## 14. Key Constants & Thresholds

| Constant | Value | Location | Purpose |
|---|---|---|---|
| Camera interval | 130 ms | `map.tsx` | Nav camera tick rate (~7.7 Hz) |
| Camera animation | 80 ms | `map.tsx` | Per-tick animation duration |
| Heading dead-zone (heading-up) | 1° | `map.tsx` | Min change before sending to camera |
| Heading dead-zone (vehicle) | 2° | `map.tsx` | |
| Heading dead-zone (walk) | 5° | `map.tsx` | |
| Min displacement (UserLocation) | 2 m | `map.tsx` | Mapbox native dot update threshold |
| Report cluster radius | 30 m | `ReportLayer` | Reports within 30 m → single pin |
| Report fetch debounce | 400 ms | `map.tsx` | After pan settles before fetch |
| Off-route distance | 45 m | `NavigationEngine` | Strike threshold |
| Off-route strikes | 3 | `NavigationEngine` | Strikes before reroute |
| Step reach distance | 18 m | `NavigationEngine` | Proximity to advance step |
| Min walk speed | 1.4 m/s | `NavigationEngine` | ETA floor for walk legs |
| Boarding speed | 1.5 m/s | `useNavigation` | Trigger WAITING→IN_TRANSIT |
| GPS re-anchor interval | 2.5 s | `map.tsx` (camera) | Sensor fusion re-anchor |
| Stops cache TTL | 24 h | `cache.ts` | AsyncStorage TTL for all stops |
| Reports cache TTL | 10 min | `cache.ts` | AsyncStorage TTL for last-seen reports |
| GPS lost timeout | 8 s | `useNavigation` | Trigger dead reckoning + red pill |
| Session restore TTL | 2 h | `useNavigation` | Max age for cold-start session restore |
