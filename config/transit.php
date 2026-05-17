<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP (OpenTripPlanner) settings
    |--------------------------------------------------------------------------
    */
    'otp' => [
        'base_url'  => env('OTP_BASE_URL', 'http://127.0.0.1:8080/otp/routers/default'),
        'cache_ttl' => (int) env('OTP_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Maps API key (server-side)
    |--------------------------------------------------------------------------
    | Used by WalkingService to call the Directions API for road-snapped
    | walking routes. Set GOOGLE_MAPS_API_KEY in hopln-api/.env.
    | Must have "Directions API" enabled in Google Cloud Console.
    */
    'google_maps_key' => env('GOOGLE_MAPS_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Road snapper driver
    |--------------------------------------------------------------------------
    | 'none'    — use raw OTP geometry (recommended; GTFS shapes are road-aligned)
    | 'mapbox'  — Mapbox Map Matching API  (requires MAPBOX_API_KEY)
    | 'google'  — Google Roads API         (requires GOOGLE_ROADS_API_KEY)
    */
    'road_snapper' => env('ROAD_SNAPPER_DRIVER', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Geocoding driver (used by /api/v1/places/search)
    |--------------------------------------------------------------------------
    | 'mapbox'    — Mapbox Geocoding API   (requires MAPBOX_API_KEY)
    | 'google'    — Google Places API      (requires GOOGLE_PLACES_API_KEY)
    | 'nominatim' — OpenStreetMap Nominatim (no key required)
    */
    'geocoder' => env('GEOCODER_DRIVER', 'mapbox'),

];
