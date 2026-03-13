# OnLiFi Deployment Guide

Complete guide for deploying OnLiFi to a production server.

## Prerequisites

- Ubuntu 20.04+ or similar Linux distribution
- PHP 8.1+
- MySQL 8.0+
- Composer
- Nginx or Apache
- Node.js 18+ (for frontend)

---

## Server Setup

### 1. Install Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.1 and extensions
sudo apt install -y php8.1 php8.1-fpm php8.1-mysql php8.1-mbstring \
    php8.1-xml php8.1-bcmath php8.1-curl php8.1-zip php8.1-gd

# Install MySQL
sudo apt install -y mysql-server

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Nginx
sudo apt install -y nginx

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs
```

### 2. Configure MySQL

```bash
sudo mysql_secure_installation

# Create databases and user
sudo mysql -u root -p
```

```sql
-- Create central database
CREATE DATABASE onlifi_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create database user
CREATE USER 'onlifi_user'@'localhost' IDENTIFIED BY 'your_secure_password';

-- Grant privileges
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'onlifi_user'@'localhost';
GRANT CREATE ON *.* TO 'onlifi_user'@'localhost';
FLUSH PRIVILEGES;

EXIT;
```

---

## Backend Deployment

### 1. Clone Repository

```bash
cd /var/www
sudo git clone https://github.com/yourusername/onlifi.git
sudo chown -R www-data:www-data onlifi
cd onlifi/backend
```

### 2. Setup Directories and Permissions

```bash
# Run the setup script
chmod +x setup-directories.sh
sudo ./setup-directories.sh
```

**Or manually:**

```bash
mkdir -p bootstrap/cache
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p storage/app/public

chmod -R 775 bootstrap/cache storage
sudo chown -R www-data:www-data bootstrap/cache storage
```

### 3. Install Composer Dependencies

```bash
composer install --optimize-autoloader --no-dev
```

### 4. Configure Environment

```bash
cp .env.example .env
nano .env
```

**Update these values:**

```env
APP_NAME=OnLiFi
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=onlifi_central
DB_USERNAME=onlifi_user
DB_PASSWORD=your_secure_password

# Tenant database prefix
TENANT_DB_PREFIX=onlifi_tenant_

# Session and cache
SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync

# Mail configuration (optional)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

### 5. Generate Application Key

```bash
php artisan key:generate
```

### 6. Run Migrations

```bash
# Run central database migrations
php artisan migrate --database=central

# Create super admin
php artisan db:seed --class=SuperAdminSeeder
```

**Default admin credentials:**
- Email: `admin@onlifi.com`
- Password: `admin123`

⚠️ **Change this password immediately after first login!**

### 7. Link Storage

```bash
php artisan storage:link
```

### 8. Optimize for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Frontend Deployment

### 1. Install Dependencies

```bash
cd /var/www/onlifi/frontend
npm install
```

### 2. Configure Environment

```bash
cp .env.example .env
nano .env
```

```env
VITE_API_URL=https://yourdomain.com/api
VITE_APP_NAME=OnLiFi
```

### 3. Build for Production

```bash
npm run build
```

This creates optimized files in the `dist` directory.

---

## Nginx Configuration

### 1. Create Nginx Config

```bash
sudo nano /etc/nginx/sites-available/onlifi
```

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    
    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    
    root /var/www/onlifi/frontend/dist;
    index index.html;
    
    # SSL certificates (use Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    
    # Frontend (SPA)
    location / {
        try_files $uri $uri/ /index.html;
    }
    
    # Backend API
    location /api {
        alias /var/www/onlifi/backend/public;
        try_files $uri $uri/ @backend;
        
        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/onlifi/backend/public/index.php;
            include fastcgi_params;
        }
    }
    
    location @backend {
        rewrite /api/(.*)$ /api/index.php?/$1 last;
    }
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    
    # Logs
    access_log /var/log/nginx/onlifi-access.log;
    error_log /var/log/nginx/onlifi-error.log;
}
```

### 2. Enable Site

```bash
sudo ln -s /etc/nginx/sites-available/onlifi /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 3. Setup SSL with Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

---

## FreeRADIUS Setup

### 1. Install FreeRADIUS

```bash
sudo apt install -y freeradius freeradius-mysql
```

### 2. Configure SQL Module

```bash
sudo nano /etc/freeradius/3.0/mods-available/sql
```

```
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    server = "localhost"
    port = 3306
    login = "onlifi_user"
    password = "your_secure_password"
    radius_db = "onlifi_central"
    
    # Read clients from database
    read_clients = yes
    client_table = "nas"
}
```

### 3. Enable SQL Module

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/
sudo systemctl restart freeradius
```

### 4. Test FreeRADIUS

```bash
sudo freeradius -X
```

---

## Post-Deployment Checklist

### Security

- [ ] Change default super admin password
- [ ] Configure firewall (UFW)
- [ ] Enable fail2ban
- [ ] Setup automatic backups
- [ ] Configure log rotation
- [ ] Review file permissions

### Firewall Setup

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 1812/udp  # RADIUS auth
sudo ufw allow 1813/udp  # RADIUS accounting
sudo ufw enable
```

### Monitoring

- [ ] Setup server monitoring (Uptime Kuma, etc.)
- [ ] Configure error logging
- [ ] Setup database backups
- [ ] Monitor disk space

### Testing

- [ ] Test super admin login
- [ ] Create test tenant
- [ ] Approve test tenant
- [ ] Generate test vouchers
- [ ] Test RADIUS authentication
- [ ] Test router telemetry

---

## Backup Strategy

### Database Backup Script

Create `/var/www/onlifi/backup-db.sh`:

```bash
#!/bin/bash

BACKUP_DIR="/var/backups/onlifi"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR

# Backup central database
mysqldump -u onlifi_user -p'your_secure_password' onlifi_central > \
    $BACKUP_DIR/central_$DATE.sql

# Backup all tenant databases
mysql -u onlifi_user -p'your_secure_password' -e "SHOW DATABASES LIKE 'onlifi_tenant_%'" | \
    grep onlifi_tenant_ | while read db; do
    mysqldump -u onlifi_user -p'your_secure_password' $db > \
        $BACKUP_DIR/${db}_$DATE.sql
done

# Compress backups
tar -czf $BACKUP_DIR/onlifi_backup_$DATE.tar.gz $BACKUP_DIR/*_$DATE.sql
rm $BACKUP_DIR/*_$DATE.sql

# Keep only last 7 days
find $BACKUP_DIR -name "*.tar.gz" -mtime +7 -delete

echo "Backup completed: $BACKUP_DIR/onlifi_backup_$DATE.tar.gz"
```

### Schedule Daily Backups

```bash
chmod +x /var/www/onlifi/backup-db.sh
sudo crontab -e
```

Add:
```
0 2 * * * /var/www/onlifi/backup-db.sh >> /var/log/onlifi-backup.log 2>&1
```

---

## Troubleshooting

### Issue: Composer install fails

```bash
# Clear composer cache
composer clear-cache

# Install with verbose output
composer install -vvv
```

### Issue: Permission denied errors

```bash
sudo chown -R www-data:www-data /var/www/onlifi
sudo chmod -R 775 /var/www/onlifi/backend/storage
sudo chmod -R 775 /var/www/onlifi/backend/bootstrap/cache
```

### Issue: 500 Internal Server Error

```bash
# Check Laravel logs
tail -f /var/www/onlifi/backend/storage/logs/laravel.log

# Check Nginx logs
tail -f /var/log/nginx/onlifi-error.log

# Check PHP-FPM logs
tail -f /var/log/php8.1-fpm.log
```

### Issue: Database connection failed

```bash
# Test MySQL connection
mysql -u onlifi_user -p -h localhost onlifi_central

# Check .env database credentials
cat /var/www/onlifi/backend/.env | grep DB_
```

### Issue: RADIUS not authenticating

```bash
# Test RADIUS in debug mode
sudo freeradius -X

# Check RADIUS logs
tail -f /var/log/freeradius/radius.log

# Test with radtest
radtest testuser testpass localhost 0 testing123
```

---

## Maintenance

### Update Application

```bash
cd /var/www/onlifi
sudo git pull origin main

# Backend
cd backend
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Frontend
cd ../frontend
npm install
npm run build

# Restart services
sudo systemctl reload nginx
sudo systemctl reload php8.1-fpm
```

### Clear Cache

```bash
cd /var/www/onlifi/backend
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## Performance Optimization

### Enable OPcache

```bash
sudo nano /etc/php/8.1/fpm/php.ini
```

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

### Queue Workers (Optional)

For better performance with background jobs:

```bash
sudo nano /etc/systemd/system/onlifi-worker.service
```

```ini
[Unit]
Description=OnLiFi Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/onlifi/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable onlifi-worker
sudo systemctl start onlifi-worker
```

---

## Summary

Your OnLiFi system is now deployed and ready for production use!

**Key URLs:**
- Frontend: `https://yourdomain.com`
- Admin Panel: `https://yourdomain.com/admin/login`
- API: `https://yourdomain.com/api`

**Next Steps:**
1. Login to admin panel and change default password
2. Configure system settings
3. Create your first tenant
4. Setup MikroTik routers
5. Generate vouchers
6. Monitor system health

**Support:**
- Check logs: `/var/www/onlifi/backend/storage/logs/`
- Review documentation: `ADMIN_PANEL_GUIDE.md`, `CRITICAL_FEATURES_VERIFICATION.md`
