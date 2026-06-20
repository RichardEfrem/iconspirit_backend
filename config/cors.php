<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-XSRF-TOKEN', 'X-Requested-With', 'Authorization'],
    'exposed_headers' => [],
    'max_age' => 3600, // Cache CORS preflight for 1 hour
    'supports_credentials' => true,
];

