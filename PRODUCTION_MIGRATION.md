# OnLiFi Production Migration Runbook

This is the single production checklist for installing the required OnLiFi application components and deploying by pulling the GitHub repository into the server destination directory.

This guide intentionally does not include Nginx configuration or SoftEther/SSTP installation. Those are assumed to already be installed and managed separately.

## 1. Server Assumptions

- Ubuntu/Debian server with shell access.
- Nginx already points the API host to `backend/public`.
- Nginx already points the dashboard host to the built frontend output, usually `frontend/dist`.
- SoftEther is already installed.
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
  git curl unzip zip supervisor cron redis-server mysql-server mysql-client \
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

CREATE USER 'onlifi_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_user'@'localhost';
GRANT ALL PRIVILEGES ON `onlifi\_%`.* TO 'onlifi_user'@'localhost';

CREATE USER 'radius_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_RADIUS_PASSWORD';
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
DB_PASSWORD=CHANGE_THIS_STRONG_PASSWORD

CENTRAL_DB_HOST=127.0.0.1
CENTRAL_DB_PORT=3306
CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_user
CENTRAL_DB_PASSWORD=CHANGE_THIS_STRONG_PASSWORD

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

MAIL_MAILER=smtp
MAIL_HOST=YOUR_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=YOUR_SMTP_USERNAME
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@onlifi.net
MAIL_FROM_NAME="${APP_NAME}"

YOAPI_USERNAME=YOUR_YO_USERNAME
YOAPI_PASSWORD=YOUR_YO_PASSWORD
YOAPI_MODE=production

SMS_PROVIDER=comms
SMS_API_KEY=YOUR_SMS_API_KEY
SMS_SENDER_ID=OnLiFi

RADIUS_SERVER_IP=YOUR_FREERADIUS_REACHABLE_IP
RADIUS_AUTH_PORT=1812
RADIUS_ACCT_PORT=1813
RADIUS_SHARED_SECRET=CHANGE_THIS_GLOBAL_RADIUS_SECRET
RADIUS_DB_USER=radius_user
RADIUS_DB_PASSWORD=CHANGE_THIS_RADIUS_PASSWORD
```

Important:

- `FILESYSTEM_DISK=public` is needed for uploaded captive logos and downloadable assets.
- `APP_URL` must be the API host, not `localhost`, otherwise generated logo/download URLs can be wrong.
- Keep `RADIUS_SHARED_SECRET` exactly the same in Laravel system settings, MikroTik provisioning, and FreeRADIUS `clients.conf`.

## 6. Install Backend Dependencies

```bash
cd /var/www/onlifi/backend
composer install --no-dev --optimize-autoloader
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rw storage bootstrap/cache
```

## 7. Build Frontend

```bash
cd /var/www/onlifi/frontend
npm ci
npm run build
```

Your existing Nginx frontend host should serve `frontend/dist`.

## 8. Run Migrations And Seed Required Data

```bash
cd /var/www/onlifi/backend
php artisan migrate --force
php artisan onlifi:tenants:migrate
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If this is a fresh install, create the first super administrator using the existing project flow or Tinker, depending on what credentials you want:

```bash
php artisan tinker
```

Then create the admin using the current `SuperAdmin` model fields used by the application.

## 9. Queue Worker

The app uses database queues by default. Create a Supervisor program:

```bash
sudo nano /etc/supervisor/conf.d/onlifi-worker.conf
```

```ini
[program:onlifi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/onlifi/backend/artisan queue:work database --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/onlifi/backend/storage/logs/worker.log
stopwaitsecs=3600
```

Start it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart onlifi-worker:*
```

## 10. Laravel Scheduler

Add the scheduler to cron:

```bash
sudo crontab -u www-data -e
```

Add:

```cron
* * * * * cd /var/www/onlifi/backend && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler is required for recurring cleanup, accounting, expiry, and any queued maintenance commands that are wired into Laravel scheduling.

## 11. Redis

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

## 12. FreeRADIUS Setup

Copy OnLiFi FreeRADIUS files:

```bash
sudo systemctl stop freeradius
cd /etc/freeradius/3.0

sudo cp /var/www/onlifi/backend/config/freeradius/clients.conf clients.conf
sudo cp /var/www/onlifi/backend/config/freeradius/default sites-available/default
sudo cp /var/www/onlifi/backend/config/freeradius/sql.conf mods-available/sql

sudo mkdir -p mods-config/perl
sudo cp /var/www/onlifi/backend/config/freeradius/multi_tenant.pl mods-config/perl/onlifi_multi_tenant.pl
sudo chmod +x mods-config/perl/onlifi_multi_tenant.pl
```

Edit `/etc/freeradius/3.0/clients.conf` and set:

```text
secret = CHANGE_THIS_GLOBAL_RADIUS_SECRET
```

The secret must match `RADIUS_SHARED_SECRET`.

Enable required modules:

```bash
cd /etc/freeradius/3.0/mods-enabled
sudo ln -sf ../mods-available/perl perl
sudo ln -sf ../mods-available/sql sql
```

Edit `/etc/freeradius/3.0/mods-available/perl`:

```text
perl {
    filename = /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
    func_authorize = authorize
    func_authenticate = authenticate
    func_accounting = accounting
    func_post_auth = post_auth
}
```

Edit `/etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl` and set the central DB login:

```perl
my $central_db_host = "localhost";
my $central_db_name = "onlifi_central";
my $central_db_user = "radius_user";
my $central_db_pass = "CHANGE_THIS_RADIUS_PASSWORD";
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

## 13. Firewall Ports

Open the application and RADIUS ports in your server/cloud firewall as appropriate:

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 1812/udp
sudo ufw allow 1813/udp
sudo ufw allow 3799/udp
```

Do not open MySQL publicly.

## 14. Deployment Pull Command

Use this after Jenkins or manually after pushing to GitHub:

```bash
cd /var/www/onlifi
git pull origin main

cd /var/www/onlifi/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan onlifi:tenants:migrate
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo supervisorctl restart onlifi-worker:*
sudo systemctl restart php8.3-fpm

cd /var/www/onlifi/frontend
npm ci
npm run build
```

If using PHP 8.2, restart `php8.2-fpm` instead.

## 15. Post-Deployment Health Checks

Backend:

```bash
cd /var/www/onlifi/backend
php artisan about
php artisan route:list | head
php artisan onlifi:tenants:migrate
tail -n 100 storage/logs/laravel.log
```

Redis:

```bash
redis-cli ping
```

Queue:

```bash
sudo supervisorctl status
tail -n 100 /var/www/onlifi/backend/storage/logs/worker.log
```

FreeRADIUS:

```bash
sudo systemctl status freeradius
sudo ss -lunp | grep -E ':1812|:1813|:3799'
```

Direct RADIUS auth test, using a real voucher/router:

```bash
echo 'User-Name=VOUCHER_CODE,User-Password=VOUCHER_CODE,NAS-Identifier=SITE-ONLIFI-1' \
  | radclient -x 127.0.0.1 auth CHANGE_THIS_GLOBAL_RADIUS_SECRET
```

Accounting test:

```bash
echo 'User-Name=VOUCHER_CODE,NAS-Identifier=SITE-ONLIFI-1,Acct-Status-Type=Start,Acct-Session-Id=test-1,NAS-IP-Address=127.0.0.1,Framed-IP-Address=10.10.0.253,Calling-Station-Id=AA:BB:CC:DD:EE:FF,Called-Station-Id=onlifi-hotspot,NAS-Port-Type=Wireless-802.11,NAS-Port-Id=onlifi-lan' \
  | radclient -x 127.0.0.1 acct CHANGE_THIS_GLOBAL_RADIUS_SECRET
```

Expected:

```text
Received Access-Accept
Received Accounting-Response
```

## 16. Router Provisioning Checks

After deploying, provision a test site/router and verify:

```routeros
/system identity print
/radius print detail
/ip hotspot profile print detail
/ip hotspot print detail
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
- Captive files include the active `login.html`.

## 17. Captive Portal Assets

Uploaded captive logos require:

```bash
cd /var/www/onlifi/backend
php artisan storage:link
```

The generated captive download returns:

- `login.html` when no uploaded logo is used.
- A ZIP containing `login.html` and `logo.*` when a logo is used.

Upload both files to the same MikroTik hotspot directory when using the ZIP.

## 18. Mobile Money And SMS

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

## 19. Required Production Checklist

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
- `php artisan onlifi:tenants:migrate` completed.
- FreeRADIUS validates with `freeradius -XC`.
- UDP `1812`, `1813`, and `3799` reachable from routers.
- Laravel scheduler installed under `www-data`.
- Supervisor queue worker running.
- Frontend build completed.
- Nginx points to the correct API public path and frontend build path.

## 20. Rollback

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
sudo supervisorctl restart onlifi-worker:*
sudo systemctl restart php8.3-fpm

cd ../frontend
npm ci
npm run build
```

Do not roll back database migrations blindly. If a migration changed production data, inspect the migration and make a deliberate rollback plan first.
