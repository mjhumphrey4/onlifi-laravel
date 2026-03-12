<?php

use Illuminate\Support\Facades\Facade;

return [
    'name' => env('APP_NAME', 'OnLiFi'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Nairobi'),
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_UG',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'maintenance' => [
        'driver' => 'file',
    ],
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
    'aliases' => Facade::defaultAliases()->merge([
        //
    ])->toArray(),
];
