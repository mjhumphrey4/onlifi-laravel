<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [
    'name' => env('APP_NAME', 'OnLiFi'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://api.onlifi.net'),
    'api_url' => env('API_URL', env('APP_URL', 'http://api.onlifi.net')),
    'frontend_url' => env('FRONTEND_URL', 'http://onlifi.net'),
    'manual_payment_base_url' => env('MANUAL_PAYMENT_BASE_URL', 'http://pay.onlifi.net'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Nairobi'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_UG'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
    'providers' => ServiceProvider::defaultProviders()->merge([
        App\Providers\AppServiceProvider::class,
    ])->toArray(),
    'aliases' => Facade::defaultAliases()->merge([
        //
    ])->toArray(),
];
