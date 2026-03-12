<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'yoapi' => [
        'username' => env('YOAPI_USERNAME'),
        'password' => env('YOAPI_PASSWORD'),
        'mode' => env('YOAPI_MODE', 'sandbox'),
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'comms'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'OnLiFi'),
    ],

    'mikrotik' => [
        'default_host' => env('MIKROTIK_DEFAULT_HOST', '192.168.88.1'),
        'default_port' => env('MIKROTIK_DEFAULT_PORT', 8728),
        'default_username' => env('MIKROTIK_DEFAULT_USERNAME', 'admin'),
        'default_password' => env('MIKROTIK_DEFAULT_PASSWORD', 'admin'),
    ],
];
