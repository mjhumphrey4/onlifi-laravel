-- Migration: Add family_type and wifi_name columns to transactions table
-- Date: 2026-04-13
-- Description: Support for Family Monthly package with Private Router option

-- Add family_type column (normal or private)
ALTER TABLE transactions 
ADD COLUMN family_type VARCHAR(20) DEFAULT 'normal';

-- Add wifi_name column for private router purchases
ALTER TABLE transactions 
ADD COLUMN wifi_name VARCHAR(100) NULL;

-- Add index for family_type for faster queries
ALTER TABLE transactions ADD INDEX idx_family_type (family_type);
