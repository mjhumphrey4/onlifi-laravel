# Deployment Steps for Onlifi-Vanilla

## Quick Deployment Guide

### Step 1: Deploy Nginx Configuration

SSH into your server and update the Nginx configuration:

```bash
# SSH to server
ssh hum@192.168.0.180

# Copy the new Nginx config
sudo nano /etc/nginx/sites-available/onlifi.conf
```

Paste this configuration:

```nginx
server {
    listen 80;
    server_name 192.168.0.180;
    
    root /var/www/html/newdashboard;
    index index.html index.php;

    # Logging
    access_log /var/log/nginx/onlifi-access.log;
    error_log /var/log/nginx/onlifi-error.log;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Handle API requests (MUST come before SPA catch-all)
    location /api/ {
        root /var/www/html/newdashboard;
        try_files $uri $uri/ =404;
        
        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Serve React SPA (catch-all for client-side routing)
    location / {
        root /var/www/html/newdashboard/dist;
        try_files $uri $uri/ /index.html;
        
        # Cache static assets
        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
            expires 1y;
            add_header Cache-Control "public, immutable";
        }
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ /\.git {
        deny all;
    }
}
```

### Step 2: Enable the Configuration

```bash
# Remove old config if exists
sudo rm /etc/nginx/sites-enabled/default

# Enable new config
sudo ln -sf /etc/nginx/sites-available/onlifi.conf /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# If test passes, reload Nginx
sudo systemctl reload nginx
```

### Step 3: Verify PHP-FPM is Running

```bash
# Check PHP-FPM status
sudo systemctl status php8.3-fpm

# If not running, start it
sudo systemctl start php8.3-fpm
sudo systemctl enable php8.3-fpm
```

### Step 4: Set Correct Permissions

```bash
cd /var/www/html
sudo chown -R hum:www-data .
sudo chmod -R 775 .
sudo find . -type f -exec chmod 664 {} \;
```

### Step 5: Create Central Database

```bash
# Import central auth database
mysql -u root -p < /var/www/html/database/central_auth_schema.sql

# Grant privileges to database user
mysql -u root -p
```

In MySQL:
```sql
GRANT ALL PRIVILEGES ON onlifi_central.* TO 'yo'@'localhost';
GRANT CREATE ON *.* TO 'yo'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 6: Test the Setup

```bash
# Test API endpoint
curl http://192.168.0.180/api/auth_api.php?action=me

# Expected response (JSON):
# {"success":false,"error":"Not authenticated"}
```

### Step 7: Trigger Jenkins Build

The Jenkins pipeline will:
1. Pull latest code from GitHub
2. Sync to server
3. Fix permissions
4. Build React app on server
5. Set web permissions

### Step 8: Access the Application

Open browser and navigate to:
- **Main App:** `http://192.168.0.180/`
- **Signup:** `http://192.168.0.180/signup`
- **Login:** `http://192.168.0.180/login`

## URL Structure

With the new configuration:

| URL | What It Serves |
|-----|----------------|
| `http://192.168.0.180/` | React SPA (from `/var/www/html/newdashboard/dist/`) |
| `http://192.168.0.180/signup` | Signup page (React Router) |
| `http://192.168.0.180/login` | Login page (React Router) |
| `http://192.168.0.180/api/auth_api.php` | Authentication API |
| `http://192.168.0.180/api/mikrotik_api.php` | MikroTik API |

## File Structure on Server

```
/var/www/html/
├── newdashboard/
│   ├── dist/                  # React build (served at /)
│   │   ├── index.html
│   │   ├── assets/
│   │   └── ...
│   ├── api/                   # PHP APIs (served at /api/)
│   │   ├── auth_api.php
│   │   ├── mikrotik_api.php
│   │   └── api.php
│   ├── node_modules/          # Build dependencies
│   └── src/                   # Source files
├── database/
│   ├── central_auth_schema.sql
│   └── mikrotik_schema.sql
├── config.php
├── config_multitenant.php
└── MikrotikAPI.php
```

## Troubleshooting

### Issue: 404 on API calls

**Check:**
```bash
# Verify API files exist
ls -la /var/www/html/newdashboard/api/

# Check Nginx error log
sudo tail -f /var/log/nginx/onlifi-error.log
```

### Issue: 502 Bad Gateway

**Fix:**
```bash
# Check PHP-FPM
sudo systemctl status php8.3-fpm
sudo systemctl restart php8.3-fpm

# Verify socket exists
ls -la /var/run/php/php8.3-fpm.sock
```

### Issue: Permission Denied

**Fix:**
```bash
cd /var/www/html
sudo chown -R hum:www-data .
sudo chmod -R 775 .
```

### Issue: Database Connection Failed

**Check:**
```bash
# Test MySQL connection
mysql -u yo -p

# Verify database exists
SHOW DATABASES;

# Check config file
cat /var/www/html/config_multitenant.php
```

### Issue: Blank Page

**Check:**
```bash
# Verify dist folder exists and has files
ls -la /var/www/html/newdashboard/dist/

# Check browser console for errors
# Check Nginx error log
sudo tail -f /var/log/nginx/onlifi-error.log
```

## Testing Checklist

- [ ] Nginx config test passes: `sudo nginx -t`
- [ ] Nginx reloaded: `sudo systemctl reload nginx`
- [ ] PHP-FPM running: `sudo systemctl status php8.3-fpm`
- [ ] API responds: `curl http://192.168.0.180/api/auth_api.php?action=me`
- [ ] React app loads: Open `http://192.168.0.180/` in browser
- [ ] No console errors in browser
- [ ] Can navigate to `/signup`
- [ ] Can navigate to `/login`
- [ ] Central database exists: `mysql -u yo -p -e "SHOW DATABASES;"`

## Default Admin Account

After database import:
- **Username:** `admin`
- **Password:** `Admin@123456`
- **Email:** `admin@onlifi.local`

## Next Steps After Deployment

1. **Change admin password** - Login and update to secure password
2. **Test signup flow** - Create a test user account
3. **Verify database provisioning** - Check that new user gets own database
4. **Test login** - Login with new user
5. **Check admin dashboard** - View users at `/users` (admin only)
6. **Configure SSL** - Set up HTTPS for production
7. **Set up backups** - Backup central database regularly

## Support

If issues persist:
1. Check `/var/log/nginx/onlifi-error.log`
2. Check `/var/log/php8.3-fpm.log`
3. Check browser console (F12)
4. Verify file permissions
5. Test database connectivity
