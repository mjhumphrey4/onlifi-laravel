<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'https://onlifi.net,https://api.onlifi.net')))),
    'allowed_origins_patterns' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGIN_PATTERNS', '#^https?://([a-z0-9-]+\.)?onlifi\.net$#')))),
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => true,
];
