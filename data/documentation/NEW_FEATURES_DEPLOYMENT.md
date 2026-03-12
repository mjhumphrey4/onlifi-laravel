# New Features Deployment Guide

## Features Implemented

### 1. **Voucher Types Management** ✅
- **New Page:** Voucher Types tab for managing voucher profiles
- **Features:**
  - Create/Edit/Delete voucher types
  - Set duration (hours), base amount, data limits, speed limits
  - View usage statistics per voucher type
  - Integrated with RADIUS tables for authentication
- **Files:**
  - `newdashboard/src/app/pages/VoucherTypes.tsx`
  - `newdashboard/api/voucher_types_api.php`
  - `database/migrations/add_voucher_types.sql`

### 2. **Settings Menu with Router Telemetry Script** ✅
- **New Menu Item:** Settings
- **Features:**
  - Downloadable telemetry push script for MikroTik routers
  - Setup instructions for real-time router monitoring
  - Copy-to-clipboard functionality
- **Files:**
  - `newdashboard/src/app/pages/Settings.tsx`

### 3. **Performance Analysis Tabs** ✅
- **Enhanced Page:** Analyze Performance
- **Features:**
  - Separate tabs for Mobile Money and Vouchers
  - Individual statistics for each channel
  - Comparative analytics
- **Files:**
  - `newdashboard/src/app/pages/AnalyzePerformance.tsx` (updated)

### 4. **Dashboard Router Stats** ✅
- **Enhanced Dashboard:** Main dashboard now shows router telemetry
- **Features:**
  - CPU usage with color-coded bars
  - Memory usage
  - Uptime display
  - Network speed (download/upload)
  - Real-time updates
- **Files:**
  - `newdashboard/src/app/pages/DashboardEnhanced.tsx` (updated)

---

## Database Changes

### New Table: `voucher_types`

```sql
CREATE TABLE voucher_types (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    type_name VARCHAR(100) NOT NULL,
    duration_hours INT(11) NOT NULL,
    base_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    description TEXT,
    data_limit_mb INT(11) DEFAULT NULL,
    speed_limit_kbps INT(11) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_type_name (type_name)
);
```

### Modified Tables

**`vouchers` table:**
- Added `voucher_type_id` foreign key

**`voucher_groups` table:**
- Added `voucher_type_id` foreign key

---

## Deployment Steps

### Step 1: Backup Database

```bash
# SSH to server
ssh hum@192.168.0.180

# Backup tenant database
mysqldump -u root -p onlifi_hum_a56c53 > ~/backups/onlifi_hum_$(date +%Y%m%d_%H%M%S).sql
```

### Step 2: Run Database Migration

```bash
# Login to MySQL
mysql -u root -p

# Select tenant database
USE onlifi_hum_a56c53;

# Run migration
SOURCE /var/www/html/database/migrations/add_voucher_types.sql;

# Verify
SHOW TABLES LIKE 'voucher_types';
DESCRIBE voucher_types;
```

### Step 3: Deploy Backend Changes

```bash
cd /var/www/html

# Pull latest code
git pull origin main

# Verify new API file exists
ls -la newdashboard/api/voucher_types_api.php

# Set permissions
sudo chown -R hum:www-data newdashboard/api/
sudo chmod 644 newdashboard/api/voucher_types_api.php
```

### Step 4: Deploy Frontend Changes

```bash
cd /var/www/html/newdashboard

# Install dependencies (if needed)
npm install

# Build React app
npm run build

# Verify build
ls -la dist/assets/

# Set permissions
cd /var/www/html
sudo chown -R hum:www-data newdashboard
sudo chmod -R 775 newdashboard
```

### Step 5: Restart Services

```bash
# Restart PHP-FPM
sudo systemctl restart php8.1-fpm

# Restart Nginx
sudo systemctl restart nginx

# Check status
sudo systemctl status php8.1-fpm
sudo systemctl status nginx
```

---

## Testing the Features

### Test 1: Voucher Types

```bash
# Open browser
http://192.168.0.180/voucher-types

# Expected:
# - Page loads with voucher types grid
# - "Create Voucher Type" button visible
# - Default voucher types (1h, 2h, 3h, etc.) displayed
# - Can create new voucher type
# - Can edit existing voucher type
# - Can delete voucher type
```

**Create Test Voucher Type:**
1. Click "Create Voucher Type"
2. Fill in:
   - Type Name: "Test 4 Hours"
   - Duration: 4
   - Base Amount: 1500
   - Description: "Test voucher type"
3. Click "Create"
4. Verify it appears in the grid

### Test 2: Settings & Router Script

```bash
# Open browser
http://192.168.0.180/settings

# Expected:
# - Settings page loads
# - "Link Router" section visible
# - Telemetry script displayed
# - "Download" button works
# - "Copy" button works
# - Setup instructions visible
```

**Download Script:**
1. Click "Download" button
2. Verify `telemetry_push.sh` downloads
3. Open file and verify content
4. Check for placeholders: `YOUR_ROUTER_ID`, `YOUR_API_KEY`

### Test 3: Performance Tabs

```bash
# Open browser
http://192.168.0.180/performance

# Expected:
# - Two tabs visible: "Mobile Money" and "Vouchers"
# - Clicking tabs switches content
# - Each tab shows different statistics
# - Charts update based on selected tab
# - Stats cards show correct data
```

**Switch Tabs:**
1. Click "Mobile Money" tab
2. Verify stats show mobile money data
3. Click "Vouchers" tab
4. Verify stats show voucher data
5. Check that charts update accordingly

### Test 4: Dashboard Router Stats

```bash
# Open browser
http://192.168.0.180/

# Expected:
# - "Router Status" card visible
# - Shows up to 3 routers
# - Each router displays:
#   - CPU load with progress bar
#   - Memory usage with progress bar
#   - Uptime (days and hours)
#   - Network speed (if available)
# - Color-coded bars (green/yellow/red)
```

**Verify Router Stats:**
1. Check CPU load percentage
2. Check memory usage (MB)
3. Verify uptime format: "Xd Yh"
4. Check network speed (if telemetry script is running)
5. Verify color coding:
   - Green: Normal (<60%)
   - Yellow: Warning (60-80%)
   - Red: Critical (>80%)

---

## API Endpoints

### Voucher Types API

**Base URL:** `/api/voucher_types_api.php`

**Endpoints:**

1. **List all voucher types**
   ```
   GET /api/voucher_types_api.php?action=list
   ```

2. **Create voucher type**
   ```
   POST /api/voucher_types_api.php?action=create
   Body: {
     "type_name": "1 Hour",
     "duration_hours": 1,
     "base_amount": 500,
     "description": "Basic 1-hour access",
     "data_limit_mb": null,
     "speed_limit_kbps": null
   }
   ```

3. **Update voucher type**
   ```
   POST /api/voucher_types_api.php?action=update
   Body: {
     "id": 1,
     "type_name": "1 Hour Updated",
     "duration_hours": 1,
     "base_amount": 600,
     ...
   }
   ```

4. **Delete voucher type**
   ```
   POST /api/voucher_types_api.php?action=delete
   Body: { "id": 1 }
   ```

---

## Integration with RADIUS

### How Voucher Types Work with FreeRADIUS

When creating vouchers with a voucher type:

1. **Voucher Creation:**
   - User selects a voucher type (e.g., "2 Hours")
   - System retrieves `duration_hours` from `voucher_types` table
   - Voucher is created with `voucher_type_id` reference

2. **RADIUS Sync:**
   - When voucher is synced to RADIUS (via `sync_voucher_to_radius` endpoint)
   - Duration is converted to seconds: `duration_hours * 3600`
   - Added to `radreply` table as `Session-Timeout` attribute

3. **Authentication:**
   - User enters voucher code
   - FreeRADIUS checks `radcheck` for username/password
   - FreeRADIUS applies `Session-Timeout` from `radreply`
   - User gets internet access for specified duration

**Example:**
```sql
-- Voucher Type: "2 Hours"
INSERT INTO voucher_types (type_name, duration_hours, base_amount)
VALUES ('2 Hours', 2, 900);

-- Voucher created with this type
INSERT INTO vouchers (voucher_code, voucher_type_id, ...)
VALUES ('VCH-ABC123', 1, ...);

-- Synced to RADIUS
INSERT INTO radreply (username, attribute, op, value)
VALUES ('VCH-ABC123', 'Session-Timeout', ':=', '7200');
-- 7200 seconds = 2 hours
```

---

## Router Telemetry Setup

### Installing the Telemetry Script

1. **Download Script:**
   - Go to Settings page
   - Click "Download" button
   - Save `telemetry_push.sh`

2. **Edit Configuration:**
   ```bash
   nano telemetry_push.sh
   
   # Update these lines:
   ROUTER_ID="1"  # Get from Devices page
   API_KEY="your_api_key_here"  # Contact admin
   ```

3. **Upload to Router:**
   ```bash
   # Via SCP
   scp telemetry_push.sh admin@router-ip:/home/admin/
   
   # Or via MikroTik Files interface
   ```

4. **Make Executable:**
   ```bash
   ssh admin@router-ip
   chmod +x /home/admin/telemetry_push.sh
   ```

5. **Add to Crontab:**
   ```bash
   crontab -e
   
   # Add this line (runs every minute):
   */1 * * * * /home/admin/telemetry_push.sh >> /var/log/telemetry.log 2>&1
   ```

6. **Verify:**
   ```bash
   # Check log
   tail -f /var/log/telemetry.log
   
   # Should see successful POST requests
   ```

---

## Troubleshooting

### Issue: Voucher Types not loading

**Check:**
1. Database migration ran successfully
2. API file has correct permissions
3. Session is authenticated

**Debug:**
```bash
# Check table exists
mysql -u root -p -e "USE onlifi_hum_a56c53; SHOW TABLES LIKE 'voucher_types';"

# Test API directly
curl -b "ONLIFI_SESSION=<session>" \
  http://192.168.0.180/api/voucher_types_api.php?action=list

# Check PHP error log
sudo tail -f /var/log/php8.1-fpm.log
```

### Issue: Settings page not found

**Check:**
1. Frontend build completed successfully
2. Routes file updated
3. Settings.tsx file exists

**Debug:**
```bash
# Check file exists
ls -la /var/www/html/newdashboard/src/app/pages/Settings.tsx

# Rebuild frontend
cd /var/www/html/newdashboard
npm run build

# Check browser console for errors
```

### Issue: Performance tabs not showing data

**Check:**
1. API endpoints returning data
2. Tabs switching correctly
3. Data filtering logic

**Debug:**
```bash
# Test API
curl -b "ONLIFI_SESSION=<session>" \
  "http://192.168.0.180/api/api.php?action=performance&site=Enock&days=7"

# Check browser console
# Open DevTools → Console tab
```

### Issue: Router stats not displaying

**Check:**
1. Telemetry script is running on routers
2. API returning telemetry data
3. Router IDs match

**Debug:**
```bash
# Test telemetry API
curl -b "ONLIFI_SESSION=<session>" \
  http://192.168.0.180/api/mikrotik_api.php?action=router_telemetry

# Check router_telemetry table
mysql -u root -p -e "USE onlifi_hum_a56c53; SELECT * FROM router_telemetry;"

# Verify cron job on router
ssh admin@router-ip
crontab -l | grep telemetry
```

---

## Menu Structure

Updated sidebar menu:

```
Dashboard
Clients
Devices
Vouchers
Voucher Types        ← NEW
User Management (admin only)
Transactions
Withdrawals
Analyze Performance  (with tabs)
Voucher Stock
Import Vouchers
Settings             ← NEW
```

---

## Summary of Changes

### Backend Files Created/Modified

**Created:**
- `newdashboard/api/voucher_types_api.php` - Voucher types CRUD API
- `database/migrations/add_voucher_types.sql` - Database migration

**Modified:**
- `newdashboard/api/mikrotik_api.php` - Already optimized in previous deployment

### Frontend Files Created/Modified

**Created:**
- `newdashboard/src/app/pages/VoucherTypes.tsx` - Voucher types management page
- `newdashboard/src/app/pages/Settings.tsx` - Settings page with router script

**Modified:**
- `newdashboard/src/app/routes.ts` - Added new routes
- `newdashboard/src/app/components/Layout.tsx` - Added menu items
- `newdashboard/src/app/pages/AnalyzePerformance.tsx` - Added tabs
- `newdashboard/src/app/pages/DashboardEnhanced.tsx` - Enhanced router stats

### Database Changes

**New Tables:**
- `voucher_types`

**Modified Tables:**
- `vouchers` - Added `voucher_type_id` column
- `voucher_groups` - Added `voucher_type_id` column

---

## Next Steps

1. **Test all features thoroughly**
2. **Train users on new voucher types workflow**
3. **Set up telemetry scripts on all routers**
4. **Monitor performance and gather feedback**
5. **Consider implementing:**
   - Bulk voucher creation with types
   - Voucher type templates
   - Advanced analytics per voucher type
   - API key management in Settings

---

## Rollback Plan

If issues occur:

```bash
# Rollback database
mysql -u root -p onlifi_hum_a56c53 < ~/backups/onlifi_hum_YYYYMMDD_HHMMSS.sql

# Rollback code
cd /var/www/html
git log --oneline -5
git checkout <previous_commit_hash>

# Rebuild frontend
cd newdashboard
npm run build

# Restart services
sudo systemctl restart php8.1-fpm nginx
```

---

## Success Criteria

✅ Voucher Types page loads and CRUD operations work  
✅ Settings page displays telemetry script  
✅ Performance page shows separate tabs for Mobile Money and Vouchers  
✅ Dashboard displays router stats (CPU, memory, uptime, speed)  
✅ All menu items navigate correctly  
✅ No console errors  
✅ Database migration successful  
✅ API endpoints respond correctly  

**All features implemented and ready for production!** 🚀
