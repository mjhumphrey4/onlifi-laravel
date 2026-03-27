# FreeRADIUS Multi-Tenant Configuration Guide

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           ONLIFI RADIUS ARCHITECTURE                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐                │
│  │  MikroTik    │     │  MikroTik    │     │  MikroTik    │                │
│  │  Router A    │     │  Router B    │     │  Router C    │                │
│  │  (Tenant 1)  │     │  (Tenant 1)  │     │  (Tenant 2)  │                │
│  │  No Public IP│     │  No Public IP│     │  No Public IP│                │
│  └──────┬───────┘     └──────┬───────┘     └──────┬───────┘                │
│         │                    │                    │                         │
│         │  NAS-Identifier    │  NAS-Identifier    │  NAS-Identifier        │
│         │  = "router-a-uuid" │  = "router-b-uuid" │  = "router-c-uuid"     │
│         │                    │                    │                         │
│         └────────────────────┼────────────────────┘                         │
│                              │                                              │
│                              ▼                                              │
│                    ┌─────────────────┐                                      │
│                    │   FreeRADIUS    │                                      │
│                    │   Server        │                                      │
│                    │   192.168.0.180 │                                      │
│                    │   Port 1812/1813│                                      │
│                    └────────┬────────┘                                      │
│                             │                                               │
│              ┌──────────────┼──────────────┐                               │
│              │              │              │                                │
│              ▼              ▼              ▼                                │
│    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐             │
│    │  Central DB     │ │  Tenant 1 DB    │ │  Tenant 2 DB    │             │
│    │  onlifi_central │ │  tenant_acme    │ │  tenant_beta    │             │
│    │                 │ │                 │ │                 │             │
│    │  - tenants      │ │  - radcheck     │ │  - radcheck     │             │
│    │  - nas          │ │  - radreply     │ │  - radreply     │             │
│    │  - super_admins │ │  - radacct      │ │  - radacct      │             │
│    │                 │ │  - vouchers     │ │  - vouchers     │             │
│    └─────────────────┘ └─────────────────┘ └─────────────────┘             │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## How It Works

### Authentication Flow

1. **User connects to MikroTik Hotspot** and enters voucher code
2. **MikroTik sends RADIUS Access-Request** to FreeRADIUS server (192.168.0.180:1812)
   - Includes `NAS-Identifier` attribute with unique router UUID
   - Includes `User-Name` (voucher code) and `User-Password`
3. **FreeRADIUS receives request** and:
   - Looks up `NAS-Identifier` in central `nas` table
   - Gets `tenant_id` from the NAS record
   - Looks up tenant's database credentials from `tenants` table
   - Connects to tenant's database dynamically
4. **FreeRADIUS queries tenant's `radcheck` table** for the voucher
5. **If voucher is valid**, FreeRADIUS:
   - Returns `Access-Accept` with reply attributes (speed limits, session timeout, etc.)
   - Logs the authentication in tenant's `radpostauth` table
6. **MikroTik grants access** to the user

### Key Concept: NAS-Identifier Based Routing

Since MikroTik routers don't have public IPs, we use `NAS-Identifier` (a unique UUID per router) to identify which tenant the router belongs to. This is configured in MikroTik's RADIUS settings.

---

## Server Requirements

- **OS**: Ubuntu 22.04 LTS (or similar)
- **FreeRADIUS**: Version 3.x
- **MySQL/MariaDB**: 8.0+ (already running for Laravel)
- **Network**: FreeRADIUS server must be reachable from all MikroTik routers

---

## Installation

### 1. Install FreeRADIUS

```bash
sudo apt update
sudo apt install -y freeradius freeradius-mysql freeradius-utils
```

### 2. Stop FreeRADIUS for Configuration

```bash
sudo systemctl stop freeradius
```

---

## Configuration Files

### File 1: `/etc/freeradius/3.0/mods-available/sql`

This is the main SQL module configuration. We'll configure it to connect to the central database first, then dynamically switch to tenant databases.

```bash
sudo nano /etc/freeradius/3.0/mods-available/sql
```

```
# -*- text -*-
#
# FreeRADIUS SQL Module Configuration for Onlifi Multi-Tenant
#

sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    # Central database connection (for NAS lookup)
    server = "localhost"
    port = 3306
    login = "radius_user"
    password = "your_secure_password"
    
    # Default to central database
    radius_db = "onlifi_central"
    
    # Connection pool settings
    pool {
        start = 5
        min = 4
        max = 32
        spare = 3
        uses = 0
        lifetime = 0
        idle_timeout = 60
    }
    
    # Read clients (NAS) from database
    read_clients = yes
    client_table = "nas"
    
    # SQL queries are in a separate file
    $INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries.conf
}

# Second SQL instance for tenant database queries
sql sql_tenant {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    # These will be set dynamically per-request
    server = "%{control:Tenant-DB-Host}"
    port = "%{control:Tenant-DB-Port}"
    login = "%{control:Tenant-DB-User}"
    password = "%{control:Tenant-DB-Pass}"
    radius_db = "%{control:Tenant-DB-Name}"
    
    pool {
        start = 5
        min = 4
        max = 32
        spare = 3
        uses = 0
        lifetime = 0
        idle_timeout = 60
    }
    
    $INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries_tenant.conf
}
```

### File 2: `/etc/freeradius/3.0/mods-config/sql/main/mysql/queries.conf`

This file contains SQL queries for the **central database** (NAS lookup and tenant resolution).

```bash
sudo nano /etc/freeradius/3.0/mods-config/sql/main/mysql/queries.conf
```

```sql
# -*- text -*-
#
# Central Database Queries for Onlifi Multi-Tenant FreeRADIUS
#

# NAS Query - Find the NAS and its associated tenant
client_query = "\
    SELECT n.id, n.nasname, n.shortname, n.type, n.secret, n.server, \
           t.id as tenant_id, t.database_name, t.database_host, \
           t.database_port, t.database_username, t.database_password \
    FROM nas n \
    JOIN tenants t ON n.tenant_id = t.id \
    WHERE n.router_identifier = '%{NAS-Identifier}' \
    AND t.is_active = 1 \
    AND t.status = 'approved'"

# Authorize query to get tenant info based on NAS-Identifier
authorize_check_query = "\
    SELECT t.database_name, t.database_host, t.database_port, \
           t.database_username, t.database_password, t.id as tenant_id, \
           t.name as tenant_name \
    FROM nas n \
    JOIN tenants t ON n.tenant_id = t.id \
    WHERE n.router_identifier = '%{NAS-Identifier}' \
    AND t.is_active = 1 \
    AND t.status = 'approved' \
    LIMIT 1"

# We don't use these for central DB
authorize_reply_query = ""
authorize_group_check_query = ""
authorize_group_reply_query = ""

# Accounting queries (stored in tenant DB, not central)
accounting_onoff_query = ""
accounting_update_query = ""
accounting_update_query_alt = ""
accounting_start_query = ""
accounting_start_query_alt = ""
accounting_stop_query = ""
accounting_stop_query_alt = ""

# Post-auth (stored in tenant DB)
post-auth_query = ""
```

### File 3: `/etc/freeradius/3.0/mods-config/sql/main/mysql/queries_tenant.conf`

This file contains SQL queries for **tenant databases** (actual authentication).

```bash
sudo nano /etc/freeradius/3.0/mods-config/sql/main/mysql/queries_tenant.conf
```

```sql
# -*- text -*-
#
# Tenant Database Queries for Onlifi Multi-Tenant FreeRADIUS
#
# These queries run against the dynamically selected tenant database
#

# Authorization: Check if voucher exists and is valid
authorize_check_query = "\
    SELECT v.id, v.voucher_code as username, \
           'Cleartext-Password' as attribute, \
           ':=' as op, \
           v.password as value \
    FROM vouchers v \
    WHERE v.voucher_code = '%{SQL-User-Name}' \
    AND v.status IN ('unused', 'used') \
    AND (v.expires_at IS NULL OR v.expires_at > NOW()) \
    LIMIT 1"

# Authorization: Get reply attributes (speed limits, session timeout, etc.)
authorize_reply_query = "\
    SELECT v.voucher_code as username, \
           'Session-Timeout' as attribute, \
           '=' as op, \
           CAST((v.validity_hours * 3600) - COALESCE(v.total_session_time_minutes * 60, 0) AS CHAR) as value \
    FROM vouchers v \
    WHERE v.voucher_code = '%{SQL-User-Name}' \
    AND v.status IN ('unused', 'used') \
    UNION ALL \
    SELECT v.voucher_code as username, \
           'Mikrotik-Rate-Limit' as attribute, \
           '=' as op, \
           CONCAT(COALESCE(v.speed_limit_kbps, 0), 'k/', COALESCE(v.speed_limit_kbps, 0), 'k') as value \
    FROM vouchers v \
    WHERE v.voucher_code = '%{SQL-User-Name}' \
    AND v.status IN ('unused', 'used') \
    AND v.speed_limit_kbps IS NOT NULL \
    UNION ALL \
    SELECT v.voucher_code as username, \
           'Mikrotik-Total-Limit' as attribute, \
           '=' as op, \
           CAST(COALESCE(v.data_limit_mb, 0) * 1048576 AS CHAR) as value \
    FROM vouchers v \
    WHERE v.voucher_code = '%{SQL-User-Name}' \
    AND v.status IN ('unused', 'used') \
    AND v.data_limit_mb IS NOT NULL"

# Group check query (using voucher profile as group)
authorize_group_check_query = "\
    SELECT v.profile_name as groupname, \
           'Auth-Type' as attribute, \
           ':=' as op, \
           'Accept' as value \
    FROM vouchers v \
    WHERE v.voucher_code = '%{SQL-User-Name}' \
    AND v.status IN ('unused', 'used') \
    LIMIT 1"

# Group reply query (profile-based attributes)
authorize_group_reply_query = "\
    SELECT vt.profile_name as groupname, \
           'Mikrotik-Rate-Limit' as attribute, \
           '=' as op, \
           CONCAT(vt.download_speed_kbps, 'k/', vt.upload_speed_kbps, 'k') as value \
    FROM voucher_types vt \
    WHERE vt.profile_name = '%{SQL-Group}'"

# Accounting Start: Mark voucher as used
accounting_start_query = "\
    UPDATE vouchers SET \
        status = 'used', \
        first_used_at = COALESCE(first_used_at, NOW()), \
        last_used_at = NOW(), \
        used_by_mac = '%{Calling-Station-Id}', \
        used_by_ip = '%{Framed-IP-Address}' \
    WHERE voucher_code = '%{SQL-User-Name}'"

accounting_start_query_alt = "${..accounting_start_query}"

# Accounting Update: Update session time and data usage
accounting_update_query = "\
    UPDATE vouchers SET \
        last_used_at = NOW(), \
        total_session_time_minutes = COALESCE(total_session_time_minutes, 0) + (%{Acct-Session-Time:-0} / 60), \
        total_data_used_mb = COALESCE(total_data_used_mb, 0) + \
            ((%{Acct-Input-Octets:-0} + %{Acct-Output-Octets:-0}) / 1048576) \
    WHERE voucher_code = '%{SQL-User-Name}'"

accounting_update_query_alt = "${..accounting_update_query}"

# Accounting Stop: Final update when session ends
accounting_stop_query = "\
    UPDATE vouchers SET \
        last_used_at = NOW(), \
        total_session_time_minutes = COALESCE(total_session_time_minutes, 0) + (%{Acct-Session-Time:-0} / 60), \
        total_data_used_mb = COALESCE(total_data_used_mb, 0) + \
            ((%{Acct-Input-Octets:-0} + %{Acct-Output-Octets:-0}) / 1048576), \
        status = CASE \
            WHEN (COALESCE(total_session_time_minutes, 0) + (%{Acct-Session-Time:-0} / 60)) >= (validity_hours * 60) \
            THEN 'expired' \
            WHEN data_limit_mb IS NOT NULL AND \
                 (COALESCE(total_data_used_mb, 0) + ((%{Acct-Input-Octets:-0} + %{Acct-Output-Octets:-0}) / 1048576)) >= data_limit_mb \
            THEN 'expired' \
            ELSE status \
        END \
    WHERE voucher_code = '%{SQL-User-Name}'"

accounting_stop_query_alt = "${..accounting_stop_query}"

# Accounting On/Off (NAS reboot)
accounting_onoff_query = ""

# Insert accounting record into radacct
accounting_insert_query = "\
    INSERT INTO radacct \
        (acctsessionid, acctuniqueid, username, nasipaddress, nasportid, \
         nasporttype, acctstarttime, acctsessiontime, acctauthentic, \
         connectinfo_start, acctinputoctets, acctoutputoctets, \
         calledstationid, callingstationid, acctterminatecause, \
         servicetype, framedprotocol, framedipaddress) \
    VALUES \
        ('%{Acct-Session-Id}', '%{Acct-Unique-Session-Id}', '%{SQL-User-Name}', \
         '%{NAS-IP-Address}', '%{NAS-Port-Id}', '%{NAS-Port-Type}', \
         %{%{Acct-Status-Type}:-NULL}, %{Acct-Session-Time:-0}, '%{Acct-Authentic}', \
         '%{Connect-Info}', %{Acct-Input-Octets:-0}, %{Acct-Output-Octets:-0}, \
         '%{Called-Station-Id}', '%{Calling-Station-Id}', '%{Acct-Terminate-Cause}', \
         '%{Service-Type}', '%{Framed-Protocol}', '%{Framed-IP-Address}')"

# Post-authentication logging
post-auth_query = "\
    INSERT INTO radpostauth (username, pass, reply, authdate) \
    VALUES ('%{SQL-User-Name}', '%{User-Password}', '%{reply:Packet-Type}', NOW())"
```

### File 4: `/etc/freeradius/3.0/sites-available/default`

Configure the main virtual server to handle multi-tenant authentication.

```bash
sudo nano /etc/freeradius/3.0/sites-available/default
```

```
# -*- text -*-
#
# Onlifi Multi-Tenant FreeRADIUS Virtual Server
#

server default {
    listen {
        type = auth
        ipaddr = *
        port = 1812
    }
    
    listen {
        type = acct
        ipaddr = *
        port = 1813
    }
    
    # Pre-processing
    preprocess {
        # Normalize attributes
    }
    
    # Authorization section
    authorize {
        # First, look up the NAS in central database to get tenant info
        # This sets control attributes with tenant database credentials
        
        # Check if NAS-Identifier is present
        if (!&NAS-Identifier) {
            reject
        }
        
        # Query central database for tenant info based on NAS-Identifier
        sql {
            # This query returns tenant database credentials
            # Results are stored in control attributes
        }
        
        # If we found tenant info, set up the tenant database connection
        if (&control:Tenant-DB-Name) {
            # Now query the tenant database for the actual user
            sql_tenant
        }
        else {
            # No tenant found for this NAS
            reject
        }
        
        # Check password
        pap
    }
    
    # Authentication section
    authenticate {
        Auth-Type PAP {
            pap
        }
        
        Auth-Type CHAP {
            chap
        }
        
        Auth-Type MS-CHAP {
            mschap
        }
    }
    
    # Pre-accounting
    preacct {
        preprocess
    }
    
    # Accounting section
    accounting {
        # Get tenant info first
        sql
        
        # Then log to tenant database
        if (&control:Tenant-DB-Name) {
            sql_tenant
        }
    }
    
    # Post-authentication
    post-auth {
        # Get tenant info
        sql
        
        # Log to tenant database
        if (&control:Tenant-DB-Name) {
            sql_tenant
        }
        
        Post-Auth-Type REJECT {
            sql
            if (&control:Tenant-DB-Name) {
                sql_tenant
            }
        }
    }
}
```

### File 5: `/etc/freeradius/3.0/policy.d/tenant_lookup`

Create a custom policy for tenant database lookup.

```bash
sudo nano /etc/freeradius/3.0/policy.d/tenant_lookup
```

```
# -*- text -*-
#
# Tenant Lookup Policy for Onlifi Multi-Tenant FreeRADIUS
#

# Policy to look up tenant database credentials based on NAS-Identifier
policy tenant_lookup {
    # Query central database for tenant info
    if ("%{sql:SELECT COUNT(*) FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' AND t.is_active = 1}" > 0) {
        
        # Get tenant database credentials
        update control {
            &Tenant-DB-Name := "%{sql:SELECT t.database_name FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' LIMIT 1}"
            &Tenant-DB-Host := "%{sql:SELECT t.database_host FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' LIMIT 1}"
            &Tenant-DB-Port := "%{sql:SELECT t.database_port FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' LIMIT 1}"
            &Tenant-DB-User := "%{sql:SELECT t.database_username FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' LIMIT 1}"
            &Tenant-DB-Pass := "%{sql:SELECT t.database_password FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' LIMIT 1}"
            &Tenant-ID := "%{sql:SELECT t.id FROM nas n JOIN tenants t ON n.tenant_id = t.id WHERE n.router_identifier = '%{NAS-Identifier}' LIMIT 1}"
        }
        
        ok
    }
    else {
        # NAS not found or tenant not active
        reject
    }
}
```

---

## Simplified Approach: Single Database with Tenant Prefix

Since dynamic database switching in FreeRADIUS is complex, here's a **simpler alternative** using a single database with tenant-prefixed voucher codes:

### Alternative Architecture

Instead of separate databases per tenant, use a single `radius` database with:
- Voucher codes prefixed with tenant slug: `acme_ABC123`, `beta_XYZ789`
- Single `radcheck`, `radreply`, `radacct` tables with `tenant_id` column

### File: `/etc/freeradius/3.0/mods-config/sql/main/mysql/queries_simple.conf`

```sql
# -*- text -*-
#
# Simplified Single-Database Multi-Tenant Queries
#

# Get tenant_id from NAS-Identifier
authorize_check_query = "\
    SELECT rc.id, rc.username, rc.attribute, rc.op, rc.value \
    FROM radcheck rc \
    JOIN nas n ON rc.tenant_id = n.tenant_id \
    WHERE rc.username = '%{SQL-User-Name}' \
    AND n.router_identifier = '%{NAS-Identifier}'"

authorize_reply_query = "\
    SELECT rr.id, rr.username, rr.attribute, rr.op, rr.value \
    FROM radreply rr \
    JOIN nas n ON rr.tenant_id = n.tenant_id \
    WHERE rr.username = '%{SQL-User-Name}' \
    AND n.router_identifier = '%{NAS-Identifier}'"

# Accounting with tenant isolation
accounting_start_query = "\
    INSERT INTO radacct \
        (acctsessionid, acctuniqueid, username, nasipaddress, \
         nasportid, nasporttype, acctstarttime, acctauthentic, \
         calledstationid, callingstationid, servicetype, \
         framedprotocol, framedipaddress, tenant_id) \
    SELECT \
        '%{Acct-Session-Id}', '%{Acct-Unique-Session-Id}', '%{SQL-User-Name}', \
        '%{NAS-IP-Address}', '%{NAS-Port-Id}', '%{NAS-Port-Type}', \
        NOW(), '%{Acct-Authentic}', '%{Called-Station-Id}', \
        '%{Calling-Station-Id}', '%{Service-Type}', '%{Framed-Protocol}', \
        '%{Framed-IP-Address}', n.tenant_id \
    FROM nas n \
    WHERE n.router_identifier = '%{NAS-Identifier}'"
```

---

## Database Setup

### 1. Create RADIUS Database User

```sql
-- Connect to MySQL as root
mysql -u root -p

-- Create radius user
CREATE USER 'radius_user'@'localhost' IDENTIFIED BY 'your_secure_password';

-- Grant permissions on central database
GRANT SELECT ON onlifi_central.nas TO 'radius_user'@'localhost';
GRANT SELECT ON onlifi_central.tenants TO 'radius_user'@'localhost';

-- Grant permissions on all tenant databases (run for each tenant)
GRANT SELECT, INSERT, UPDATE ON tenant_acme.radcheck TO 'radius_user'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tenant_acme.radreply TO 'radius_user'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tenant_acme.radacct TO 'radius_user'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tenant_acme.radpostauth TO 'radius_user'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tenant_acme.vouchers TO 'radius_user'@'localhost';

FLUSH PRIVILEGES;
```

### 2. Populate NAS Table

When a tenant adds a router, insert into the central `nas` table:

```sql
INSERT INTO nas (nasname, router_identifier, shortname, type, secret, tenant_id, router_id, created_at, updated_at)
VALUES (
    '0.0.0.0/0',                          -- Accept from any IP (we use NAS-Identifier instead)
    'router-uuid-12345',                   -- Unique router identifier (UUID)
    'Acme Router 1',                       -- Friendly name
    'other',                               -- Router type
    'shared_radius_secret',                -- RADIUS shared secret
    1,                                     -- Tenant ID
    1,                                     -- Router ID in tenant database
    NOW(),
    NOW()
);
```

### 3. Sync Vouchers to radcheck

When vouchers are created in Laravel, sync them to `radcheck`:

```php
// In VoucherController or VoucherObserver
public function syncToRadius(Voucher $voucher)
{
    DB::connection('tenant')->table('radcheck')->updateOrInsert(
        ['username' => $voucher->voucher_code],
        [
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => $voucher->password,
        ]
    );
    
    // Add reply attributes
    if ($voucher->validity_hours) {
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $voucher->voucher_code, 'attribute' => 'Session-Timeout'],
            [
                'op' => '=',
                'value' => $voucher->validity_hours * 3600,
            ]
        );
    }
    
    if ($voucher->speed_limit_kbps) {
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $voucher->voucher_code, 'attribute' => 'Mikrotik-Rate-Limit'],
            [
                'op' => '=',
                'value' => "{$voucher->speed_limit_kbps}k/{$voucher->speed_limit_kbps}k",
            ]
        );
    }
}
```

---

## MikroTik Router Configuration

### 1. Configure RADIUS Client

```routeros
# Add RADIUS server
/radius add \
    service=hotspot \
    address=192.168.0.180 \
    secret="shared_radius_secret" \
    timeout=3000ms \
    authentication-port=1812 \
    accounting-port=1813

# CRITICAL: Set NAS-Identifier (unique per router)
/radius set 0 src-address=0.0.0.0

# Configure Hotspot to use RADIUS
/ip hotspot profile set default \
    use-radius=yes \
    radius-accounting=yes \
    radius-interim-update=5m \
    nas-port-type=wireless-802.11
```

### 2. Set Unique NAS-Identifier

This is the **most important step** - each router must have a unique identifier:

```routeros
# Set the NAS-Identifier (must match what's in the nas table)
/system identity set name="router-uuid-12345"
```

Or use a custom script to set it:

```routeros
# Generate and store a unique router ID
:local routerId "router-uuid-12345"
/radius set 0 comment=$routerId
```

### 3. Configure Hotspot Server

```routeros
# Create hotspot server
/ip hotspot setup

# Configure hotspot profile
/ip hotspot profile set default \
    hotspot-address=10.5.50.1 \
    dns-name=hotspot.local \
    html-directory=hotspot \
    login-by=http-chap,http-pap \
    use-radius=yes \
    radius-accounting=yes \
    radius-interim-update=5m
```

---

## Enable and Start FreeRADIUS

### 1. Enable SQL Module

```bash
cd /etc/freeradius/3.0/mods-enabled
sudo ln -s ../mods-available/sql sql
```

### 2. Test Configuration

```bash
# Test configuration syntax
sudo freeradius -XC

# Run in debug mode
sudo freeradius -X
```

### 3. Test Authentication

```bash
# Test with radtest
radtest "VOUCHER_CODE" "VOUCHER_PASSWORD" localhost 0 testing123

# Test with specific NAS-Identifier
echo "User-Name=VOUCHER_CODE,User-Password=VOUCHER_PASSWORD,NAS-Identifier=router-uuid-12345" | radclient -x localhost auth testing123
```

### 4. Start Service

```bash
sudo systemctl enable freeradius
sudo systemctl start freeradius
sudo systemctl status freeradius
```

---

## Troubleshooting

### Check Logs

```bash
# FreeRADIUS debug log
sudo tail -f /var/log/freeradius/radius.log

# Run in debug mode
sudo freeradius -X
```

### Common Issues

1. **"No matching NAS"**: NAS-Identifier not found in `nas` table
2. **"Invalid user"**: Voucher not in `radcheck` or wrong password
3. **"Tenant not active"**: Tenant status is not 'approved' or is_active is false
4. **Connection refused**: Check firewall (ports 1812, 1813 UDP)

### Test Queries Manually

```bash
mysql -u radius_user -p onlifi_central

# Check NAS table
SELECT * FROM nas WHERE router_identifier = 'router-uuid-12345';

# Check tenant
SELECT id, name, database_name, is_active, status FROM tenants WHERE id = 1;
```

---

## Security Considerations

1. **RADIUS Secret**: Use a strong, unique secret (at least 16 characters)
2. **Database Passwords**: Store encrypted, use environment variables
3. **Firewall**: Only allow RADIUS ports from known networks
4. **TLS**: Consider using RadSec (RADIUS over TLS) for secure transport
5. **Audit Logging**: Enable detailed logging for security audits

---

## Integration with Laravel

### 1. Create NAS Management Controller

```php
// app/Http/Controllers/NasController.php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NasController extends Controller
{
    public function registerRouter(Request $request)
    {
        $tenant = $request->user()->tenant;
        
        $routerIdentifier = Str::uuid()->toString();
        
        DB::connection('central')->table('nas')->insert([
            'nasname' => '0.0.0.0/0',
            'router_identifier' => $routerIdentifier,
            'shortname' => $request->input('name'),
            'type' => 'other',
            'secret' => config('radius.shared_secret'),
            'tenant_id' => $tenant->id,
            'router_id' => $request->input('router_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return response()->json([
            'router_identifier' => $routerIdentifier,
            'radius_server' => config('radius.server_ip'),
            'radius_port' => 1812,
            'radius_secret' => config('radius.shared_secret'),
            'message' => 'Router registered successfully',
        ]);
    }
}
```

### 2. Voucher Sync Service

```php
// app/Services/RadiusService.php
<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

class RadiusService
{
    public function syncVoucher(Voucher $voucher): void
    {
        // Insert/update radcheck
        DB::connection('tenant')->table('radcheck')->updateOrInsert(
            ['username' => $voucher->voucher_code, 'attribute' => 'Cleartext-Password'],
            ['op' => ':=', 'value' => $voucher->password]
        );
        
        // Session timeout
        $remainingSeconds = ($voucher->validity_hours * 3600) - ($voucher->total_session_time_minutes * 60);
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $voucher->voucher_code, 'attribute' => 'Session-Timeout'],
            ['op' => '=', 'value' => max(0, $remainingSeconds)]
        );
        
        // Speed limit
        if ($voucher->speed_limit_kbps) {
            $rateLimit = "{$voucher->speed_limit_kbps}k/{$voucher->speed_limit_kbps}k";
            DB::connection('tenant')->table('radreply')->updateOrInsert(
                ['username' => $voucher->voucher_code, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => '=', 'value' => $rateLimit]
            );
        }
        
        // Data limit
        if ($voucher->data_limit_mb) {
            $dataLimit = $voucher->data_limit_mb * 1048576; // Convert to bytes
            DB::connection('tenant')->table('radreply')->updateOrInsert(
                ['username' => $voucher->voucher_code, 'attribute' => 'Mikrotik-Total-Limit'],
                ['op' => '=', 'value' => $dataLimit]
            );
        }
    }
    
    public function disableVoucher(Voucher $voucher): void
    {
        DB::connection('tenant')->table('radcheck')
            ->where('username', $voucher->voucher_code)
            ->delete();
            
        DB::connection('tenant')->table('radreply')
            ->where('username', $voucher->voucher_code)
            ->delete();
    }
}
```

---

## Summary

### Key Points

1. **NAS-Identifier is the key** - Since routers don't have public IPs, we identify them by a unique UUID
2. **Central database** stores NAS-to-tenant mappings
3. **Tenant databases** store actual vouchers and RADIUS tables
4. **FreeRADIUS** looks up tenant from NAS-Identifier, then queries tenant database
5. **MikroTik** must be configured with the correct NAS-Identifier

### Quick Setup Checklist

- [ ] Install FreeRADIUS with MySQL module
- [ ] Configure `/etc/freeradius/3.0/mods-available/sql`
- [ ] Create SQL queries files
- [ ] Create radius database user with proper permissions
- [ ] Register routers in `nas` table with unique identifiers
- [ ] Configure MikroTik RADIUS client with correct NAS-Identifier
- [ ] Sync vouchers to `radcheck` and `radreply` tables
- [ ] Test with `radtest` and `radclient`
- [ ] Enable and start FreeRADIUS service
