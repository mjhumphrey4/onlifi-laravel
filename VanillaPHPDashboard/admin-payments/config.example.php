<?php

return [
    'app_name' => 'Onlifi Payments Admin',
    'base_url' => 'https://pay.onlifi.net',
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

    'default_admin' => [
        'name' => 'Onlifi Admin',
        'email' => 'admin@onlifi.net',
        'password' => 'ChangeMeNow!',
    ],
];
