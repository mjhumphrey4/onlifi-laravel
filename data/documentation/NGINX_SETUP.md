# Nginx Setup Guide for Onlifi-Vanilla

## Current Configuration

Your Nginx is serving the React app from:
```
/var/www/html/newdashboard/dist
```

The API files are located at:
```
/var/www/html/newdashboard/api/auth_api.php
/var/www/html/newdashboard/api/mikrotik_api.php
```

## Required Nginx Configuration

### Option 1: Update Existing Server Block

If you already have a server block for `192.168.0.180`, add these location blocks:

```nginx
# Handle API requests FIRST (before SPA catch-all)
location /newdashboard/api/ {
    root /var/www/html;
    try_files $uri $uri/ =404;
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}

# Serve React SPA
location /newdashboard/ {
    alias /var/www/html/newdashboard/dist/;
    try_files $uri $uri/ /newdashboard/index.html;
}
```

### Option 2: Use Complete Configuration File

Copy the provided `nginx-onlifi.conf` to your server:

```bash
# On your server (192.168.0.180)
sudo cp nginx-onlifi.conf /etc/nginx/sites-available/onlifi.conf
sudo ln -s /etc/nginx/sites-available/onlifi.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Important: PHP-FPM Socket Path

Check your PHP-FPM socket path:

```bash
# Find your PHP version
php -v

# Common socket paths:
# PHP 8.1: /var/run/php/php8.1-fpm.sock
# PHP 8.0: /var/run/php/php8.0-fpm.sock
# PHP 7.4: /var/run/php/php7.4-fpm.sock

# Verify socket exists
ls -la /var/run/php/
```

Update the `fastcgi_pass` line in your Nginx config to match your PHP version.

## Verify Configuration

### 1. Test Nginx Config
```bash
sudo nginx -t
```

### 2. Reload Nginx
```bash
sudo systemctl reload nginx
```

### 3. Check Nginx Status
```bash
sudo systemctl status nginx
```

### 4. Test API Endpoint
```bash
curl http://192.168.0.180/newdashboard/api/auth_api.php?action=me
```

Expected response (when not logged in):
```json
{"success":false,"error":"Not authenticated"}
```

### 5. Test React App
Open browser: `http://192.168.0.180/newdashboard/`

You should see the login page without console errors.

## Troubleshooting

### Issue: 404 on API calls

**Check:**
1. Nginx config has `/newdashboard/api/` location block
2. Location block comes BEFORE the SPA catch-all
3. PHP files exist at `/var/www/html/newdashboard/api/`

**Fix:**
```bash
# Verify files exist
ls -la /var/www/html/newdashboard/api/

# Check Nginx error log
sudo tail -f /var/log/nginx/error.log
```

### Issue: 502 Bad Gateway

**Cause:** PHP-FPM not running or wrong socket path

**Fix:**
```bash
# Check PHP-FPM status
sudo systemctl status php8.1-fpm

# Start if stopped
sudo systemctl start php8.1-fpm

# Check socket path
ls -la /var/run/php/
```

### Issue: Blank page or routing errors

**Cause:** SPA routing not configured properly

**Fix:**
Ensure `try_files $uri $uri/ /newdashboard/index.html;` is in the location block.

### Issue: Permission denied

**Cause:** Wrong file permissions

**Fix:**
```bash
cd /var/www/html
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
```

## File Structure

Your server should have this structure:

```
/var/www/html/
├── newdashboard/
│   ├── dist/              # React build output (served by Nginx)
│   │   ├── index.html
│   │   ├── assets/
│   │   └── ...
│   ├── api/               # PHP API files
│   │   ├── auth_api.php
│   │   ├── mikrotik_api.php
│   │   └── api.php
│   ├── src/               # Source files (not needed on server)
│   └── ...
├── database/              # SQL schemas
├── config.php
├── config_multitenant.php
├── MikrotikAPI.php
└── ...
```

## Access URLs

After proper configuration:

- **Dashboard:** `http://192.168.0.180/newdashboard/`
- **Signup:** `http://192.168.0.180/newdashboard/signup`
- **Login:** `http://192.168.0.180/newdashboard/login`
- **API Test:** `http://192.168.0.180/newdashboard/api/auth_api.php?action=me`

## Security Recommendations

1. **Use HTTPS** - Set up SSL certificate
2. **Firewall** - Restrict access to necessary ports
3. **Rate Limiting** - Add Nginx rate limiting for API endpoints
4. **Database** - Ensure MySQL only accepts local connections
5. **File Permissions** - Keep strict permissions on config files

```bash
# Protect config files
sudo chmod 640 /var/www/html/config*.php
sudo chown www-data:www-data /var/www/html/config*.php
```

## Quick Checklist

- [ ] Nginx config has `/newdashboard/api/` location block
- [ ] API location block comes BEFORE SPA location block
- [ ] PHP-FPM socket path is correct
- [ ] File permissions are set (755 for dirs, 644 for files)
- [ ] Nginx config test passes (`sudo nginx -t`)
- [ ] Nginx reloaded (`sudo systemctl reload nginx`)
- [ ] API endpoint responds (test with curl)
- [ ] React app loads in browser
- [ ] No console errors in browser
- [ ] Can navigate to `/signup` and `/login`

## Current Status

Based on your setup:
- ✅ Nginx pointing to `/var/www/html/newdashboard/dist`
- ✅ API paths in React app: `/newdashboard/api/auth_api.php`
- ⚠️ **Need to verify:** Nginx has proper location blocks for API

The paths in the React app are correct. You just need to ensure Nginx is configured to handle both:
1. Static files from `/newdashboard/dist/`
2. PHP execution for `/newdashboard/api/*.php`
