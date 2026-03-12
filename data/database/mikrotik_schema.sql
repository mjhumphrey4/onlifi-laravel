-- MikroTik and FreeRADIUS Integration Schema
-- This schema supports voucher management, FreeRADIUS integration, and MikroTik router operations

-- ============================================================================
-- FREERADIUS TABLES (Standard FreeRADIUS schema with modifications)
-- ============================================================================

-- Users table for FreeRADIUS authentication
CREATE TABLE IF NOT EXISTS radcheck (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User reply attributes
CREATE TABLE IF NOT EXISTS radreply (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Group check attributes
CREATE TABLE IF NOT EXISTS radgroupcheck (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Group reply attributes
CREATE TABLE IF NOT EXISTS radgroupreply (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY groupname (groupname(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User to group mapping
CREATE TABLE IF NOT EXISTS radusergroup (
    username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    priority INT(11) NOT NULL DEFAULT 1,
    KEY username (username(32))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accounting table
CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGINT(21) NOT NULL AUTO_INCREMENT,
    acctsessionid VARCHAR(64) NOT NULL DEFAULT '',
    acctuniqueid VARCHAR(32) NOT NULL DEFAULT '',
    username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    realm VARCHAR(64) DEFAULT '',
    nasipaddress VARCHAR(15) NOT NULL DEFAULT '',
    nasportid VARCHAR(15) DEFAULT NULL,
    nasporttype VARCHAR(32) DEFAULT NULL,
    acctstarttime DATETIME NULL DEFAULT NULL,
    acctupdatetime DATETIME NULL DEFAULT NULL,
    acctstoptime DATETIME NULL DEFAULT NULL,
    acctinterval INT(12) DEFAULT NULL,
    acctsessiontime INT(12) UNSIGNED DEFAULT NULL,
    acctauthentic VARCHAR(32) DEFAULT NULL,
    connectinfo_start VARCHAR(50) DEFAULT NULL,
    connectinfo_stop VARCHAR(50) DEFAULT NULL,
    acctinputoctets BIGINT(20) DEFAULT NULL,
    acctoutputoctets BIGINT(20) DEFAULT NULL,
    calledstationid VARCHAR(50) NOT NULL DEFAULT '',
    callingstationid VARCHAR(50) NOT NULL DEFAULT '',
    acctterminatecause VARCHAR(32) NOT NULL DEFAULT '',
    servicetype VARCHAR(32) DEFAULT NULL,
    framedprotocol VARCHAR(32) DEFAULT NULL,
    framedipaddress VARCHAR(15) NOT NULL DEFAULT '',
    PRIMARY KEY (radacctid),
    UNIQUE KEY acctuniqueid (acctuniqueid),
    KEY username (username),
    KEY framedipaddress (framedipaddress),
    KEY acctsessionid (acctsessionid),
    KEY acctsessiontime (acctsessiontime),
    KEY acctstarttime (acctstarttime),
    KEY acctinterval (acctinterval),
    KEY acctstoptime (acctstoptime),
    KEY nasipaddress (nasipaddress),
    KEY callingstationid (callingstationid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Post-auth logging
CREATE TABLE IF NOT EXISTS radpostauth (
    id INT(11) NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL DEFAULT '',
    pass VARCHAR(64) NOT NULL DEFAULT '',
    reply VARCHAR(32) NOT NULL DEFAULT '',
    authdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- VOUCHER MANAGEMENT TABLES
-- ============================================================================

-- Sales points for voucher distribution
CREATE TABLE IF NOT EXISTS voucher_sales_points (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    contact_person VARCHAR(100) DEFAULT NULL,
    contact_phone VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Voucher types (templates for creating vouchers)
CREATE TABLE IF NOT EXISTS voucher_types (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    type_name VARCHAR(100) NOT NULL,
    duration_hours INT(11) NOT NULL,
    base_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    description TEXT,
    data_limit_mb INT(11) DEFAULT NULL,
    speed_limit_kbps INT(11) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY type_name (type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Voucher groups for batch management
CREATE TABLE IF NOT EXISTS voucher_groups (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    group_name VARCHAR(100) NOT NULL,
    description TEXT,
    profile_name VARCHAR(64) NOT NULL,
    validity_hours INT(11) NOT NULL,
    data_limit_mb INT(11) DEFAULT NULL,
    speed_limit_kbps INT(11) DEFAULT NULL,
    price DECIMAL(10, 2) NOT NULL,
    sales_point_id INT(11) UNSIGNED DEFAULT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY sales_point_id (sales_point_id),
    FOREIGN KEY (sales_point_id) REFERENCES voucher_sales_points(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Individual vouchers
CREATE TABLE IF NOT EXISTS vouchers (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_code VARCHAR(64) NOT NULL,
    password VARCHAR(64) NOT NULL,
    group_id INT(11) UNSIGNED NOT NULL,
    profile_name VARCHAR(64) NOT NULL,
    validity_hours INT(11) NOT NULL,
    data_limit_mb INT(11) DEFAULT NULL,
    speed_limit_kbps INT(11) DEFAULT NULL,
    price DECIMAL(10, 2) NOT NULL,
    sales_point_id INT(11) UNSIGNED DEFAULT NULL,
    status ENUM('unused', 'used', 'expired', 'disabled') NOT NULL DEFAULT 'unused',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    first_used_at TIMESTAMP NULL DEFAULT NULL,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    used_by_mac VARCHAR(17) DEFAULT NULL,
    used_by_ip VARCHAR(15) DEFAULT NULL,
    total_data_used_mb DECIMAL(10, 2) DEFAULT 0,
    total_session_time_minutes INT(11) DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY voucher_code (voucher_code),
    KEY group_id (group_id),
    KEY sales_point_id (sales_point_id),
    KEY status (status),
    KEY created_at (created_at),
    FOREIGN KEY (group_id) REFERENCES voucher_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (sales_point_id) REFERENCES voucher_sales_points(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Voucher usage history
CREATE TABLE IF NOT EXISTS voucher_usage_history (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_id INT(11) UNSIGNED NOT NULL,
    voucher_code VARCHAR(64) NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    ip_address VARCHAR(15) DEFAULT NULL,
    session_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    session_end TIMESTAMP NULL DEFAULT NULL,
    data_uploaded_mb DECIMAL(10, 2) DEFAULT 0,
    data_downloaded_mb DECIMAL(10, 2) DEFAULT 0,
    session_duration_minutes INT(11) DEFAULT 0,
    nas_ip VARCHAR(15) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY voucher_id (voucher_id),
    KEY voucher_code (voucher_code),
    KEY mac_address (mac_address),
    KEY session_start (session_start),
    FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- MIKROTIK ROUTER MANAGEMENT TABLES
-- ============================================================================

-- Router configuration
CREATE TABLE IF NOT EXISTS mikrotik_routers (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(15) NOT NULL,
    api_port INT(5) NOT NULL DEFAULT 8728,
    username VARCHAR(64) NOT NULL,
    password VARCHAR(255) NOT NULL,
    location VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Router telemetry data
CREATE TABLE IF NOT EXISTS router_telemetry (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    router_id INT(11) UNSIGNED NOT NULL,
    cpu_load DECIMAL(5, 2) DEFAULT NULL,
    memory_used_mb INT(11) DEFAULT NULL,
    memory_total_mb INT(11) DEFAULT NULL,
    uptime_seconds BIGINT(20) DEFAULT NULL,
    active_connections INT(11) DEFAULT NULL,
    total_clients INT(11) DEFAULT NULL,
    bandwidth_upload_kbps DECIMAL(10, 2) DEFAULT NULL,
    bandwidth_download_kbps DECIMAL(10, 2) DEFAULT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY router_id (router_id),
    KEY recorded_at (recorded_at),
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Active clients cache (updated periodically from router)
CREATE TABLE IF NOT EXISTS active_clients (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    router_id INT(11) UNSIGNED NOT NULL,
    mac_address VARCHAR(17) NOT NULL,
    ip_address VARCHAR(15) NOT NULL,
    username VARCHAR(64) DEFAULT NULL,
    device_type VARCHAR(50) DEFAULT NULL,
    uptime_seconds INT(11) DEFAULT NULL,
    data_uploaded_mb DECIMAL(10, 2) DEFAULT 0,
    data_downloaded_mb DECIMAL(10, 2) DEFAULT 0,
    signal_strength INT(3) DEFAULT NULL,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY router_mac (router_id, mac_address),
    KEY mac_address (mac_address),
    KEY ip_address (ip_address),
    KEY last_seen (last_seen),
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- VOUCHER STATISTICS VIEWS
-- ============================================================================

-- Daily voucher usage statistics
CREATE TABLE IF NOT EXISTS voucher_daily_stats (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    stat_date DATE NOT NULL,
    sales_point_id INT(11) UNSIGNED DEFAULT NULL,
    vouchers_created INT(11) DEFAULT 0,
    vouchers_used INT(11) DEFAULT 0,
    total_revenue DECIMAL(10, 2) DEFAULT 0,
    unique_devices INT(11) DEFAULT 0,
    total_data_used_mb DECIMAL(10, 2) DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY stat_date_point (stat_date, sales_point_id),
    KEY sales_point_id (sales_point_id),
    FOREIGN KEY (sales_point_id) REFERENCES voucher_sales_points(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- Additional indexes for common queries
CREATE INDEX idx_vouchers_status_created ON vouchers(status, created_at);
CREATE INDEX idx_voucher_usage_date ON voucher_usage_history(session_start);
CREATE INDEX idx_radacct_username_start ON radacct(username, acctstarttime);
CREATE INDEX idx_active_clients_router_seen ON active_clients(router_id, last_seen);

-- ============================================================================
-- INITIAL DATA
-- ============================================================================

-- Insert default sales point
INSERT INTO voucher_sales_points (name, location, contact_person, is_active) 
VALUES ('Main Office', 'Head Office', 'Administrator', 1)
ON DUPLICATE KEY UPDATE name=name;

-- Insert default router (example)
INSERT INTO mikrotik_routers (name, ip_address, api_port, username, password, location, is_active)
VALUES ('Main Router', '192.168.88.1', 8728, 'admin', 'admin', 'Main Office', 1)
ON DUPLICATE KEY UPDATE name=name;
