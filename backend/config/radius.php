<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FreeRADIUS Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the FreeRADIUS server that handles hotspot authentication.
    | MikroTik routers connect to this server to authenticate voucher codes.
    |
    */

    // RADIUS server IP address (where FreeRADIUS is running)
    'server_ip' => env('RADIUS_SERVER_IP', '129.168.0.42'),

    // Authentication port (default: 1812)
    'auth_port' => env('RADIUS_AUTH_PORT', 1812),

    // Accounting port (default: 1813)
    'acct_port' => env('RADIUS_ACCT_PORT', 1813),

    // Shared secret for RADIUS authentication
    // This should be a strong, random string shared between FreeRADIUS and MikroTik routers
    'shared_secret' => env('RADIUS_SHARED_SECRET', 'Onlifi26A'),

    /*
    |--------------------------------------------------------------------------
    | Auto-sync Settings
    |--------------------------------------------------------------------------
    |
    | Control automatic synchronization of vouchers with FreeRADIUS.
    |
    */

    // Enable automatic sync when vouchers are created/updated
    'auto_sync_enabled' => env('RADIUS_AUTO_SYNC', true),

    // Cleanup expired vouchers from RADIUS (in hours)
    'cleanup_interval_hours' => env('RADIUS_CLEANUP_INTERVAL', 24),

    /*
    |--------------------------------------------------------------------------
    | MikroTik Attributes
    |--------------------------------------------------------------------------
    |
    | Vendor-specific attributes for MikroTik routers.
    |
    */

    // Default rate limit format: "download_kbps/upload_kbps"
    'default_rate_limit' => env('RADIUS_DEFAULT_RATE_LIMIT', '2048k/2048k'),

    // Session timeout in seconds (default: 24 hours)
    'default_session_timeout' => env('RADIUS_DEFAULT_SESSION_TIMEOUT', 86400),

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Database settings for FreeRADIUS SQL module.
    | These are used by the Perl module for multi-tenant routing.
    |
    */

    // Central database (for NAS lookup)
    'central_database' => env('RADIUS_CENTRAL_DB', 'onlifi_central'),

    // Database user for FreeRADIUS
    'database_user' => env('RADIUS_DB_USER', 'radius_user'),

    // Database password for FreeRADIUS
    'database_password' => env('RADIUS_DB_PASSWORD', ''),
];
