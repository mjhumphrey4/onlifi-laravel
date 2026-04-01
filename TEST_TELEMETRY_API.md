# Test Telemetry API - Step by Step

## Step 1: Verify Server Has Latest Code

```bash
cd /var/www/onlifi
git log -1 --oneline
# Should show: "Remove tenant filtering from telemetry..."

# If not, pull latest
git pull origin main
```

---

## Step 2: Clear ALL Caches

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm

# Verify routes are registered
php artisan route:list | grep telemetry
```

**Expected output:**
```
GET|HEAD  api/telemetry/latest .... TelemetryController@getLatest
GET|HEAD  api/telemetry/stats ..... TelemetryController@getStats
POST      api/telemetry ........... TelemetryController@receive
```

---

## Step 3: Test API Endpoint Directly (No Auth First)

```bash
# Test without auth to see if endpoint exists
curl -X GET "http://192.168.0.180:8000/api/telemetry/stats" \
  -H "Accept: application/json" \
  -v
```

**Expected:** Should return 401 Unauthorized (means endpoint exists, just needs auth)

**If you get 404:** Routes not registered - run `php artisan route:clear` again

---

## Step 4: Get Auth Token from Browser

1. Open your dashboard in browser
2. Press **F12** > **Application** tab > **Local Storage**
3. Look for key like `auth_token`, `token`, or `sanctum_token`
4. Copy the token value

OR check **Network** tab:
1. Refresh dashboard
2. Click any API request
3. Look at **Request Headers** > **Authorization: Bearer XXX**
4. Copy the token after "Bearer "

---

## Step 5: Test API with Auth Token

```bash
# Replace YOUR_TOKEN with actual token from browser
curl -X GET "http://192.168.0.180:8000/api/telemetry/stats" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  | jq
```

**Expected response:**
```json
{
  "total_active_users": 1,
  "total_routers": 2,
  "online_routers": 1,
  "avg_cpu": 0.5,
  "avg_memory": 7.23,
  "routers": [...]
}
```

---

## Step 6: Check Laravel Logs

```bash
# Watch logs in real-time
tail -f /var/www/onlifi/storage/logs/laravel.log

# In another terminal, make the API call again
curl -X GET "http://192.168.0.180:8000/api/telemetry/stats" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Look for:
```
[timestamp] local.INFO: Fetching telemetry stats
[timestamp] local.INFO: Found telemetry records {"count":2,"ids":[8,9]}
```

---

## Step 7: Check Browser Network Tab

1. Open dashboard in browser
2. Press **F12** > **Network** tab
3. Refresh the page
4. Filter by "telemetry" or "stats"

**Look for:**
- Request to `/api/telemetry/stats`
- Status code: 200 (success) or 401 (auth issue)
- Response data

**If you DON'T see any telemetry request:**
- Frontend is not calling the API
- Check browser console for JavaScript errors

---

## Step 8: Check Frontend API Call

Open browser console (F12 > Console) and manually test:

```javascript
// Get auth token from localStorage
const token = localStorage.getItem('auth_token') || localStorage.getItem('token');
console.log('Token:', token);

// Make API call
fetch('/api/telemetry/stats', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
  }
})
.then(r => r.json())
.then(data => console.log('Telemetry data:', data))
.catch(err => console.error('Error:', err));
```

---

## Common Issues & Solutions

### Issue 1: "No endpoint" in browser console
**Cause:** Frontend is not making the API call
**Solution:** Check frontend code, verify DashboardEnhanced.tsx is loaded

### Issue 2: 401 Unauthorized
**Cause:** Auth token is invalid or expired
**Solution:** Logout and login again to get fresh token

### Issue 3: 404 Not Found
**Cause:** Routes not registered
**Solution:** 
```bash
php artisan route:clear
php artisan config:clear
sudo systemctl restart php8.2-fpm
```

### Issue 4: 500 Internal Server Error
**Cause:** PHP error in TelemetryController
**Solution:** Check Laravel logs:
```bash
tail -50 /var/www/onlifi/storage/logs/laravel.log
```

### Issue 5: Empty response `{total_active_users: 0, ...}`
**Cause:** Database query returning no results
**Solution:** Check if data exists:
```sql
SELECT COUNT(*) FROM onlifi_central.router_telemetry;
```

---

## Quick Diagnostic Script

Run this all-in-one diagnostic:

```bash
#!/bin/bash
echo "=== Git Status ==="
cd /var/www/onlifi && git log -1 --oneline

echo -e "\n=== Routes Check ==="
php artisan route:list | grep telemetry

echo -e "\n=== Database Check ==="
mysql -u onlifi -p -e "SELECT COUNT(*) as total FROM onlifi_central.router_telemetry;"

echo -e "\n=== Test Endpoint (No Auth) ==="
curl -s -o /dev/null -w "HTTP Status: %{http_code}\n" "http://localhost:8000/api/telemetry/stats"

echo -e "\n=== Laravel Logs (Last 10 lines) ==="
tail -10 /var/www/onlifi/storage/logs/laravel.log
```

---

## What to Share

If still not working, share:

1. **Route list output:**
   ```bash
   php artisan route:list | grep telemetry
   ```

2. **Browser console screenshot** (F12 > Console tab)

3. **Browser network tab screenshot** (F12 > Network tab, filter "stats")

4. **Laravel logs:**
   ```bash
   tail -50 storage/logs/laravel.log
   ```

5. **Test endpoint result:**
   ```bash
   curl -X GET "http://localhost:8000/api/telemetry/stats" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Accept: application/json"
   ```
