# OnLiFi Laravel Deployment Guide

Complete deployment guide for the OnLiFi Payment & Voucher Management System.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Server Setup](#server-setup)
3. [Database Setup](#database-setup)
4. [Backend Deployment](#backend-deployment)
5. [Frontend Deployment](#frontend-deployment)
6. [SSL Configuration](#ssl-configuration)
7. [MikroTik Configuration](#mikrotik-configuration)
8. [Post-Deployment](#post-deployment)
9. [Monitoring](#monitoring)
10. [Troubleshooting](#troubleshooting)

## Prerequisites

### Server Requirements

- Ubuntu 20.04 LTS or newer (recommended)
- 2GB RAM minimum (4GB recommended)
- 20GB disk space minimum
- Root or sudo access

### Software Requirements

- PHP 8.1 or higher
- MySQL 5.7 or higher
- Nginx or Apache
- Node.js 18 or higher
- Composer
- Git

## Server Setup

### 1. Update System

```bash
sudo apt update
sudo apt upgrade -y
```

### 2. Install PHP and Extensions

```bash
sudo apt install -y php8.1-fpm php8.1-cli php8.1-mysql php8.1-xml php8.1-mbstring \
    php8.1-curl php8.1-zip php8.1-gd php8.1-bcmath php8.1-intl
```

### 3. Install MySQL

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

### 4. Install Nginx

```bash
sudo apt install -y nginx
```

### 5. Install Node.js

```bash
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs
```

### 6. Install Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## Database Setup

### 1. Create Databases

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE payment_mikrotik CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'onlifi_user'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON payment_mikrotik.* TO 'onlifi_user'@'localhost';
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 2. Import Schemas (Optional)

```bash
cd /var/www/onlifi-laravel/data/database
mysql -u onlifi_user -p payment_mikrotik < mikrotik_schema.sql
mysql -u onlifi_user -p onlifi_central < central_auth_schema.sql
```

## Backend Deployment

### 1. Clone Repository

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone <your-repo-url> onlifi-laravel
cd onlifi-laravel/backend
```

### 2. Install Dependencies

```bash
composer install --optimize-autoloader --no-dev
```

### 3. Configure Environment

```bash
cp .env.example .env
nano .env
```

Update the following:

```env
APP_NAME="OnLiFi Payment System"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=payment_mikrotik
DB_USERNAME=onlifi_user
DB_PASSWORD=secure_password_here

CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_user
CENTRAL_DB_PASSWORD=secure_password_here

YOAPI_USERNAME=your_yo_username
YOAPI_PASSWORD=your_yo_password
YOAPI_MODE=production

SITE_URL=https://api.yourdomain.com/
FRONTEND_URL=https://yourdomain.com

CORS_ALLOWED_ORIGINS=https://yourdomain.com
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Run Migrations

```bash
php artisan migrate --force
```

### 6. Optimize for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 7. Set Permissions

```bash
sudo chown -R www-data:www-data /var/www/onlifi-laravel/backend
sudo chmod -R 775 /var/www/onlifi-laravel/backend/storage
sudo chmod -R 775 /var/www/onlifi-laravel/backend/bootstrap/cache
```

### 8. Configure Nginx

```bash
sudo nano /etc/nginx/sites-available/onlifi-backend
```

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/onlifi-laravel/backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/onlifi-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

## Frontend Deployment

### 1. Navigate to Frontend

```bash
cd /var/www/onlifi-laravel/frontend
```

### 2. Install Dependencies

```bash
npm install
```

### 3. Configure Environment

```bash
cp .env.example .env
nano .env
```

```env
VITE_API_URL=https://api.yourdomain.com/api
VITE_APP_NAME=OnLiFi Payment System
VITE_ORIGIN_SITE=Production
```

### 4. Build for Production

```bash
npm run build
```

### 5. Configure Nginx

```bash
sudo nano /etc/nginx/sites-available/onlifi-frontend
```

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    root /var/www/onlifi-laravel/frontend/dist;

    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/onlifi-frontend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

## SSL Configuration

### Using Let's Encrypt (Recommended)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Backend SSL
sudo certbot --nginx -d api.yourdomain.com

# Frontend SSL
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### Auto-renewal

```bash
sudo certbot renew --dry-run
```

## MikroTik Configuration

### 1. Install Telemetry Script

1. Copy script from `data/scripts/mikrotik-telemetry-script.rsc`
2. Open MikroTik Winbox
3. Go to System > Scripts
4. Create new script, paste content
5. Update backend URL in script

### 2. Configure FreeRADIUS

Edit `/etc/freeradius/3.0/mods-available/sql`:

```
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "localhost"
    port = 3306
    login = "onlifi_user"
    password = "secure_password_here"
    radius_db = "payment_mikrotik"
}
```

Enable SQL module:

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/
sudo systemctl restart freeradius
```

### 3. Configure Hotspot

In MikroTik:

```
/ip hotspot profile
set default use-radius=yes

/radius
add address=<freeradius-server-ip> secret=<radius-secret> service=hotspot
```

## Post-Deployment

### 1. Test Backend API

```bash
curl https://api.yourdomain.com/api/health
```

Expected response:
```json
{
    "status": "ok",
    "timestamp": "2024-01-01T00:00:00+03:00",
    "timezone": "Africa/Nairobi"
}
```

### 2. Test Frontend

Visit `https://yourdomain.com` in browser

### 3. Test Payment Flow

1. Navigate to payment page
2. Initiate test payment
3. Check transaction in database
4. Verify IPN handling

### 4. Create Cron Jobs

```bash
sudo crontab -e
```

Add:

```cron
# Laravel scheduler
* * * * * cd /var/www/onlifi-laravel/backend && php artisan schedule:run >> /dev/null 2>&1

# Clear old logs (weekly)
0 0 * * 0 find /var/www/onlifi-laravel/backend/storage/logs -name "*.log" -mtime +30 -delete
```

## Monitoring

### 1. Setup Log Monitoring

```bash
sudo apt install -y logwatch
```

### 2. Monitor Laravel Logs

```bash
tail -f /var/www/onlifi-laravel/backend/storage/logs/laravel.log
```

### 3. Monitor Nginx Logs

```bash
tail -f /var/log/nginx/access.log
tail -f /var/log/nginx/error.log
```

### 4. Database Monitoring

```bash
mysql -u onlifi_user -p -e "SHOW PROCESSLIST;"
```

## Troubleshooting

### Backend Not Accessible

1. Check Nginx status: `sudo systemctl status nginx`
2. Check PHP-FPM: `sudo systemctl status php8.1-fpm`
3. Check logs: `tail -f /var/log/nginx/error.log`

### Database Connection Issues

1. Verify credentials in `.env`
2. Test connection: `mysql -u onlifi_user -p payment_mikrotik`
3. Check MySQL status: `sudo systemctl status mysql`

### Permission Issues

```bash
sudo chown -R www-data:www-data /var/www/onlifi-laravel/backend
sudo chmod -R 775 /var/www/onlifi-laravel/backend/storage
sudo chmod -R 775 /var/www/onlifi-laravel/backend/bootstrap/cache
```

### IPN Not Working

1. Verify firewall allows incoming connections
2. Check IPN URL is publicly accessible
3. Verify YO! Payments certificates are in place
4. Check logs for IPN requests

### Clear All Caches

```bash
cd /var/www/onlifi-laravel/backend
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Backup Strategy

### Database Backup

```bash
#!/bin/bash
# Save as /usr/local/bin/backup-onlifi-db.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/onlifi"
mkdir -p $BACKUP_DIR

mysqldump -u onlifi_user -p'secure_password_here' payment_mikrotik > $BACKUP_DIR/payment_mikrotik_$DATE.sql
mysqldump -u onlifi_user -p'secure_password_here' onlifi_central > $BACKUP_DIR/onlifi_central_$DATE.sql

# Keep only last 30 days
find $BACKUP_DIR -name "*.sql" -mtime +30 -delete
```

Make executable and add to cron:

```bash
sudo chmod +x /usr/local/bin/backup-onlifi-db.sh
sudo crontab -e
```

Add:

```cron
0 2 * * * /usr/local/bin/backup-onlifi-db.sh
```

## Security Checklist

- [ ] SSL certificates installed and auto-renewing
- [ ] Firewall configured (UFW or iptables)
- [ ] Database users have minimal required permissions
- [ ] `.env` files have proper permissions (600)
- [ ] APP_DEBUG=false in production
- [ ] Strong passwords for all services
- [ ] Regular security updates applied
- [ ] Backup strategy in place
- [ ] Monitoring and alerting configured
- [ ] Rate limiting enabled on API endpoints

## Performance Optimization

### Enable OPcache

Edit `/etc/php/8.1/fpm/php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
```

Restart PHP-FPM:

```bash
sudo systemctl restart php8.1-fpm
```

### MySQL Optimization

Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
max_connections = 200
```

Restart MySQL:

```bash
sudo systemctl restart mysql
```

---

**Deployment complete! Your OnLiFi system should now be live and operational.**
