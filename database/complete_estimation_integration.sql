-- Complete database setup for cost estimation feature
-- Run this script first to ensure proper database structure

-- Ensure estimation column exists in transportation table
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER resolution_notes;

-- Ensure estimation column exists in maintenance table
ALTER TABLE road_maintenance_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER updated_at;

-- Fix report_type column length to handle specific types
ALTER TABLE road_transportation_reports 
MODIFY COLUMN report_type VARCHAR(100) DEFAULT NULL;

ALTER TABLE road_maintenance_reports 
MODIFY COLUMN report_type VARCHAR(100) DEFAULT NULL;

-- Show final table structures
DESCRIBE road_transportation_reports;
DESCRIBE road_maintenance_reports;
