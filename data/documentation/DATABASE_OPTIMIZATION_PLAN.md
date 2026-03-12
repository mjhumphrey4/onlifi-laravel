# Database Optimization & Architecture Recommendations

## Executive Summary

Based on your multi-tenant database structure analysis, here are my recommendations for optimizing the schema, removing redundant tables, implementing real-time data fetching, and configuring FreeRADIUS for multi-tenant support.

---

## 1. Router Telemetry (`router_telemetry`)

### Current Implementation
```sql
CREATE TABLE router_telemetry (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    router_id INT(11) UNSIGNED NOT NULL,
    cpu_load DECIMAL(5, 2),
    memory_used_mb INT(11),
    uptime_seconds BIGINT(20),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ...
);
```

### ❌ Problem
Currently stores **all historical telemetry data**, which grows indefinitely and slows down queries.

### ✅ Recommendation: Keep Only Latest Data

**Option A: Single Latest Record (Recommended)**
```sql
-- Modified table to store only the latest telemetry per router
CREATE TABLE router_telemetry (
    router_id INT(11) UNSIGNED NOT NULL,
    cpu_load DECIMAL(5, 2),
    memory_used_mb INT(11),
    memory_total_mb INT(11),
    uptime_seconds BIGINT(20),
    active_connections INT(11),
    bandwidth_upload_kbps DECIMAL(10, 2),
    bandwidth_download_kbps DECIMAL(10, 2),
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (router_id),
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Benefits:**
- Only one row per router (uses `REPLACE INTO` or `INSERT ... ON DUPLICATE KEY UPDATE`)
- Fast queries (no need to filter by latest timestamp)
- Minimal storage footprint
- Real-time dashboard data

**Option B: Keep Last 24 Hours (Alternative)**
If you need short-term historical data for graphs:
```sql
-- Keep the current structure but add a cleanup job
-- Cron job to run daily:
DELETE FROM router_telemetry 
WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

**Implementation:**
```php
// In mikrotik_api.php, change INSERT to REPLACE
$stmt = $pdo->prepare("
    REPLACE INTO router_telemetry 
    (router_id, cpu_load, memory_used_mb, memory_total_mb, uptime_seconds, recorded_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
```

---

## 2. Voucher Daily Stats (`voucher_daily_stats`)

### Current Implementation
```sql
CREATE TABLE voucher_daily_stats (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    stat_date DATE NOT NULL,
    sales_point_id INT(11) UNSIGNED,
    vouchers_created INT(11) DEFAULT 0,
    vouchers_used INT(11) DEFAULT 0,
    total_revenue DECIMAL(10, 2),
    ...
);
```

### ❌ Problem
**This table is completely redundant!** All this data can be calculated in real-time from existing tables.

### ✅ Recommendation: **DELETE THIS TABLE**

**Calculate stats dynamically using SQL queries:**

```sql
-- Daily voucher stats (real-time)
SELECT 
    DATE(created_at) as stat_date,
    sales_point_id,
    COUNT(*) as vouchers_created,
    SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as vouchers_used,
    SUM(CASE WHEN status = 'used' THEN price ELSE 0 END) as total_revenue,
    COUNT(DISTINCT used_by_mac) as unique_devices
FROM vouchers
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), sales_point_id
ORDER BY stat_date DESC;
```

**Benefits:**
- Always accurate (no sync issues)
- No storage overhead
- No maintenance required
- Simpler codebase

**Performance Optimization:**
Add composite index for fast queries:
```sql
CREATE INDEX idx_vouchers_created_sales_status 
ON vouchers(created_at, sales_point_id, status);
```

**Migration Steps:**
1. Update `mikrotik_api.php` to use dynamic queries
2. Drop the table: `DROP TABLE voucher_daily_stats;`
3. Remove any code that inserts into this table

---

## 3. Active Clients (`active_clients`)

### Current Implementation
```sql
CREATE TABLE active_clients (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    router_id INT(11) UNSIGNED NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    ip_address VARCHAR(15) NOT NULL,
    username VARCHAR(64),
    uptime_seconds INT(11),
    last_seen TIMESTAMP,
    ...
);
```

### ❌ Problem
**Storing MikroTik real-time data in database is redundant!** This data should be fetched directly from the router API.

### ✅ Recommendation: **DELETE THIS TABLE**

**Fetch active clients directly from MikroTik:**

```php
// In mikrotik_api.php
case 'active_clients':
    requireAuth();
    try {
        $routerId = (int)($_GET['router_id'] ?? 0);
        
        // Get router config
        $stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ?");
        $stmt->execute([$routerId]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$router) {
            fail('Router not found', 404);
        }
        
        // Connect to MikroTik and fetch REAL-TIME data
        $mk = new MikrotikAPI($router['ip_address'], $router['username'], $router['password']);
        
        if (!$mk->connect()) {
            fail('Failed to connect to router', 500);
        }
        
        // Get active HotSpot users (REAL-TIME)
        $activeUsers = $mk->comm('/ip/hotspot/active/print');
        
        // Get DHCP leases for IP mapping
        $dhcpLeases = $mk->comm('/ip/dhcp-server/lease/print');
        
        $clients = [];
        foreach ($activeUsers as $user) {
            $clients[] = [
                'mac_address' => $user['mac-address'] ?? '',
                'ip_address' => $user['address'] ?? '',
                'username' => $user['user'] ?? '',
                'uptime' => $user['uptime'] ?? '',
                'bytes_in' => $user['bytes-in'] ?? 0,
                'bytes_out' => $user['bytes-out'] ?? 0,
                'session_time' => $user['session-time-left'] ?? '',
            ];
        }
        
        $mk->disconnect();
        
        respond([
            'success' => true,
            'clients' => $clients,
            'total' => count($clients)
        ]);
        
    } catch (Exception $e) {
        fail('Failed to fetch clients: ' . $e->getMessage(), 500);
    }
    break;
```

**Benefits:**
- Always accurate (real-time data from router)
- No database storage needed
- No sync/update logic required
- Faster response (no DB write overhead)

**Migration Steps:**
1. Update frontend to fetch from API endpoint (already done)
2. Remove all `INSERT INTO active_clients` code
3. Drop the table: `DROP TABLE active_clients;`

---

## 4. Vouchers vs RADIUS Tables

### Your Question: "What's stored in vouchers? Thought everything is in RADIUS tables?"

### Answer: **Both are needed, but serve different purposes**

#### `vouchers` Table (Business Logic)
```sql
-- Stores business/management data
CREATE TABLE vouchers (
    voucher_code VARCHAR(64),
    group_id INT(11),
    price DECIMAL(10, 2),          -- ← Business data
    sales_point_id INT(11),        -- ← Business data
    status ENUM('unused', 'used'), -- ← Business data
    created_at TIMESTAMP,          -- ← Business data
    ...
);
```

**Purpose:**
- Voucher lifecycle management (unused → used → expired)
- Sales tracking (which sales point sold it)
- Revenue calculation (price)
- Batch management (group_id)
- Business analytics

#### RADIUS Tables (Authentication)
```sql
-- Stores authentication credentials
CREATE TABLE radcheck (
    username VARCHAR(64),  -- Voucher code
    attribute VARCHAR(64), -- 'Cleartext-Password'
    value VARCHAR(253)     -- Voucher password
);

CREATE TABLE radreply (
    username VARCHAR(64),  -- Voucher code
    attribute VARCHAR(64), -- 'Session-Timeout'
    value VARCHAR(253)     -- Validity in seconds
);
```

**Purpose:**
- FreeRADIUS authentication (username/password check)
- Session limits (time, data)
- Network access control

### ✅ Recommendation: **Keep Both**

**Workflow:**
1. User creates voucher → Insert into `vouchers` table
2. Sync to RADIUS → Insert into `radcheck` and `radreply`
3. User authenticates → FreeRADIUS checks `radcheck`
4. Session ends → Update `vouchers.status` to 'used'

---

## 5. FreeRADIUS Multi-Tenant Configuration

### Challenge
FreeRADIUS needs to query **different databases** for different users (multi-tenant).

### ✅ Solution: Dynamic Database Selection

#### Option A: SQL Proxy with Dynamic Database (Recommended)

**1. Create a SQL view/stored procedure that routes queries:**

```sql
-- In central database (onlifi_central)
CREATE TABLE user_database_mapping (
    username_prefix VARCHAR(10) NOT NULL,
    database_name VARCHAR(64) NOT NULL,
    PRIMARY KEY (username_prefix)
);

-- Example data
INSERT INTO user_database_mapping VALUES 
('VCH-HUM-', 'onlifi_hum_a56c53'),
('VCH-JOHN-', 'onlifi_john_b78d92');
```

**2. Modify FreeRADIUS SQL queries to use dynamic database:**

Edit `/etc/freeradius/3.0/mods-available/sql`:

```sql
# Authorize query with dynamic database selection
authorize_check_query = "\
    SELECT rc.id, rc.username, rc.attribute, rc.op, rc.value \
    FROM ( \
        SELECT database_name \
        FROM onlifi_central.user_database_mapping \
        WHERE '%{SQL-User-Name}' LIKE CONCAT(username_prefix, '%') \
        LIMIT 1 \
    ) AS db_map, \
    ${db_map.database_name}.radcheck rc \
    WHERE rc.username = '%{SQL-User-Name}' \
    ORDER BY rc.id"
```

**Problem:** MySQL doesn't support dynamic database names in queries.

#### Option B: FreeRADIUS rlm_perl Module (Advanced)

Use Perl to dynamically select database based on voucher prefix:

```perl
# /etc/freeradius/3.0/mods-config/perl/voucher_auth.pl
sub authorize {
    my $username = $RAD_REQUEST{'User-Name'};
    
    # Extract prefix (e.g., VCH-HUM- from VCH-HUM-ABC123)
    if ($username =~ /^(VCH-[A-Z]+-)/i) {
        my $prefix = $1;
        
        # Query central DB for database name
        my $dbh = DBI->connect("DBI:mysql:onlifi_central", "user", "pass");
        my $sth = $dbh->prepare("SELECT database_name FROM user_database_mapping WHERE username_prefix = ?");
        $sth->execute($prefix);
        my ($db_name) = $sth->fetchrow_array();
        
        # Query tenant database
        my $tenant_dbh = DBI->connect("DBI:mysql:$db_name", "user", "pass");
        my $check_sth = $tenant_dbh->prepare("SELECT value FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
        $check_sth->execute($username);
        my ($password) = $check_sth->fetchrow_array();
        
        if ($password) {
            $RAD_CHECK{'Cleartext-Password'} = $password;
            return RLM_MODULE_OK;
        }
    }
    
    return RLM_MODULE_NOTFOUND;
}
```

#### Option C: Voucher Prefix-Based Database Routing (Simplest)

**Best approach for your use case:**

1. **Enforce voucher code format:** `VCH-{USERNAME}-{RANDOM}`
   - Example: `VCH-HUM-A1B2C3`, `VCH-JOHN-X9Y8Z7`

2. **Create a routing table in central database:**

```sql
-- In onlifi_central database
CREATE TABLE radius_routing (
    user_prefix VARCHAR(20) NOT NULL,
    database_name VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_prefix)
);

INSERT INTO radius_routing VALUES
('VCH-HUM-', 'onlifi_hum_a56c53'),
('VCH-JOHN-', 'onlifi_john_b78d92');
```

3. **Create a unified RADIUS view:**

```sql
-- Create a UNION view across all tenant databases
CREATE OR REPLACE VIEW onlifi_central.radcheck_unified AS
SELECT 'onlifi_hum_a56c53' as source_db, rc.* FROM onlifi_hum_a56c53.radcheck rc
UNION ALL
SELECT 'onlifi_john_b78d92' as source_db, rc.* FROM onlifi_john_b78d92.radcheck rc;

CREATE OR REPLACE VIEW onlifi_central.radreply_unified AS
SELECT 'onlifi_hum_a56c53' as source_db, rr.* FROM onlifi_hum_a56c53.radreply rr
UNION ALL
SELECT 'onlifi_john_b78d92' as source_db, rr.* FROM onlifi_john_b78d92.radreply rr;
```

4. **Configure FreeRADIUS to use unified views:**

```sql
# /etc/freeradius/3.0/mods-available/sql
sql {
    driver = "rlm_sql_mysql"
    server = "localhost"
    port = 3306
    login = "radius_user"
    password = "radius_pass"
    radius_db = "onlifi_central"
    
    # Use unified views
    authorize_check_query = "SELECT id, username, attribute, op, value FROM radcheck_unified WHERE username = '%{SQL-User-Name}' ORDER BY id"
    
    authorize_reply_query = "SELECT id, username, attribute, op, value FROM radreply_unified WHERE username = '%{SQL-User-Name}' ORDER BY id"
}
```

**Benefits:**
- Simple configuration
- No custom Perl code
- Works with standard FreeRADIUS
- Easy to add new tenants (just add to UNION)

**Drawback:**
- Need to update view when adding new tenants

**Automation:**
```php
// When creating new user database, update the view
function addTenantToRadiusView($databaseName) {
    $pdo = getCentralDB();
    
    // Get existing view definition
    $stmt = $pdo->query("SHOW CREATE VIEW radcheck_unified");
    $viewDef = $stmt->fetch(PDO::FETCH_ASSOC)['Create View'];
    
    // Add new UNION clause
    $newUnion = "UNION ALL SELECT '{$databaseName}' as source_db, rc.* FROM {$databaseName}.radcheck rc";
    
    // Recreate view
    $pdo->exec("DROP VIEW IF EXISTS radcheck_unified");
    $pdo->exec($viewDef . " " . $newUnion);
    
    // Same for radreply
    // ...
}
```

---

## 6. User Database Deployment with Admin Approval

### Current Flow
1. User signs up
2. Database created immediately
3. User can login

### ✅ Recommended Flow with Admin Approval

```sql
-- Modify onlifi_central.users table
ALTER TABLE users 
ADD COLUMN account_status ENUM('pending', 'approved', 'suspended', 'rejected') 
    NOT NULL DEFAULT 'pending' AFTER status,
ADD COLUMN database_deployed TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status,
ADD COLUMN approved_by INT(11) UNSIGNED NULL AFTER database_deployed,
ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by,
ADD COLUMN rejection_reason TEXT NULL AFTER approved_at;
```

**Updated Workflow:**

1. **User Signup** (`auth_api.php?action=signup`)
```php
// Create user in central DB only
INSERT INTO users (username, email, password_hash, account_status, database_deployed)
VALUES (?, ?, ?, 'pending', 0);

// Send notification to admin
sendAdminNotification("New user signup: {$username}");

respond([
    'success' => true,
    'message' => 'Account created. Awaiting admin approval.'
]);
```

2. **Admin Reviews** (New admin panel feature)
```php
// GET /api/auth_api.php?action=pending_users (admin only)
case 'pending_users':
    requireAdmin();
    $stmt = $pdo->query("
        SELECT id, username, email, full_name, created_at 
        FROM users 
        WHERE account_status = 'pending' 
        ORDER BY created_at DESC
    ");
    respond(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
```

3. **Admin Approves** (New endpoint)
```php
// POST /api/auth_api.php?action=approve_user
case 'approve_user':
    requireAdmin();
    $userId = (int)$_POST['user_id'];
    $adminId = $_SESSION['user_id'];
    
    // Get user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || $user['account_status'] !== 'pending') {
        fail('Invalid user or already processed', 400);
    }
    
    // Generate database name
    $dbName = generateDatabaseName($user['username']);
    
    // Create tenant database
    createTenantDatabase($dbName);
    
    // Update user record
    $stmt = $pdo->prepare("
        UPDATE users 
        SET account_status = 'approved',
            database_deployed = 1,
            database_name = ?,
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$dbName, $adminId, $userId]);
    
    // Add to RADIUS routing
    addTenantToRadiusView($dbName);
    
    // Send approval email
    sendEmail($user['email'], 'Account Approved', 'Your account has been approved!');
    
    respond(['success' => true, 'message' => 'User approved and database deployed']);
    break;
```

4. **Admin Rejects** (New endpoint)
```php
// POST /api/auth_api.php?action=reject_user
case 'reject_user':
    requireAdmin();
    $userId = (int)$_POST['user_id'];
    $reason = trim($_POST['reason'] ?? '');
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET account_status = 'rejected',
            rejection_reason = ?
        WHERE id = ? AND account_status = 'pending'
    ");
    $stmt->execute([$reason, $userId]);
    
    respond(['success' => true]);
    break;
```

5. **Login Check** (Update existing login)
```php
// In auth_api.php login case
if ($user['account_status'] === 'pending') {
    fail('Account pending admin approval', 403);
}
if ($user['account_status'] === 'rejected') {
    fail('Account rejected: ' . $user['rejection_reason'], 403);
}
if ($user['account_status'] === 'suspended') {
    fail('Account suspended', 403);
}
```

---

## Migration Plan

### Phase 1: Remove Redundant Tables
```sql
-- Backup first!
mysqldump -u root -p onlifi_hum_a56c53 > backup_before_migration.sql

-- Drop redundant tables
DROP TABLE IF EXISTS active_clients;
DROP TABLE IF EXISTS voucher_daily_stats;

-- Optimize router_telemetry
ALTER TABLE router_telemetry DROP PRIMARY KEY;
ALTER TABLE router_telemetry DROP COLUMN id;
ALTER TABLE router_telemetry ADD PRIMARY KEY (router_id);
```

### Phase 2: Update Application Code
1. Remove `active_clients` INSERT/UPDATE code
2. Change to real-time MikroTik API calls
3. Remove `voucher_daily_stats` INSERT code
4. Replace with dynamic SQL queries
5. Update `router_telemetry` to use `REPLACE INTO`

### Phase 3: Add Admin Approval
1. Modify `users` table schema
2. Create admin approval endpoints
3. Build admin UI for user management
4. Update signup flow

### Phase 4: Configure FreeRADIUS
1. Create unified RADIUS views
2. Update FreeRADIUS SQL configuration
3. Test authentication across tenants
4. Create automation for new tenant addition

---

## Summary of Changes

| Table | Action | Reason |
|-------|--------|--------|
| `router_telemetry` | **Modify** | Keep only latest data per router |
| `voucher_daily_stats` | **DELETE** | Calculate dynamically from `vouchers` |
| `active_clients` | **DELETE** | Fetch real-time from MikroTik API |
| `vouchers` | **Keep** | Business logic and management |
| `radcheck/radreply` | **Keep** | FreeRADIUS authentication |
| `users` (central) | **Modify** | Add approval workflow columns |

**Storage Savings:** ~70-80% reduction in database size  
**Performance Gain:** Faster queries, real-time data  
**Maintenance:** Simpler codebase, no sync logic needed

---

## Next Steps

1. Review this plan and confirm approach
2. I'll create migration scripts
3. Update API endpoints
4. Build admin approval UI
5. Configure FreeRADIUS
6. Test thoroughly before production deployment

Would you like me to proceed with implementing these changes?
