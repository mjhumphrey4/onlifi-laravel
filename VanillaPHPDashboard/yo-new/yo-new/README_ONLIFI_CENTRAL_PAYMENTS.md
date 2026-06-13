# Onlifi Central Payments

This folder is the source of truth for the new payments system.

- PHP payment endpoints live in this directory.
- The admin UI lives in `newdashboard`.
- `workflow/login.html` is reference material for the hotspot request shape only.

## Admin Dashboard

The dashboard is admin-only. Site/customer logins were removed from `newdashboard/api/api.php`.

Admin credentials are read from `config.php` or environment variables:

```php
define('ONLIFI_ADMIN_USERNAME', 'admin');
define('ONLIFI_ADMIN_PASSWORD', 'change-this-password');
define('ONLIFI_ADMIN_EMAIL', 'admin@payments.onlifi.net');
```

For production, use a password hash instead of plain text:

```bash
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Then set:

```php
define('ONLIFI_ADMIN_PASSWORD', '');
define('ONLIFI_ADMIN_PASSWORD_HASH', '$2y$...');
```

Run the UI from:

```bash
cd VanillaPHPDashboard/yo-new/yo-new/newdashboard
npm install
npm run build
```

Serve the built dashboard together with `newdashboard/api/api.php`.

## Reusable Site Paths

Every active site in the `payment_sites` registry can use the same PHP files through rewrite rules:

```apache
RewriteRule ^([a-zA-Z0-9-]+)/(initiate|check_status|ipn|callback|failure|validate|api)\.php$ $2.php?site=$1 [QSA,L]
```

For Nginx with the React build served from `newdashboard/dist`, use flat PHP locations:

```nginx
server {
    listen 80;
    server_name payments.onlifi.net;

    root /var/www/onlifi/VanillaPHPDashboard/yo-new/yo-new/newdashboard/dist;
    index index.html;

    access_log /var/log/nginx/payments_onlifi_access.log;
    error_log  /var/log/nginx/payments_onlifi_error.log;
    client_max_body_size 50M;

    location = /api/api.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/onlifi/VanillaPHPDashboard/yo-new/yo-new/newdashboard/api/api.php;
        fastcgi_param DOCUMENT_ROOT /var/www/onlifi/VanillaPHPDashboard/yo-new/yo-new/newdashboard/api;
        fastcgi_read_timeout 120;
    }

    location ~ ^/([a-zA-Z0-9-]+)/(initiate|check_status|ipn|callback|failure|validate|api)\.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/onlifi/VanillaPHPDashboard/yo-new/yo-new/$2.php;
        fastcgi_param QUERY_STRING site=$1&$query_string;
        fastcgi_read_timeout 120;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

If your server uses a different PHP-FPM socket, replace `/run/php/php7.4-fpm.sock` with the path shown by `ls /run/php/`.

Examples:

```text
https://payments.onlifi.net/ranken/initiate.php
https://payments.onlifi.net/ranken/check_status.php?ref=TXN...
https://payments.onlifi.net/ranken/ipn.php
https://payments.onlifi.net/ranken/api.php?action=balance
```

## Central Tables

`site_registry.php` creates and manages:

- `payment_sites`: slug, display name, origin label, tenant DB assignment, API key, SMS settings.
- `payment_sms_logs`: one row for each MamboSMS send attempt.
- `payment_transactions`: signed ledger entries for withdrawals. Withdrawal amounts are stored as negative values.

Existing site transaction tables remain in their assigned tenant databases. Dashboard balances sum successful collections from those tenant DBs and subtract successful central withdrawal ledger rows.

## Onlifi Laravel Reads

Laravel should read balances from:

```http
GET /site-slug/api.php?action=balance
X-Site-Key: site-api-key
```

The response includes gross collections, fees, withdrawals, and available balance. Recent transactions are available through:

```http
GET /site-slug/api.php?action=transactions&limit=25
X-Site-Key: site-api-key
```

## SMS

Set these environment values in production:

```text
MAMBOSMS_API_KEY=...
MAMBOSMS_SEND_URL=https://api-mongolia.mambosms.com/v1/send-sms
MAMBOSMS_BALANCE_URL=https://api-mongolia.mambosms.com/v1/accounts/balance
MAMBOSMS_SENDER_ID=ONLIFI
MAMBOSMS_MESSAGE_CATEGORY=customised
MAMBOSMS_BRAND_NAME=ONLIFI WiFi
```

Each site has its own SMS ON/OFF switch in the Sites tab. If SMS is OFF, payment and voucher processing continues without sending an SMS.
