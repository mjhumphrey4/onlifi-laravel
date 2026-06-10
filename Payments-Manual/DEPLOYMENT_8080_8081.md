# Payments Manual Dashboards on 8080 and 8081

These configs serve each React dashboard as a static build and pass `/api/*` to its matching Laravel backend through PHP-FPM.

- YoPayments dashboard: `http://SERVER_IP:8080`
- IOTEC dashboard: `http://SERVER_IP:8081`
- YoPayments backend health: `http://SERVER_IP:8080/api/health`
- IOTEC backend health: `http://SERVER_IP:8081/api/health`

## 1. Server path

The included Nginx configs assume the repository lives at:

```bash
/var/www/onlifi-laravel
```

If your server path is different, replace `/var/www/onlifi-laravel` in both files under `Payments-Manual/nginx/`.

## 2. Build the frontends

```bash
cd /var/www/onlifi-laravel/Payments-Manual/UI
npm install --ignore-scripts
npm run build

cd /var/www/onlifi-laravel/Payments-Manual/IOTEC-Payments/UI
npm install --ignore-scripts
npm run build
```

The apps default to same-origin `/api`, so no `VITE_API_URL` is required when served by these Nginx configs.

## 3. Prepare the Laravel backends

YoPayments:

```bash
cd /var/www/onlifi-laravel/Payments-Manual/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan view:cache
```

IOTEC:

```bash
cd /var/www/onlifi-laravel/Payments-Manual/IOTEC-Payments/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan view:cache
```

Edit each `.env` before caching config:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://SERVER_IP:8080
PAYMENTS_MANUAL_ADMIN_TOKEN=use-a-long-random-token
```

For IOTEC:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://SERVER_IP:8081
IOTEC_ADMIN_TOKEN=use-a-different-long-random-token
```

The admin token is the login token for each dashboard.

If you use SQLite, create the database file if it does not exist:

```bash
touch database/database.sqlite
chown -R www-data:www-data storage bootstrap/cache database
chmod -R ug+rw storage bootstrap/cache database
```

After logging in, open each dashboard's settings screen and set the legacy transaction database fields:

- `legacy_db_host`
- `legacy_db_port`
- `legacy_db_name`
- `legacy_db_user`
- `legacy_db_password`
- `legacy_transactions_table`

Those settings are what make the dashboard read live YoPayments or IOTEC transaction data from the existing payment databases.

## 4. Install Nginx configs

```bash
sudo cp /var/www/onlifi-laravel/Payments-Manual/nginx/yopayments-8080.conf /etc/nginx/sites-available/yopayments-8080.conf
sudo cp /var/www/onlifi-laravel/Payments-Manual/nginx/iotec-8081.conf /etc/nginx/sites-available/iotec-8081.conf

sudo ln -s /etc/nginx/sites-available/yopayments-8080.conf /etc/nginx/sites-enabled/yopayments-8080.conf
sudo ln -s /etc/nginx/sites-available/iotec-8081.conf /etc/nginx/sites-enabled/iotec-8081.conf

sudo nginx -t
sudo systemctl reload nginx
```

If your PHP-FPM socket is not `/run/php/php8.3-fpm.sock`, update `fastcgi_pass` in both Nginx configs. Check available sockets with:

```bash
ls /run/php/
```

## 5. Keep backend workers active

The dashboards mostly use synchronous API requests, but both Laravel apps are configured with `QUEUE_CONNECTION=database`. Run one worker per backend if queued work is added or enabled:

```bash
cd /var/www/onlifi-laravel/Payments-Manual/backend
php artisan queue:work --sleep=3 --tries=3

cd /var/www/onlifi-laravel/Payments-Manual/IOTEC-Payments/backend
php artisan queue:work --sleep=3 --tries=3
```

For production, supervise these with systemd or Supervisor.

## 6. Verify

```bash
curl http://SERVER_IP:8080/api/health
curl http://SERVER_IP:8081/api/health
```

Then open:

```text
http://SERVER_IP:8080
http://SERVER_IP:8081
```
