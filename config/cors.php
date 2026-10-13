<?php

// CORS: only the frontend origin configured via environment (api-contract §4).
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([env('CORS_ORIGIN')]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
