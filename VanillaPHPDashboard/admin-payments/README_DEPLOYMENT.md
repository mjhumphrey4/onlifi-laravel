# Onlifi Central Vanilla PHP Payments Dashboard

This is the new admin-only payment dashboard for reusable YoPayments routing.

## What It Does

- Admins manage sites, not dashboard users.
- Each site gets a path slug such as `ranken`, `stk-wifi`, or `bite-tech`.
- Hotspot pages call:
  - `https://payments.onlifi.net/site-name/initiate.php`
  - `https://payments.onlifi.net/site-name/check_status.php?ref=...`
  - `https://payments.onlifi.net/site-name/api.php?action=balance` for Laravel balance reads
- The same physical PHP files serve every site through rewrite rules.
- Collections and withdrawals share one signed ledger:
  - successful collections are positive amounts
  - successful withdrawals are negative amounts
  - available balance is the sum of successful ledger entries
- Each site has an SMS ON/OFF switch. When enabled, successful payment transactions send a customer SMS through MamboSMS and write to the SMS Logs tab.
- Each site can optionally mirror transactions and vouchers into its assigned Onlifi/Laravel tenant database.

## Install

1. Copy `config.example.php` to `config.php`.
2. Set the central dashboard database credentials.
3. Set the YoPayments API credentials.
4. Set the MamboSMS API key and default sender settings.
5. Point the web root or an alias to this folder.
6. Visit `/login.php`.
7. Change the default admin password immediately in the database or by creating your own admin row.

The default admin is seeded only when `payment_admins` is empty.

## Apache Rewrite

`.htaccess` is included:

```apache
RewriteRule ^([a-zA-Z0-9-]+)/(initiate|check_status|ipn|callback|failure)\.php$ $2.php?site=$1 [QSA,L]
```

## Nginx Rewrite

Use this pattern for `payments.onlifi.net`:

```nginx
server {
    server_name payments.onlifi.net;
    root /var/www/onlifi/VanillaPHPDashboard/admin-payments;
    index index.php;

    location ~ ^/([a-zA-Z0-9-]+)/(initiate|check_status|ipn|callback|failure|api)\.php$ {
        rewrite ^/([a-zA-Z0-9-]+)/(initiate|check_status|ipn|callback|failure|api)\.php$ /$2.php?site=$1 last;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

## Hotspot Login Changes

In each MikroTik `login.html`, set the site slug once:

```js
const PAYMENT_SITE_SLUG = 'site-name';
const PAYMENT_BASE_URL = 'https://payments.onlifi.net/' + PAYMENT_SITE_SLUG;
```

Then call:

```js
fetch(PAYMENT_BASE_URL + '/initiate.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams(paymentData).toString()
});

fetch(PAYMENT_BASE_URL + '/check_status.php?ref=' + encodeURIComponent(ref) + '&t=' + Date.now());
```

The dashboard resolves the site from `/site-name/`, so the same backend files are reused for every site.

## SMS Setup

SMS settings live in `config.php`:

```php
'sms' => [
    'api_key' => 'your-mambosms-api-key',
    'send_url' => 'https://api-mongolia.mambosms.com/v1/send-sms',
    'balance_url' => 'https://api-mongolia.mambosms.com/v1/accounts/balance',
    'sender_id' => 'ONLIFI',
    'message_category' => 'customised',
    'brand_name' => 'ONLIFI WiFi',
],
```

Per site, the admin can set:

- `SMS sender ID`
- `SMS category`
- `SMS brand name`
- `Send SMS after successful transactions`

When the switch is ON, the dashboard sends one SMS per successful collection transaction. If a voucher code exists, the SMS includes the voucher code. If voucher creation is not configured for that site yet, the SMS becomes a payment confirmation. Successful sends are not repeated for the same transaction.

The SMS Logs tab records:

- site
- recipient
- sent/failed status
- provider cost
- provider balance
- message body
- external payment reference

## Linking To Onlifi Laravel

Recommended production options:

1. Central dashboard is the payment source of truth.
   Onlifi-Laravel reads balances from this dashboard through `GET /site-name/api.php?action=balance` using `X-Site-Key: site-api-key`. This avoids duplicate balance math and keeps withdrawals centralized.

2. Central dashboard mirrors into each tenant DB.
   Configure each site with `db_host`, `db_name`, `db_user`, `db_pass`, `tenant_id`, and `onlifi_site_id`. The dashboard writes compatible `transactions` rows and creates vouchers/RADIUS rows when the tenant tables exist.

3. Hybrid.
   Use central dashboard for collections, callbacks, withdrawals, and balances. Mirror only voucher records to tenant databases so Laravel and RADIUS keep working locally.

For withdrawal safety, keep withdrawals in `payment_transactions` as negative `transaction_type = withdrawal` rows. Laravel should not compute available balance from voucher sales alone; it should use the signed ledger balance from this dashboard or a synced equivalent.

## Site Assignment Fields

- `slug`: URL path, for example `/ranken/initiate.php`
- `display_name`: dashboard card name
- `origin_site`: recorded transaction site label
- `tenant_id`: Onlifi tenant identifier, optional
- `onlifi_site_id`: Onlifi site identifier used when mirroring vouchers
- `db_*`: tenant database assignment
- `default_profile`: RADIUS/voucher profile fallback

## Callback URLs

YoPayments IPN is automatically set per site:

```text
https://payments.onlifi.net/site-name/ipn.php
```

Generic provider callbacks can use:

```text
https://payments.onlifi.net/site-name/callback.php
```

The callback handler resolves the transaction by external reference and validates it against the central ledger before mirroring to the tenant database.
