<?php

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (str_starts_with($path, '/api/')) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = array_filter(array_map(
            'trim',
            explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: 'https://onlifi.net,https://api.onlifi.net')
        ));
        $allowedPatterns = array_filter(array_map(
            'trim',
            explode(',', getenv('CORS_ALLOWED_ORIGIN_PATTERNS') ?: '#^https://([a-z0-9-]+\.)?onlifi\.net$#')
        ));

        $originAllowed = in_array($origin, $allowedOrigins, true);

        if (!$originAllowed) {
            foreach ($allowedPatterns as $pattern) {
                if (@preg_match($pattern, $origin) === 1) {
                    $originAllowed = true;
                    break;
                }
            }
        }

        if ($originAllowed) {
            header('Access-Control-Allow-Origin: '.$origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: '.($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? 'Content-Type, Authorization, X-Requested-With, X-Site-ID, Accept'));
            header('Access-Control-Expose-Headers: Content-Disposition');
            header('Access-Control-Max-Age: 86400');
            header('Vary: Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
        }

        http_response_code(204);
        exit;
    }
}

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
