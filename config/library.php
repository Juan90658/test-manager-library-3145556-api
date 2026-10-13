<?php

// Manager Library settings. Every secret comes from the environment (constitution, art. IX).
return [
    'jwt_secret' => env('JWT_SECRET'),
    'jwt_ttl_seconds' => 8 * 60 * 60,
    'admin_username' => env('ADMIN_USERNAME'),
    'admin_password' => env('ADMIN_PASSWORD'),
    'covers_dir' => env('COVERS_DIR', 'storage/app/covers'),
    'cors_origin' => env('CORS_ORIGIN'),
    'clock_offset_days' => (int) env('CLOCK_OFFSET_DAYS', 0),
];
