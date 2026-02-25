-- Fix report_type column length to accommodate specific issue types
-- This script increases the column length from VARCHAR(20) to VARCHAR(100)

-- Update road_transportation_reports table
ALTER TABLE road_transportation_reports 
MODIFY COLUMN report_type VARCHAR(100) DEFAULT NULL;

-- Update road_maintenance_reports table (for consistency)
ALTER TABLE road_maintenance_reports 
MODIFY COLUMN report_type VARCHAR(100) DEFAULT NULL;

-- Show the updated structure
DESCRIBE road_transportation_reports;
