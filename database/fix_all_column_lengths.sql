-- Fix column length issues for report_type and add estimation columns
-- This script fixes all database structure issues

-- Fix report_type column length in transportation table
ALTER TABLE road_transportation_reports 
MODIFY COLUMN report_type VARCHAR(100) DEFAULT NULL;

-- Fix report_type column length in maintenance table  
ALTER TABLE road_maintenance_reports 
MODIFY COLUMN report_type VARCHAR(100) DEFAULT NULL;

-- Add estimation column if it doesn't exist
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER resolution_notes;

ALTER TABLE road_maintenance_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER updated_at;

-- Show updated table structures
DESCRIBE road_transportation_reports;
DESCRIBE road_maintenance_reports;
