# OnLiFi Production Migration Runbook

This is the single production checklist for installing the required OnLiFi application components and deploying by pulling the GitHub repository into the server destination directory.

This guide intentionally does not include Nginx certificate tuning beyond the hosts needed for OnLiFi. WireGuard is included because routers use it for remote management and RouterOS API access.

## 1. Server Assumptions

- Ubuntu/Debian server with shell access.
- Nginx points the API host to `backend/public`.
- Nginx points the dashboard host to the built frontend output, usually `frontend/dist`.
- WireGuard remote access runs on this server at `89.167.42.53:51820/udp`.
- Repository destination: `/var/www/onlifi`.
- API domain: `https://api.onlifi.net`.
- Dashboard domain: `https://onlifi.net`.
- Manual payment domain: `https://pay.onlifi.net`.
- FreeRADIUS runs on the same server unless you intentionally split it.

Adjust paths and domains where needed.

## 2. Install System Packages

```bash
sudo apt update
sudo apt install -y \
  git curl unzip zip redis-server mysql-server mysql-client nginx certbot python3-certbot-nginx wireguard-tools supervisor \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl php8.3-redis \
  freeradius freeradius-mysql freeradius-utils libdbi-perl libdbd-mysql-perl
```

If your server uses PHP 8.2 instead of 8.3, install the same extensions with the `php8.2-*` package names. The application requires PHP `8.2+`.

Install Composer if it is not already available:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
composer --version
```

Install Node.js LTS for the frontend build. Use your preferred Node LTS source; the app needs a modern Node/npm toolchain for Vite:

```bash
node --version
npm --version
```

## 3. Create Databases And Users

Create the central database and application user:

```sql
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'onlifi_user'@'localhost' IDENTIFIED BY '##Onlus@Tech2026&&Onlifi##';
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_user'@'localhost';
GRANT ALL PRIVILEGES ON `onlifi\_%`.* TO 'onlifi_user'@'localhost';

CREATE USER 'radius_user'@'localhost' IDENTIFIED BY 'onlifi@rad26';
GRANT SELECT ON onlifi_central.tenants TO 'radius_user'@'localhost';
GRANT SELECT ON onlifi_central.nas TO 'radius_user'@'localhost';
GRANT SELECT ON onlifi_central.sites TO 'radius_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON `onlifi\_%`.* TO 'radius_user'@'localhost';

FLUSH PRIVILEGES;
```

The application creates tenant and site databases dynamically with names that begin with `onlifi_`, so both users need access to that database pattern.

## 4. Pull The Repository

First install:

```bash
sudo mkdir -p /var/www
sudo chown -R "$USER:www-data" /var/www
cd /var/www
git clone YOUR_GITHUB_REPO_URL onlifi
cd /var/www/onlifi
```

Subsequent deploy:

```bash
cd /var/www/onlifi
git pull origin main
```

Use the branch your Jenkins/deployment flow expects if it is not `main`.

## 5. Backend Environment

```bash
cd /var/www/onlifi/backend
cp .env.example .env
php artisan key:generate
```

Set the production values in `backend/.env`:

```env
APP_NAME=OnLiFi
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Africa/Nairobi
APP_URL=https://api.onlifi.net
API_URL=https://api.onlifi.net
FRONTEND_URL=https://onlifi.net
MANUAL_PAYMENT_BASE_URL=https://pay.onlifi.net

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=onlifi_central
DB_USERNAME=onlifi_user
DB_PASSWORD="##Onlus@Tech2026&&Onlifi##"

CENTRAL_DB_HOST=127.0.0.1
CENTRAL_DB_PORT=3306
CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_user
CENTRAL_DB_PASSWORD="##Onlus@Tech2026&&Onlifi##"

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CACHE_DB=1

FILESYSTEM_DISK=public
CORS_ALLOWED_ORIGINS=https://onlifi.net,https://api.onlifi.net
CORS_ALLOWED_ORIGIN_PATTERNS=#^https://([a-z0-9-]+\.)?onlifi\.net$#

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=YOUR_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=YOUR_SMTP_USERNAME
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_FROM_ADDRESS=noreply@onlifi.net
MAIL_FROM_NAME="${APP_NAME}"

YOAPI_USERNAME=YOUR_YO_USERNAME
YOAPI_PASSWORD=YOUR_YO_PASSWORD
YOAPI_MODE=production

SMS_PROVIDER=comms
SMS_API_KEY=YOUR_SMS_API_KEY
SMS_SENDER_ID=OnLiFi

RADIUS_SERVER_IP=89.167.42.53
RADIUS_AUTH_PORT=1812
RADIUS_ACCT_PORT=1813
RADIUS_SHARED_SECRET=Onlifi26A
RADIUS_DB_USER=radius_user
RADIUS_DB_PASSWORD=onlifi@rad26
```

Important:

- `FILESYSTEM_DISK=public` is needed for uploaded captive logos and downloadable assets.
- `APP_URL` must be the API host, not `localhost`, otherwise generated logo/download URLs can be wrong.
- Keep `RADIUS_SHARED_SECRET` exactly the same in Laravel system settings, MikroTik provisioning, and FreeRADIUS `clients.conf`.
- WireGuard endpoint settings are stored in the admin system settings. For production use `wireguard_endpoint_host=89.167.42.53`, `wireguard_endpoint_port=51820`, and paste the server public key into `wireguard_server_public_key`.

## 6. Install Backend Dependencies

```bash
cd /var/www/onlifi/backend
composer install --no-dev --optimize-autoloader
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

## 7. Build Frontend

```bash
cd /var/www/onlifi/frontend
npm ci
npm run build
```

## 8. Nginx And Backend Runtime

Laravel does not need `php artisan serve` in production. The backend runs through Nginx and PHP-FPM:

- Nginx receives `https://api.onlifi.net`.
- Nginx serves `/var/www/onlifi/backend/public`.
- PHP-FPM executes Laravel PHP requests.
- Queue and scheduler run as separate Supervisor programs.

Create the API host:

```bash
sudo nano /etc/nginx/sites-available/api.onlifi.net
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.onlifi.net;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.onlifi.net;

    ssl_certificate /etc/letsencrypt/live/api.onlifi.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.onlifi.net/privkey.pem;

    root /var/www/onlifi/backend/public;
    index index.php index.html;

    client_max_body_size 50M;

    access_log /var/log/nginx/api.onlifi.net.access.log;
    error_log /var/log/nginx/api.onlifi.net.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Create the dashboard host:

```bash
sudo nano /etc/nginx/sites-available/onlifi.net
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name onlifi.net www.onlifi.net;
    return 301 https://onlifi.net$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name onlifi.net www.onlifi.net;

    ssl_certificate /etc/letsencrypt/live/onlifi.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/onlifi.net/privkey.pem;

    root /var/www/onlifi/frontend/dist;
    index index.html;

    access_log /var/log/nginx/onlifi.net.access.log;
    error_log /var/log/nginx/onlifi.net.error.log;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

If you manually host `pay.onlifi.net` files on the same server, create its web root and host:

```bash
sudo mkdir -p /var/www/pay.onlifi.net
sudo chown -R www-data:www-data /var/www/pay.onlifi.net
sudo nano /etc/nginx/sites-available/pay.onlifi.net
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name pay.onlifi.net;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name pay.onlifi.net;

    ssl_certificate /etc/letsencrypt/live/pay.onlifi.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pay.onlifi.net/privkey.pem;

    root /var/www/pay.onlifi.net;
    index index.php index.html;

    access_log /var/log/nginx/pay.onlifi.net.access.log;
    error_log /var/log/nginx/pay.onlifi.net.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Create certificates first, then enable the final hosts:

```bash
sudo systemctl enable php8.3-fpm nginx
sudo systemctl restart php8.3-fpm

sudo systemctl stop nginx
sudo certbot certonly --standalone -d api.onlifi.net
sudo certbot certonly --standalone -d onlifi.net -d www.onlifi.net
sudo certbot certonly --standalone -d pay.onlifi.net

sudo ln -sf /etc/nginx/sites-available/api.onlifi.net /etc/nginx/sites-enabled/api.onlifi.net
sudo ln -sf /etc/nginx/sites-available/onlifi.net /etc/nginx/sites-enabled/onlifi.net
sudo ln -sf /etc/nginx/sites-available/pay.onlifi.net /etc/nginx/sites-enabled/pay.onlifi.net

sudo nginx -t
sudo systemctl start nginx
```

If using PHP 8.2, replace `php8.3-fpm` and `/run/php/php8.3-fpm.sock` with the PHP 8.2 equivalents.

### CORS Recovery Check

If the browser shows `No 'Access-Control-Allow-Origin' header`, first confirm the dashboard is loaded over HTTPS:

```text
https://onlifi.net
```

Then confirm `backend/.env` has:

```env
APP_URL=https://api.onlifi.net
API_URL=https://api.onlifi.net
FRONTEND_URL=https://onlifi.net
CORS_ALLOWED_ORIGINS=https://onlifi.net,https://api.onlifi.net
CORS_ALLOWED_ORIGIN_PATTERNS=#^https://([a-z0-9-]+\.)?onlifi\.net$#
```

Clear and rebuild Laravel config after changing CORS or URL values:

```bash
cd /var/www/onlifi/backend
composer dump-autoload -o
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

Test preflight directly from the server:

```bash
curl -i -X OPTIONS https://api.onlifi.net/api/tenant/login \
  -H "Origin: https://onlifi.net" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type,authorization"
```

Expected headers include:

```text
HTTP/2 204
access-control-allow-origin: https://onlifi.net
access-control-allow-credentials: true
```

`HTTP/2 200` is also acceptable. Any `301`, `401`, `403`, `404`, `405`, or `500` on the `OPTIONS` response means the preflight is being intercepted before Laravel's CORS response succeeds. Re-run `php artisan optimize:clear`, `php artisan config:cache`, and restart PHP-FPM/Nginx after deploying CORS-related changes.

The application also includes an early `/api/*` preflight guard in `backend/public/index.php`. If the test still returns `500`, confirm production has the current file and inspect the server logs:

```bash
cd /var/www/onlifi/backend
grep -n "Access-Control-Allow-Origin" public/index.php
tail -n 80 storage/logs/laravel.log
sudo tail -n 80 /var/log/nginx/api.onlifi.net.error.log
```

### Login 500 Recovery Check

If admin login returns `500` and the Laravel log mentions `personal_access_tokens`, the central Sanctum token table is missing. Run:

```bash
cd /var/www/onlifi/backend
php artisan migrate --force
php artisan migrate:status | grep -i personal_access_tokens
php artisan optimize:clear
php artisan config:cache
sudo systemctl restart php8.3-fpm
```

If the migration is not listed, publish Sanctum migrations and run migrations again:

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate --force
```

## 9. Run Migrations And Seed Required Data

```bash
cd /var/www/onlifi/backend
php artisan migrate --force
php artisan migrate:status | grep -i personal_access_tokens
php artisan onlifi:tenants:migrate
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If this is a fresh install, create the first super administrator from Tinker:

```bash
php artisan tinker
```

```php
\App\Models\SuperAdmin::updateOrCreate(
    ['email' => 'admin@onlifi.net'],
    [
        'name' => 'OnLiFi Administrator',
        'password' => \Illuminate\Support\Facades\Hash::make('CHANGE_THIS_ADMIN_PASSWORD'),
        'role' => 'super_admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]
);
```

Then sign in at:

```text
https://onlifi.net/admin/login
```

If signup logging or email fails with `storage/logs/laravel.log permission denied`, repair ownership and permissions:

```bash
cd /var/www/onlifi/backend
sudo mkdir -p storage/logs bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo systemctl restart php8.3-fpm
```

For SMTP, do not set `MAIL_SCHEME=tls`. Laravel expects `smtp` for port `587` or `smtps` for port `465`.

## 10. Queue Worker Supervisor Program

The app uses database queues by default. Create a Supervisor worker:

```bash
sudo nano /etc/supervisor/conf.d/onlifi-worker.conf
```

```ini
[program:onlifi-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/onlifi/backend/artisan queue:work database --sleep=3 --tries=3 --timeout=120
directory=/var/www/onlifi/backend
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/www/onlifi/backend/storage/logs/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stopwaitsecs=3600
```

Enable it:

```bash
sudo systemctl enable --now supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start onlifi-worker:*
sudo supervisorctl status onlifi-worker:*
```

## 11. Laravel Scheduler Supervisor Program

Use a long-running Supervisor program that calls Laravel's scheduler every minute:

```bash
sudo nano /etc/supervisor/conf.d/onlifi-scheduler.conf
```

```ini
[program:onlifi-scheduler]
process_name=%(program_name)s
command=/bin/bash -lc 'while true; do /usr/bin/php /var/www/onlifi/backend/artisan schedule:run --verbose --no-interaction; sleep 60; done'
directory=/var/www/onlifi/backend
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/www/onlifi/backend/storage/logs/scheduler.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stopwaitsecs=60
```

Enable it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start onlifi-scheduler
sudo supervisorctl status onlifi-scheduler
```

The scheduler is required for recurring cleanup, accounting, expiry, and any queued maintenance commands that are wired into Laravel scheduling.

The scheduler also runs `onlifi:router-snapshots:sync` every 5 minutes. This job pulls active clients, router users, IP bindings, DHCP leases, DHCP pools, and PPPoE clients from each site's MikroTik over the WireGuard/API path, stores the latest snapshot in the tenant database, and warms Redis. Dashboard pages should read this cached data instead of opening live RouterOS sessions on every page load.

## 12. Redis

Enable and start Redis:

```bash
sudo systemctl enable redis-server
sudo systemctl restart redis-server
redis-cli ping
```

Expected:

```text
PONG
```

The app uses Redis for cache when `CACHE_STORE=redis`. This improves pages like Clients and other frequently read dashboard data.

## 13. WireGuard Remote Access

Create the WireGuard server interface on the production server. This is the endpoint every provisioned MikroTik will connect to:

```bash
sudo mkdir -p /etc/wireguard
sudo chmod 700 /etc/wireguard
umask 077
wg genkey | sudo tee /etc/wireguard/onlifi-server.key | wg pubkey | sudo tee /etc/wireguard/onlifi-server.pub
sudo cat /etc/wireguard/onlifi-server.key
sudo cat /etc/wireguard/onlifi-server.pub
```

Create `/etc/wireguard/wg0.conf`:

```bash
sudo nano /etc/wireguard/wg0.conf
```

```ini
[Interface]
Address = 10.10.1.1/24
ListenPort = 51820
PrivateKey = paste the value from /etc/wireguard/onlifi-server.key
SaveConfig = false
```

Start the tunnel:

```bash
sudo systemctl enable --now wg-quick@wg0
sudo wg show
```

In the OnLiFi admin dashboard, open `System Settings > WireGuard` and set:

```text
wireguard_endpoint_host = 89.167.42.53
wireguard_endpoint_port = 51820
wireguard_server_public_key = contents of /etc/wireguard/onlifi-server.pub
wireguard_allowed_address = 10.10.1.0/24
```

For each approved site, assign a unique WireGuard private IP such as `10.10.1.10/32` in Admin Remote Access. The admin panel shows the router public key and a ready server peer block. Add that peer to the Linux server:

```bash
sudo wg set wg0 peer ROUTER_PUBLIC_KEY allowed-ips 10.10.1.10/32
sudo wg show
```

To persist peers across reboot, add each `[Peer]` block shown by OnLiFi to `/etc/wireguard/wg0.conf`, then restart:

```bash
sudo systemctl restart wg-quick@wg0
sudo wg show
```

Once a router is provisioned, it should bring up `onlifi-wg`, assign its admin-selected `10.10.1.x/32` address, and create a peer pointed at `89.167.42.53:51820`. Traffic starts flowing as soon as the router has the server public key and the Linux server has the router public key as a peer.

The tenant Remote Access page shows the user-facing endpoint as `vpn.onlifi.net:{remote_access_port}`. That generated port is for your public Winbox/Nginx mapping only. It is separate from the WireGuard UDP endpoint, which must remain `89.167.42.53:51820` for router provisioning.

## 14. FreeRADIUS Setup

Copy OnLiFi FreeRADIUS files:

```bash
sudo systemctl stop freeradius
cd /etc/freeradius/3.0

sudo cp /var/www/onlifi/backend/config/freeradius/clients.conf clients.conf
sudo cp /var/www/onlifi/backend/config/freeradius/default sites-available/default
sudo cp /var/www/onlifi/backend/config/freeradius/perl mods-available/perl
sudo ln -sf ../sites-available/default sites-enabled/default

sudo mkdir -p mods-config/perl
sudo cp /var/www/onlifi/backend/config/freeradius/multi_tenant.pl mods-config/perl/onlifi_multi_tenant.pl
sudo chmod +x mods-config/perl/onlifi_multi_tenant.pl
```

Edit `/etc/freeradius/3.0/clients.conf` and set:

```text
secret = Onlifi26A
```

The secret must match `RADIUS_SHARED_SECRET`.

Enable required modules:

```bash
cd /etc/freeradius/3.0/mods-enabled
sudo rm -f sql
sudo ln -sf ../mods-available/perl perl
```

Do not enable `mods-enabled/sql` for the current OnLiFi production flow. Tenant/site routing is handled by the Perl module using `NAS-Identifier`. If SQL is enabled by mistake, FreeRADIUS can fail during startup with errors such as `Reference "${client_table}" not found` or `Reference "${ENV_RADIUS_DB_PASSWORD}" not found` before OnLiFi's Perl module runs.

Edit `/etc/freeradius/3.0/mods-available/perl`:

```text
perl {
    filename = /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
    func_authorize = authorize
    func_authenticate = authenticate
    func_accounting = accounting
    func_start_accounting = accounting
    func_stop_accounting = accounting
    func_post_auth = post_auth
    perl_flags = "-w"
}
```

Edit `/etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl` and set the central DB login:

```perl
my $central_db_host = "localhost";
my $central_db_name = "onlifi_central";
my $central_db_user = "radius_user";
my $central_db_pass = "onlifi@rad26";
```

Validate and start:

```bash
sudo freeradius -XC
sudo systemctl enable freeradius
sudo systemctl restart freeradius
```

For live debugging:

```bash
sudo systemctl stop freeradius
sudo freeradius -X
```

## 15. Firewall Ports

Open the application, RADIUS, and WireGuard ports in your server/cloud firewall as appropriate:

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 1812/udp
sudo ufw allow 1813/udp
sudo ufw allow 3799/udp
sudo ufw allow 51820/udp
```

Do not open MySQL publicly.

## 16. Deployment Pull Command

Use this after Jenkins or manually after pushing to GitHub:

```bash
cd /var/www/onlifi
git pull origin main

cd /var/www/onlifi/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan migrate:status | grep -i personal_access_tokens
php artisan onlifi:tenants:migrate
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

cd /var/www/onlifi/frontend
npm ci
npm run build

cd /var/www/onlifi/backend
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
sudo supervisorctl restart onlifi-worker:*
sudo supervisorctl restart onlifi-scheduler
sudo systemctl restart freeradius
```

If using PHP 8.2, restart `php8.2-fpm` instead.

## 17. Post-Deployment Health Checks

Backend:

```bash
cd /var/www/onlifi/backend
php artisan about
php artisan route:list | head
php artisan onlifi:tenants:migrate
tail -n 100 storage/logs/laravel.log
```

Runtime services:

```bash
sudo systemctl status nginx
sudo systemctl status php8.3-fpm
curl -I https://api.onlifi.net/api/health
curl -I https://onlifi.net
```

Redis:

```bash
redis-cli ping
```

Queue:

```bash
sudo supervisorctl status onlifi-worker:*
tail -n 100 /var/www/onlifi/backend/storage/logs/worker.log
```

Scheduler:

```bash
sudo supervisorctl status onlifi-scheduler
tail -n 100 /var/www/onlifi/backend/storage/logs/scheduler.log
cd /var/www/onlifi/backend
php artisan onlifi:router-snapshots:sync
```

FreeRADIUS:

```bash
sudo systemctl status freeradius
sudo ss -lunp | grep -E ':1812|:1813|:3799'
```

WireGuard:

```bash
sudo systemctl status wg-quick@wg0
sudo wg show
sudo ss -lunp | grep ':51820'
```

Direct RADIUS auth test, using a real voucher/router:

```bash
echo 'User-Name=VOUCHER_CODE,User-Password=VOUCHER_CODE,NAS-Identifier=SITE-ONLIFI-1' \
  | radclient -x 127.0.0.1 auth Onlifi26A
```

Accounting test:

```bash
echo 'User-Name=VOUCHER_CODE,NAS-Identifier=SITE-ONLIFI-1,Acct-Status-Type=Start,Acct-Session-Id=test-1,NAS-IP-Address=127.0.0.1,Framed-IP-Address=10.10.0.253,Calling-Station-Id=AA:BB:CC:DD:EE:FF,Called-Station-Id=onlifi-hotspot,NAS-Port-Type=Wireless-802.11,NAS-Port-Id=onlifi-lan' \
  | radclient -x 127.0.0.1 acct Onlifi26A
```

Expected:

```text
Received Access-Accept
Received Accounting-Response
```

## 18. Router Provisioning Checks

After deploying, provision a test site/router and verify:

```routeros
/system identity print
/radius print detail
/ip hotspot profile print detail
/ip hotspot print detail
/interface wireguard print detail
/interface wireguard peers print detail
/ip address print where interface=onlifi-wg
/log print where message~"radius"
```

Expected:

- Identity follows `[site]-ONLIFI-1`.
- RADIUS address matches `RADIUS_SERVER_IP`.
- Authentication port is `1812`.
- Accounting port is `1813`.
- Secret matches `RADIUS_SHARED_SECRET`.
- Hotspot profile uses `use-radius=yes`.
- Hotspot profile uses `radius-accounting=yes`.
- Hotspot profile uses `login-by=http-pap`.
- WireGuard interface `onlifi-wg` exists.
- WireGuard peer endpoint is `89.167.42.53:51820`.
- WireGuard address matches the admin-assigned site private IP, for example `10.10.1.10/32`.
- Captive files include the full `hotspot` directory and the active `login.html`.

## 19. Captive Portal Assets

Uploaded captive logos require:

```bash
cd /var/www/onlifi/backend
php artisan storage:link
```

The generated captive download returns a ZIP containing the complete MikroTik `hotspot/` directory, including `login.html`, status/error/logout pages, `md5.js`, CSS, images, XML helpers, and an uploaded logo when configured.

Extract the ZIP and upload the complete `hotspot` directory to the MikroTik files area. Router provisioning also fetches this same file tree automatically.

## 20. Mobile Money And SMS

Set these before accepting production payments:

```env
YOAPI_USERNAME=...
YOAPI_PASSWORD=...
YOAPI_MODE=production
SMS_PROVIDER=...
SMS_API_KEY=...
SMS_SENDER_ID=OnLiFi
```

Confirm callback/IPN URLs with the payment provider:

```text
https://api.onlifi.net/api/captive/ipn
https://api.onlifi.net/api/captive/failure
```

If using the manual payment flow, the captive page points users to:

```text
https://pay.onlifi.net/{site-name}/initiate.php
https://pay.onlifi.net/{site-name}/check_status.php
https://pay.onlifi.net/{site-name}/look/voucher-lookup.php
```

## 21. Required Production Checklist

- `APP_DEBUG=false`.
- `APP_ENV=production`.
- `APP_KEY` generated once and never changed after production data exists.
- `APP_URL=https://api.onlifi.net`.
- `FRONTEND_URL=https://onlifi.net`.
- `FILESYSTEM_DISK=public`.
- `CACHE_STORE=redis`.
- `QUEUE_CONNECTION=database` or `redis`, with a matching worker.
- `php artisan storage:link` completed.
- `php artisan migrate --force` completed.
- `php artisan migrate:status | grep -i personal_access_tokens` shows the Sanctum token table migration has run.
- `php artisan onlifi:tenants:migrate` completed.
- FreeRADIUS validates with `freeradius -XC`.
- UDP `1812`, `1813`, and `3799` reachable from routers.
- WireGuard `wg0` is running and listening on UDP `51820`.
- UDP `51820` is reachable from routers.
- `wireguard_endpoint_host=89.167.42.53`, `wireguard_endpoint_port=51820`, and `wireguard_server_public_key` are set in admin system settings.
- `onlifi-scheduler` running under Supervisor.
- `onlifi-worker:*` running under Supervisor.
- Frontend build completed.
- Nginx points to the correct API public path and frontend build path.

## 22. Rollback

If a deployment fails after pulling:

```bash
cd /var/www/onlifi
git log --oneline -5
git checkout PREVIOUS_GOOD_COMMIT

cd backend
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
sudo supervisorctl restart onlifi-worker:*
sudo supervisorctl restart onlifi-scheduler

cd ../frontend
npm ci
npm run build
```

Do not roll back database migrations blindly. If a migration changed production data, inspect the migration and make a deliberate rollback plan first.
