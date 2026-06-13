<?php

return [
    'app_name' => 'Onlifi Payments Admin',
    'base_url' => 'https://payments.onlifi.net',
    'timezone' => 'Africa/Kampala',

    'central_db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'onlifi_payments_admin',
        'user' => 'onlifi_payments',
        'pass' => 'change-this-password',
    ],

    'yo' => [
        'username' => 'replace-with-yo-username',
        'password' => 'replace-with-yo-password',
        'mode' => 'production',
    ],

    'sms' => [
        'api_key' => 'replace-with-mambosms-api-key',
        'send_url' => 'https://api-mongolia.mambosms.com/v1/send-sms',
        'balance_url' => 'https://api-mongolia.mambosms.com/v1/accounts/balance',
        'sender_id' => 'ONLIFI',
        'message_category' => 'customised',
        'brand_name' => 'ONLIFI WiFi',
        'timeout_seconds' => 10,
    ],

    'default_admin' => [
        'name' => 'Onlifi Admin',
        'email' => 'admin@onlifi.net',
        'password' => 'ChangeMeNow!',
    ],
];
