# Onlifi FreeRADIUS Multi-Tenant Setup Guide

## Overview

This guide explains how to set up FreeRADIUS for multi-tenant hotspot authentication where:
- Each MikroTik router has a **unique RADIUS secret**
- Routers are identified by their **MikroTik System Identity** (not IP address)
- Each tenant has their own database with vouchers
- FreeRADIUS dynamically routes to the correct tenant database

## Prerequisites

- Ubuntu 22.04 LTS (or similar)
- MySQL/MariaDB 8.0+
- FreeRADIUS 3.x
- Onlifi Laravel backend deployed

## Step 1: Install FreeRADIUS

```bash
sudo apt update
sudo apt install -y freeradius freeradius-mysql freeradius-utils libdbi-perl libdbd-mysql-perl

# Stop FreeRADIUS for configuration
sudo systemctl stop freeradius
```

## Step 2: Create RADIUS Database User

```sql
-- Connect to MySQL as root
mysql -u root -p

-- Create radius user with access to central and tenant databases
CREATE USER 'radius_user'@'localhost' IDENTIFIED BY 'your_secure_password';

-- Grant access to central database
GRANT SELECT ON onlifi_central.nas TO 'radius_user'@'localhost';
GRANT SELECT ON onlifi_central.tenants TO 'radius_user'@'localhost';

-- Grant access to ALL tenant databases (use pattern matching)
-- Run this for each tenant database, or use a wildcard if your MySQL supports it
GRANT SELECT, INSERT, UPDATE ON `tenant_%`.* TO 'radius_user'@'localhost';

FLUSH PRIVILEGES;
```

## Step 3: Copy Configuration Files

```bash
# Navigate to FreeRADIUS config directory
cd /etc/freeradius/3.0

# Backup original configs
sudo cp mods-available/sql mods-available/sql.backup
sudo cp sites-available/default sites-available/default.backup

# Copy Onlifi configs from your Laravel backend
# Assuming backend is at /var/www/onlifi/backend
sudo cp /var/www/onlifi/backend/config/freeradius/sql.conf mods-available/sql
sudo cp /var/www/onlifi/backend/config/freeradius/default sites-available/default
sudo cp /var/www/onlifi/backend/config/freeradius/clients.conf clients.conf

# Copy query files
sudo mkdir -p mods-config/sql/main/mysql
sudo cp /var/www/onlifi/backend/config/freeradius/queries.conf mods-config/sql/main/mysql/
sudo cp /var/www/onlifi/backend/config/freeradius/queries_tenant.conf mods-config/sql/main/mysql/

# Copy Perl module
sudo mkdir -p mods-config/perl
sudo cp /var/www/onlifi/backend/config/freeradius/multi_tenant.pl mods-config/perl/onlifi_multi_tenant.pl
sudo chmod +x mods-config/perl/onlifi_multi_tenant.pl
```

## Step 4: Configure Perl Module

Edit the Perl module to set database credentials:

```bash
sudo nano /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
```

Update these lines:
```perl
my $central_db_host = "localhost";
my $central_db_name = "onlifi_central";
my $central_db_user = "radius_user";
my $central_db_pass = "your_secure_password";  # <-- Change this!
```

## Step 5: Enable Required Modules

```bash
cd /etc/freeradius/3.0/mods-enabled

# Enable SQL module
sudo ln -sf ../mods-available/sql sql

# Enable Perl module
sudo ln -sf ../mods-available/perl perl

# Configure Perl module
sudo nano ../mods-available/perl
```

Update the Perl module configuration:
```
perl {
    module = /etc/freeradius/3.0/mods-config/perl/onlifi_multi_tenant.pl
    func_authorize = authorize
    func_authenticate = authenticate
    func_accounting = accounting
    func_post_auth = post_auth
}
```

## Step 6: Update SQL Module Password

Edit the SQL module to set the actual password:

```bash
sudo nano /etc/freeradius/3.0/mods-available/sql
```

Change:
```
password = "${ENV_RADIUS_DB_PASSWORD}"
```

To:
```
password = "your_secure_password"
```

Or set the environment variable in `/etc/default/freeradius`:
```bash
echo 'ENV_RADIUS_DB_PASSWORD="your_secure_password"' | sudo tee -a /etc/default/freeradius
```

## Step 7: Test Configuration

```bash
# Test configuration syntax
sudo freeradius -XC

# If no errors, run in debug mode
sudo freeradius -X
```

## Step 8: Register a Router in the NAS Table

When a tenant adds a router through the Onlifi dashboard, it creates an entry in the `nas` table:

```sql
-- Example: Register a router for tenant ID 1
INSERT INTO nas (
    nasname,
    router_identifier,
    shortname,
    type,
    secret,
    tenant_id,
    created_at,
    updated_at
) VALUES (
    '0.0.0.0/0',                    -- Accept from any IP
    'ACME-ROUTER-001',              -- MikroTik System Identity
    'Acme Main Router',             -- Friendly name
    'other',                        -- Router type
    'unique_secret_for_acme_001',   -- UNIQUE secret for this router
    1,                              -- Tenant ID
    NOW(),
    NOW()
);
```

## Step 9: Configure MikroTik Router

On each MikroTik router, configure RADIUS:

```routeros
# Set the System Identity (this becomes the NAS-Identifier)
/system identity set name="ACME-ROUTER-001"

# Add RADIUS server
/radius add \
    address=192.168.0.180 \
    secret="unique_secret_for_acme_001" \
    service=hotspot \
    authentication-port=1812 \
    accounting-port=1813 \
    timeout=3000ms

# Configure Hotspot to use RADIUS
/ip hotspot profile set [find] \
    use-radius=yes \
    radius-accounting=yes \
    radius-interim-update=5m
```

**IMPORTANT:** The System Identity MUST match the `router_identifier` in the `nas` table!

## Step 10: Start FreeRADIUS

```bash
sudo systemctl enable freeradius
sudo systemctl start freeradius
sudo systemctl status freeradius
```

## Testing Authentication

### Test with radtest

```bash
# Basic test (won't work for multi-tenant without NAS-Identifier)
radtest "VOUCHER_CODE" "VOUCHER_PASSWORD" localhost 0 testing123

# Test with NAS-Identifier (simulates MikroTik request)
echo "User-Name=VOUCHER_CODE,User-Password=VOUCHER_PASSWORD,NAS-Identifier=ACME-ROUTER-001" | \
    radclient -x localhost auth unique_secret_for_acme_001
```

### Check Logs

```bash
# FreeRADIUS logs
sudo tail -f /var/log/freeradius/radius.log

# Debug mode (stop service first)
sudo systemctl stop freeradius
sudo freeradius -X
```

## How It Works

### Authentication Flow

```
1. User enters voucher code on MikroTik hotspot
                    │
                    ▼
2. MikroTik sends RADIUS Access-Request
   - User-Name: VOUCHER_CODE
   - User-Password: VOUCHER_PASSWORD
   - NAS-Identifier: ACME-ROUTER-001 (System Identity)
                    │
                    ▼
3. FreeRADIUS receives request
   - Perl module extracts NAS-Identifier
   - Queries central `nas` table for router
   - Validates RADIUS secret matches
   - Gets tenant_id from NAS record
                    │
                    ▼
4. Perl module connects to tenant database
   - Queries `vouchers` table for voucher code
   - Checks voucher status (unused/used, not expired)
   - Gets voucher attributes (validity, speed, data limit)
                    │
                    ▼
5. FreeRADIUS returns Access-Accept
   - Session-Timeout: remaining time in seconds
   - Mikrotik-Rate-Limit: speed limit
   - Mikrotik-Total-Limit: data limit in bytes
                    │
                    ▼
6. MikroTik grants access to user
```

### Database Structure

**Central Database (onlifi_central):**
```
nas table:
├── id
├── nasname (0.0.0.0/0 - accept any IP)
├── router_identifier (MikroTik Identity - UNIQUE)
├── shortname (friendly name)
├── type (other)
├── secret (UNIQUE per router)
├── tenant_id (links to tenants table)
└── timestamps

tenants table:
├── id
├── name
├── database_name (tenant_acme)
├── database_host
├── database_username
├── database_password
├── is_active
└── status (approved)
```

**Tenant Database (tenant_acme):**
```
vouchers table:
├── voucher_code (username for RADIUS)
├── password
├── validity_hours
├── speed_limit_kbps
├── data_limit_mb
├── status (unused/used/expired)
├── total_session_time_minutes
├── total_data_used_mb
└── timestamps

radcheck table (optional, for standard RADIUS):
├── username
├── attribute
├── op
└── value

radreply table (optional):
├── username
├── attribute
├── op
└── value

radacct table (session history):
├── acctsessionid
├── username
├── nasipaddress
├── acctstarttime
├── acctstoptime
├── acctinputoctets
├── acctoutputoctets
└── etc.
```

## Troubleshooting

### "No tenant found for router identifier"

- Check that the MikroTik System Identity matches `router_identifier` in `nas` table
- Verify the tenant is active and approved

### "Invalid user" or authentication fails

- Check voucher exists in tenant's `vouchers` table
- Verify voucher status is 'unused' or 'used' (not 'expired' or 'disabled')
- Check voucher hasn't expired (expires_at > NOW())

### "Connection refused"

- Check FreeRADIUS is running: `sudo systemctl status freeradius`
- Check firewall allows UDP ports 1812 and 1813
- Verify MySQL is accessible from FreeRADIUS

### RADIUS secret mismatch

- Each router must use its own unique secret
- The secret in MikroTik must match the `secret` column in `nas` table for that router

## Security Best Practices

1. **Unique secrets per router** - Never share RADIUS secrets between routers
2. **Strong secrets** - Use at least 16 random characters
3. **Database encryption** - Store tenant database passwords encrypted
4. **Firewall** - Only allow RADIUS ports from known network ranges
5. **TLS** - Consider RadSec (RADIUS over TLS) for secure transport
6. **Audit logging** - Enable detailed logging for security audits

## Maintenance

### Sync Vouchers to RADIUS

When vouchers are created in Laravel, they're automatically synced to the tenant's database. The `VoucherObserver` handles this.

To manually sync all vouchers:

```bash
cd /var/www/onlifi/backend
php artisan tinker --execute="app(App\Services\RadiusService::class)->syncAllActiveVouchers();"
```

### Cleanup Expired Vouchers

```bash
php artisan tinker --execute="app(App\Services\RadiusService::class)->cleanupExpiredVouchers();"
```

### View Active Sessions

```bash
# Query radacct table for active sessions
mysql -u radius_user -p tenant_acme -e "SELECT username, nasipaddress, acctstarttime FROM radacct WHERE acctstoptime IS NULL;"
```
