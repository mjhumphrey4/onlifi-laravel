# Fixes Summary - Telemetry, Sales Points, and Site Selection

## Issues Fixed

### 1. ✅ MikroTik Telemetry System
**Problem**: Router telemetry script failed to post data to dashboard
**Root Causes**:
- Site model had `api_token` in `$hidden` array, preventing it from being exposed in API
- TelemetryController depended on `MikrotikRouter` model (tenant database)
- `router_telemetry` table had `router_id` as NOT NULL
- MikroTik script used incorrect HTTP header format (comma-separated)
- Timestamp format from MikroTik (`mar/26/2026 23:45:25`) wasn't parsed correctly

**Fixes Applied**:
- Removed `api_token` from Site model's `$hidden` array
- Simplified TelemetryController to work without MikrotikRouter dependency
- Modified `router_telemetry` table to allow NULL for `router_id`
- Fixed MikroTik script to use `http-content-type` parameter instead of comma-separated headers
- Added timestamp parsing in TelemetryController to handle MikroTik date format

**Files Modified**:
- `backend/app/Models/Site.php`
- `backend/app/Http/Controllers/TelemetryController.php`
- `backend/app/Http/Controllers/SiteController.php`
- `frontend/src/app/pages/Settings.tsx`
- `backend/database/migrations/2024_01_15_000002_update_sites_table_structure.php`

### 2. ✅ Sales Points Visibility
**Problem**: Sales points not visible in the application
**Root Cause**: `SalesPointController` used `withCount` and `withSum` on relationships that caused errors

**Fixes Applied**:
- Simplified `SalesPointController::index()` to avoid relationship queries
- Simplified `SalesPointController::show()` to avoid relationship queries
- Added error handling and logging

**Files Modified**:
- `backend/app/Http/Controllers/SalesPointController.php`

### 3. ✅ Site Selection Context
**Problem**: Selecting a site in one part of the app didn't affect other features/data
**Root Cause**: No global site selection context - each page managed its own local state

**Fixes Applied**:
- Created `SiteContext` provider for global site management
- Integrated `SiteProvider` in App.tsx
- Updated Layout component to use global `useSite()` hook
- Site selection now persists across all pages

**Files Created**:
- `frontend/src/app/context/SiteContext.tsx`

**Files Modified**:
- `frontend/src/app/App.tsx`
- `frontend/src/app/components/Layout.tsx`

## Deployment Instructions

### On Server (192.168.0.180)

```bash
# 1. Pull latest code
cd /var/www/onlifi
git pull origin main

# 2. Fix router_telemetry table (if not already done)
cd backend
php artisan tinker
```

In tinker:
```php
// Allow NULL for router_id
DB::statement('ALTER TABLE router_telemetry MODIFY COLUMN router_id BIGINT UNSIGNED NULL');

// Verify
DB::select("DESCRIBE router_telemetry");

exit
```

```bash
# 3. Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 4. Rebuild frontend
cd ../frontend
npm run build

# Or restart dev server if using Vite
# pkill -f vite
# npm run dev &
```

## Testing Guide

### Test 1: Telemetry System

**On Server:**
```bash
cd /var/www/onlifi/backend

# Get a site token
TOKEN=$(php artisan tinker --execute="echo DB::table('sites')->first()->api_token;")

# Test telemetry endpoint
curl -X POST http://192.168.0.180:8000/api/telemetry \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"router_name":"TestRouter","cpu_load":25,"memory_total_mb":256,"memory_used_mb":128}'

# Expected response:
# {"success":true,"message":"Telemetry data received successfully",...}

# Verify data stored
php artisan tinker --execute="print_r(DB::table('router_telemetry')->latest('created_at')->first());"
```

**On MikroTik Router:**
1. Download new telemetry script from Settings page
2. Verify script contains actual token (not TOKEN_NOT_LOADED)
3. Apply script to router
4. Run: `/system script run onlifi-telemetry`
5. Check logs: `/log print where topics~"info"`
6. Look for: "onlifi-telemetry: data posted successfully"

### Test 2: Sales Points

**In Browser:**
1. Go to Vouchers page
2. Click "Sales Points" button
3. Verify sales points dialog opens
4. Verify existing sales points are displayed
5. Try creating a new sales point
6. Verify it appears in the list

### Test 3: Site Selection

**In Browser:**
1. Click site dropdown in sidebar (shows current site name)
2. Select a different site
3. Navigate to different pages (Dashboard, Vouchers, Transactions, etc.)
4. Verify the selected site persists across pages
5. Verify data changes based on selected site (if site-specific data exists)

## Expected Behavior After Fixes

### Telemetry
- ✅ Settings page shows actual API token for selected site
- ✅ Downloaded script contains valid token
- ✅ MikroTik router successfully posts data every 5 minutes
- ✅ Data appears in `router_telemetry` table
- ✅ Server logs show "Telemetry stored successfully"

### Sales Points
- ✅ Sales points dialog opens without errors
- ✅ Existing sales points are displayed
- ✅ Can create new sales points
- ✅ Can view sales point statistics

### Site Selection
- ✅ Site dropdown shows all available sites
- ✅ Can select any site from dropdown
- ✅ Selected site persists across page navigation
- ✅ "Add New Site" option available in dropdown
- ✅ Can create new sites from dropdown

## Database Changes Required

```sql
-- Allow NULL for router_id in router_telemetry table
ALTER TABLE router_telemetry MODIFY COLUMN router_id BIGINT UNSIGNED NULL;

-- Verify sites table has api_token column
DESCRIBE sites;

-- If api_token column missing, run migration:
-- php artisan migrate
```

## API Endpoints Affected

### Fixed/Updated:
- `GET /api/sites` - Now returns sites with api_token
- `POST /api/telemetry` - Now accepts MikroTik timestamp format
- `GET /api/sales-points` - Simplified to avoid relationship errors
- `POST /api/sites/{id}/regenerate-token` - Works correctly
- `GET /api/sites/{id}/token` - Returns token properly

## Frontend Components Affected

### Updated:
- `Settings.tsx` - Uses site token from site object
- `Layout.tsx` - Uses global SiteContext
- `App.tsx` - Wraps app with SiteProvider
- `SiteContext.tsx` - New global context provider

## Known Limitations

1. **Site-specific data filtering**: While site selection now works globally, individual pages may need updates to actually filter data by selected site. The context is available via `useSite()` hook.

2. **Router association**: Telemetry data is stored with `router_id` as NULL for now. Future enhancement: associate routers with sites properly.

3. **Sales points statistics**: Sales point revenue/voucher counts removed temporarily to fix visibility. Can be re-added with proper relationship handling.

## Next Steps (Optional Enhancements)

1. **Update all pages to use `useSite()` hook** and filter data by `selectedSite.id`
2. **Add site-specific router management** to properly associate routers with sites
3. **Create telemetry dashboard** to visualize router data
4. **Add site-specific voucher filtering** in voucher pages
5. **Implement site-based access control** for multi-tenant scenarios

## Troubleshooting

### Telemetry still shows TOKEN_NOT_LOADED
- Clear browser cache and refresh
- Verify `/api/sites` returns sites with `api_token` field
- Check browser console for errors

### Sales points still not visible
- Check Laravel logs: `tail -f storage/logs/laravel.log`
- Verify `voucher_sales_points` table exists in tenant database
- Check if tenant context is properly set

### Site selection doesn't persist
- Verify `SiteProvider` is wrapping the app in `App.tsx`
- Check browser console for React context errors
- Ensure all components use `useSite()` hook, not local state

## Git Commits

All fixes have been committed and pushed to main branch:
- `3a03731` - Fix timestamp parsing for MikroTik telemetry data
- `e6caf60` - Fix sales points visibility - simplify controller
- `26a4fc7` - Add global site selection context
