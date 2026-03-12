# Quick Start Guide - MikroTik Features

## Prerequisites

- PHP 7.2 or higher
- MySQL/MariaDB database
- Node.js 18+ and npm
- MikroTik router with API enabled
- FreeRADIUS (optional, for voucher authentication)

## Step 1: Database Setup

### Create the database (if not exists)
```bash
mysql -u root -p
```

```sql
CREATE DATABASE IF NOT EXISTS payment_mikrotik;
USE payment_mikrotik;
```

### Import the schema
```bash
mysql -u root -p payment_mikrotik < database/mikrotik_schema.sql
```

### Verify tables were created
```sql
SHOW TABLES;
```

You should see tables like:
- `mikrotik_routers`
- `vouchers`
- `voucher_groups`
- `voucher_sales_points`
- `active_clients`
- `radcheck`, `radreply`, etc. (FreeRADIUS tables)

## Step 2: Configure MikroTik Router

### Enable API on your MikroTik router
```
/ip service enable api
/ip service set api port=8728
```

### Create API user (recommended)
```
/user add name=api_user password=secure_password group=full
```

### Add router to database
```sql
INSERT INTO mikrotik_routers (name, ip_address, api_port, username, password, location, is_active)
VALUES ('Main Router', '192.168.88.1', 8728, 'api_user', 'secure_password', 'Main Office', 1);
```

## Step 3: Configure HotSpot (if using vouchers)

### Create HotSpot profile
```
/ip hotspot profile add name=voucher-profile
```

### Set up HotSpot server
```
/ip hotspot setup
```

### Configure RADIUS (if using FreeRADIUS)
```
/radius add service=hotspot address=127.0.0.1 secret=testing123
/ip hotspot profile set voucher-profile use-radius=yes
```

## Step 4: Install Dependencies

### Backend (PHP)
```bash
cd /path/to/onlifi-vanilla
composer install
```

### Frontend (React)
```bash
cd newdashboard
npm install
```

## Step 5: Build Frontend

```bash
cd newdashboard
npm run build
```

The built files will be in `newdashboard/dist/`

## Step 6: Configure Web Server

### Apache (.htaccess example)
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.html [L]
</IfModule>
```

### Nginx (example)
```nginx
location / {
    try_files $uri $uri/ /index.html;
}

location /api/ {
    try_files $uri $uri/ /api/api.php?$query_string;
}
```

## Step 7: Test the Installation

### 1. Access the dashboard
```
http://your-domain.com/newdashboard/
```

### 2. Login with default credentials
- Username: `admin`
- Password: `##12345678Aa`

### 3. Test router connectivity
Navigate to **Devices** page and verify your router appears

### 4. Create your first voucher group
1. Go to **Vouchers** page
2. Click **"Create Vouchers"**
3. Fill in the form:
   - Group name: "Test Vouchers"
   - Validity: 24 hours
   - Price: 1000 UGX
   - Quantity: 10
4. Click **"Create Vouchers"**

### 5. View active clients
Navigate to **Clients** page to see connected devices

## Step 8: Create Sales Points (Optional)

```sql
INSERT INTO voucher_sales_points (name, location, contact_person, contact_phone, is_active)
VALUES 
('Downtown Shop', 'Main Street', 'John Doe', '+256 700 000 000', 1),
('Airport Kiosk', 'Airport Terminal', 'Jane Smith', '+256 700 000 001', 1);
```

Or use the UI:
1. Go to **Vouchers** page
2. Click **"Sales Points"**
3. Click **"Add New Sales Point"**
4. Fill in the details

## Step 9: Set Up Automated Tasks (Optional)

### Create cron jobs for periodic updates

```bash
crontab -e
```

Add these lines:

```cron
# Refresh active clients every 5 minutes
*/5 * * * * curl -s http://your-domain.com/api/mikrotik_api.php?action=clients_refresh > /dev/null

# Collect router telemetry every minute
* * * * * curl -s http://your-domain.com/api/mikrotik_api.php?action=router_telemetry > /dev/null

# Generate daily statistics at midnight
0 0 * * * php /path/to/onlifi-vanilla/scripts/generate_daily_stats.php
```

## Step 10: Security Hardening

### 1. Change default passwords
```sql
-- Update admin password in api.php
-- Or use the UI to change passwords
```

### 2. Secure database credentials
Edit `config.php`:
```php
define('DB_USER', 'your_secure_user');
define('DB_PASS', 'your_secure_password');
```

### 3. Enable HTTPS
Use Let's Encrypt or your SSL certificate provider

### 4. Restrict API access
Configure firewall rules to allow API access only from trusted IPs

### 5. Secure MikroTik API
```
/ip service set api address=192.168.1.100/32
```

## Troubleshooting

### Router connection fails
```bash
# Test connection from command line
php -r "
require 'MikrotikAPI.php';
\$api = new MikrotikAPI('192.168.88.1', 'admin', 'password');
var_dump(\$api->connect());
"
```

### No clients showing
1. Check if HotSpot is running: `/ip hotspot active print`
2. Verify DHCP server: `/ip dhcp-server print`
3. Check API permissions

### Vouchers not authenticating
1. Verify FreeRADIUS is running: `systemctl status freeradius`
2. Check radcheck table: `SELECT * FROM radcheck WHERE username='VCH-XXXXX';`
3. Test RADIUS: `radtest VCH-XXXXX password localhost 0 testing123`

### Frontend not loading
1. Check if build completed: `ls newdashboard/dist/`
2. Verify web server configuration
3. Check browser console for errors

## Default User Accounts

| Username | Password | Role | Site |
|----------|----------|------|------|
| admin | ##12345678Aa | Admin | All |
| enock | bite@25 | User | Enock |
| richard | 0700738027 | User | Richard |
| stk | ##12345678Aa | User | STK |

**Important:** Change these passwords immediately in production!

## Next Steps

1. **Customize voucher templates** - Design your voucher printouts
2. **Set up email notifications** - Alert on low voucher stock
3. **Configure backup** - Set up automated database backups
4. **Monitor performance** - Track system metrics
5. **Train staff** - Educate users on the new features

## Support

For issues or questions:
1. Check the logs: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`
2. Review the comprehensive documentation: `MIKROTIK_FEATURES_README.md`
3. Check MikroTik logs: `/log print`
4. Verify database connectivity and permissions

## Quick Reference

### Important URLs
- Dashboard: `/newdashboard/`
- Clients: `/newdashboard/clients`
- Devices: `/newdashboard/devices`
- Vouchers: `/newdashboard/vouchers`
- API: `/api/mikrotik_api.php`

### Key Files
- Database schema: `database/mikrotik_schema.sql`
- MikroTik API: `MikrotikAPI.php`
- Backend API: `newdashboard/api/mikrotik_api.php`
- Config: `config.php`

### Useful SQL Queries

```sql
-- Check voucher inventory
SELECT status, COUNT(*) as count FROM vouchers GROUP BY status;

-- View recent voucher usage
SELECT * FROM voucher_usage_history ORDER BY session_start DESC LIMIT 10;

-- Check active clients
SELECT * FROM active_clients WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE);

-- Revenue by sales point
SELECT vsp.name, SUM(v.price) as revenue 
FROM vouchers v 
JOIN voucher_sales_points vsp ON v.sales_point_id = vsp.id 
WHERE v.status = 'used' 
GROUP BY vsp.id;
```

## Success Checklist

- [ ] Database created and schema imported
- [ ] MikroTik router configured and connected
- [ ] Frontend built and deployed
- [ ] Can login to dashboard
- [ ] Router appears in Devices page
- [ ] Can create vouchers
- [ ] Clients page shows connected devices
- [ ] Sales points created
- [ ] Cron jobs configured (optional)
- [ ] Security hardened
- [ ] Backups configured

Congratulations! Your MikroTik integration is now ready to use! 🎉
