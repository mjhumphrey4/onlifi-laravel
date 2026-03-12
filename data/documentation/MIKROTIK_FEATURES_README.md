# MikroTik Router Operations - Feature Documentation

## Overview

This document describes the new MikroTik router management features added to the Onlifi-Vanilla project. These features enable comprehensive network management, voucher creation, client monitoring, and FreeRADIUS integration.

## New Features

### 1. **Clients Management** (`/clients`)
Real-time monitoring of active users on your MikroTik network.

**Features:**
- Live display of all connected clients
- Device type detection (based on MAC address OUI)
- IP address and MAC address tracking
- Uptime monitoring for each client
- Data transfer statistics (upload/download)
- Voucher association (shows which voucher each client is using)
- Auto-refresh every 30 seconds
- Manual refresh button for real-time updates

**Key Metrics:**
- Total active clients
- Clients with vouchers
- Total upload/download data
- Per-client data usage

### 2. **Devices Management** (`/devices`)
Monitor your MikroTik routers and network infrastructure.

**Features:**
- Router status monitoring
- CPU load tracking
- Memory usage statistics
- Uptime tracking
- Real-time telemetry data
- Health status indicators
- Placeholder for Uptime Kuma integration (coming soon)

**Router Metrics:**
- CPU load percentage with visual indicators
- Memory usage (used/total)
- System uptime
- Last seen timestamp
- Active/inactive status

### 3. **Voucher Management** (`/vouchers`)
Comprehensive voucher creation and management system with FreeRADIUS integration.

#### 3.1 Voucher Groups
Organize vouchers into logical groups for easier management.

**Features:**
- Create voucher groups with custom configurations
- Batch voucher generation (up to 1000 per batch)
- Group-level statistics
- Usage tracking per group
- Revenue reporting

**Configuration Options:**
- Group name and description
- Validity period (hours)
- Data limit (MB)
- Speed limit (Kbps)
- Price per voucher
- Profile name
- Sales point assignment

#### 3.2 Voucher Creation Dialog
Beautiful, user-friendly interface for creating vouchers.

**Features:**
- Step-by-step form with validation
- Real-time preview of voucher configuration
- Custom code prefix and length
- Sales point assignment
- Bulk generation (1-1000 vouchers)
- Total value calculation

**Generated Voucher Details:**
- Unique voucher code (customizable prefix)
- Secure password
- Validity period
- Data and speed limits
- Associated profile
- Sales point tracking

#### 3.3 Sales Points Management
Track voucher distribution across multiple sales locations.

**Features:**
- Create and manage sales points
- Location and contact information
- Per-point voucher statistics
- Revenue tracking by sales point
- Performance analytics

**Sales Point Information:**
- Name and location
- Contact person and phone
- Total vouchers distributed
- Total revenue generated
- Active/inactive status

#### 3.4 Voucher Statistics
Comprehensive analytics and reporting.

**Metrics:**
- Total vouchers created
- Used vs. unused vouchers
- Expired vouchers
- Total revenue
- Daily usage trends (last 30 days)
- Unique devices per day
- Revenue by sales point
- Usage rate percentage

### 4. **Enhanced Dashboard**
The main dashboard now includes MikroTik-specific metrics alongside payment data.

**New Dashboard Sections:**
- **Voucher Statistics Card**: Quick overview of voucher inventory and revenue
- **Router Status Card**: Real-time router health metrics
- **Active Clients Counter**: Live count of connected users
- **Integrated View**: Seamless blend of payment and network data

**Dashboard Metrics:**
- Today's earnings (mobile money)
- Total earnings
- Total withdrawals
- Active clients count
- Voucher usage statistics
- Router CPU and memory status
- Recent transactions with voucher codes

## Database Schema

### Core Tables

#### 1. **FreeRADIUS Tables**
Standard FreeRADIUS schema for authentication:
- `radcheck` - User credentials
- `radreply` - User attributes
- `radgroupcheck` - Group check attributes
- `radgroupreply` - Group reply attributes
- `radusergroup` - User-to-group mapping
- `radacct` - Accounting records
- `radpostauth` - Post-authentication logging

#### 2. **Voucher Management Tables**
- `voucher_sales_points` - Sales point information
- `voucher_groups` - Voucher group configurations
- `vouchers` - Individual voucher records
- `voucher_usage_history` - Detailed usage tracking
- `voucher_daily_stats` - Daily statistics aggregation

#### 3. **Router Management Tables**
- `mikrotik_routers` - Router configurations
- `router_telemetry` - Historical telemetry data
- `active_clients` - Cached client information

## API Endpoints

### MikroTik API (`/api/mikrotik_api.php`)

#### Router Management
- `GET ?action=routers` - List all configured routers
- `GET ?action=router_telemetry` - Get router telemetry data
- `GET ?action=router_telemetry&router_id={id}` - Get specific router telemetry

#### Client Management
- `GET ?action=clients` - Get active clients (from cache)
- `GET ?action=clients_refresh` - Refresh clients from router (real-time)
- `GET ?action=clients_refresh&router_id={id}` - Refresh specific router

#### Voucher Management
- `GET ?action=voucher_groups` - List all voucher groups
- `GET ?action=vouchers` - List vouchers
- `GET ?action=vouchers&group_id={id}` - Filter by group
- `GET ?action=vouchers&status={status}` - Filter by status
- `POST ?action=create_vouchers` - Create new voucher group and vouchers
- `GET ?action=voucher_stats` - Get voucher statistics
- `GET ?action=voucher_stats&days={n}` - Get stats for last N days

#### Sales Points
- `GET ?action=sales_points` - List all sales points
- `POST ?action=create_sales_point` - Create new sales point

#### FreeRADIUS Integration
- `POST ?action=sync_voucher_to_radius` - Sync voucher to FreeRADIUS

## MikroTik API Helper Class

### `MikrotikAPI.php`
PHP class for communicating with MikroTik routers via API.

**Key Methods:**
- `connect()` - Establish connection to router
- `getActiveClients()` - Get DHCP leases
- `getHotspotUsers()` - Get active HotSpot users
- `getSystemResources()` - Get router system information
- `getInterfaceStats()` - Get interface statistics
- `addHotspotUser()` - Add new HotSpot user (voucher)
- `removeHotspotUser()` - Remove HotSpot user
- `testConnection()` - Test router connectivity

**Features:**
- Automatic connection management
- Device type detection via MAC OUI lookup
- Error handling and logging
- Secure password handling

## Installation & Setup

### 1. Database Setup
```bash
mysql -u root -p payment_mikrotik < database/mikrotik_schema.sql
```

### 2. Router Configuration
Add your MikroTik router to the database:
```sql
INSERT INTO mikrotik_routers (name, ip_address, api_port, username, password, location, is_active)
VALUES ('Main Router', '192.168.88.1', 8728, 'admin', 'your_password', 'Main Office', 1);
```

### 3. Enable MikroTik API
On your MikroTik router:
```
/ip service enable api
/ip service set api port=8728
```

### 4. Create Default Sales Point
```sql
INSERT INTO voucher_sales_points (name, location, contact_person, is_active) 
VALUES ('Main Office', 'Head Office', 'Administrator', 1);
```

### 5. Frontend Build
```bash
cd newdashboard
npm install
npm run build
```

## Usage Guide

### Creating Vouchers

1. Navigate to **Vouchers** page (`/vouchers`)
2. Click **"Create Vouchers"** button
3. Fill in the voucher configuration:
   - Group name and description
   - Validity period (hours)
   - Price per voucher
   - Optional: Data limit, speed limit
   - Quantity (1-1000)
   - Sales point (optional)
4. Review the preview
5. Click **"Create Vouchers"**
6. Vouchers are automatically generated with unique codes

### Monitoring Clients

1. Navigate to **Clients** page (`/clients`)
2. View real-time list of connected clients
3. Click **"Refresh"** for immediate update
4. Monitor data usage and uptime
5. Check which vouchers are in use

### Checking Router Status

1. Navigate to **Devices** page (`/devices`)
2. View router health metrics
3. Monitor CPU and memory usage
4. Check system uptime
5. Identify potential issues

### Managing Sales Points

1. Navigate to **Vouchers** page
2. Click **"Sales Points"** button
3. Click **"Add New Sales Point"**
4. Enter sales point details
5. View performance statistics per point

## FreeRADIUS Integration

### Automatic Sync
Vouchers are automatically synced to FreeRADIUS when created:
- Username: Voucher code
- Password: Generated password
- Session-Timeout: Validity hours × 3600
- Data limits (if configured)

### Manual Sync
Use the API endpoint to manually sync:
```bash
POST /api/mikrotik_api.php?action=sync_voucher_to_radius
{
  "voucher_id": 123
}
```

### Accounting
FreeRADIUS accounting records are stored in `radacct` table and linked to voucher usage history.

## Security Considerations

1. **Router Credentials**: Store router passwords securely, consider encryption
2. **API Access**: Restrict API access to trusted networks
3. **Voucher Codes**: Generated with cryptographically secure random bytes
4. **Database**: Use strong passwords and limit access
5. **HTTPS**: Always use HTTPS in production

## Performance Optimization

1. **Client Cache**: Active clients cached for 5 minutes to reduce router queries
2. **Telemetry**: Router telemetry collected every minute
3. **Auto-refresh**: Dashboard auto-refreshes every 60 seconds
4. **Pagination**: Voucher lists paginated (50 per page)
5. **Indexes**: Database indexes on frequently queried columns

## Future Enhancements

### Planned Features
- ✅ Uptime Kuma integration for comprehensive monitoring
- ✅ Historical metrics and trend analysis
- ✅ Custom alert configuration
- ✅ Voucher printing templates
- ✅ QR code generation for vouchers
- ✅ SMS integration for voucher delivery
- ✅ Multi-router load balancing
- ✅ Advanced reporting and analytics
- ✅ Voucher expiration notifications
- ✅ Bandwidth usage alerts

## Troubleshooting

### Common Issues

**1. Cannot connect to router**
- Verify router IP address and API port
- Check firewall rules
- Ensure API service is enabled on router
- Verify credentials

**2. No clients showing**
- Check if HotSpot is configured on router
- Verify DHCP server is running
- Refresh client list manually
- Check router connectivity

**3. Vouchers not working**
- Verify FreeRADIUS is running
- Check radcheck table for voucher entry
- Verify HotSpot profile configuration
- Check router logs

**4. Telemetry not updating**
- Check router API connectivity
- Verify cron jobs are running
- Check database permissions
- Review error logs

## Support & Maintenance

### Logs
- PHP errors: Check web server error logs
- API errors: Check `error_log()` output
- Router issues: Check MikroTik system logs
- Database: Check MySQL error logs

### Monitoring
- Set up cron jobs for periodic data refresh
- Monitor database size and performance
- Track API response times
- Monitor router connectivity

### Backup
- Regular database backups
- Export voucher data periodically
- Backup router configurations
- Document custom configurations

## Credits

Developed for Onlifi-Vanilla project with MikroTik RouterOS integration and FreeRADIUS support.

## License

Proprietary - All rights reserved
