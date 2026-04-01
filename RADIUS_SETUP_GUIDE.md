# FreeRADIUS Multi-Tenant Setup Guide

Complete guide to get your FreeRADIUS server working with tenant vouchers and MikroTik routers.

---

## Overview

The Onlifi system uses FreeRADIUS to authenticate hotspot users via voucher codes. Each tenant has their own database, and FreeRADIUS dynamically routes authentication requests to the correct tenant database based on the router's NAS-Identifier.

**Flow:**
1. User enters voucher code on MikroTik hotspot login page
2. MikroTik sends RADIUS request with NAS-Identifier to FreeRADIUS server
3. FreeRADIUS Perl module looks up tenant by NAS-Identifier in central database
4. FreeRADIUS queries tenant's database for voucher authentication
5. User is granted/denied access based on voucher validity

---

## Prerequisites

- Ubuntu/Debian server with FreeRADIUS 3.0 installed
- MySQL/MariaDB server running
- Laravel application deployed and databases migrated
- MikroTik router(s) with hotspot configured

---

## Step 1: Install FreeRADIUS

```bash
# Install FreeRADIUS and required modules
sudo apt update
sudo apt install -y freeradius freeradius-mysql freeradius-utils libfreeradius-perl

# Stop FreeRADIUS for configuration
sudo systemctl stop freeradius
```

---

## Step 2: Deploy FreeRADIUS Configuration Files

Copy all configuration files from `backend/config/freeradius/` to your FreeRADIUS server:

```bash
# Navigate to your project directory
cd /path/to/onlifi-laravel

# Copy SQL configuration
sudo cp backend/config/freeradius/sql.conf \
       /etc/freeradius/3.0/mods-available/sql

# Copy clients configuration (dynamic client lookup)
sudo cp backend/config/freeradius/clients.conf \
       /etc/freeradius/3.0/clients.conf

# Copy queries for central database (NAS lookup only)
sudo cp backend/config/freeradius/queries.conf \
       /etc/freeradius/3.0/mods-config/sql/main/mysql/queries.conf

# Note: Tenant database queries are handled by the Perl module directly
# No need to copy queries_tenant.conf

# Copy Perl module configuration
sudo cp backend/config/freeradius/perl \
       /etc/freeradius/3.0/mods-available/perl

# Create Perl scripts directory if it doesn't exist
sudo mkdir -p /etc/freeradius/3.0/mods-config/perl

# Copy multi-tenant Perl script
sudo cp backend/config/freeradius/multi_tenant.pl \
       /etc/freeradius/3.0/mods-config/perl/multi_tenant.pl

# Set correct permissions
sudo chmod 644 /etc/freeradius/3.0/mods-config/perl/multi_tenant.pl
sudo chown freerad:freerad /etc/freeradius/3.0/mods-config/perl/multi_tenant.pl

# Copy virtual server configuration
sudo cp backend/config/freeradius/default \
       /etc/freeradius/3.0/sites-available/default

# Enable SQL and Perl modules
sudo ln -sf /etc/freeradius/3.0/mods-available/sql \
            /etc/freeradius/3.0/mods-enabled/sql
sudo ln -sf /etc/freeradius/3.0/mods-available/perl \
            /etc/freeradius/3.0/mods-enabled/perl
```

---

## Step 3: Configure Database Connection

Edit `/etc/freeradius/3.0/mods-available/sql` and update the database credentials:

```conf
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    # Central database connection
    server = "localhost"
    port = 3306
    login = "your_db_user"
    password = "your_db_password"
    
    # Central database name
    radius_db = "onlifi_central"
    
    # ... rest of configuration
}
```

Also update the Perl script environment variables in `/etc/freeradius/3.0/mods-config/perl/multi_tenant.pl`:

```perl
# Database configuration (lines 15-18)
my $DB_HOST = $ENV{'RADIUS_DB_HOST'} || 'localhost';
my $DB_PORT = $ENV{'RADIUS_DB_PORT'} || 3306;
my $DB_USER = $ENV{'RADIUS_DB_USER'} || 'your_db_user';
my $DB_PASS = $ENV{'RADIUS_DB_PASS'} || 'your_db_password';
```

Or set environment variables in `/etc/default/freeradius`:

```bash
RADIUS_DB_HOST=localhost
RADIUS_DB_PORT=3306
RADIUS_DB_USER=your_db_user
RADIUS_DB_PASS=your_db_password
RADIUS_CENTRAL_DB=onlifi_central
```

---

## Step 4: Test FreeRADIUS Configuration

```bash
# Test configuration syntax
sudo freeradius -CX

# If successful, you should see:
# Configuration appears to be OK

# Start FreeRADIUS in debug mode to check for errors
sudo freeradius -X

# Press Ctrl+C to stop debug mode
```

**Common Errors and Fixes:**

1. **"Configuration item 'module' is deprecated"**
   - Already fixed in `perl` config - uses `filename` instead

2. **"Can't connect to MySQL server"**
   - Check database credentials in `sql.conf` (for central DB)
   - Check database credentials in `multi_tenant.pl` (lines 30-33)
   - Ensure MySQL is running: `sudo systemctl status mysql`

3. **"Permission denied" for Perl script**
   - Run: `sudo chmod 644 /etc/freeradius/3.0/mods-config/perl/multi_tenant.pl`
   - Run: `sudo chown freerad:freerad /etc/freeradius/3.0/mods-config/perl/multi_tenant.pl`

4. **"Unknown MySQL server host '%{control:Tenant-DB-Host}'"**
   - This error means you have an old version of `sql.conf` with the `sql_tenant` module
   - Update to the latest `sql.conf` which removes the `sql_tenant` module
   - The Perl module handles all tenant database connections directly

---

## Step 5: Start FreeRADIUS Service

```bash
# Enable FreeRADIUS to start on boot
sudo systemctl enable freeradius

# Start FreeRADIUS
sudo systemctl start freeradius

# Check status
sudo systemctl status freeradius

# View logs
sudo tail -f /var/log/freeradius/radius.log
```

---

## Step 6: Register Your MikroTik Router(s)

### Option A: Via Web Dashboard (Recommended)

1. Log in to your tenant dashboard
2. Navigate to **RADIUS Setup** in the sidebar
3. Click **"Add Router"**
4. Enter router name and description
5. Click **"Register Router"**
6. Download or copy the generated MikroTik configuration script
7. Run the script on your MikroTik router (see Step 7)

### Option B: Via API

```bash
curl -X POST http://your-domain.com/api/nas \
  -H "Authorization: Bearer YOUR_TENANT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Main Office Router",
    "description": "Router at main office location"
  }'
```

The response will include:
- `router_identifier`: Unique ID for this router (e.g., ONLIFI-1-250401-ABC12345)
- `radius_config`: RADIUS server details
- `mikrotik_script`: Ready-to-use MikroTik configuration script

---

## Step 7: Configure MikroTik Router

### Method 1: Using Winbox (GUI)

1. Open Winbox and connect to your MikroTik router
2. Go to **System > Scripts**
3. Click **"+"** to create a new script
4. Paste the generated MikroTik script
5. Click **"Run Script"**
6. Verify configuration in **RADIUS** menu

### Method 2: Using Terminal/SSH

1. SSH into your MikroTik router
2. Save the script to a file: `radius-config.rsc`
3. Upload to router via FTP or drag-and-drop in Winbox
4. Run: `/import file-name=radius-config.rsc`

### What the Script Does:

- Adds RADIUS server with your server IP and shared secret
- Sets NAS-Identifier (router identifier) in System Identity
- Configures Hotspot profile to use RADIUS authentication
- Enables RADIUS accounting with 5-minute interim updates

---

## Step 8: Create Vouchers

1. Go to **Vouchers** page in dashboard
2. Click **"Generate Vouchers"**
3. Select voucher type (or create new type)
4. Set quantity, validity hours, data limits, etc.
5. Click **"Generate"**
6. Vouchers are automatically synced to RADIUS database

---

## Step 9: Test Authentication

### Test with radtest (from FreeRADIUS server):

```bash
# Test voucher authentication
radtest VOUCHER_CODE VOUCHER_PASSWORD localhost 0 testing123

# Example:
radtest ABC12345 XYZ67890 localhost 0 testing123

# Expected output for valid voucher:
# Received Access-Accept
```

### Test from MikroTik Hotspot:

1. Connect to the hotspot WiFi network
2. Open browser - you'll be redirected to login page
3. Enter voucher code and password
4. Click **Login**
5. If successful, you'll get internet access

---

## Step 10: Monitor and Troubleshoot

### View RADIUS Logs:

```bash
# Real-time logs
sudo tail -f /var/log/freeradius/radius.log

# Authentication logs
sudo grep "Auth:" /var/log/freeradius/radius.log

# Accounting logs
sudo grep "Acct:" /var/log/freeradius/radius.log
```

### Check Voucher Status in Database:

```bash
# Connect to tenant database
mysql -u your_db_user -p

# Switch to tenant database
USE tenant_1_onlifi;

# View vouchers
SELECT voucher_code, status, first_used_at, last_used_at 
FROM vouchers 
ORDER BY created_at DESC 
LIMIT 10;

# View active sessions
SELECT * FROM radacct WHERE acctstoptime IS NULL;
```

### Common Issues:

**1. "Access-Reject" - Voucher not found**
- Check if voucher exists in tenant database
- Verify NAS-Identifier matches registered router
- Check Perl script is routing to correct tenant database

**2. "Access-Reject" - Voucher expired**
- Check voucher status: `SELECT status FROM vouchers WHERE voucher_code = 'ABC12345'`
- Verify validity_hours and first_used_at

**3. Router not sending RADIUS requests**
- Verify RADIUS server IP is correct in MikroTik
- Check firewall allows UDP ports 1812 and 1813
- Verify shared secret matches between MikroTik and FreeRADIUS

**4. Perl script errors**
- Check Perl module is loaded: `sudo freeradius -X | grep perl`
- Verify database credentials in multi_tenant.pl
- Check Perl script permissions

---

## Firewall Configuration

If using UFW firewall:

```bash
# Allow RADIUS authentication port
sudo ufw allow 1812/udp

# Allow RADIUS accounting port
sudo ufw allow 1813/udp

# Reload firewall
sudo ufw reload
```

---

## Security Best Practices

1. **Use strong RADIUS shared secret**
   - Generate with: `openssl rand -base64 32`
   - Update in both FreeRADIUS and MikroTik

2. **Restrict RADIUS access by IP**
   - Edit `/etc/freeradius/3.0/clients.conf`
   - Add specific NAS client entries instead of 0.0.0.0/0

3. **Enable TLS for database connections**
   - Configure SSL in sql.conf
   - Use encrypted connections between FreeRADIUS and MySQL

4. **Regular log rotation**
   - Configure logrotate for FreeRADIUS logs
   - Monitor disk space usage

---

## Maintenance

### Restart FreeRADIUS after configuration changes:

```bash
sudo systemctl restart freeradius
```

### Update configuration files:

```bash
# Pull latest changes from git
git pull origin main

# Copy updated files
sudo cp backend/config/freeradius/* /etc/freeradius/3.0/...

# Test configuration
sudo freeradius -CX

# Restart service
sudo systemctl restart freeradius
```

---

## Support

If you encounter issues:

1. Check FreeRADIUS logs: `/var/log/freeradius/radius.log`
2. Run in debug mode: `sudo freeradius -X`
3. Verify database connectivity
4. Check MikroTik RADIUS configuration
5. Review this guide's troubleshooting section

For additional help, refer to:
- FreeRADIUS documentation: https://freeradius.org/documentation/
- MikroTik wiki: https://wiki.mikrotik.com/wiki/Manual:RADIUS_Client

---

## Quick Reference

| Component | Location |
|-----------|----------|
| FreeRADIUS config | `/etc/freeradius/3.0/` |
| SQL module | `/etc/freeradius/3.0/mods-enabled/sql` |
| Perl module | `/etc/freeradius/3.0/mods-enabled/perl` |
| Multi-tenant script | `/etc/freeradius/3.0/mods-config/perl/multi_tenant.pl` |
| Central DB queries | `/etc/freeradius/3.0/mods-config/sql/main/mysql/queries.conf` |
| Tenant DB queries | `/etc/freeradius/3.0/mods-config/sql/main/mysql/queries_tenant.conf` |
| Logs | `/var/log/freeradius/radius.log` |
| Service control | `sudo systemctl {start|stop|restart|status} freeradius` |

| Port | Protocol | Purpose |
|------|----------|---------|
| 1812 | UDP | RADIUS Authentication |
| 1813 | UDP | RADIUS Accounting |
| 3306 | TCP | MySQL Database |

---

**You're all set! Your FreeRADIUS server is now ready to authenticate users with voucher codes.**
