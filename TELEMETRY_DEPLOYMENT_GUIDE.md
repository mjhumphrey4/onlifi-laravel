# Telemetry System Deployment Guide

## Changes Made

### 1. Backend Changes
- **Site Model**: Removed `api_token` from `$hidden` array so it's exposed in API responses
- **SiteController**: Simplified to avoid relationship errors (removed `withCount`)
- **TelemetryController**: Created new controller that doesn't depend on MikrotikRouter model
- **Migration**: Created `2024_01_15_000002_update_sites_table_structure.php` to add api_token to sites

### 2. Frontend Changes
- **Settings.tsx**: Updated to load site tokens and generate telemetry scripts with actual tokens

## Deployment Steps

### On Server (192.168.0.180)

```bash
# 1. Pull latest code
cd /var/www/onlifi
git pull origin main

# 2. Run migrations
cd /var/www/onlifi/backend
php artisan migrate

# 3. Verify sites table structure
php artisan tinker
```

In tinker:
```php
// Check if api_token column exists
DB::select("DESCRIBE sites");

// Check existing sites
$sites = DB::table('sites')->get(['id', 'name', 'slug', 'api_token']);
print_r($sites->toArray());

// If sites exist but have no tokens, generate them:
DB::table('sites')->whereNull('api_token')->orWhere('api_token', '')->update([
    'api_token' => DB::raw("CONCAT(MD5(RAND()), MD5(RAND()))")
]);

// Verify tokens were created
$sites = DB::table('sites')->get(['id', 'name', 'slug', 'api_token']);
print_r($sites->toArray());

exit
```

```bash
# 4. Clear Laravel caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 5. Check if router_telemetry table exists
php artisan tinker --execute="print_r(DB::getSchemaBuilder()->hasTable('router_telemetry'));"

# If it returns false, create it manually:
# php artisan make:migration create_router_telemetry_table
# Then edit the migration file and run: php artisan migrate
```

## Testing the Telemetry System

### Step 1: Verify API Endpoint Works

```bash
# Get a site token from the database
php artisan tinker --execute="echo DB::table('sites')->first()->api_token;"

# Test the telemetry endpoint (replace TOKEN with actual token)
curl -X POST http://192.168.0.180:8000/api/telemetry \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "site_slug": "default",
    "router_name": "TestRouter",
    "router_identity": "TestRouter",
    "router_version": "7.1",
    "router_board": "RB750Gr3",
    "cpu_load": 25,
    "memory_total_mb": 256,
    "memory_used_mb": 128,
    "uptime_seconds": 86400,
    "active_connections": 5,
    "bandwidth_download_kbps": 1024,
    "bandwidth_upload_kbps": 512,
    "total_tx_bytes": 1000000,
    "total_rx_bytes": 2000000
  }'
```

Expected response:
```json
{
  "success": true,
  "message": "Telemetry data received successfully",
  "site": "YourSiteName",
  "router": "TestRouter"
}
```

### Step 2: Check Logs

```bash
# Check Laravel logs for telemetry entries
tail -f /var/www/onlifi/backend/storage/logs/laravel.log
```

Look for:
- `Telemetry received`
- `Telemetry authenticated successfully`
- `Telemetry stored successfully`

### Step 3: Verify Data in Database

```bash
php artisan tinker
```

```php
// Check if data was stored
DB::table('router_telemetry')->latest('created_at')->first();
exit
```

### Step 4: Download Script from Frontend

1. Open browser and go to: `http://192.168.0.180:8000/settings`
2. Select a site from the dropdown
3. Verify the "Site API Token" section shows an actual token (not "TOKEN_NOT_LOADED")
4. Download the telemetry script
5. Open the downloaded file and verify it contains the actual token

### Step 5: Apply Script to MikroTik Router

1. Copy the entire script content
2. Open MikroTik Terminal (Winbox or SSH)
3. Paste the script and press Enter
4. Check MikroTik logs:
   ```
   /log print where topics~"info"
   ```
5. Look for "onlifi-telemetry" entries

## Troubleshooting

### Issue: TOKEN_NOT_LOADED in downloaded script

**Cause**: Frontend not loading site token properly

**Fix**:
1. Check browser console for errors
2. Verify `/api/sites` returns sites with `api_token` field
3. Clear browser cache and refresh

### Issue: MikroTik shows "failed to post data"

**Causes**:
1. Invalid API token
2. Network connectivity issue
3. Backend not receiving requests

**Debug**:
```bash
# Check if telemetry endpoint is accessible
curl -I http://192.168.0.180:8000/api/telemetry

# Check Laravel logs
tail -f /var/www/onlifi/backend/storage/logs/laravel.log

# Test with curl (see Step 1 above)
```

### Issue: 500 Error on /api/sites

**Cause**: Database relationship errors

**Fix**: Already fixed in latest code - pull and redeploy

### Issue: router_telemetry table doesn't exist

**Fix**:
```bash
# Check if table exists
php artisan tinker --execute="print_r(DB::getSchemaBuilder()->hasTable('router_telemetry'));"

# If false, the migration didn't run. Check migrations table:
php artisan tinker --execute="print_r(DB::table('migrations')->where('migration', 'like', '%telemetry%')->get());"
```

## Next Steps After Successful Deployment

1. **Create sites** if none exist (via "Add New Site" button in header)
2. **Download telemetry scripts** for each site
3. **Apply scripts to MikroTik routers**
4. **Monitor logs** to ensure data is being received
5. **Build dashboard views** to display telemetry data

## Sales Points Issue

The sales points visibility issue needs separate investigation. Check:
- `/api/sales-points` endpoint
- Frontend components that display sales points
- Any filtering based on site selection

## Site Selection Context Issue

The site selection not changing features/data needs:
- Review how `selectedSite` is used throughout the app
- Ensure API calls include site context
- Check if vouchers, routers, etc. are filtered by site_id
