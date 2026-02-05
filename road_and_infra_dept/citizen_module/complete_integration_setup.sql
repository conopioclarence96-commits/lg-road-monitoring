-- Complete Database Integration Script for Citizen Reports
-- This script ensures citizen reports appear correctly in the LGU officer view

-- Step 1: Fix road_name column to allow NULL values (fixes the original error)
ALTER TABLE damage_reports 
MODIFY COLUMN road_name VARCHAR(255) NULL AFTER id;

-- Step 2: Add reported_at column if it doesn't exist
ALTER TABLE damage_reports 
ADD COLUMN IF NOT EXISTS reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_at;

-- Step 3: Update existing records to synchronize timestamps
UPDATE damage_reports 
SET reported_at = created_at 
WHERE reported_at IS NULL;

-- Step 4: Ensure all required columns exist for the citizen module
ALTER TABLE damage_reports 
ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NOT NULL DEFAULT '',
ADD COLUMN IF NOT EXISTS damage_type VARCHAR(50) NOT NULL DEFAULT 'other',
ADD COLUMN IF NOT EXISTS estimated_size VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS traffic_impact ENUM('none', 'minor', 'moderate', 'severe', 'blocked') DEFAULT 'moderate',
ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20) NULL,
ADD COLUMN IF NOT EXISTS anonymous_report TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS lgu_notes TEXT NULL;

-- Step 5: Update severity enum to include all needed values
ALTER TABLE damage_reports 
MODIFY COLUMN severity ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium';

-- Step 6: Update status enum to include all needed values
ALTER TABLE damage_reports 
MODIFY COLUMN status ENUM('pending', 'under_review', 'approved', 'in_progress', 'completed', 'rejected') DEFAULT 'pending';

-- Step 7: Ensure images column is JSON type
ALTER TABLE damage_reports 
MODIFY COLUMN images JSON;

-- Step 8: Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_damage_reports_status ON damage_reports(status);
CREATE INDEX IF NOT EXISTS idx_damage_reports_created_at ON damage_reports(created_at);
CREATE INDEX IF NOT EXISTS idx_damage_reports_reported_at ON damage_reports(reported_at);
CREATE INDEX IF NOT EXISTS idx_damage_reports_severity ON damage_reports(severity);
CREATE INDEX IF NOT EXISTS idx_damage_reports_location ON damage_reports(location);
CREATE INDEX IF NOT EXISTS idx_damage_reports_barangay ON damage_reports(barangay);
CREATE INDEX IF NOT EXISTS idx_damage_reports_damage_type ON damage_reports(damage_type);
CREATE INDEX IF NOT EXISTS idx_damage_reports_traffic_impact ON damage_reports(traffic_impact);

-- Show final table structure for verification
DESCRIBE damage_reports;
