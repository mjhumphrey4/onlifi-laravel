-- Add fallback_ref column to transactions table to track IOTEC fallback attempts
-- This column stores the IOTEC external reference when a YO payment fails and is retried

ALTER TABLE transactions 
ADD COLUMN IF NOT EXISTS fallback_ref VARCHAR(255) DEFAULT NULL AFTER status_message;

-- Add index for faster lookups
CREATE INDEX IF NOT EXISTS idx_fallback_ref ON transactions(fallback_ref);

-- Also add fallback_from column to IOTEC transactions table to track original transaction
-- This is for the IOTEC database
-- Run this separately on the IOTEC database if needed:
-- ALTER TABLE transactions 
-- ADD COLUMN IF NOT EXISTS fallback_from VARCHAR(255) DEFAULT NULL AFTER status_message;
-- CREATE INDEX IF NOT EXISTS idx_fallback_from ON transactions(fallback_from);
