-- Add photo_path column to damage_reports table
-- This script adds support for storing evidence photo file paths

-- Check if photo_path column exists and add it if it doesn't
ALTER TABLE damage_reports 
ADD COLUMN IF NOT EXISTS photo_path VARCHAR(500) NULL 
AFTER status;

-- Add index for better performance if column was added
CREATE INDEX IF NOT EXISTS idx_damage_reports_photo_path ON damage_reports(photo_path);

-- Show the updated table structure
DESCRIBE damage_reports;
