-- Database Optimization Migration
-- This script removes redundant tables and optimizes router_telemetry for real-time data
-- Run this on each tenant database (e.g., onlifi_hum_a56c53)

-- ============================================================================
-- BACKUP FIRST!
-- mysqldump -u root -p DATABASE_NAME > backup_before_optimization.sql
-- ============================================================================

-- Step 1: Drop active_clients table (data now fetched real-time from MikroTik)
DROP TABLE IF EXISTS active_clients;

-- Step 2: Drop voucher_daily_stats table (stats calculated dynamically)
DROP TABLE IF EXISTS voucher_daily_stats;

-- Step 3: Optimize router_telemetry to store only latest data per router
-- First, backup existing data if needed
CREATE TABLE IF NOT EXISTS router_telemetry_backup AS SELECT * FROM router_telemetry;

-- Drop the old table
DROP TABLE IF EXISTS router_telemetry;

-- Create new optimized table (one row per router)
CREATE TABLE router_telemetry (
    router_id INT(11) UNSIGNED NOT NULL,
    cpu_load DECIMAL(5, 2) DEFAULT NULL,
    memory_used_mb INT(11) DEFAULT NULL,
    memory_total_mb INT(11) DEFAULT NULL,
    uptime_seconds BIGINT(20) DEFAULT NULL,
    active_connections INT(11) DEFAULT NULL,
    total_clients INT(11) DEFAULT NULL,
    bandwidth_upload_kbps DECIMAL(10, 2) DEFAULT NULL,
    bandwidth_download_kbps DECIMAL(10, 2) DEFAULT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (router_id),
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 4: Add composite index for faster voucher stats queries
CREATE INDEX idx_vouchers_created_sales_status 
ON vouchers(created_at, sales_point_id, status);

-- Step 5: Clean up old indexes that are no longer needed
DROP INDEX IF EXISTS idx_active_clients_router_seen ON active_clients;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify tables were dropped
SELECT 'Tables dropped successfully' as status;
SHOW TABLES LIKE 'active_clients';  -- Should return empty
SHOW TABLES LIKE 'voucher_daily_stats';  -- Should return empty

-- Verify new router_telemetry structure
DESCRIBE router_telemetry;

-- Test voucher stats query (should be fast with new index)
EXPLAIN SELECT 
    DATE(created_at) as stat_date,
    sales_point_id,
    COUNT(*) as vouchers_created,
    SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) as vouchers_used,
    SUM(CASE WHEN status = 'used' THEN price ELSE 0 END) as total_revenue
FROM vouchers
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), sales_point_id;

-- ============================================================================
-- ROLLBACK (if needed)
-- ============================================================================

-- To rollback router_telemetry:
-- DROP TABLE router_telemetry;
-- RENAME TABLE router_telemetry_backup TO router_telemetry;

-- Note: active_clients and voucher_daily_stats cannot be rolled back
-- as they are now obsolete. The application fetches this data differently.

-- ============================================================================
-- POST-MIGRATION CLEANUP
-- ============================================================================

-- After confirming everything works, drop the backup table:
-- DROP TABLE IF EXISTS router_telemetry_backup;

SELECT 'Migration completed successfully!' as status;
