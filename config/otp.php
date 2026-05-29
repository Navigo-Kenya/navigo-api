<?php

return [
    'base_url' => env('OTP_BASE_URL', 'http://localhost:8080'),
    'timeout'  => env('OTP_SYNC_TIMEOUT', 120),

    // GTFS zip written here (relative to storage/app/)
    'gtfs_storage_path' => env('OTP_GTFS_STORAGE_PATH', 'gtfs/gtfs.zip'),

    // Delivery: 'local' | 'scp' | 'none'
    'deploy_driver' => env('OTP_DEPLOY_DRIVER', 'local'),
    'data_path'     => env('OTP_DATA_PATH', ''),     // abs path to OTP data dir (local driver)

    // SCP driver
    'scp_target' => env('OTP_SCP_TARGET', ''),       // user@host:/path/to/gtfs.zip
    'scp_key'    => env('OTP_SCP_KEY',    ''),       // path to SSH private key

    // Graph rebuild — arbitrary shell command; empty = skip
    'build_cmd' => env('OTP_BUILD_CMD', ''),

    // Health check after rebuild
    'health_check_url'     => env('OTP_HEALTH_CHECK_URL',     'http://127.0.0.1:8080/otp'),
    'health_check_retries' => (int) env('OTP_HEALTH_CHECK_RETRIES', 30),
    'health_check_delay'   => (int) env('OTP_HEALTH_CHECK_DELAY',   10),

    // Road snapper for stop-to-road alignment: 'mapbox' | 'google' | 'none'
    'road_snapper_driver'  => env('ROAD_SNAPPER_DRIVER', 'none'),
];
