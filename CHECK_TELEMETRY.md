# Telemetry Troubleshooting Guide

## Step 1: Check if Migration Was Run

```bash
# On your server
cd /var/www/onlifi
php artisan migrate:status --database=central
```

Look for: `2024_01_01_000008_create_central_router_telemetry_table` - should show "Ran"

If it shows "Pending", run:
```bash
php artisan migrate --database=central --force
```

---

## Step 2: Check if Table Exists

```sql
-- Connect to MySQL
mysql -u onlifi -p

-- Switch to central database
USE onlifi_central;

-- Check if table exists
SHOW TABLES LIKE 'router_telemetry';

-- If table exists, check structure
DESCRIBE router_telemetry;

-- Check if there's any data
SELECT COUNT(*) FROM router_telemetry;

-- See latest records
SELECT id, router_identity, cpu_load, active_connections, created_at 
FROM router_telemetry 
ORDER BY created_at DESC 
LIMIT 5;
```

---

## Step 3: Test Telemetry Endpoint Manually

```bash
# Get your auth token first
# Login to the app and check browser DevTools > Application > Local Storage
# Look for 'auth_token' or similar

# Test telemetry endpoint
curl -X GET "http://192.168.0.180:8000/api/telemetry/stats" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

Expected response:
```json
{
  "total_active_users": 0,
  "total_routers": 1,
  "online_routers": 1,
  "avg_cpu": 25.5,
  "avg_memory": 45.2,
  "routers": [...]
}
```

---

## Step 4: Check Laravel Logs

```bash
# Watch logs in real-time
tail -f /var/www/onlifi/storage/logs/laravel.log

# Then trigger telemetry from MikroTik
# On MikroTik terminal:
/system script run telemetry_script
```

You should see:
```
[timestamp] local.INFO: Telemetry received
[timestamp] local.INFO: Telemetry authenticated successfully
[timestamp] local.INFO: Telemetry stored successfully
```

If you see errors like:
- "router_telemetry table does not exist" → Run migration
- "Invalid API token" → Check site API token
- "SQLSTATE" errors → Database connection issue

---

## Step 5: Verify MikroTik Script is Sending Data

On MikroTik terminal:
```
# Check if script exists
/system script print

# Run the telemetry script manually
/system script run telemetry_script

# Check scheduler (should run every 5 minutes)
/system scheduler print
```

Expected output:
```
OnLiFi: Collecting telemetry...
OnLiFi: CPU=25% Memory=128MB Users=3
OnLiFi: Sending to dashboard...
OnLiFi: Response: 200
```

If you see:
- "Response: 401" → API token is wrong
- "Response: 500" → Server error (check Laravel logs)
- "timeout" → Network/firewall issue

---

## Step 6: Check Frontend Console

Open browser DevTools (F12) > Console tab, then refresh dashboard.

Look for:
```
Fetching telemetry from: /api/telemetry/stats
Telemetry response status: 200
Telemetry response: {total_active_users: 0, ...}
```

If you see:
- "401 Unauthorized" → Auth token expired or missing
- "404 Not Found" → Route not registered (run `php artisan route:clear`)
- "500 Internal Server Error" → Check Laravel logs
- "No telemetry data received" → API returned null/empty

---

## Common Issues & Fixes

### Issue 1: Table Doesn't Exist
**Symptom:** Error "router_telemetry table does not exist"
**Fix:**
```bash
php artisan migrate --database=central --force
```

### Issue 2: Migration Already Ran But Table Still Missing
**Symptom:** Migration shows "Ran" but table doesn't exist
**Fix:**
```bash
# Drop and recreate
mysql -u onlifi -p onlifi_central -e "DROP TABLE IF EXISTS router_telemetry;"
php artisan migrate:refresh --database=central --path=/database/migrations/2024_01_01_000008_create_central_router_telemetry_table.php
```

### Issue 3: Data Being Stored But Not Showing
**Symptom:** Data in database but API returns empty
**Fix:**
```bash
# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

### Issue 4: Wrong Database Connection
**Symptom:** TelemetryController queries wrong database
**Fix:** Already fixed in latest code - make sure you pulled latest:
```bash
cd /var/www/onlifi
git pull origin main
```

### Issue 5: Frontend Not Authenticated
**Symptom:** 401 errors in browser console
**Fix:** 
- Logout and login again
- Check if token is being sent in request headers

---

## Quick Diagnostic Commands

Run these in order and share the output:

```bash
# 1. Check migration status
php artisan migrate:status --database=central | grep router_telemetry

# 2. Check if table exists and has data
mysql -u onlifi -p -e "USE onlifi_central; SELECT COUNT(*) as total_records FROM router_telemetry;"

# 3. Check latest telemetry record
mysql -u onlifi -p -e "USE onlifi_central; SELECT * FROM router_telemetry ORDER BY created_at DESC LIMIT 1\G"

# 4. Test API endpoint (replace TOKEN)
curl -s "http://localhost:8000/api/telemetry/stats" \
  -H "Authorization: Bearer YOUR_TOKEN" | jq

# 5. Check Laravel logs for errors
tail -20 /var/www/onlifi/storage/logs/laravel.log | grep -i error
```

---

## If Still Not Working

Share the output of:
1. `php artisan migrate:status --database=central`
2. `mysql -u onlifi -p -e "SHOW TABLES FROM onlifi_central;"`
3. `mysql -u onlifi -p -e "SELECT COUNT(*) FROM onlifi_central.router_telemetry;"`
4. Browser console logs when loading dashboard
5. Laravel log errors: `tail -50 storage/logs/laravel.log`
