# RouterOS Telemetry Deployment Guide

## Critical Fix: MikroTik Uses RouterOS Scripts, Not Bash

**IMPORTANT:** The previous implementation incorrectly suggested using bash scripts and cron. MikroTik routers run RouterOS, which uses its own scripting language and scheduler system.

---

## What Changed

### ❌ Previous (INCORRECT)
- Bash script with cron
- Linux-style commands (`cat`, `free`, `bc`)
- Crontab scheduling

### ✅ Current (CORRECT)
- RouterOS script language (`.rsc` file)
- RouterOS commands (`/system resource get`, `/interface get`)
- RouterOS scheduler (`/system scheduler`)

---

## New Implementation

### 1. **RouterOS Telemetry Script**

**File:** `mikrotik-telemetry-script.rsc`

**Features:**
- Written in RouterOS scripting language
- Collects: CPU, memory, uptime, active clients, bandwidth
- Uses router identity for automatic user routing
- Auto-creates scheduler (runs every 5 minutes)
- Error handling with `on-error` blocks
- JSON payload construction

**Key Functions:**
- `getSystemStats` - CPU, memory, uptime
- `getInterfaceStats` - TX/RX bytes for bandwidth calculation
- `getHotspotStats` - Active hotspot users
- `uptimeToSeconds` - Converts RouterOS uptime format to seconds

---

### 2. **Telemetry Ingestion API**

**File:** `newdashboard/api/telemetry_ingest.php`

**How It Works:**

1. **Receives telemetry** from MikroTik router via HTTP POST
2. **Extracts router identity** from payload
3. **Searches all tenant databases** to find which user owns this router
4. **Routes data** to correct user's database
5. **Stores telemetry** using `REPLACE INTO` (only latest data)
6. **Updates router last_seen** timestamp

**Router Identity Mapping:**
```
Router sends: {"router_identity": "MyRouter", ...}
     ↓
API searches all tenant databases for router named "MyRouter"
     ↓
Finds router in database: onlifi_hum_a56c53
     ↓
Stores telemetry in that database
     ↓
User sees data on their dashboard
```

---

### 3. **Updated Settings Page**

**File:** `newdashboard/src/app/pages/Settings.tsx`

**Changes:**
- Replaced bash script with RouterOS script
- Updated instructions for MikroTik Terminal
- Changed file extension from `.sh` to `.rsc`
- Updated setup steps for RouterOS

---

## Deployment Steps

### Step 1: Deploy Backend Changes

```bash
# SSH to server
ssh hum@192.168.0.180

# Navigate to project
cd /var/www/html

# Pull latest code
git pull origin main

# Verify new API file exists
ls -la newdashboard/api/telemetry_ingest.php

# Set permissions
sudo chown hum:www-data newdashboard/api/telemetry_ingest.php
sudo chmod 644 newdashboard/api/telemetry_ingest.php

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm
```

### Step 2: Deploy Frontend Changes

```bash
cd /var/www/html/newdashboard

# Rebuild React app
npm run build

# Verify build
ls -la dist/

# Set permissions
cd /var/www/html
sudo chown -R hum:www-data newdashboard
sudo chmod -R 775 newdashboard
```

### Step 3: Configure MikroTik Router

#### Option A: Via Winbox

1. Open Winbox and connect to router
2. Go to **System → Scripts**
3. Click **Add New** (+)
4. Name: `onlifi-telemetry`
5. Copy entire script from Settings page
6. Paste into **Source** field
7. Click **OK**
8. Edit script and replace `YOUR_API_TOKEN_HERE`
9. Go to **Terminal** and run: `/system script run onlifi-telemetry`
10. Check logs: `/log print where topics~"info"`

#### Option B: Via SSH/Terminal

```routeros
# Connect via SSH
ssh admin@router-ip

# Create script (paste entire script content)
/system script add name=onlifi-telemetry source="[paste script here]"

# Edit to add API token
/system script edit onlifi-telemetry source

# Run manually to test
/system script run onlifi-telemetry

# Check logs
/log print where topics~"info"

# Verify scheduler was created
/system scheduler print
```

---

## Router Identity Setup

### Critical: Router Name Must Match

The router identity is used to route telemetry data to the correct user dashboard.

**Check Router Identity:**
```routeros
/system identity print
```

**Set Router Identity:**
```routeros
/system identity set name="MyRouterName"
```

**Add Router to Dashboard:**
1. Go to **Devices** page in dashboard
2. Click **Add Router**
3. Enter router name (must match identity)
4. Enter IP address, username, password
5. Save

**Telemetry Flow:**
```
Router Identity: "MyRouterName"
     ↓
Sends telemetry with identity
     ↓
API searches for router named "MyRouterName" in all databases
     ↓
Finds it in user's database
     ↓
Stores telemetry there
     ↓
User sees data on their dashboard
```

---

## Testing

### Test 1: Manual Script Execution

```routeros
# Run script manually
/system script run onlifi-telemetry

# Expected output:
# Onlifi: Starting telemetry collection...
# Onlifi: Router Identity: MyRouterName
# Onlifi: CPU: 25%
# Onlifi: Active Users: 5
# SUCCESS: Telemetry posted to dashboard
```

### Test 2: Check Logs

```routeros
# View recent logs
/log print where topics~"info"

# Expected entries:
# onlifi-telemetry: data posted successfully
# onlifi-telemetry: CPU=25% Users=5 Identity=MyRouterName
# onlifi-telemetry: scheduler created - runs every 5 minutes
```

### Test 3: Verify Scheduler

```routeros
# List schedulers
/system scheduler print

# Expected output:
# 0 name="onlifi-telemetry-scheduler" start-time=startup 
#   interval=5m on-event="/system script run onlifi-telemetry"
```

### Test 4: Check Dashboard

1. Go to dashboard: `http://192.168.0.180/`
2. Check **Router Status** card
3. Should show:
   - Router name
   - CPU usage
   - Memory usage
   - Uptime
   - Network speed (after 5 minutes)

### Test 5: Verify API Logs

```bash
# SSH to server
ssh hum@192.168.0.180

# Check PHP error log
sudo tail -f /var/log/php8.1-fpm.log

# Expected entries:
# Telemetry received from router: MyRouterName
# Router 'MyRouterName' found in database: onlifi_hum_a56c53 (ID: 1)
# Telemetry stored successfully for router 'MyRouterName'
```

---

## Troubleshooting

### Issue: Script fails to run

**Check:**
```routeros
# View script
/system script print detail

# Check for syntax errors
/system script run onlifi-telemetry

# View error in logs
/log print where topics~"error,critical"
```

**Common Causes:**
- Missing closing braces `}`
- Incorrect variable names
- API URL not reachable

### Issue: Telemetry not reaching dashboard

**Check:**
```routeros
# Test connectivity
/tool fetch url="http://192.168.0.180/api/telemetry_ingest.php" mode=http

# Check if router has internet access
/ping 8.8.8.8 count=5

# Verify dashboard URL is correct
:put [/system script get onlifi-telemetry source]
```

**Debug:**
```bash
# On server, watch incoming requests
sudo tail -f /var/log/nginx/access.log | grep telemetry_ingest

# Check PHP errors
sudo tail -f /var/log/php8.1-fpm.log
```

### Issue: Router not found in database

**Error Message:**
```
Router identity 'MyRouterName' not registered in system
```

**Solution:**
1. Check router identity: `/system identity print`
2. Go to dashboard **Devices** page
3. Add router with exact same name
4. Run script again

### Issue: Data not showing on dashboard

**Check:**
1. Router identity matches database entry
2. Telemetry API is receiving data (check logs)
3. Data is being stored (check database)
4. Dashboard is fetching telemetry

**Verify Database:**
```sql
-- SSH to server
mysql -u root -p

-- Check telemetry data
USE onlifi_hum_a56c53;
SELECT * FROM router_telemetry;

-- Should show latest data for each router
```

---

## API Token Management

### Generate API Token

For now, use a placeholder token. In future, implement proper API key management:

```php
// In Settings page or admin panel
$apiToken = bin2hex(random_bytes(32));
// Store in users table or api_keys table
```

### Validate Token

In `telemetry_ingest.php`, add token validation:

```php
// Get token from Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);

// Validate token (implement your logic)
if (!validateApiToken($token)) {
    fail('Invalid API token', 401);
}
```

---

## Scheduler Configuration

### Default: Every 5 Minutes

```routeros
/system scheduler print
```

### Change Interval

```routeros
# Change to every 1 minute
/system scheduler set onlifi-telemetry-scheduler interval=1m

# Change to every 10 minutes
/system scheduler set onlifi-telemetry-scheduler interval=10m
```

### Disable Scheduler

```routeros
/system scheduler disable onlifi-telemetry-scheduler
```

### Re-enable Scheduler

```routeros
/system scheduler enable onlifi-telemetry-scheduler
```

---

## Data Flow Diagram

```
┌─────────────────┐
│ MikroTik Router │
│  (RouterOS)     │
└────────┬────────┘
         │
         │ Every 5 minutes
         │ /system scheduler
         │
         ▼
┌─────────────────────────┐
│ onlifi-telemetry script │
│ - Collects CPU, memory  │
│ - Gets router identity  │
│ - Builds JSON payload   │
└────────┬────────────────┘
         │
         │ HTTP POST
         │ /tool fetch
         │
         ▼
┌──────────────────────────┐
│ telemetry_ingest.php API │
│ - Receives JSON          │
│ - Extracts identity      │
│ - Searches databases     │
└────────┬─────────────────┘
         │
         │ Finds router in
         │ tenant database
         │
         ▼
┌─────────────────────────┐
│ User's Tenant Database  │
│ - router_telemetry      │
│ - REPLACE INTO (latest) │
└────────┬────────────────┘
         │
         │ Dashboard fetches
         │ via mikrotik_api.php
         │
         ▼
┌─────────────────────────┐
│ User's Dashboard        │
│ - Router Status card    │
│ - Shows CPU, memory     │
│ - Shows uptime, speed   │
└─────────────────────────┘
```

---

## Security Considerations

### 1. API Token

- Use strong, random tokens
- Store securely in database
- Validate on every request
- Implement token rotation

### 2. HTTPS

For production, use HTTPS:

```routeros
# Change URL in script
:local dashboardUrl "https://yourdomain.com/api/telemetry_ingest.php"

# Use HTTPS mode
/tool fetch url=$dashboardUrl mode=https ...
```

### 3. Rate Limiting

Implement rate limiting in `telemetry_ingest.php`:

```php
// Limit to 1 request per minute per router
$cacheKey = "telemetry_rate_limit_" . $routerIdentity;
if (apcu_exists($cacheKey)) {
    fail('Rate limit exceeded', 429);
}
apcu_store($cacheKey, true, 60); // 60 seconds
```

---

## Performance Optimization

### Database Indexing

```sql
-- Ensure indexes exist
ALTER TABLE router_telemetry ADD INDEX idx_router_id (router_id);
ALTER TABLE mikrotik_routers ADD INDEX idx_name (name);
ALTER TABLE mikrotik_routers ADD INDEX idx_active (is_active);
```

### Caching

Implement caching for router lookups:

```php
// Cache router-to-database mapping
$cacheKey = "router_db_map_" . $routerIdentity;
$cachedDb = apcu_fetch($cacheKey);
if ($cachedDb) {
    $targetDatabase = $cachedDb;
    // Skip database search
}
```

---

## Summary

### What Was Fixed

✅ Replaced bash script with RouterOS script  
✅ Created proper telemetry ingestion API  
✅ Implemented router identity-based routing  
✅ Updated Settings page with correct instructions  
✅ Auto-scheduler creation in RouterOS  
✅ Real-time data display on dashboard  

### Files Created/Modified

**Created:**
- `mikrotik-telemetry-script.rsc` - RouterOS telemetry script
- `newdashboard/api/telemetry_ingest.php` - Telemetry ingestion API

**Modified:**
- `newdashboard/src/app/pages/Settings.tsx` - Updated with RouterOS script
- `newdashboard/src/app/pages/DashboardEnhanced.tsx` - Already displays telemetry

### Key Differences from Previous Implementation

| Aspect | Previous (Wrong) | Current (Correct) |
|--------|------------------|-------------------|
| Script Type | Bash (.sh) | RouterOS (.rsc) |
| Scheduler | Cron | RouterOS Scheduler |
| Commands | Linux (cat, free) | RouterOS (/system resource) |
| Installation | Upload + chmod | /system script add |
| Execution | ./script.sh | /system script run |
| Interval | Every 1 minute | Every 5 minutes |
| Router ID | Manual config | Auto from identity |

---

## Next Steps

1. **Deploy changes** to production
2. **Test with one router** first
3. **Monitor logs** for errors
4. **Verify dashboard** shows data
5. **Roll out to all routers**

**All RouterOS telemetry implementation complete and ready for deployment!** 🚀
