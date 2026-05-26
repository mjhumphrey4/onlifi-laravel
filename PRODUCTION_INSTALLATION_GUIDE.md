# OnLiFi - Complete Production Installation Guide

A comprehensive guide for deploying the OnLiFi Multi-Tenant Payment, Voucher & RADIUS System on Ubuntu 22.04 LTS.

---

## Table of Contents

1. [Overview](#overview)
2. [System Requirements](#system-requirements)
3. [Pre-Installation](#pre-installation)
4. [Step 1: Server Preparation](#step-1-server-preparation)
5. [Step 2: MySQL Database Setup](#step-2-mysql-database-setup)
6. [Step 3: PHP & Composer Setup](#step-3-php--composer-setup)
7. [Step 4: Node.js Setup](#step-4-nodejs-setup)
8. [Step 5: FreeRADIUS Installation](#step-5-freeradius-installation)
9. [Step 6: Nginx Web Server](#step-6-nginx-web-server)
10. [Step 7: Backend Deployment](#step-7-backend-deployment)
11. [Step 8: Frontend Deployment](#step-8-frontend-deployment)
12. [Step 9: FreeRADIUS Configuration](#step-9-freeradius-configuration)
13. [Step 10: SSL Certificates](#step-10-ssl-certificates)
14. [Step 11: Post-Deployment Setup](#step-11-post-deployment-setup)
15. [Step 12: MikroTik Configuration](#step-12-mikrotik-configuration)
16. [Maintenance & Monitoring](#maintenance--monitoring)
17. [Troubleshooting](#troubleshooting)

---

## Overview

OnLiFi is a multi-tenant WiFi hotspot management system with:

- **Laravel Backend**: REST API for payments, vouchers, routers
- **React Frontend**: Admin dashboard for tenants
- **FreeRADIUS**: Authentication server for hotspot users
- **MySQL**: Database (central + per-tenant databases)
- **MikroTik Integration**: Router management and telemetry

### Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              PRODUCTION SETUP                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌──────────────┐      ┌──────────────┐      ┌──────────────┐             │
│   │   Nginx      │      │   Nginx      │      │  FreeRADIUS  │             │
│   │  (Frontend)  │      │  (Backend)   │      │   :1812/1813 │             │
│   │   :443       │      │   :443       │      │              │             │
│   └──────┬───────┘      └──────┬───────┘      └──────┬───────┘             │
│          │                     │                     │                    │
│          └─────────────┬───────┘                     │                    │
│                        │                             │                    │
│                        ▼                             ▼                    │
│              ┌─────────────────┐            ┌─────────────────┐           │
│              │  React Frontend │            │  MySQL Server   │           │
│              │  (Static Files) │            │                 │           │
│              └─────────────────┘            │  ┌───────────┐  │           │
│                                           │  │  Central  │  │           │
│              ┌─────────────────┐            │  │   DB      │  │           │
│              │ Laravel Backend │◄───────────┤  └───────────┘  │           │
│              │    (PHP-FPM)    │            │  ┌───────────┐  │           │
│              └─────────────────┘            │  │  Tenant   │  │           │
│                                           │  │   DBs     │  │           │
│                                           │  └───────────┘  │           │
│                                           └─────────────────┘           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## System Requirements

### Hardware

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| CPU | 2 cores | 4+ cores |
| RAM | 4 GB | 8+ GB |
| Disk | 50 GB SSD | 100+ GB SSD |
| Network | 100 Mbps | 1 Gbps |

### Software

- **OS**: Ubuntu 22.04 LTS (or 24.04 LTS)
- **PHP**: 8.2+
- **MySQL**: 8.0+ (or MariaDB 10.6+)
- **Node.js**: 18 LTS+
- **Nginx**: 1.18+
- **FreeRADIUS**: 3.0.x

### Domain Names

You need at least one domain:
- `api.yourdomain.com` - Backend API
- `yourdomain.com` - Frontend dashboard

Or use sub-paths:
- `yourdomain.com/api` - Backend API
- `yourdomain.com` - Frontend dashboard

---

## Pre-Installation

### 1. Update System

```bash
# Update package lists
sudo apt update

# Upgrade all packages
sudo apt upgrade -y

# Install essential tools
sudo apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates gnupg2

# Set timezone
sudo timedatectl set-timezone Africa/Nairobi
```

### 2. Configure Firewall

```bash
# Install UFW (if not installed)
sudo apt install -y ufw

# Allow SSH (important!)
sudo ufw allow ssh

# Allow HTTP and HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Allow FreeRADIUS ports (for MikroTik connections)
sudo ufw allow 1812/udp
sudo ufw allow 1813/udp

# Enable firewall
sudo ufw enable
```

---

## Step 1: Server Preparation

### Create Application User

```bash
# Create a dedicated user for the application
sudo useradd -m -s /bin/bash onlifi

# Add to www-data group for web server access
sudo usermod -a -G www-data onlifi

# Set up sudo access (optional but recommended)
sudo usermod -a -G sudo onlifi

# Switch to onlifi user for remaining setup
sudo su - onlifi
```

### Create Directory Structure

```bash
# Create application directory
sudo mkdir -p /var/www/onlifi-laravel
sudo chown onlifi:www-data /var/www/onlifi-laravel

# Create log directory
sudo mkdir -p /var/log/onlifi
sudo chown onlifi:www-data /var/log/onlifi

# Create backup directory
sudo mkdir -p /var/backups/onlifi
sudo chown onlifi:www-data /var/backups/onlifi
```

---

## Step 2: MySQL Database Setup

### Install MySQL Server

```bash
# Install MySQL
sudo apt install -y mysql-server

# Secure MySQL installation
sudo mysql_secure_installation

# Answer the prompts:
# - Validate password component: Y (recommended)
# - Password strength: STRONG
# - New root password: [set a strong password]
# - Remove anonymous users: Y
# - Disallow root login remotely: Y
# - Remove test database: Y
# - Reload privilege tables: Y
```

### Configure MySQL for Production

```bash
# Edit MySQL configuration
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Add/modify these settings:

```ini
[mysqld]
# Basic settings
bind-address = 127.0.0.1
port = 3306

# Performance tuning (adjust based on RAM)
innodb_buffer_pool_size = 2G
innodb_log_file_size = 512M
max_connections = 200

# UTF-8
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

```bash
# Restart MySQL
sudo systemctl restart mysql

# Enable MySQL to start on boot
sudo systemctl enable mysql
```

### Create Databases and Users

```bash
# Login to MySQL as root
sudo mysql -u root -p
```

```sql
-- Create central database
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create application database user
CREATE USER 'onlifi_app'@'localhost' IDENTIFIED BY 'YourStrongPasswordHere!';

-- Grant privileges on central database
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_app'@'localhost';

-- Grant privilege to create tenant databases
GRANT CREATE, DROP, INDEX ON *.* TO 'onlifi_app'@'localhost';

-- Create FreeRADIUS database user (if FreeRADIUS on same server)
CREATE USER 'radius'@'localhost' IDENTIFIED BY 'RadiusStrongPassword!';
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'radius'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;
EXIT;
```

---

## Step 3: PHP & Composer Setup

### Install PHP 8.2

```bash
# Add PHP repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP and required extensions
sudo apt install -y php8.2-fpm php8.2-cli php8.2-common \
    php8.2-mysql php8.2-xml php8.2-mbstring php8.2-curl \
    php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
    php8.2-opcache php8.2-readline php8.2-tokenizer \
    php8.2-json php8.2-fileinfo php8.2-openssl
```

### Configure PHP-FPM for Production

```bash
# Edit PHP-FPM pool configuration
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

Update these settings:

```ini
; User and group
user = www-data
group = www-data

; Listen socket
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process management - adjust based on your server
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

; Environment variables
env[APP_ENV] = production
env[APP_DEBUG] = false
```

```bash
# Edit PHP production settings
sudo nano /etc/php/8.2/fpm/php.ini
```

Update these settings:

```ini
; Production settings
memory_limit = 256M
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 60
max_input_vars = 3000

; Error handling (production)
display_errors = Off
log_errors = On
error_log = /var/log/php/errors.log

; OPcache (production)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
```

```bash
# Create PHP log directory
sudo mkdir -p /var/log/php
sudo chown www-data:www-data /var/log/php

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
sudo systemctl enable php8.2-fpm
```

### Install Composer

```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Verify installation
composer --version
```

---

## Step 4: Node.js Setup

### Install Node.js 18 LTS

```bash
# Install NodeSource repository
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -

# Install Node.js and npm
sudo apt install -y nodejs

# Verify installation
node --version  # Should show v18.x.x
npm --version

# Install pnpm (faster package manager)
sudo npm install -g pnpm
```

---

## Step 5: FreeRADIUS Installation

### Install FreeRADIUS Packages

```bash
# Install FreeRADIUS with MySQL support
sudo apt install -y freeradius freeradius-mysql freeradius-utils

# Stop FreeRADIUS for configuration
sudo systemctl stop freeradius
```

### Create FreeRADIUS Database Tables

```bash
# Login to MySQL
sudo mysql -u root -p
```

```sql
-- Use central database for FreeRADIUS tables
USE onlifi_central;

-- FreeRADIUS will create its own tables, but we need to ensure the user has access
-- The Laravel migrations will create radcheck, radreply, radacct tables

-- Verify radius user can access
SHOW GRANTS FOR 'radius'@'localhost';
EXIT;
```

### Backup Default FreeRADIUS Config

```bash
# Backup default configuration
sudo cp -r /etc/freeradius/3.0 /etc/freeradius/3.0.default.backup
```

---

## Step 6: Nginx Web Server

### Install Nginx

```bash
sudo apt install -y nginx

# Start and enable Nginx
sudo systemctl start nginx
sudo systemctl enable nginx
```

### Configure Nginx for Backend API

```bash
# Create backend configuration
sudo nano /etc/nginx/sites-available/onlifi-api
```

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/onlifi-laravel/backend/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Gzip compression
gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript;

    # Handle PHP files
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        
        # Performance
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
        fastcgi_read_timeout 60s;
    }

    # Handle all other requests
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Deny access to hidden files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Deny access to sensitive files
    location ~ ^/(\.env|\.git|composer\.|artisan|package\.json|phpunit\.xml) {
        deny all;
    }

    # Logging
    access_log /var/log/nginx/onlifi-api-access.log;
    error_log /var/log/nginx/onlifi-api-error.log;
}
```

### Configure Nginx for Frontend

```bash
# Create frontend configuration
sudo nano /etc/nginx/sites-available/onlifi-dashboard
```

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    root /var/www/onlifi-laravel/frontend/dist;
    index index.html;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript text/xml;

    # Static assets - long cache
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Handle SPA routing
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API proxy (if using same domain)
    location /api {
        proxy_pass http://localhost:8000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
        proxy_read_timeout 60s;
    }

    # Deny access to hidden files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Logging
    access_log /var/log/nginx/onlifi-dashboard-access.log;
    error_log /var/log/nginx/onlifi-dashboard-error.log;
}
```

### Enable Sites

```bash
# Enable sites
sudo ln -s /etc/nginx/sites-available/onlifi-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/onlifi-dashboard /etc/nginx/sites-enabled/

# Remove default site
sudo rm /etc/nginx/sites-enabled/default

# Test Nginx configuration
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

---

## Step 7: Backend Deployment

### Clone Repository

```bash
# Switch to application user
sudo su - onlifi

# Clone repository (replace with your repo URL)
cd /var/www/onlifi-laravel
git clone <your-repo-url> .

# Or if using local files, copy them to this directory
cd /var/www/onlifi-laravel
```

### Install Dependencies

```bash
cd /var/www/onlifi-laravel/backend

# Install PHP dependencies (no dev for production)
composer install --optimize-autoloader --no-dev

# If you need dev dependencies temporarily for migrations
# composer install --optimize-autoloader
```

### Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Edit environment file
nano .env
```

Production `.env` configuration:

```env
APP_NAME="OnLiFi WiFi Management"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

# Logging
LOG_CHANNEL=daily
LOG_LEVEL=warning

# Database - Central
CENTRAL_DB_CONNECTION=mysql
CENTRAL_DB_HOST=127.0.0.1
CENTRAL_DB_PORT=3306
CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_app
CENTRAL_DB_PASSWORD=YourStrongPasswordHere!

# Database - Default (fallback)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=onlifi_central
DB_USERNAME=onlifi_app
DB_PASSWORD=YourStrongPasswordHere!

# Session and Cache
SESSION_DRIVER=database
CACHE_DRIVER=file
QUEUE_CONNECTION=database

# YO! Payments (Mobile Money)
YOAPI_USERNAME=your_yo_username
YOAPI_PASSWORD=your_yo_password
YOAPI_MODE=production
YOAPI_URL=https://paymentsapi1.yo.co.ug/ybs/taskmanager/processing

# RADIUS Configuration
RADIUS_SERVER_IP=127.0.0.1
RADIUS_AUTH_PORT=1812
RADIUS_ACCT_PORT=1813
RADIUS_SHARED_SECRET=RadiusStrongPassword!
RADIUS_AUTO_SYNC=true

# Frontend URL (for CORS)
FRONTEND_URL=https://yourdomain.com
CORS_ALLOWED_ORIGINS=https://yourdomain.com

# Mail (configure for your provider)
MAIL_MAILER=smtp
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD=your_mail_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="OnLiFi System"
```

### Generate Application Key

```bash
php artisan key:generate
```

### Run Migrations

```bash
# Run central database migrations
php artisan migrate --database=central --force

# If you need to run tenant migrations
php artisan migrate --database=tenant --path=database/migrations/tenant --force
```

### Optimize for Production

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize
```

### Set Permissions

```bash
# Exit to root user first
exit

# Set proper ownership
sudo chown -R onlifi:www-data /var/www/onlifi-laravel/backend

# Set directory permissions
sudo chmod -R 775 /var/www/onlifi-laravel/backend/storage
sudo chmod -R 775 /var/www/onlifi-laravel/backend/bootstrap/cache

# Set file permissions
sudo find /var/www/onlifi-laravel/backend/storage -type f -exec chmod 664 {} \;
sudo find /var/www/onlifi-laravel/backend/bootstrap/cache -type f -exec chmod 664 {} \;
```

### Setup Laravel Scheduler (Cron)

```bash
# Edit crontab
sudo crontab -u onlifi -e
```

Add this line:

```cron
* * * * * cd /var/www/onlifi-laravel/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## Step 8: Frontend Deployment

### Install Dependencies

```bash
# Switch to application user
sudo su - onlifi

cd /var/www/onlifi-laravel/frontend

# Install Node.js dependencies
pnpm install
```

### Configure Environment

```bash
cp .env.example .env
nano .env
```

Production `.env`:

```env
VITE_API_URL=https://api.yourdomain.com/api
VITE_APP_NAME="OnLiFi Dashboard"
VITE_ORIGIN_SITE=Production
```

### Build for Production

```bash
# Build production bundle
pnpm run build

# The dist/ folder will be created with optimized static files
```

### Verify Build

```bash
# Check dist folder exists and has files
ls -la /var/www/onlifi-laravel/frontend/dist/

# Should contain:
# - index.html
# - assets/ (JS, CSS files)
```

---

## Step 9: FreeRADIUS Configuration

### Configure SQL Module

```bash
sudo nano /etc/freeradius/3.0/mods-available/sql
```

Update the configuration:

```
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    server = "localhost"
    port = 3306
    login = "radius"
    password = "RadiusStrongPassword!"
    radius_db = "onlifi_central"
    
    # Connection pooling
    pool {
        start = ${thread[pool].start_servers}
        min = ${thread[pool].min_spare_servers}
        max = ${thread[pool].max_servers}
        spare = ${thread[pool].max_spare_servers}
        uses = 0
        lifetime = 0
        cleanup_interval = 30
        idle_timeout = 60
        retry_delay = 30
    }
    
    # Table names
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    
    # Read clients from SQL (optional)
    read_clients = yes
    client_table = "nas"
    
    # Group attributes
    group_attribute = "SQL-Group"
    
    # Deactivate unknown users
    allow_vulnerable_openssl = no
}
```

### Enable SQL Module

```bash
# Create symlink to enable module
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/
```

### Configure Sites

```bash
# Edit default site
sudo nano /etc/freeradius/3.0/sites-available/default
```

Ensure these sections are uncommented/enabled:

```
server default {
    listen {
        type = auth
        ipaddr = *
        port = 1812
    }
    
    listen {
        ipaddr = *
        port = 1813
        type = acct
    }
    
    authorize {
        preprocess
        filter_username
        suffix
        sql
        if (!ok) {
            reject
        }
        expiration
        logintime
    }
    
    authenticate {
        Auth-Type PAP {
            pap
        }
    }
    
    post-auth {
        sql
        if (session-state:User-Name && reply:Session-Timeout) {
            update reply {
                Session-Timeout := "%{reply:Session-Timeout}"
            }
        }
    }
    
    preacct {
        preprocess
        acct_unique
        suffix
        sql
    }
    
    accounting {
        sql
    }
    
    session {
        sql
    }
}
```

### Start FreeRADIUS

```bash
# Test configuration
sudo freeradius -C

# If no errors, start service
sudo systemctl start freeradius
sudo systemctl enable freeradius

# Check status
sudo systemctl status freeradius

# Check logs
sudo tail -f /var/log/freeradius/radius.log
```

---

## Step 10: SSL Certificates

### Install Certbot

```bash
sudo apt install -y certbot python3-certbot-nginx
```

### Obtain Certificates

```bash
# Backend API
sudo certbot --nginx -d api.yourdomain.com --agree-tos --non-interactive --email admin@yourdomain.com

# Frontend Dashboard
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com --agree-tos --non-interactive --email admin@yourdomain.com
```

### Auto-Renewal

```bash
# Test auto-renewal
sudo certbot renew --dry-run

# Add to cron (certbot already adds a systemd timer)
sudo systemctl list-timers | grep certbot
```

---

## Step 11: Post-Deployment Setup

### Create Super Admin

```bash
# Switch to application user
sudo su - onlifi
cd /var/www/onlifi-laravel/backend

# Create super admin using tinker
php artisan tinker
```

```php
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Hash;

$admin = SuperAdmin::create([
    'name' => 'System Administrator',
    'email' => 'admin@yourdomain.com',
    'password' => Hash::make('YourSecureAdminPassword!'),
    'role' => 'super_admin',
    'is_active' => true,
]);

echo "Admin created with ID: " . $admin->id;
exit;
```

### Test API Health

```bash
# Test health endpoint
curl https://api.yourdomain.com/api/health

# Expected response:
# {"status":"ok","timestamp":"2024-01-01T00:00:00+00:00","timezone":"Africa/Nairobi"}
```

### Test Login

```bash
# Test super admin login
curl -X POST https://api.yourdomain.com/api/super-admin/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@yourdomain.com",
    "password": "YourSecureAdminPassword!"
  }'

# Should return token and admin info
```

---

## Step 12: MikroTik Configuration

### 1. Install Telemetry Script

Download the telemetry script from your dashboard:

1. Login to dashboard: `https://yourdomain.com`
2. Go to Settings > Sites
3. Select your site
4. Download telemetry script
5. Copy script content

In MikroTik Terminal:

```bash
# Add script
/system script add name=onlifi-telemetry source="<paste script here>"

# Run script manually to test
/system script run onlifi-telemetry

# Check logs
/log print where topics~"onlifi"
```

### 2. Configure RADIUS on MikroTik

```bash
# Add RADIUS server
/radius add \
    service=hotspot,login \
    address=<your-freeradius-ip> \
    secret="RadiusStrongPassword!" \
    timeout=5s \
    authentication-port=1812 \
    accounting-port=1813 \
    comment="OnLiFi RADIUS"

# Set NAS-Identifier (must match registered router)
/system identity set name="YOUR_ROUTER_IDENTIFIER"

# Configure hotspot profile
/ip hotspot profile set [find] use-radius=yes radius-accounting=yes

# Test RADIUS connection
/radius monitor [find]
```

### 3. Test Authentication

Connect to hotspot and try to login with a voucher code.

---

## Maintenance & Monitoring

### Log Rotation

```bash
# Edit logrotate config
sudo nano /etc/logrotate.d/onlifi
```

```
/var/www/onlifi-laravel/backend/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 664 onlifi www-data
    sharedscripts
    postrotate
        /usr/bin/systemctl reload php8.2-fpm > /dev/null 2>&1 || true
    endscript
}
```

### Database Backup Script

```bash
sudo nano /usr/local/bin/backup-onlifi.sh
```

```bash
#!/bin/bash

BACKUP_DIR="/var/backups/onlifi"
DATE=$(date +%Y%m%d_%H%M%S)
MYSQL_USER="onlifi_app"
MYSQL_PASS="YourStrongPasswordHere!"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup central database
mysqldump -u $MYSQL_USER -p$MYSQL_PASS onlifi_central > $BACKUP_DIR/central_$DATE.sql

# Backup all tenant databases
for db in $(mysql -u $MYSQL_USER -p$MYSQL_PASS -e "SHOW DATABASES LIKE 'onlifi_%';" | grep -v Database); do
    if [ "$db" != "onlifi_central" ]; then
        mysqldump -u $MYSQL_USER -p$MYSQL_PASS $db > $BACKUP_DIR/${db}_$DATE.sql
    fi
done

# Compress backups
gzip $BACKUP_DIR/*.sql

# Keep only last 30 days
find $BACKUP_DIR -name "*.gz" -mtime +30 -delete

# Log backup completion
echo "[$(date)] Backup completed: $DATE" >> /var/log/onlifi-backup.log
```

```bash
# Make executable and add to cron
sudo chmod +x /usr/local/bin/backup-onlifi.sh
sudo crontab -e
```

```cron
# Daily backup at 2 AM
0 2 * * * /usr/local/bin/backup-onlifi.sh
```

### Monitoring Commands

```bash
# Check services status
sudo systemctl status nginx
sudo systemctl status php8.2-fpm
sudo systemctl status mysql
sudo systemctl status freeradius

# Monitor logs
sudo tail -f /var/log/nginx/onlifi-api-error.log
sudo tail -f /var/www/onlifi-laravel/backend/storage/logs/laravel.log
sudo tail -f /var/log/freeradius/radius.log

# Check disk space
df -h

# Check memory usage
free -h

# Check active connections
sudo netstat -tulpn | grep -E ':(80|443|1812|1813)'
```

---

## Troubleshooting

### Common Issues

#### 1. 500 Internal Server Error

```bash
# Check Laravel logs
tail -f /var/www/onlifi-laravel/backend/storage/logs/laravel.log

# Clear caches
sudo su - onlifi
cd /var/www/onlifi-laravel/backend
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

#### 2. Database Connection Failed

```bash
# Test MySQL connection
mysql -u onlifi_app -p -e "SHOW DATABASES;"

# Check MySQL status
sudo systemctl status mysql

# Check error logs
sudo tail -f /var/log/mysql/error.log
```

#### 3. FreeRADIUS Not Responding

```bash
# Test RADIUS configuration
sudo freeradius -C

# Check RADIUS logs
sudo tail -f /var/log/freeradius/radius.log

# Check if ports are listening
sudo netstat -tulpn | grep freeradius
```

#### 4. Permission Denied Errors

```bash
# Fix permissions
sudo chown -R onlifi:www-data /var/www/onlifi-laravel
sudo chmod -R 775 /var/www/onlifi-laravel/backend/storage
sudo chmod -R 775 /var/www/onlifi-laravel/backend/bootstrap/cache
```

#### 5. CORS Errors in Frontend

Check `CORS_ALLOWED_ORIGINS` in backend `.env` matches your frontend domain.

---

## Security Checklist

- [ ] Change all default passwords
- [ ] Enable UFW firewall
- [ ] SSL certificates installed and auto-renewing
- [ ] APP_DEBUG=false in production
- [ ] Strong MySQL passwords
- [ ] FreeRADIUS secret is strong and unique
- [ ] Regular backups configured
- [ ] Log monitoring enabled
- [ ] PHP expose_php = Off
- [ ] Nginx security headers configured
- [ ] Regular security updates (`sudo apt update && sudo apt upgrade`)

---

## Next Steps

1. **Create First Tenant**: Login to dashboard and create a tenant
2. **Configure Payment Gateway**: Add YO! Payments credentials
3. **Add MikroTik Routers**: Register routers and download scripts
4. **Generate Vouchers**: Create voucher batches for testing
5. **Test End-to-End**: Complete a payment and authentication flow

---

**Installation Complete!**

Your OnLiFi multi-tenant WiFi management system is now running in production.

For support, refer to:
- `README.md` - General documentation
- `SETUP_GUIDE.md` - Development setup
- `DEPLOYMENT.md` - Additional deployment details
- `FREERADIUS_CONFIGURATION.md` - RADIUS configuration
