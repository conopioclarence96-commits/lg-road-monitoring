-- Fix column name consistency for citizen reports integration
-- This script ensures both 'created_at' and 'reported_at' columns exist and are synchronized

-- Add reported_at column if it doesn't exist
ALTER TABLE damage_reports 
ADD COLUMN IF NOT EXISTS reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_at;

-- Update existing records to have reported_at = created_at if reported_at is NULL
UPDATE damage_reports 
SET reported_at = created_at 
WHERE reported_at IS NULL;

-- Ensure both columns have the same default behavior
ALTER TABLE damage_reports 
MODIFY COLUMN reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
