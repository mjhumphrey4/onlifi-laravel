-- Add Voucher Types table for managing voucher profiles
-- Run this on each tenant database

CREATE TABLE IF NOT EXISTS voucher_types (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    type_name VARCHAR(100) NOT NULL,
    duration_hours INT(11) NOT NULL,
    base_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    description TEXT,
    data_limit_mb INT(11) DEFAULT NULL,
    speed_limit_kbps INT(11) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_type_name (type_name),
    INDEX idx_active (is_active),
    INDEX idx_duration (duration_hours)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add voucher_type_id to vouchers table
ALTER TABLE vouchers 
ADD COLUMN voucher_type_id INT(11) UNSIGNED DEFAULT NULL AFTER group_id,
ADD INDEX idx_voucher_type (voucher_type_id),
ADD FOREIGN KEY (voucher_type_id) REFERENCES voucher_types(id) ON DELETE SET NULL;

-- Add voucher_type_id to voucher_groups table
ALTER TABLE voucher_groups
ADD COLUMN voucher_type_id INT(11) UNSIGNED DEFAULT NULL AFTER sales_point_id,
ADD INDEX idx_voucher_type (voucher_type_id),
ADD FOREIGN KEY (voucher_type_id) REFERENCES voucher_types(id) ON DELETE SET NULL;

-- Insert default voucher types (examples)
INSERT INTO voucher_types (type_name, duration_hours, base_amount, description) VALUES
('1 Hour', 1, 500.00, 'Basic 1-hour internet access'),
('2 Hours', 2, 900.00, 'Standard 2-hour internet access'),
('3 Hours', 3, 1200.00, 'Extended 3-hour internet access'),
('6 Hours', 6, 2000.00, 'Half-day internet access'),
('12 Hours', 12, 3500.00, 'Full-day internet access'),
('24 Hours', 24, 6000.00, 'One-day unlimited access')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

SELECT 'Voucher types table created successfully!' as status;
