# OnLiFi Integration Guide

Complete guide for integrating FreeRADIUS, YoAPI Payments, Real-time Telemetry, and MikroTik routers.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [FreeRADIUS Integration](#freeradius-integration)
3. [YoAPI Mobile Money Integration](#yoapi-mobile-money-integration)
4. [Real-time Telemetry Dashboard](#real-time-telemetry-dashboard)
5. [MikroTik Router Integration](#mikrotik-router-integration)
6. [Complete Flow Diagram](#complete-flow-diagram)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              OnLiFi System                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐                   │
│  │   Frontend   │───▶│  Laravel API │───▶│   MySQL DB   │                   │
│  │   (React)    │    │   Backend    │    │  (Central +  │                   │
│  └──────────────┘    └──────┬───────┘    │   Tenant)    │                   │
│                             │            └──────────────┘                   │
│                             │                    ▲                          │
│                             ▼                    │                          │
│  ┌──────────────┐    ┌──────────────┐    ┌──────┴───────┐                   │
│  │   YoAPI      │◀──▶│   Payment    │    │  FreeRADIUS  │                   │
│  │  (Mobile $)  │    │   Service    │    │   Server     │                   │
│  └──────────────┘    └──────────────┘    └──────┬───────┘                   │
│                                                  │                          │
│                                                  ▼                          │
│                                          ┌──────────────┐                   │
│                                          │   MikroTik   │                   │
│                                          │   Routers    │                   │
│                                          └──────────────┘                   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Multi-Tenant Architecture

Each tenant (client) has:
- **Separate database** (`onlifi_tenant_{id}`)
- **Own vouchers** stored in their database
- **Own FreeRADIUS tables** (radcheck, radreply, radacct)
- **Own routers** with telemetry data
- **Own transactions** from mobile money payments

---

## FreeRADIUS Integration

### How Vouchers Sync to RADIUS

When a voucher is created, it's automatically synced to FreeRADIUS tables:

```php
// VoucherService.php - createVoucher()
$voucher = Voucher::create([...]);

$radiusService = app(FreeRadiusService::class);
$radiusService->syncVoucherToRadius([
    'voucher_code' => $voucher->voucher_code,
    'password' => $voucher->password,
    'validity_hours' => $voucher->validity_hours,
    'data_limit_mb' => $voucher->data_limit_mb,
    'speed_limit_kbps' => $voucher->speed_limit_kbps,
]);
```

### FreeRADIUS Tables (Per Tenant)

#### radcheck - Authentication
```sql
-- Stores username/password for authentication
INSERT INTO radcheck (username, attribute, op, value) VALUES
('VOUCHER123', 'Cleartext-Password', ':=', 'pass1234'),
('VOUCHER123', 'Auth-Type', ':=', 'Accept');
```

#### radreply - Session Attributes
```sql
-- Stores session limits returned to MikroTik
INSERT INTO radreply (username, attribute, op, value) VALUES
('VOUCHER123', 'Session-Timeout', '=', '3600'),      -- 1 hour
('VOUCHER123', 'Idle-Timeout', '=', '900'),          -- 15 min idle
('VOUCHER123', 'Mikrotik-Rate-Limit', '=', '2048k/2048k'),  -- 2Mbps
('VOUCHER123', 'Mikrotik-Total-Limit', '=', '1073741824'); -- 1GB
```

#### radacct - Accounting (Usage Tracking)
```sql
-- Automatically populated by FreeRADIUS when users connect
-- Tracks: session time, data in/out, start/stop times
SELECT username, acctsessiontime, acctinputoctets, acctoutputoctets 
FROM radacct WHERE username = 'VOUCHER123';
```

### FreeRADIUS Server Configuration

#### 1. Install FreeRADIUS with MySQL
```bash
sudo apt install freeradius freeradius-mysql
```

#### 2. Configure SQL Module (`/etc/freeradius/3.0/mods-available/sql`)
```
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    server = "localhost"
    port = 3306
    login = "radius_user"
    password = "secure_password"
    
    # For multi-tenant, you'll need a proxy or per-tenant config
    radius_db = "onlifi_tenant_1"
    
    read_clients = yes
    client_table = "nas"
    
    # Important: Use these table names
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
}
```

#### 3. Enable SQL Module
```bash
cd /etc/freeradius/3.0/mods-enabled
sudo ln -s ../mods-available/sql sql
```

#### 4. Configure Sites (`/etc/freeradius/3.0/sites-available/default`)
```
authorize {
    preprocess
    sql
}

authenticate {
    Auth-Type PAP {
        pap
    }
}

accounting {
    sql
}

post-auth {
    sql
}
```

#### 5. Multi-Tenant RADIUS Setup

For multi-tenant support, you have two options:

**Option A: Realm-based routing**
```
# In proxy.conf
realm tenant1.onlifi.com {
    # Route to tenant 1 database
}

realm tenant2.onlifi.com {
    # Route to tenant 2 database
}
```

**Option B: NAS-based routing (Recommended)**
```sql
-- In central database, create NAS table
CREATE TABLE nas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nasname VARCHAR(128) NOT NULL,  -- Router IP
    shortname VARCHAR(32),
    type VARCHAR(30) DEFAULT 'other',
    ports INT,
    secret VARCHAR(60) NOT NULL,    -- RADIUS secret
    server VARCHAR(64),
    community VARCHAR(50),
    description VARCHAR(200),
    tenant_id INT NOT NULL          -- Link to tenant
);
```

Then use a custom module to switch databases based on NAS.

### Testing RADIUS Authentication

```bash
# Test with radtest
radtest VOUCHER123 pass1234 localhost 0 testing123

# Expected output:
# Received Access-Accept Id 123 from 127.0.0.1:1812 to 127.0.0.1:xxxxx length 20
```

---

## YoAPI Mobile Money Integration

### Configuration

Add to `.env`:
```env
YOAPI_USERNAME=your_yo_username
YOAPI_PASSWORD=your_yo_password
YOAPI_MODE=sandbox  # or 'production'
```

### Payment Flow

```
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│  Client  │────▶│  OnLiFi  │────▶│  YoAPI   │────▶│  Mobile  │
│  Device  │     │  Backend │     │  Server  │     │  Money   │
└──────────┘     └──────────┘     └──────────┘     └──────────┘
     │                │                │                │
     │  1. Request    │                │                │
     │  Voucher       │                │                │
     │───────────────▶│                │                │
     │                │  2. Initiate   │                │
     │                │  Payment       │                │
     │                │───────────────▶│                │
     │                │                │  3. Send USSD  │
     │                │                │  Prompt        │
     │                │                │───────────────▶│
     │                │                │                │
     │                │                │  4. User       │
     │                │                │  Confirms      │
     │                │                │◀───────────────│
     │                │  5. IPN        │                │
     │                │  Callback      │                │
     │                │◀───────────────│                │
     │                │                │                │
     │  6. Voucher    │                │                │
     │  via SMS       │                │                │
     │◀───────────────│                │                │
```

### API Endpoints

#### 1. Initiate Payment
```http
POST /api/payments/initiate
Content-Type: application/json
X-API-Key: tenant_api_key
X-API-Secret: tenant_api_secret

{
    "amount": 1000,
    "msisdn": "256772123456",
    "origin_site": "Hotel WiFi",
    "client_mac": "AA:BB:CC:DD:EE:FF",
    "voucher_type": "1hour",
    "origin_url": "http://hotspot.local/login"
}
```

**Response:**
```json
{
    "status": 1,
    "transactionReference": "YO123456789",
    "externalReference": "TXN_1234567890_abc123",
    "statusMessage": "Transaction pending"
}
```

#### 2. Check Status
```http
GET /api/payments/check-status?ref=TXN_1234567890_abc123
```

**Response:**
```json
{
    "transactionStatus": 1,
    "statusMessage": "Payment successful",
    "voucherCode": "ABCD1234"
}
```

#### 3. IPN Callback (Called by YoAPI)
```http
POST /api/payments/ipn
Content-Type: application/x-www-form-urlencoded

date_time=2024-01-15+10:30:00
amount=1000
narrative=Feature+Payment
network_ref=MN123456
external_ref=TXN_1234567890_abc123
msisdn=256772123456
signature=base64_signature
```

### YoAPI Certificate Setup

Place certificates in `app/Services/`:
- `Yo_Uganda_Public_Certificate.crt` (Production)
- `Yo_Uganda_Public_Sandbox_Certificate.crt` (Sandbox)

These are used to verify IPN signatures.

### Transaction Tracking

All transactions are stored in the tenant's `transactions` table:

```php
// Check transaction history
$transactions = Transaction::where('status', 'success')
    ->whereDate('created_at', today())
    ->get();

// Get revenue
$revenue = Transaction::where('status', 'success')
    ->sum('amount');
```

---

## Real-time Telemetry Dashboard

### How Telemetry Works

OnLiFi uses a **push-based** telemetry system where MikroTik routers send data to the API every 30 seconds.

```
┌──────────────┐                    ┌──────────────┐
│   MikroTik   │  POST /telemetry   │   OnLiFi     │
│   Router     │───────────────────▶│   Backend    │
│              │   Every 30 sec     │              │
└──────────────┘                    └──────┬───────┘
                                           │
                                           ▼
                                    ┌──────────────┐
                                    │   Dashboard  │
                                    │   (React)    │
                                    └──────────────┘
```

### Telemetry Data Structure

```json
{
    "router_id": 1,
    "cpu_load": 15.5,
    "memory_used_mb": 128,
    "memory_total_mb": 256,
    "uptime_seconds": 86400,
    "active_connections": 25,
    "total_clients": 30,
    "bandwidth_upload_kbps": 5000,
    "bandwidth_download_kbps": 15000
}
```

### API Endpoints for Dashboard

#### 1. Get Real-time Stats
```http
GET /api/dashboard/realtime-stats
X-API-Key: tenant_api_key
X-API-Secret: tenant_api_secret
```

**Response:**
```json
{
    "total_active_users": 45,
    "total_routers": 3,
    "online_routers": 3,
    "today_transactions": 25,
    "today_revenue": 50000,
    "active_vouchers": 100,
    "unused_vouchers": 500,
    "routers": [
        {
            "id": 1,
            "name": "Main Router",
            "location": "Lobby",
            "cpu_load": 15.5,
            "memory_used_mb": 128,
            "memory_total_mb": 256,
            "active_users": 25,
            "last_seen": "2024-01-15T10:30:00Z",
            "is_online": true
        }
    ],
    "timestamp": "2024-01-15T10:30:00Z"
}
```

#### 2. Get Active Users
```http
GET /api/dashboard/active-users
```

**Response:**
```json
{
    "total_active_users": 45,
    "users": [
        {
            "username": "ABCD1234",
            "mac_address": "AA:BB:CC:DD:EE:FF",
            "ip_address": "192.168.88.100",
            "uptime": "1h30m",
            "bytes_in": 104857600,
            "bytes_out": 52428800,
            "router_id": 1,
            "router_name": "Main Router",
            "router_location": "Lobby"
        }
    ],
    "timestamp": "2024-01-15T10:30:00Z"
}
```

### Frontend Real-time Updates

For real-time updates in React, use polling or WebSockets:

```javascript
// Polling approach (simple)
useEffect(() => {
    const fetchStats = async () => {
        const response = await fetch('/api/dashboard/realtime-stats', {
            headers: {
                'X-API-Key': apiKey,
                'X-API-Secret': apiSecret
            }
        });
        const data = await response.json();
        setStats(data);
    };

    fetchStats();
    const interval = setInterval(fetchStats, 10000); // Every 10 seconds

    return () => clearInterval(interval);
}, []);
```

### Download Router Telemetry Script

```http
GET /api/dashboard/router-script
```

Returns a `.rsc` file that can be imported into MikroTik.

---

## MikroTik Router Integration

### Option 1: RADIUS Authentication (Recommended)

This is the best approach for voucher-based hotspot authentication.

#### MikroTik Configuration

```routeros
# 1. Add RADIUS Server
/radius add address=YOUR_RADIUS_SERVER_IP secret=your_secret service=hotspot

# 2. Configure Hotspot to use RADIUS
/ip hotspot profile set default use-radius=yes

# 3. Set RADIUS attributes
/ip hotspot profile set default \
    rate-limit-attr=Mikrotik-Rate-Limit \
    session-timeout-attr=Session-Timeout \
    idle-timeout-attr=Idle-Timeout
```

#### How It Works

1. User connects to WiFi → Redirected to captive portal
2. User enters voucher code + password
3. MikroTik sends RADIUS Access-Request to FreeRADIUS
4. FreeRADIUS checks `radcheck` table for credentials
5. If valid, returns `radreply` attributes (speed, time limits)
6. MikroTik applies limits and grants access
7. MikroTik sends accounting data to FreeRADIUS periodically

### Option 2: Direct API Integration

For advanced use cases, MikroTik can call the OnLiFi API directly.

#### MikroTik Script for Telemetry

```routeros
# OnLiFi Telemetry Script
# Add to System > Scripts

:local apiUrl "https://your-domain.com/api/routers/telemetry/ingest"
:local apiKey "your_tenant_api_key"
:local apiSecret "your_tenant_api_secret"
:local routerId 1

# Get system resources
:local cpuLoad [/system resource get cpu-load]
:local freeMemory [/system resource get free-memory]
:local totalMemory [/system resource get total-memory]
:local uptime [/system resource get uptime]

# Get active hotspot users
:local activeUsers [/ip hotspot active print count-only]

# Calculate memory used in MB
:local memoryUsedMb (($totalMemory - $freeMemory) / 1048576)
:local memoryTotalMb ($totalMemory / 1048576)

# Build JSON payload
:local payload "{\"router_id\":$routerId,\"cpu_load\":$cpuLoad,\"memory_used_mb\":$memoryUsedMb,\"memory_total_mb\":$memoryTotalMb,\"active_connections\":$activeUsers}"

# Send to API
/tool fetch url=$apiUrl \
    http-method=post \
    http-header-field="Content-Type:application/json,X-API-Key:$apiKey,X-API-Secret:$apiSecret" \
    http-data=$payload \
    output=none
```

#### Schedule Telemetry Script

```routeros
/system scheduler add name=onlifi-telemetry \
    interval=30s \
    on-event="/system script run onlifi-telemetry"
```

### Hotspot Profile Configuration

```routeros
# Create profiles matching your voucher types
/ip hotspot user profile add name=1hour rate-limit=2M/2M session-timeout=1h
/ip hotspot user profile add name=6hours rate-limit=5M/5M session-timeout=6h
/ip hotspot user profile add name=24hours rate-limit=10M/10M session-timeout=24h
/ip hotspot user profile add name=unlimited rate-limit=20M/20M
```

### Walled Garden (Allow API Access)

```routeros
# Allow access to OnLiFi API before authentication
/ip hotspot walled-garden ip add dst-host=your-domain.com action=accept
/ip hotspot walled-garden ip add dst-host=paymentsapi1.yo.co.ug action=accept
```

---

## Complete Flow Diagram

### Voucher Purchase Flow

```
┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐    ┌─────────┐
│  User   │    │ Captive │    │ OnLiFi  │    │  YoAPI  │    │ Mobile  │
│         │    │ Portal  │    │ Backend │    │         │    │ Money   │
└────┬────┘    └────┬────┘    └────┬────┘    └────┬────┘    └────┬────┘
     │              │              │              │              │
     │ 1. Connect   │              │              │              │
     │   to WiFi    │              │              │              │
     │─────────────▶│              │              │              │
     │              │              │              │              │
     │ 2. Redirect  │              │              │              │
     │   to portal  │              │              │              │
     │◀─────────────│              │              │              │
     │              │              │              │              │
     │ 3. Select    │              │              │              │
     │   package    │              │              │              │
     │─────────────▶│              │              │              │
     │              │              │              │              │
     │              │ 4. Initiate  │              │              │
     │              │   payment    │              │              │
     │              │─────────────▶│              │              │
     │              │              │              │              │
     │              │              │ 5. Request   │              │
     │              │              │   payment    │              │
     │              │              │─────────────▶│              │
     │              │              │              │              │
     │              │              │              │ 6. USSD      │
     │              │              │              │   prompt     │
     │              │              │              │─────────────▶│
     │              │              │              │              │
     │ 7. Enter PIN │              │              │              │
     │   on phone   │              │              │              │
     │──────────────────────────────────────────────────────────▶│
     │              │              │              │              │
     │              │              │              │ 8. Payment   │
     │              │              │              │   confirmed  │
     │              │              │              │◀─────────────│
     │              │              │              │              │
     │              │              │ 9. IPN       │              │
     │              │              │   callback   │              │
     │              │              │◀─────────────│              │
     │              │              │              │              │
     │              │              │ 10. Create   │              │
     │              │              │   voucher &  │              │
     │              │              │   sync RADIUS│              │
     │              │              │──────┐       │              │
     │              │              │      │       │              │
     │              │              │◀─────┘       │              │
     │              │              │              │              │
     │ 11. SMS with │              │              │              │
     │   voucher    │              │              │              │
     │◀─────────────────────────────              │              │
     │              │              │              │              │
     │ 12. Enter    │              │              │              │
     │   voucher    │              │              │              │
     │─────────────▶│              │              │              │
     │              │              │              │              │
     │              │ 13. RADIUS   │              │              │
     │              │   auth       │              │              │
     │              │─────────────▶│              │              │
     │              │              │              │              │
     │              │ 14. Access   │              │              │
     │              │   granted    │              │              │
     │              │◀─────────────│              │              │
     │              │              │              │              │
     │ 15. Internet │              │              │              │
     │   access!    │              │              │              │
     │◀─────────────│              │              │              │
```

---

## Environment Variables

```env
# Application
APP_NAME=OnLiFi
APP_ENV=production
APP_URL=https://your-domain.com

# Central Database
CENTRAL_DB_HOST=127.0.0.1
CENTRAL_DB_DATABASE=onlifi_central
CENTRAL_DB_USERNAME=onlifi_user
CENTRAL_DB_PASSWORD=secure_password

# Tenant Database (template)
DB_HOST=127.0.0.1
DB_USERNAME=onlifi_user
DB_PASSWORD=secure_password

# YoAPI
YOAPI_USERNAME=your_username
YOAPI_PASSWORD=your_password
YOAPI_MODE=production

# SMS
SMS_PROVIDER=comms
SMS_API_KEY=your_sms_api_key
SMS_SENDER_ID=OnLiFi

# MikroTik Defaults
MIKROTIK_DEFAULT_HOST=192.168.88.1
MIKROTIK_DEFAULT_PORT=8728
MIKROTIK_DEFAULT_USERNAME=admin
MIKROTIK_DEFAULT_PASSWORD=admin
```

---

## Troubleshooting

### RADIUS Not Authenticating

1. **Check FreeRADIUS logs:**
   ```bash
   sudo freeradius -X
   ```

2. **Verify radcheck entry exists:**
   ```sql
   SELECT * FROM radcheck WHERE username = 'VOUCHER123';
   ```

3. **Test with radtest:**
   ```bash
   radtest VOUCHER123 password localhost 0 testing123
   ```

4. **Check MikroTik RADIUS config:**
   ```routeros
   /radius print
   /ip hotspot profile print
   ```

### Payments Not Processing

1. **Check YoAPI credentials in `.env`**

2. **Verify IPN URL is accessible:**
   ```bash
   curl -X POST https://your-domain.com/api/payments/ipn
   ```

3. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

4. **Verify certificates exist:**
   ```bash
   ls -la app/Services/Yo_Uganda_*.crt
   ```

### Telemetry Not Updating

1. **Check router can reach API:**
   ```routeros
   /tool fetch url="https://your-domain.com/api/health" mode=http
   ```

2. **Verify API credentials in script**

3. **Check scheduler is running:**
   ```routeros
   /system scheduler print
   ```

4. **Test script manually:**
   ```routeros
   /system script run onlifi-telemetry
   ```

---

## Security Best Practices

1. **Use HTTPS** for all API communications
2. **Rotate API keys** periodically
3. **Use strong RADIUS secrets** (32+ characters)
4. **Encrypt router passwords** in database
5. **Implement rate limiting** on payment endpoints
6. **Validate IPN signatures** (already implemented)
7. **Use firewall rules** to restrict RADIUS access
8. **Regular database backups**

---

## Summary

| Component | Purpose | Key Files |
|-----------|---------|-----------|
| **FreeRADIUS** | Voucher authentication | `FreeRadiusService.php`, `radcheck`, `radreply` |
| **YoAPI** | Mobile money payments | `YoPaymentService.php`, `YoAPI.php` |
| **Telemetry** | Real-time dashboard | `MikrotikController.php`, `TenantDashboardController.php` |
| **MikroTik** | Router management | `MikrotikService.php`, `MikrotikAPI.php` |

The system is designed to be **multi-tenant**, **scalable**, and **real-time**. Each tenant operates independently with their own database, vouchers, and routers.
